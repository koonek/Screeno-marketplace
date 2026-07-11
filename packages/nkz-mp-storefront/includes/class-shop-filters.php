<?php
/**
 * ShopFilters – levý filtrovací sidebar pro /obchod a kategorie produktů.
 *
 * Filtry: kategorie (checkbox), cena (rozsah), prodejce (checkbox), skladem.
 * Filtrování běží přes AJAX (překreslení gridu bez reloadu) i přes čisté
 * query parametry v URL (SEO / no-JS fallback – woocommerce_product_query).
 *
 * Layout: před smyčkou otevřeme dvousloupcový grid (sidebar + výsledky),
 * za smyčkou zavřeme. Žádný template override – jen WC hooky.
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/shop_filters', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ShopFilters {

	public const AJAX_ACTION = 'nkzmp_shop_filter';
	private const NONCE      = 'nkzmp_shop_filter';

	private static ?ShopFilters $instance = null;

	public static function instance(): ShopFilters {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/shop_filters', true ) ) {
			return;
		}

		// Layout wrapper kolem výsledků (sidebar + grid sloupec).
		add_action( 'woocommerce_before_shop_loop', [ $this, 'open_layout' ], 1 );
		add_action( 'woocommerce_after_shop_loop', [ $this, 'close_layout' ], 50 );

		// Aplikace filtrů na hlavní shop query (URL / no-JS / SEO).
		add_action( 'woocommerce_product_query', [ $this, 'apply_to_query' ] );

		// Enqueue JS na shop/kategorie.
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );

		// AJAX endpoint.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'ajax_filter' ] );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'ajax_filter' ] );
	}

	/** Pouze nad hlavním shop / category archivem produktů. */
	private static function applicable(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}
		return is_shop() || is_product_taxonomy();
	}

	public function enqueue(): void {
		if ( ! self::applicable() ) {
			return;
		}
		wp_enqueue_script(
			'nkz-mp-shop-filters',
			NKZMP_STOREFRONT_URL . 'assets/shop-filters.js',
			[],
			NKZMP_STOREFRONT_VERSION,
			true
		);
		wp_localize_script(
			'nkz-mp-shop-filters',
			'nkzmpShopFilters',
			[
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'action'  => self::AJAX_ACTION,
				'nonce'   => wp_create_nonce( self::NONCE ),
			]
		);
	}

	/* ───────────────────────── Layout ───────────────────────── */

	public function open_layout(): void {
		if ( ! self::applicable() ) {
			return;
		}
		echo '<div class="nkzmp-shop-layout">';

		// Mobilní toggle (skrytý na desktopu přes CSS).
		echo '<button type="button" class="nkzmp-shop-filters-toggle" aria-expanded="false">'
			. '<span>' . esc_html__( 'Filtry', 'nkz-mp-storefront' ) . '</span>'
			. '</button>';

		echo '<aside class="nkzmp-shop-filters" id="nkzmp-shop-filters">';
		$this->render_sidebar();
		echo '</aside>';

		echo '<div class="nkzmp-shop-results" id="nkzmp-shop-results">';
	}

	public function close_layout(): void {
		if ( ! self::applicable() ) {
			return;
		}
		echo '</div>'; // .nkzmp-shop-results
		echo '</div>'; // .nkzmp-shop-layout
	}

	/* ───────────────────────── Sidebar UI ───────────────────────── */

	private function render_sidebar(): void {
		$active = self::read_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification

		echo '<form class="nkzmp-filters" method="get" action="' . esc_url( self::base_url() ) . '">';

		echo '<div class="nkzmp-filters__head">';
		echo '<h2 class="nkzmp-filters__title">' . esc_html__( 'Filtry', 'nkz-mp-storefront' ) . '</h2>';
		echo '<button type="button" class="nkzmp-filters__clear" data-nkzmp-clear>' . esc_html__( 'Vymazat', 'nkz-mp-storefront' ) . '</button>';
		echo '</div>';

		$this->render_search( $active['q'] );
		$this->render_categories( $active['cat'] );
		$this->render_price( $active['min_price'], $active['max_price'] );
		$this->render_vendors( $active['vendor'] );
		$this->render_stock( $active['instock'] );

		// No-JS submit.
		echo '<noscript><button type="submit" class="nkzmp-filters__submit">' . esc_html__( 'Použít filtry', 'nkz-mp-storefront' ) . '</button></noscript>';

		// Mobilni "Hotovo" - viditelne jen v bottom-sheet rezimu (CSS).
		echo '<button type="button" class="nkzmp-filters__done" data-nkzmp-done>' . esc_html__( 'Hotovo', 'nkz-mp-storefront' ) . '</button>';

		echo '</form>';
	}

	private function render_search( string $q ): void {
		echo '<fieldset class="nkzmp-filters__group nkzmp-filters__group--search">';
		echo '<legend>' . esc_html__( 'Hledat', 'nkz-mp-storefront' ) . '</legend>';
		printf(
			'<input type="search" name="q" class="nkzmp-filters__search" value="%1$s" placeholder="%2$s" data-nkzmp-search aria-label="%2$s" autocomplete="off">',
			esc_attr( $q ),
			esc_attr__( 'Hledat podle slova…', 'nkz-mp-storefront' )
		);
		echo '</fieldset>';
	}

	private function render_categories( array $selected ): void {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		// Na stránce kategorie tu kategorii předvybereme.
		if ( empty( $selected ) && is_product_taxonomy() ) {
			$current = get_queried_object();
			if ( $current instanceof \WP_Term ) {
				$selected = [ $current->slug ];
			}
		}

		echo '<fieldset class="nkzmp-filters__group" data-nkzmp-group="cat">';
		echo '<legend>' . esc_html__( 'Kategorie', 'nkz-mp-storefront' ) . '</legend>';
		echo '<ul class="nkzmp-filters__list">';
		foreach ( $terms as $term ) {
			$id = 'nkzmp-cat-' . $term->term_id;
			printf(
				'<li><label for="%1$s"><input type="checkbox" id="%1$s" name="cat[]" value="%2$s"%3$s> <span>%4$s</span> <em>%5$d</em></label></li>',
				esc_attr( $id ),
				esc_attr( $term->slug ),
				in_array( $term->slug, $selected, true ) ? ' checked' : '',
				esc_html( $term->name ),
				(int) $term->count
			);
		}
		echo '</ul>';
		echo '</fieldset>';
	}

	private function render_price( ?int $min, ?int $max ): void {
		$bounds = self::price_bounds();
		if ( $bounds['max'] <= $bounds['min'] ) {
			return;
		}
		$cur_min = $min ?? $bounds['min'];
		$cur_max = $max ?? $bounds['max'];

		echo '<fieldset class="nkzmp-filters__group nkzmp-filters__price" data-nkzmp-group="price">';
		echo '<legend>' . esc_html__( 'Cena', 'nkz-mp-storefront' ) . '</legend>';
		echo '<div class="nkzmp-filters__price-inputs">';
		printf(
			'<input type="number" name="min_price" inputmode="numeric" min="%1$d" max="%2$d" value="%3$d" data-nkzmp-price="min" aria-label="%4$s">',
			(int) $bounds['min'],
			(int) $bounds['max'],
			(int) $cur_min,
			esc_attr__( 'Cena od', 'nkz-mp-storefront' )
		);
		echo '<span class="nkzmp-filters__price-sep">–</span>';
		printf(
			'<input type="number" name="max_price" inputmode="numeric" min="%1$d" max="%2$d" value="%3$d" data-nkzmp-price="max" aria-label="%4$s">',
			(int) $bounds['min'],
			(int) $bounds['max'],
			(int) $cur_max,
			esc_attr__( 'Cena do', 'nkz-mp-storefront' )
		);
		echo '</div>';
		printf(
			'<div class="nkzmp-filters__range"><div class="nkzmp-filters__range-bg"></div><div class="nkzmp-filters__range-fill" data-nkzmp-range-fill></div><input type="range" min="%1$d" max="%2$d" value="%3$d" data-nkzmp-range="min"><input type="range" min="%1$d" max="%2$d" value="%4$d" data-nkzmp-range="max"></div>',
			(int) $bounds['min'],
			(int) $bounds['max'],
			(int) $cur_min,
			(int) $cur_max
		);
		echo '</fieldset>';
	}

	private function render_vendors( array $selected ): void {
		$vendors = self::product_vendors();
		if ( empty( $vendors ) ) {
			return;
		}
		echo '<fieldset class="nkzmp-filters__group" data-nkzmp-group="vendor">';
		echo '<legend>' . esc_html__( 'Prodejce', 'nkz-mp-storefront' ) . '</legend>';
		echo '<ul class="nkzmp-filters__list">';
		foreach ( $vendors as $vid => $name ) {
			$id = 'nkzmp-vendor-' . $vid;
			printf(
				'<li><label for="%1$s"><input type="checkbox" id="%1$s" name="vendor[]" value="%2$d"%3$s> <span>%4$s</span></label></li>',
				esc_attr( $id ),
				(int) $vid,
				in_array( (int) $vid, $selected, true ) ? ' checked' : '',
				esc_html( $name )
			);
		}
		echo '</ul>';
		echo '</fieldset>';
	}

	private function render_stock( bool $on ): void {
		echo '<fieldset class="nkzmp-filters__group nkzmp-filters__stock" data-nkzmp-group="instock">';
		printf(
			'<label class="nkzmp-filters__switch"><input type="checkbox" name="instock" value="1"%1$s> <span>%2$s</span></label>',
			$on ? ' checked' : '',
			esc_html__( 'Pouze skladem', 'nkz-mp-storefront' )
		);
		echo '</fieldset>';
	}

	/* ───────────────────────── Query aplikace ───────────────────────── */

	public function apply_to_query( \WP_Query $q ): void {
		// fires jen pro hlavní product query (woocommerce_product_query).
		$filters = self::read_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$clauses = self::build_clauses( $filters );

		if ( ! empty( $clauses['tax_query'] ) ) {
			$existing = (array) $q->get( 'tax_query' );
			$q->set( 'tax_query', array_merge( $existing, $clauses['tax_query'] ) );
		}
		if ( ! empty( $clauses['meta_query'] ) ) {
			$existing = (array) $q->get( 'meta_query' );
			$q->set( 'meta_query', array_merge( $existing, $clauses['meta_query'] ) );
		}
		if ( ! empty( $filters['q'] ) ) {
			$q->set( 's', $filters['q'] );
		}
	}

	/* ───────────────────────── AJAX ───────────────────────── */

	public function ajax_filter(): void {
		check_ajax_referer( self::NONCE, 'nonce' );

		$filters = self::read_filters( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$clauses = self::build_clauses( $filters );
		$paged   = isset( $_POST['paged'] ) ? max( 1, (int) $_POST['paged'] ) : 1;
		$orderby = isset( $_POST['orderby'] ) ? sanitize_text_field( wp_unslash( $_POST['orderby'] ) ) : '';

		$args = [
			'post_type'           => 'product',
			'post_status'         => 'publish',
			'ignore_sticky_posts' => true,
			'paged'               => $paged,
			'posts_per_page'      => (int) wc_get_default_products_per_row() * (int) wc_get_default_product_rows_per_page(),
		];
		if ( ! empty( $clauses['tax_query'] ) ) {
			$args['tax_query'] = $clauses['tax_query'];
		}
		if ( ! empty( $clauses['meta_query'] ) ) {
			$args['meta_query'] = $clauses['meta_query'];
		}
		if ( ! empty( $filters['q'] ) ) {
			$args['s'] = $filters['q'];
		}
		$args = array_merge( $args, self::ordering_args( $orderby ) );

		// Skryté produkty (catalog visibility) vyřadit jako WC.
		$args['tax_query']   = $args['tax_query'] ?? [];
		$args['tax_query'][] = [
			'taxonomy' => 'product_visibility',
			'field'    => 'name',
			'terms'    => [ 'exclude-from-catalog' ],
			'operator' => 'NOT IN',
		];

		$q = new \WP_Query( $args );

		// Donutíme conditional tagy: is_shop() = is_post_type_archive('product').
		// Programatická WP_Query tyto flagy sama nenastaví, takže ručně.
		$q->is_post_type_archive = true;
		$q->is_archive           = true;

		// Nastavíme globální query, ať fungují WC loop conditionals + intro_row.
		$prev_query = $GLOBALS['wp_query'] ?? null;
		$prev_post  = $GLOBALS['post'] ?? null;
		$GLOBALS['wp_query'] = $q;

		wc_setup_loop( [
			'is_shortcode' => false,
			'is_paginated' => true,
			'total'        => (int) $q->found_posts,
			'total_pages'  => (int) $q->max_num_pages,
			'per_page'     => (int) $args['posts_per_page'],
			'current_page' => $paged,
		] );

		// Aby woocommerce_catalog_ordering() ukázal správně vybranou možnost.
		if ( $orderby !== '' ) {
			$_GET['orderby'] = $orderby;
		}

		ob_start();

		// Intro toolbar (počet + řazení) – reuse ShopLoop, čte global wp_query.
		if ( class_exists( ShopLoop::class ) ) {
			ShopLoop::instance()->intro_row();
		}

		if ( $q->have_posts() ) {
			woocommerce_product_loop_start();
			while ( $q->have_posts() ) {
				$q->the_post();
				wc_get_template_part( 'content', 'product' );
			}
			woocommerce_product_loop_end();
			woocommerce_pagination();
		} else {
			echo '<p class="woocommerce-info nkzmp-shop-empty">'
				. esc_html__( 'Žádné produkty neodpovídají zvoleným filtrům.', 'nkz-mp-storefront' )
				. '</p>';
		}

		$html = ob_get_clean();

		// Restore.
		wp_reset_postdata();
		wc_reset_loop();
		$GLOBALS['wp_query'] = $prev_query;
		$GLOBALS['post']     = $prev_post;

		wp_send_json_success( [
			'html'  => $html,
			'total' => (int) $q->found_posts,
		] );
	}

	/* ───────────────────────── Helpers ───────────────────────── */

	/**
	 * @param array<string,mixed> $src
	 * @return array{cat:string[],vendor:int[],min_price:?int,max_price:?int,instock:bool}
	 */
	private static function read_filters( array $src ): array {
		$cat = [];
		if ( isset( $src['cat'] ) ) {
			$raw = is_array( $src['cat'] ) ? $src['cat'] : explode( ',', (string) $src['cat'] );
			$cat = array_values( array_filter( array_map( 'sanitize_title', (array) wp_unslash( $raw ) ) ) );
		}

		$vendor = [];
		if ( isset( $src['vendor'] ) ) {
			$raw    = is_array( $src['vendor'] ) ? $src['vendor'] : explode( ',', (string) $src['vendor'] );
			$vendor = array_values( array_filter( array_map( 'absint', (array) $raw ) ) );
		}

		$min = isset( $src['min_price'] ) && $src['min_price'] !== '' ? (int) $src['min_price'] : null;
		$max = isset( $src['max_price'] ) && $src['max_price'] !== '' ? (int) $src['max_price'] : null;

		$instock = ! empty( $src['instock'] );

		$q = isset( $src['q'] ) ? sanitize_text_field( wp_unslash( (string) $src['q'] ) ) : '';
		$q = trim( mb_substr( $q, 0, 100 ) );

		return [
			'cat'       => $cat,
			'vendor'    => $vendor,
			'min_price' => $min,
			'max_price' => $max,
			'instock'   => $instock,
			'q'         => $q,
		];
	}

	/**
	 * @param array{cat:string[],vendor:int[],min_price:?int,max_price:?int,instock:bool} $f
	 * @return array{tax_query:array<int,mixed>,meta_query:array<int|string,mixed>}
	 */
	private static function build_clauses( array $f ): array {
		$tax  = [];
		$meta = [];

		if ( ! empty( $f['cat'] ) ) {
			$tax[] = [
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => $f['cat'],
				'operator' => 'IN',
			];
		}

		if ( $f['min_price'] !== null || $f['max_price'] !== null ) {
			$min = $f['min_price'] ?? 0;
			$max = $f['max_price'] ?? PHP_INT_MAX;
			$meta[] = [
				'key'     => '_price',
				'value'   => [ $min, $max ],
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
			];
		}

		if ( ! empty( $f['vendor'] ) ) {
			// _nkzmp_vendor_id NEBO legacy _nkv_vendor_id v daném setu.
			$meta[] = [
				'relation' => 'OR',
				[
					'key'     => '_nkzmp_vendor_id',
					'value'   => $f['vendor'],
					'compare' => 'IN',
				],
				[
					'key'     => '_nkv_vendor_id',
					'value'   => $f['vendor'],
					'compare' => 'IN',
				],
			];
		}

		if ( $f['instock'] ) {
			$meta[] = [
				'key'     => '_stock_status',
				'value'   => 'instock',
				'compare' => '=',
			];
		}

		return [ 'tax_query' => $tax, 'meta_query' => $meta ];
	}

	/** Řazení – mapuje WC orderby na WP_Query args. */
	private static function ordering_args( string $orderby ): array {
		$orderby = $orderby !== '' ? $orderby : (string) get_option( 'woocommerce_default_catalog_orderby', 'menu_order' );
		switch ( $orderby ) {
			case 'price':
				return [ 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'ASC' ];
			case 'price-desc':
				return [ 'orderby' => 'meta_value_num', 'meta_key' => '_price', 'order' => 'DESC' ];
			case 'date':
				return [ 'orderby' => 'date', 'order' => 'DESC' ];
			case 'popularity':
				return [ 'orderby' => 'meta_value_num', 'meta_key' => 'total_sales', 'order' => 'DESC' ];
			case 'rating':
				return [ 'orderby' => 'meta_value_num', 'meta_key' => '_wc_average_rating', 'order' => 'DESC' ];
			case 'menu_order':
			default:
				return [ 'orderby' => 'menu_order title', 'order' => 'ASC' ];
		}
	}

	/** Min/max cena napříč publikovanými produkty (cache 1h). */
	private static function price_bounds(): array {
		$cached = get_transient( 'nkzmp_shop_price_bounds' );
		if ( is_array( $cached ) && isset( $cached['min'], $cached['max'] ) ) {
			return $cached;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			"SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) AS min, MAX(CAST(meta_value AS DECIMAL(10,2))) AS max
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_price' AND pm.meta_value != ''
			   AND p.post_type = 'product' AND p.post_status = 'publish'"
		);
		$bounds = [
			'min' => $row ? (int) floor( (float) $row->min ) : 0,
			'max' => $row ? (int) ceil( (float) $row->max ) : 0,
		];
		set_transient( 'nkzmp_shop_price_bounds', $bounds, HOUR_IN_SECONDS );
		return $bounds;
	}

	/**
	 * Prodejci, kteří mají aspoň jeden publikovaný produkt.
	 *
	 * @return array<int,string> id => name
	 */
	private static function product_vendors(): array {
		$cached = get_transient( 'nkzmp_shop_product_vendors' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$ids = $wpdb->get_col(
			"SELECT DISTINCT pm.meta_value
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id')
			   AND pm.meta_value != '' AND pm.meta_value != '0'
			   AND p.post_type = 'product' AND p.post_status = 'publish'"
		);
		$out = [];
		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $vid ) {
			if ( $vid <= 0 ) {
				continue;
			}
			$post = get_post( $vid );
			if ( $post && $post->post_status === 'publish' ) {
				$out[ $vid ] = $post->post_title;
			}
		}
		asort( $out, SORT_NATURAL | SORT_FLAG_CASE );
		set_transient( 'nkzmp_shop_product_vendors', $out, HOUR_IN_SECONDS );
		return $out;
	}

	private static function base_url(): string {
		if ( function_exists( 'wc_get_page_permalink' ) ) {
			$url = wc_get_page_permalink( 'shop' );
			if ( $url ) {
				return $url;
			}
		}
		return home_url( '/' );
	}

	/** Invalidace cache (volat při změně produktů). */
	public static function forget_cache(): void {
		delete_transient( 'nkzmp_shop_price_bounds' );
		delete_transient( 'nkzmp_shop_product_vendors' );
	}
}
