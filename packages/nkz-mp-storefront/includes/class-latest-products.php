<?php
/**
 * LatestProducts – výběr produktů pro [nkzmp_latest_products] „jeden
 * produkt od jedné značky".
 *
 * Když jedna značka nahraje víc produktů najednou, zaplnila by celý blok
 * „nově přidáno". S one_per_vendor="1" (výchozí):
 *
 *  1. nejnovější publikovaný a viditelný produkt od každého prodejce,
 *     seřazené podle data publikace sestupně, prvních `limit`,
 *  2. když je značek méně než `limit`, doplní se po kolech (druhý produkt
 *     od každé značky, pak třetí…) – nejdřív unikátní značky, pak doplnění.
 *
 * Výkon: dávkový WP_Query nad nejnovějšími produkty (jen ID, po 100),
 * dokud není dost unikátních značek; strop 300 produktů. Výsledek (pole
 * ID) se cachuje na hodinu, zneplatní se při uložení / smazání / změně
 * stavu produktu, skladu nebo stavu prodejce.
 *
 * Vazba produkt → prodejce je stejná jako u karty (`.nkzmp-shop-vendor`):
 * meta `_nkzmp_vendor_id`, záložně `_nkv_vendor_id`. Prodejce, kteří se
 * v obchodě nenabízejí (pozastavení / nezaplacené členství), vyřazuje
 * ShopFilters::vendor_visible() – stejně jako seznam značek.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class LatestProducts {

	private const VERSION_OPTION = 'nkzmp_latest_products_v';
	private const BATCH          = 100;
	private const MAX_SCAN       = 300;

	private static ?LatestProducts $instance = null;

	public static function instance(): LatestProducts {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'save_post_product', [ self::class, 'forget' ] );
		add_action( 'deleted_post', [ self::class, 'forget' ] );
		add_action( 'transition_post_status', [ $this, 'on_transition' ], 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', [ self::class, 'forget' ] );
		add_action( 'woocommerce_variation_set_stock_status', [ self::class, 'forget' ] );
		add_action( 'updated_post_meta', [ $this, 'on_meta' ], 10, 3 );
		add_action( 'added_post_meta', [ $this, 'on_meta' ], 10, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( \WP_CLI::class ) ) {
			\WP_CLI::add_command( 'nkzmp latest-products', [ $this, 'cli' ] );
		}
	}

	/** Zneplatní všechny výběry naráz (nová verze klíče). */
	public static function forget(): void {
		update_option( self::VERSION_OPTION, (int) get_option( self::VERSION_OPTION, 1 ) + 1, false );
	}

	/** @param \WP_Post $post */
	public function on_transition( $new, $old, $post ): void {
		if ( $new !== $old && $post instanceof \WP_Post && $post->post_type === 'product' ) {
			self::forget();
		}
	}

	/** Změna prodejce produktu nebo stavu prodejce. */
	public function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( in_array( $meta_key, [ '_nkzmp_vendor_id', '_nkv_vendor_id', '_nkzmp_vendor_status', '_nkv_vendor_status', '_nkzmp_billing_status' ], true ) ) {
			self::forget();
		}
	}

	/** Prodejce produktu – stejně jako badge na kartě. 0 = bez prodejce. */
	public static function product_vendor( int $product_id ): int {
		$vid = (int) get_post_meta( $product_id, '_nkzmp_vendor_id', true );
		return $vid > 0 ? $vid : (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
	}

	/** @return int[] */
	public static function parse_ids( string $raw ): array {
		return array_values( array_unique( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) ) );
	}

	/**
	 * ID produktů pro blok (pořadí = pořadí zobrazení).
	 *
	 * @param array{limit:int,category:string,exclude_vendors:int[]} $args
	 * @return int[]
	 */
	public static function ids( array $args ): array {
		$limit   = max( 1, min( 48, (int) $args['limit'] ) );
		$cat     = trim( (string) ( $args['category'] ?? '' ) );
		$exclude = array_map( 'intval', (array) ( $args['exclude_vendors'] ?? [] ) );
		sort( $exclude );
		$hide_oos = get_option( 'woocommerce_hide_out_of_stock_items' ) === 'yes';

		$key    = 'nkzmp_latest_' . md5( wp_json_encode( [ $limit, $cat, $exclude, $hide_oos ] ) ) . '_' . (int) get_option( self::VERSION_OPTION, 1 );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return array_map( 'intval', $cached );
		}

		$ids = self::select( $limit, $cat, $exclude, $hide_oos );
		set_transient( $key, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/**
	 * @param int[] $exclude
	 * @return int[]
	 */
	private static function select( int $limit, string $cat, array $exclude, bool $hide_oos ): array {
		$tax = [];
		if ( function_exists( 'wc_get_product_visibility_term_ids' ) ) {
			$vis  = wc_get_product_visibility_term_ids();
			$hide = array_filter( [ $vis['exclude-from-catalog'] ?? 0, $hide_oos ? ( $vis['outofstock'] ?? 0 ) : 0 ] );
			if ( $hide ) {
				$tax[] = [ 'taxonomy' => 'product_visibility', 'field' => 'term_taxonomy_id', 'terms' => array_values( $hide ), 'operator' => 'NOT IN' ];
			}
		}
		if ( $cat !== '' ) {
			$tax[] = [ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => array_map( 'trim', explode( ',', $cat ) ), 'operator' => 'IN' ];
		}

		$buckets = []; // vendor => [ product ids, nejnovější první ]
		$visible = []; // vendor => bool (cache v rámci výběru)
		$scanned = 0;
		$page    = 1;
		while ( $scanned < self::MAX_SCAN ) {
			$q = new \WP_Query( [
				'post_type'              => 'product',
				'post_status'            => 'publish',
				'fields'                 => 'ids',
				'orderby'                => [ 'date' => 'DESC', 'ID' => 'DESC' ],
				'posts_per_page'         => self::BATCH,
				'paged'                  => $page,
				'tax_query'              => $tax, // phpcs:ignore WordPress.DB.SlowDBQuery
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'suppress_filters'       => false,
			] );
			$batch = array_map( 'intval', (array) $q->posts );
			if ( ! $batch ) {
				break;
			}
			update_meta_cache( 'post', $batch ); // jeden dotaz na meta celé dávky
			foreach ( $batch as $pid ) {
				++$scanned;
				$vid = self::product_vendor( $pid );
				if ( $vid > 0 && in_array( $vid, $exclude, true ) ) {
					continue;
				}
				if ( $vid > 0 ) {
					$visible[ $vid ] ??= ShopFilters::vendor_visible( $vid );
					if ( ! $visible[ $vid ] ) {
						continue;
					}
				}
				$buckets[ $vid ][] = $pid;
			}
			// Dost unikátních značek → hotovo (doplňování není potřeba).
			if ( count( $buckets ) >= $limit || count( $batch ) < self::BATCH ) {
				break;
			}
			++$page;
		}

		return self::round_robin( $buckets, $limit );
	}

	/**
	 * Kolo 1 = nejnovější produkt každé značky (značky seřazené podle svého
	 * nejnovějšího produktu), kolo 2 = druhý produkt každé značky…
	 *
	 * @param array<int,int[]> $buckets vendor => produkty od nejnovějšího (v pořadí první výskytu = podle data)
	 * @return int[]
	 */
	public static function round_robin( array $buckets, int $limit ): array {
		$out   = [];
		$round = 0;
		while ( count( $out ) < $limit ) {
			$added = false;
			foreach ( $buckets as $list ) {
				if ( isset( $list[ $round ] ) ) {
					$out[] = (int) $list[ $round ];
					$added = true;
					if ( count( $out ) >= $limit ) {
						break 2;
					}
				}
			}
			if ( ! $added ) {
				break;
			}
			++$round;
		}
		return $out;
	}

	/**
	 * WP-CLI: vypíše výběr a ověří, že se prodejce neopakuje, dokud nedojdou
	 * unikátní značky.
	 *
	 * ## OPTIONS
	 * [--limit=<n>]           : Počet produktů. Default 8.
	 * [--category=<slugs>]    : Kategorie (slugy, čárkou).
	 * [--exclude_vendors=<ids>] : ID prodejců k vyřazení (čárkou).
	 * [--fresh]               : Ignorovat cache.
	 *
	 * ## EXAMPLES
	 *     wp nkzmp latest-products --limit=8 --exclude_vendors=1661
	 *
	 * @param array $args
	 * @param array $assoc
	 */
	public function cli( $args, $assoc ): void {
		if ( ! empty( $assoc['fresh'] ) ) {
			self::forget();
		}
		$limit = (int) ( $assoc['limit'] ?? 8 );
		$ids   = self::ids( [
			'limit'           => $limit,
			'category'        => (string) ( $assoc['category'] ?? '' ),
			'exclude_vendors' => self::parse_ids( (string) ( $assoc['exclude_vendors'] ?? '' ) ),
		] );
		$rows = [];
		$seen = [];
		$first_repeat = null;
		foreach ( $ids as $i => $pid ) {
			$vid = self::product_vendor( $pid );
			if ( isset( $seen[ $vid ] ) && $first_repeat === null ) {
				$first_repeat = $i;
			}
			$seen[ $vid ] = true;
			$rows[] = [
				'#'       => $i + 1,
				'product' => $pid,
				'vendor'  => $vid,
				'date'    => get_post_field( 'post_date', $pid ),
				'title'   => get_the_title( $pid ),
			];
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ '#', 'product', 'vendor', 'date', 'title' ] );

		// Kontrola: opakování značky smí začít až po vyčerpání unikátních značek.
		$unique = count( $seen );
		if ( $first_repeat !== null && $first_repeat < $unique ) {
			\WP_CLI::error( sprintf( 'Prodejce se opakuje na pozici %d, ale unikátních značek je %d.', $first_repeat + 1, $unique ) );
		}
		\WP_CLI::success( sprintf( '%d produktů, %d unikátních značek%s.', count( $ids ), $unique, $first_repeat === null ? ', žádné opakování' : ', doplněno po kolech' ) );
	}
}
