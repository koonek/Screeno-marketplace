<?php
/**
 * GiftGuide – dárkový průvodce (stránka „Dárky", shortcode [nkzmp_gift_guide]).
 *
 * Plní se sám z katalogu, nic se nemusí ručně udržovat:
 *  - „Tipy na dárek" – produkty se štítkem „tip-na-darek" (Produkty →
 *    Štítky; správce jím vybírá favority, sekce se ukáže, jen když nějaké jsou),
 *  - podle ceny – Do 300 Kč / 300–500 / 500–1 000 / Nad 1 000 Kč,
 *    každá s ukázkou produktů a odkazem do obchodu s cenovým filtrem,
 *  - podle kategorie – dlaždice hlavních kategorií,
 *  - „Nevíš? Daruj poukaz" – když v obchodě je dárkový poukaz.
 *
 * Jen skladem a viditelné produkty. Atributy: bands="300,500,1000" per="4".
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class GiftGuide {

	public const PAGE_FLAG = 'nkzmp_gift_guide_page';
	public const TAG       = 'tip-na-darek';

	private static ?GiftGuide $instance = null;

	public static function instance(): GiftGuide {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( 'nkzmp_gift_guide', [ $this, 'shortcode' ] );
		add_action( 'init', [ $this, 'maybe_create_page' ], 100 );
		add_filter( 'body_class', [ $this, 'body_class' ] );
	}

	public static function page_url(): string {
		$id  = (int) get_option( self::PAGE_FLAG, 0 );
		$url = $id > 0 ? get_permalink( $id ) : '';
		return $url ? (string) $url : home_url( '/darky/' );
	}

	/** Stránku „Dárky" založit jednou (správce ji může upravit/smazat). */
	public function maybe_create_page(): void {
		if ( get_option( self::PAGE_FLAG ) !== false || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$existing = get_page_by_path( 'darky' );
		$id = $existing ? (int) $existing->ID : (int) wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Dárky', 'nkz-mp-storefront' ),
			'post_name'    => 'darky',
			'post_content' => '[nkzmp_gift_guide]',
		] );
		update_option( self::PAGE_FLAG, $id, false );
		// Štítek pro ruční výběr tipů – ať ho správce najde v seznamu.
		if ( taxonomy_exists( 'product_tag' ) && ! term_exists( self::TAG, 'product_tag' ) ) {
			wp_insert_term( __( 'Tip na dárek', 'nkz-mp-storefront' ), 'product_tag', [ 'slug' => self::TAG ] );
		}
	}

	/** @param string[] $classes */
	public function body_class( $classes ): array {
		$classes = (array) $classes;
		$page    = (int) get_option( self::PAGE_FLAG, 0 );
		if ( $page > 0 && is_page( $page ) ) {
			array_push( $classes, 'woocommerce', 'woocommerce-page', 'nkzmp-gift-page' );
		}
		return array_values( array_unique( $classes ) );
	}

	/* ============================================================ výpis */

	private static function base_args( int $limit ): array {
		return [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => $limit,
			'orderby'             => 'rand',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'meta_query'          => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[ 'key' => '_stock_status', 'value' => 'instock' ],
				[ 'key' => Voucher::PRODUCT_META, 'compare' => 'NOT EXISTS' ],
			],
			'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => [ 'exclude-from-catalog', 'outofstock' ], 'operator' => 'NOT IN' ],
			],
		];
	}

	private static function products( array $args ): string {
		$q = new \WP_Query( $args );
		if ( ! $q->have_posts() ) {
			return '';
		}
		ob_start();
		wc_setup_loop( [ 'columns' => (int) apply_filters( 'nkzmp/v1/storefront/gift_columns', 4 ) ] );
		woocommerce_product_loop_start();
		while ( $q->have_posts() ) {
			$q->the_post();
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		wp_reset_postdata();
		wc_reset_loop();
		return (string) ob_get_clean();
	}

	private static function shop_url( array $args ): string {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		return add_query_arg( $args, $shop );
	}

	private static function section( string $title, string $sub, string $link, string $link_label, string $body ): string {
		if ( $body === '' ) {
			return '';
		}
		$h  = '<section class="nkzmp-gift__sec">';
		$h .= '<div class="nkzmp-gift__head"><div><h2>' . esc_html( $title ) . '</h2>' . ( $sub !== '' ? '<p>' . esc_html( $sub ) . '</p>' : '' ) . '</div>';
		if ( $link !== '' ) {
			$h .= '<a class="nkzmp-gift__more" href="' . esc_url( $link ) . '">' . esc_html( $link_label ) . '</a>';
		}
		$h .= '</div>' . $body . '</section>';
		return $h;
	}

	public function shortcode( $atts = [] ): string {
		$a = shortcode_atts( [ 'bands' => '300,500,1000', 'per' => '4' ], (array) $atts, 'nkzmp_gift_guide' );
		if ( class_exists( Assets::class ) ) {
			Assets::ensure_storefront_css();
			if ( apply_filters( 'nkzmp/v1/storefront/style_wc', true ) ) {
				Assets::ensure_wc_css();
			}
		}
		$per   = max( 1, min( 12, (int) $a['per'] ) );
		$bands = array_values( array_filter( array_map( 'intval', explode( ',', (string) $a['bands'] ) ), static fn( $b ) => $b > 0 ) );
		sort( $bands );

		$out  = $this->css();
		$out .= '<div class="nkzmp-gift woocommerce">';

		// Rychlé odkazy nahoře (přeskočení na sekci).
		$jump = [];
		$prev = 0;
		foreach ( $bands as $b ) {
			/* translators: %s: částka */
			$jump[] = [ '#darky-do-' . $b, $prev === 0 ? sprintf( __( 'Do %s', 'nkz-mp-storefront' ), self::kc( $b ) ) : self::kc( $prev ) . ' – ' . self::kc( $b ) ];
			$prev   = $b;
		}
		if ( $prev > 0 ) {
			/* translators: %s: částka */
			$jump[] = [ '#darky-nad-' . $prev, sprintf( __( 'Nad %s', 'nkz-mp-storefront' ), self::kc( $prev ) ) ];
		}
		$jump[] = [ '#darky-kategorie', __( 'Podle kategorie', 'nkz-mp-storefront' ) ];
		$out   .= '<nav class="nkzmp-gift__jump" aria-label="' . esc_attr__( 'Dárky podle ceny', 'nkz-mp-storefront' ) . '">';
		foreach ( $jump as [ $href, $label ] ) {
			$out .= '<a href="' . esc_attr( $href ) . '">' . esc_html( $label ) . '</a>';
		}
		$out .= '</nav>';

		// Tipy na dárek (ruční výběr štítkem).
		$tips = self::base_args( $per * 2 );
		$tips['tax_query'][] = [ 'taxonomy' => 'product_tag', 'field' => 'slug', 'terms' => [ self::TAG ] ];
		$out .= self::section( __( 'Tipy na dárek', 'nkz-mp-storefront' ), __( 'Vybrali jsme za vás – kousky, které potěší.', 'nkz-mp-storefront' ), '', '', self::products( $tips ) );

		// Podle ceny.
		$prev = 0;
		foreach ( array_merge( $bands, [ 0 ] ) as $b ) {
			$args = self::base_args( $per );
			if ( $b > 0 ) {
				// Spodní hranice ostře (500 Kč patří do „300–500", ne i do „500–1 000").
				$args['meta_query'][] = [ 'key' => '_price', 'value' => [ $prev > 0 ? $prev + 0.01 : 0, $b ], 'type' => 'DECIMAL(10,2)', 'compare' => 'BETWEEN' ];
				$id    = 'darky-do-' . $b;
				$title = $prev === 0
					/* translators: %s: částka */
					? sprintf( __( 'Dárky do %s', 'nkz-mp-storefront' ), self::kc( $b ) )
					/* translators: 1: od, 2: do */
					: sprintf( __( 'Dárky za %1$s – %2$s', 'nkz-mp-storefront' ), self::kc( $prev ), self::kc( $b ) );
				$link  = self::shop_url( array_filter( [ 'min_price' => $prev ?: null, 'max_price' => $b ] ) );
			} else {
				if ( $prev <= 0 ) {
					break;
				}
				$args['meta_query'][] = [ 'key' => '_price', 'value' => $prev, 'type' => 'NUMERIC', 'compare' => '>' ];
				$id    = 'darky-nad-' . $prev;
				/* translators: %s: částka */
				$title = sprintf( __( 'Výjimečné dárky nad %s', 'nkz-mp-storefront' ), self::kc( $prev ) );
				$link  = self::shop_url( [ 'min_price' => $prev ] );
			}
			$sec = self::section( $title, '', $link, __( 'Zobrazit vše →', 'nkz-mp-storefront' ), self::products( $args ) );
			$out .= $sec !== '' ? str_replace( '<section class="nkzmp-gift__sec">', '<section class="nkzmp-gift__sec" id="' . esc_attr( $id ) . '">', $sec ) : '';
			$prev = $b;
		}

		// Podle kategorie.
		$cats  = get_terms( [ 'taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false, 'orderby' => 'menu_order' ] );
		$uncat = (int) get_option( 'default_product_cat', 0 );
		$tiles = '';
		if ( ! is_wp_error( $cats ) ) {
			foreach ( (array) $cats as $t ) {
				$cnt = get_term_meta( $t->term_id, 'product_count_product_cat', true );
				if ( (int) $t->term_id === $uncat || ( $cnt !== '' ? (int) $cnt : (int) $t->count ) <= 0 ) {
					continue;
				}
				$thumb = (int) get_term_meta( $t->term_id, 'thumbnail_id', true );
				$img   = $thumb ? wp_get_attachment_image_url( $thumb, 'woocommerce_thumbnail' ) : '';
				$url   = get_term_link( $t );
				if ( is_wp_error( $url ) ) {
					continue;
				}
				$tiles .= '<a class="nkzmp-gift__cat" href="' . esc_url( $url ) . '"><span class="nkzmp-gift__catimg"' . ( $img ? ' style="background-image:url(' . esc_url( $img ) . ')"' : '' ) . '></span><span class="nkzmp-gift__catname">' . esc_html( $t->name ) . '</span></a>';
			}
		}
		if ( $tiles !== '' ) {
			$out .= '<section class="nkzmp-gift__sec" id="darky-kategorie"><div class="nkzmp-gift__head"><div><h2>' . esc_html__( 'Dárky podle kategorie', 'nkz-mp-storefront' ) . '</h2></div></div><div class="nkzmp-gift__cats">' . $tiles . '</div></section>';
		}

		// Poukaz.
		$voucher = get_posts( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => Voucher::PRODUCT_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => 'yes',                 // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		if ( $voucher ) {
			$out .= '<section class="nkzmp-gift__voucher"><div><h2>' . esc_html__( 'Nevíš, co vybrat?', 'nkz-mp-storefront' ) . '</h2><p>' . esc_html__( 'Daruj dárkový poukaz – obdarovaný si vybere sám od kteréhokoli tvůrce. Platí 12 měsíců.', 'nkz-mp-storefront' ) . '</p></div><a href="' . esc_url( (string) get_permalink( (int) $voucher[0] ) ) . '">' . esc_html__( 'Koupit poukaz', 'nkz-mp-storefront' ) . '</a></section>';
		}

		$out .= '</div>';
		return $out;
	}

	private static function kc( int $v ): string {
		return number_format( $v, 0, ',', "\u{00A0}" ) . "\u{00A0}Kč";
	}

	private function css(): string {
		return '<style>
		.nkzmp-gift__jump{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 32px}
		html body .nkzmp-gift__jump a{display:inline-flex;padding:10px 18px;border-radius:999px;background:#f1f2f4;color:#111;-webkit-text-fill-color:#111;font-weight:600;font-size:15px;text-decoration:none!important;white-space:nowrap}
		html body .nkzmp-gift__jump a:hover{background:#0060FF;color:#fff;-webkit-text-fill-color:#fff}
		.nkzmp-gift__sec{margin:0 0 48px;scroll-margin-top:24px}
		.nkzmp-gift__head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin:0 0 16px}
		.nkzmp-gift__head h2{margin:0;font-size:28px;line-height:1.15}
		.nkzmp-gift__head p{margin:6px 0 0;color:#6b7280}
		html body .nkzmp-gift__more{white-space:nowrap;color:#0060FF;-webkit-text-fill-color:#0060FF;font-weight:600;text-decoration:none!important}
		.nkzmp-gift__cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:16px}
		html body .nkzmp-gift__cat{display:block;text-decoration:none!important;color:#111;-webkit-text-fill-color:#111}
		.nkzmp-gift__catimg{display:block;aspect-ratio:1/1;border-radius:16px;background:#f1f2f4 center/cover no-repeat;margin:0 0 8px;transition:transform .2s}
		.nkzmp-gift__cat:hover .nkzmp-gift__catimg{transform:scale(1.02)}
		.nkzmp-gift__catname{font-weight:600;font-size:16px}
		.nkzmp-gift__voucher{display:flex;align-items:center;justify-content:space-between;gap:20px;flex-wrap:wrap;margin:0 0 48px;padding:28px 32px;border-radius:20px;background:#0060FF;color:#fff}
		.nkzmp-gift__voucher h2{margin:0 0 6px;color:#fff;-webkit-text-fill-color:#fff;font-size:26px}
		.nkzmp-gift__voucher p{margin:0;color:#dbe6ff;max-width:560px}
		html body .nkzmp-gift__voucher a{display:inline-flex;padding:14px 24px;border-radius:999px;background:#fff;color:#0060FF;-webkit-text-fill-color:#0060FF;font-weight:700;text-decoration:none!important}
		@media (max-width:767px){
		.nkzmp-gift__jump{flex-wrap:nowrap;overflow-x:auto;margin:0 -16px 24px;padding:2px 16px 6px;scrollbar-width:none}
		.nkzmp-gift__jump::-webkit-scrollbar{display:none}
		.nkzmp-gift__head h2{font-size:22px}
		.nkzmp-gift__cats{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
		.nkzmp-gift__voucher{padding:22px}
		}
		</style>';
	}
}
