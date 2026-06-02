<?php
/**
 * ShopLoop – vizuální tuning archive-product stránky (/obchod/, taxonomy
 * archivy, related products loop) pro marketplace kontext.
 *
 * Tři vrstvy přes WC hooky (žádný template override):
 *  1) Intro řádek nad gridem („Tvorby od X prodejců · Y produktů")
 *  2) Vendor badge pod titulem každé karty produktu („od Jan Tvůrce")
 *  3) CTA „Chceš taky prodávat?" pod gridem (cross-link na /pro-tvurce)
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/shop_loop', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ShopLoop {

	private const COUNT_CACHE_KEY = 'nkzmp_active_vendor_count';
	private const COUNT_CACHE_TTL = HOUR_IN_SECONDS;

	private static ?ShopLoop $instance = null;

	public static function instance(): ShopLoop {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/shop_loop', true ) ) {
			return;
		}
		// Default WC sort dropdown + result count odstranit – nahrazujeme je
		// naším intro toolbarem ('Tvorby od X prodejců' + custom sort vpravo).
		// Deferred na 'wp', aby v té době už WC mělo akce přidané.
		add_action( 'wp', static function (): void {
			remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
			remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
		} );

		add_action( 'woocommerce_before_shop_loop', [ $this, 'intro_row' ], 5 );
		add_action( 'woocommerce_after_shop_loop_item_title', [ $this, 'vendor_badge' ], 8 );
		add_action( 'woocommerce_after_main_content', [ $this, 'become_vendor_cta' ], 5 );

		// Invalidace cache počtu aktivních vendorů.
		add_action( 'save_post_nkzmp_vendor', [ __CLASS__, 'forget_count' ] );
		add_action( 'deleted_post', [ __CLASS__, 'forget_count' ] );
		add_action( 'updated_post_meta', [ __CLASS__, 'maybe_forget_count' ], 10, 3 );
	}

	/** Pouze nad hlavním shop / category archivem produktů. */
	private static function applicable(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}
		return is_shop() || is_product_taxonomy();
	}

	public function intro_row(): void {
		if ( ! self::applicable() ) {
			return;
		}
		global $wp_query;
		$total       = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
		$vendor_n    = self::active_vendor_count();

		echo '<div class="nkzmp-shop-intro">';
		echo '<div class="nkzmp-shop-intro__left">';
		if ( $vendor_n > 0 ) {
			printf(
				'<span class="nkzmp-shop-intro__lead">%s</span>',
				esc_html(
					sprintf(
						/* translators: 1: number of active vendors, 2: number of products */
						_n(
							'Tvorby od %1$d prodejce · %2$d produktů',
							'Tvorby od %1$d prodejců · %2$d produktů',
							$vendor_n,
							'nkz-mp-storefront'
						),
						$vendor_n,
						$total
					)
				)
			);
		} elseif ( $total > 0 ) {
			printf(
				'<span class="nkzmp-shop-intro__lead">%s</span>',
				esc_html(
					sprintf(
						/* translators: %d: number of products */
						_n( '%d produkt', '%d produktů', $total, 'nkz-mp-storefront' ),
						$total
					)
				)
			);
		}
		echo '</div>'; // .nkzmp-shop-intro__left

		// Sort dropdown přesuneme do toolbaru (vpravo) – default WC ho
		// vykresluje samostatně přes woocommerce_catalog_ordering, my ho
		// tady duplikujeme do toolbaru a default skryjeme přes CSS níž.
		echo '<div class="nkzmp-shop-intro__right">';
		if ( function_exists( 'woocommerce_catalog_ordering' ) ) {
			woocommerce_catalog_ordering();
		}
		echo '</div>';

		echo '</div>'; // .nkzmp-shop-intro
	}

	public function vendor_badge(): void {
		if ( ! self::applicable() ) {
			return;
		}
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		if ( $vid <= 0 ) {
			return;
		}
		$post = get_post( $vid );
		if ( ! $post ) {
			return;
		}
		$name = (string) $post->post_title;
		$url  = $post->post_name !== '' ? home_url( '/vendor/' . $post->post_name ) : '';

		echo '<a class="nkzmp-shop-vendor" href="' . esc_url( $url ) . '" rel="author">';
		echo '<span class="nkzmp-shop-vendor__by">' . esc_html__( 'od', 'nkz-mp-storefront' ) . '</span> ';
		echo '<span class="nkzmp-shop-vendor__name">' . esc_html( $name ) . '</span>';
		echo '</a>';
	}

	public function become_vendor_cta(): void {
		if ( ! self::applicable() ) {
			return;
		}
		// Konfigurovatelný cíl – default /pro-tvurce. Screeno si přebije.
		$url = (string) apply_filters( 'nkzmp/v1/storefront/become_vendor_url', home_url( '/pro-tvurce/' ) );
		if ( $url === '' ) {
			return;
		}
		echo '<aside class="nkzmp-shop-promo">';
		echo '<div class="nkzmp-shop-promo__body">';
		echo '<h3 class="nkzmp-shop-promo__h">' . esc_html__( 'Tvoříš taky?', 'nkz-mp-storefront' ) . '</h3>';
		echo '<p class="nkzmp-shop-promo__d">' . esc_html__( 'Art of život je kurátorský marketplace. Pošli nám svoji tvorbu k posouzení a staň se jedním z prodejců.', 'nkz-mp-storefront' ) . '</p>';
		echo '</div>';
		echo '<a class="nkzmp-shop-promo__cta" href="' . esc_url( $url ) . '">' . esc_html__( 'Stát se prodejcem →', 'nkz-mp-storefront' ) . '</a>';
		echo '</aside>';
	}

	/** Počet vendorů s status=active (cached 1 h). */
	private static function active_vendor_count(): int {
		$cached = get_transient( self::COUNT_CACHE_KEY );
		if ( is_numeric( $cached ) ) {
			return (int) $cached;
		}
		$q = new \WP_Query( [
			'post_type'      => [ 'nkzmp_vendor', 'nkv_vendor' ],
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_status', 'value' => 'active', 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_status', 'value' => 'active', 'compare' => '=' ],
			],
		] );
		$count = (int) $q->post_count;
		set_transient( self::COUNT_CACHE_KEY, $count, self::COUNT_CACHE_TTL );
		return $count;
	}

	public static function forget_count(): void {
		delete_transient( self::COUNT_CACHE_KEY );
	}

	/** Invalidace pouze když se mění status meta. */
	public static function maybe_forget_count( $meta_id, $post_id, $meta_key ): void {
		if ( $meta_key === '_nkzmp_vendor_status' || $meta_key === '_nkv_vendor_status' ) {
			self::forget_count();
		}
	}
}
