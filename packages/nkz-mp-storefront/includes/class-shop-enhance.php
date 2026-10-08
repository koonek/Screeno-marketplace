<?php
/**
 * ShopEnhance – vylepšení obchodu pro lepší prodej.
 *
 *  - Barvy: modrá art of život místo akcentu šablony (růžová při najetí).
 *  - Karta produktu: jednotný ořez fotek, druhá fotka při najetí,
 *    štítky „Novinka" a „Poslední kus" (spolu se slevou na jednom místě).
 *  - Kategorie: popis kategorie pod řadou kategorií, když ho šablona
 *    sama nevypisuje.
 *  - Detail produktu: proč nakoupit tady (vrácení, bezpečná platba,
 *    kdo to vyrobil) a „Další od této značky".
 *
 * Každou část jde vypnout filtrem nkzmp/v1/storefront/enhance/<část>.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ShopEnhance {

	private const BLUE = '#0060FF';

	private static ?ShopEnhance $instance = null;

	public static function instance(): ShopEnhance {
		return self::$instance ??= new self();
	}

	private static function on( string $part ): bool {
		return (bool) apply_filters( 'nkzmp/v1/storefront/enhance/' . $part, true );
	}

	public function init(): void {
		if ( self::on( 'colors' ) ) {
			add_action( 'wp_head', [ $this, 'brand_colors' ], 99 );
		}
		if ( self::on( 'card' ) ) {
			add_action( 'init', [ $this, 'card_hooks' ], 20 );
		}
		if ( self::on( 'category_description' ) ) {
			add_action( 'woocommerce_before_shop_loop', [ $this, 'category_description' ], 0 );
		}
		if ( self::on( 'product_trust' ) ) {
			add_action( 'woocommerce_single_product_summary', [ $this, 'product_trust' ], 35 );
		}
		if ( self::on( 'more_from_brand' ) ) {
			add_action( 'woocommerce_after_single_product_summary', [ $this, 'more_from_brand' ], 15 );
		}
	}

	/* ============================================================ barvy */

	/**
	 * Šablona (Elementor) má v globálních barvách akcent růžový – proto
	 * růžová při najetí na tlačítka, v košíku, pokladně… Přebijeme akcent
	 * Elementoru a tlačítka WooCommerce modrou art of život. Trvalé řešení
	 * je přepnout akcent v Elementoru (Nastavení webu → Globální barvy).
	 */
	public function brand_colors(): void {
		$blue = (string) apply_filters( 'nkzmp/v1/storefront/brand_color', self::BLUE );
		echo '<style id="nkzmp-brand-colors">
		body[class*="elementor-kit-"]{--e-global-color-accent:' . esc_attr( $blue ) . '}
		html body .woocommerce a.button:hover,html body .woocommerce button.button:hover,html body .woocommerce input.button:hover,html body .woocommerce #respond input#submit:hover,
		html body .woocommerce a.button:focus,html body .woocommerce button.button:focus,html body .woocommerce input.button:focus,
		html body .woocommerce a.button.alt,html body .woocommerce button.button.alt,html body .woocommerce input.button.alt,
		html body .woocommerce a.button.alt:hover,html body .woocommerce button.button.alt:hover,html body .woocommerce input.button.alt:hover{background-color:' . esc_attr( $blue ) . '!important;border-color:' . esc_attr( $blue ) . '!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
		html body .woocommerce a:not(.button):hover{color:' . esc_attr( $blue ) . '}
		html body .woocommerce-message,html body .woocommerce-info{border-top-color:' . esc_attr( $blue ) . '}
		html body .woocommerce-message::before,html body .woocommerce-info::before{color:' . esc_attr( $blue ) . '}
		</style>';
	}

	/* ===================================================== karta produktu */

	public function card_hooks(): void {
		// Sleva se vypisuje spolu s našimi štítky v jednom rohu (jinak by se
		// „Sleva!" a „Novinka" překrývaly).
		remove_action( 'woocommerce_before_shop_loop_item_title', 'woocommerce_show_product_loop_sale_flash', 10 );
		add_action( 'woocommerce_before_shop_loop_item_title', [ $this, 'card_badges' ], 9 );
		add_action( 'woocommerce_before_shop_loop_item_title', [ $this, 'card_alt_image' ], 11 );
		add_action( 'wp_head', [ $this, 'card_css' ], 98 );
	}

	/** Štítky na kartě: Sleva / Novinka / Poslední kus / Vyprodáno. */
	public function card_badges(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$out = [];
		if ( ! $product->is_in_stock() ) {
			$out[] = [ 'is-out', __( 'Vyprodáno', 'nkz-mp-storefront' ) ];
		} else {
			if ( $product->is_on_sale() ) {
				$out[] = [ 'is-sale', (string) apply_filters( 'nkzmp/v1/storefront/sale_label', __( 'Sleva!', 'nkz-mp-storefront' ), $product ) ];
			}
			if ( self::is_new( $product ) ) {
				$out[] = [ 'is-new', __( 'Novinka', 'nkz-mp-storefront' ) ];
			}
			if ( self::last_piece( $product ) ) {
				$out[] = [ 'is-last', __( 'Poslední kus', 'nkz-mp-storefront' ) ];
			}
		}
		$out = (array) apply_filters( 'nkzmp/v1/storefront/card_badges', $out, $product );
		if ( ! $out ) {
			return;
		}
		echo '<span class="nkzmp-card-badges">';
		foreach ( $out as [ $cls, $label ] ) {
			printf( '<span class="nkzmp-card-badge %s">%s</span>', esc_attr( $cls ), esc_html( $label ) );
		}
		echo '</span>';
	}

	public static function is_new( \WC_Product $product ): bool {
		$days    = (int) apply_filters( 'nkzmp/v1/storefront/new_days', 14 );
		$created = $product->get_date_created();
		return $created && $created->getTimestamp() >= time() - $days * DAY_IN_SECONDS;
	}

	public static function last_piece( \WC_Product $product ): bool {
		if ( $product->is_type( 'variable' ) ) {
			return false; // u variant by „poslední kus" platil jen pro jednu
		}
		return $product->managing_stock() && (int) $product->get_stock_quantity() === 1;
	}

	/** Druhá fotka (první z galerie) – ukáže se při najetí myší. */
	public function card_alt_image(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$ids = $product->get_gallery_image_ids();
		if ( ! $ids ) {
			return;
		}
		$img = wp_get_attachment_image( (int) $ids[0], 'woocommerce_thumbnail', false, [
			'class'   => 'nkzmp-card-alt',
			'loading' => 'lazy',
			'alt'     => '',
			'aria-hidden' => 'true',
		] );
		if ( $img ) {
			echo $img; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WP vrací escapované HTML.
		}
	}

	public function card_css(): void {
		if ( ! function_exists( 'is_woocommerce' ) ) {
			return;
		}
		$ratio = (string) apply_filters( 'nkzmp/v1/storefront/card_ratio', '1 / 1' );
		$blue  = (string) apply_filters( 'nkzmp/v1/storefront/brand_color', self::BLUE );
		echo '<style id="nkzmp-card">
		html body ul.products li.product a.woocommerce-LoopProduct-link{position:relative;display:block}
		html body ul.products li.product a.woocommerce-LoopProduct-link img.attachment-woocommerce_thumbnail,html body ul.products li.product a.woocommerce-LoopProduct-link img.wp-post-image,html body ul.products li.product img.nkzmp-card-alt{width:100%!important;aspect-ratio:' . esc_attr( $ratio ) . ';object-fit:cover;height:auto!important}
		html body ul.products li.product img.nkzmp-card-alt{position:absolute;top:0;left:0;opacity:0;transition:opacity .25s ease;pointer-events:none;margin:0!important}
		@media (hover:hover){html body ul.products li.product:hover img.nkzmp-card-alt{opacity:1}}
		.nkzmp-card-badges{position:absolute;top:10px;left:10px;z-index:3;display:flex;flex-direction:column;align-items:flex-start;gap:6px;pointer-events:none}
		.nkzmp-card-badge{display:inline-block;padding:4px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.3;letter-spacing:.01em;background:#fff;color:#111;box-shadow:0 1px 4px rgba(0,0,0,.12)}
		.nkzmp-card-badge.is-sale{background:' . esc_attr( $blue ) . ';color:#fff;-webkit-text-fill-color:#fff}
		.nkzmp-card-badge.is-new{background:#fff;color:' . esc_attr( $blue ) . ';-webkit-text-fill-color:' . esc_attr( $blue ) . '}
		.nkzmp-card-badge.is-last{background:#fff4e5;color:#8a4b00;-webkit-text-fill-color:#8a4b00}
		.nkzmp-card-badge.is-out{background:#f1f2f4;color:#6b7280;-webkit-text-fill-color:#6b7280}
		html body ul.products li.product .onsale{display:none!important}
		</style>';
	}

	/* ========================================================= kategorie */

	/**
	 * Popis kategorie – jen když ho šablona nevypsala (WooCommerce ho
	 * standardně dává na háček woocommerce_archive_description).
	 */
	public function category_description(): void {
		if ( ! function_exists( 'is_product_category' ) || ! is_product_category() || did_action( 'woocommerce_archive_description' ) ) {
			return;
		}
		$term = get_queried_object();
		if ( ! $term instanceof \WP_Term || trim( (string) $term->description ) === '' ) {
			return;
		}
		echo '<div class="nkzmp-cat-desc" style="max-width:720px;margin:0 0 18px;color:#4b5563;line-height:1.6;">'
			. wp_kses_post( wpautop( $term->description ) ) . '</div>';
	}

	/* ===================================================== detail produktu */

	/** Jedno ID prodejce produktu (nebo 0). */
	private static function vendor_of( int $product_id ): int {
		$vid = (int) get_post_meta( $product_id, '_nkzmp_vendor_id', true );
		return $vid > 0 ? $vid : (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
	}

	private static function vendor_url( int $vid ): string {
		$post = get_post( $vid );
		if ( ! $post ) {
			return '';
		}
		if ( class_exists( Settings::class ) ) {
			$slug = trim( (string) ( Settings::get()['single_slug'] ?? 'vendor' ), '/' );
			return home_url( '/' . ( $slug ?: 'vendor' ) . '/' . $post->post_name . '/' );
		}
		return (string) get_permalink( $post );
	}

	/** „Proč nakoupit tady" pod tlačítkem Do košíku. */
	public function product_trust(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$pid   = $product->get_parent_id() ?: $product->get_id();
		$ship  = ShopLoop::product_needs_shipping( $product );
		$items = [];
		if ( $ship ) {
			$items[] = [ 'return', __( 'Vrácení do 14 dnů bez udání důvodu', 'nkz-mp-storefront' ) ];
		}
		$items[] = [ 'lock', __( 'Bezpečná platba kartou přes Stripe', 'nkz-mp-storefront' ) ];
		$vid = self::vendor_of( $pid );
		if ( $vid > 0 && get_post( $vid ) ) {
			/* translators: %s: značka */
			$items[] = [ 'hand', sprintf( __( 'Ručně od značky %s – peníze jdou přímo tvůrci', 'nkz-mp-storefront' ), get_the_title( $vid ) ) ];
		}
		$items = (array) apply_filters( 'nkzmp/v1/storefront/product_trust', $items, $product );
		if ( ! $items ) {
			return;
		}
		$icons = [
			'return' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10.5a5.5 5.5 0 0 1 0 11H11"/>',
			'lock'   => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
			'hand'   => '<path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10z"/>',
			'truck'  => '<path d="M3 6h11v10H3z"/><path d="M14 10h4l3 3v3h-7"/><circle cx="7" cy="18" r="2"/><circle cx="17" cy="18" r="2"/>',
		];
		echo '<ul class="nkzmp-trust" style="list-style:none;margin:18px 0 0;padding:16px 0 0;border-top:1px solid #eceef2;display:flex;flex-direction:column;gap:10px;">';
		foreach ( $items as [ $icon, $text ] ) {
			printf(
				'<li style="display:flex;align-items:center;gap:10px;margin:0;font-size:14px;color:#374151;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="%s" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex:0 0 auto">%s</svg><span>%s</span></li>',
				esc_attr( self::BLUE ),
				$icons[ $icon ] ?? $icons['hand'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statické SVG.
				esc_html( $text )
			);
		}
		echo '</ul>';
	}

	/** „Další od této značky" – 4 produkty stejného tvůrce pod detailem. */
	public function more_from_brand(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = self::vendor_of( $pid );
		if ( $vid <= 0 ) {
			return;
		}
		$q = new \WP_Query( [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'posts_per_page'      => (int) apply_filters( 'nkzmp/v1/storefront/more_from_brand_count', 4 ),
			'post__not_in'        => [ $pid ],
			'orderby'             => 'rand',
			'ignore_sticky_posts' => true,
			'no_found_rows'       => true,
			'meta_query'          => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_id', 'value' => $vid ],
				[ 'key' => '_nkv_vendor_id', 'value' => $vid ],
			],
			'tax_query'           => [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => [ 'exclude-from-catalog' ], 'operator' => 'NOT IN' ],
			],
		] );
		if ( ! $q->have_posts() ) {
			return;
		}
		$name = get_the_title( $vid );
		$url  = self::vendor_url( $vid );
		echo '<section class="nkzmp-more-brand related products" style="clear:both;margin:40px 0 0;">';
		echo '<div style="display:flex;align-items:baseline;justify-content:space-between;gap:16px;margin:0 0 16px;">';
		/* translators: %s: značka */
		echo '<h2 style="margin:0;">' . esc_html( sprintf( __( 'Další od %s', 'nkz-mp-storefront' ), $name ) ) . '</h2>';
		if ( $url !== '' ) {
			echo '<a href="' . esc_url( $url ) . '" style="white-space:nowrap;color:' . esc_attr( self::BLUE ) . ';font-weight:600;text-decoration:none;">' . esc_html__( 'Celá značka →', 'nkz-mp-storefront' ) . '</a>';
		}
		echo '</div>';
		$prev = $GLOBALS['product'] ?? null;
		woocommerce_product_loop_start();
		while ( $q->have_posts() ) {
			$q->the_post();
			wc_get_template_part( 'content', 'product' );
		}
		woocommerce_product_loop_end();
		wp_reset_postdata();
		$GLOBALS['product'] = $prev;
		echo '</section>';
	}
}
