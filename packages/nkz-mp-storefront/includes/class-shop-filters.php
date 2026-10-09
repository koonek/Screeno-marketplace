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

		// Řada kategorií nad produkty (navigace jako Zalando) – před layoutem,
		// ať je přes celou šířku a AJAX překreslení výsledků ji nesmaže.
		add_action( 'woocommerce_before_shop_loop', [ $this, 'category_pills' ], 0 );
		// Layout wrapper kolem výsledků (sidebar + grid sloupec).
		add_action( 'woocommerce_before_shop_loop', [ $this, 'open_layout' ], 1 );
		add_action( 'woocommerce_before_shop_loop', [ SearchBoost::class, 'note' ], 2 );
		add_action( 'woocommerce_after_shop_loop', [ $this, 'close_layout' ], 50 );

		// Aplikace filtrů na hlavní shop query (URL / no-JS / SEO).
		add_action( 'woocommerce_product_query', [ $this, 'apply_to_query' ] );

		// Řazení: bez „oblíbenosti" a „hodnocení" – zákazník neví, podle
		// čeho by se řadilo (hodnocení se na webu ani nesbírá).
		add_filter( 'woocommerce_catalog_orderby', [ $this, 'orderby_options' ], 20 );
		add_filter( 'woocommerce_default_catalog_orderby', [ $this, 'default_orderby' ], 20 );

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

		// Mobilní toggle (skrytý na desktopu přes CSS). Klíčový vzhled
		// (modré tlačítko, modré řazení) i přímo ve stránce – na webu ho
		// přebíjel styl šablony / stará CSS z mezipaměti.
		echo '<style>
		@media (max-width:1024px){
		html body .nkzmp-shop-layout button.nkzmp-shop-filters-toggle,html body .nkzmp-shop-layout button.nkzmp-shop-filters-toggle:hover,html body .nkzmp-shop-layout button.nkzmp-shop-filters-toggle:focus,html body .nkzmp-shop-layout button.nkzmp-shop-filters-toggle:active{display:flex!important;width:100%!important;justify-content:center!important;align-items:center!important;gap:10px!important;margin:0 0 16px!important;padding:14px 22px!important;border:0!important;border-radius:999px!important;background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important;font-size:16px!important;font-weight:600!important;box-shadow:0 6px 18px rgba(0,96,255,.25)!important;outline:0}
		html body .nkzmp-shop-layout button.nkzmp-shop-filters-toggle span{color:#fff!important;-webkit-text-fill-color:#fff!important}
		}
		html body .woocommerce .woocommerce-ordering select.orderby{border:2px solid #0060FF!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;background:#fff url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%230060FF\' stroke-width=\'3\' stroke-linecap=\'round\'%3E%3Cpath d=\'M6 9l6 6 6-6\'/%3E%3C/svg%3E") no-repeat right 16px center!important;border-radius:999px!important;padding:11px 42px 11px 18px!important;font-size:15px!important;font-weight:600!important;height:auto!important;-webkit-appearance:none!important;appearance:none!important;box-shadow:none!important;cursor:pointer}
		</style>';
		echo '<button type="button" class="nkzmp-shop-filters-toggle" aria-expanded="false">'
			. '<span>' . esc_html( apply_filters( 'nkzmp/v1/storefront/filters_toggle_label', __( 'Kategorie, značky, cena', 'nkz-mp-storefront' ) ) ) . '</span>'
			. '</button>';

		echo '<aside class="nkzmp-shop-filters" id="nkzmp-shop-filters">';
		$this->render_sidebar();
		echo '</aside>';

		echo '<div class="nkzmp-shop-results" id="nkzmp-shop-results">';
	}

	/**
	 * Řada kategorií jako odkazy (vzor Zalando): v obchodě hlavní
	 * kategorie, v kategorii „Vše" + podkategorie, v podkategorii její
	 * sourozenci. Navigace, ne filtr – vede na stránku kategorie.
	 */
	public function category_pills(): void {
		if ( ! self::applicable() || ! taxonomy_exists( 'product_cat' ) ) {
			return;
		}
		$uncat   = (int) get_option( 'default_product_cat', 0 );
		$current = is_product_category() ? get_queried_object() : null;
		$parent  = 0;
		$all     = null; // [ label, url, active ]
		if ( $current instanceof \WP_Term ) {
			$kids_of_current = self::cat_children( (int) $current->term_id, $uncat );
			if ( $kids_of_current ) {
				$parent = (int) $current->term_id;
				$all    = [ __( 'Vše', 'nkz-mp-storefront' ), get_term_link( $current ), true ];
			} elseif ( (int) $current->parent > 0 ) {
				$parent = (int) $current->parent;
				$pt     = get_term( $parent, 'product_cat' );
				if ( $pt instanceof \WP_Term ) {
					/* translators: %s: hlavní kategorie */
					$all = [ sprintf( __( 'Vše z %s', 'nkz-mp-storefront' ), $pt->name ), get_term_link( $pt ), false ];
				}
			} else {
				return; // hlavní kategorie bez podkategorií – není co nabízet
			}
		} elseif ( ! is_shop() ) {
			return;
		}
		$terms = self::cat_children( $parent, $uncat );
		if ( count( $terms ) < 2 && ! $all ) {
			return;
		}
		echo '<nav class="nkzmp-catpills" aria-label="' . esc_attr__( 'Kategorie', 'nkz-mp-storefront' ) . '"><ul>';
		if ( $current instanceof \WP_Term ) {
			// Cesta zpět na všechny produkty (bez filtrů).
			printf( '<li><a class="is-back" href="%s">← %s</a></li>', esc_url( self::base_url() ), esc_html__( 'Všechny kategorie', 'nkz-mp-storefront' ) );
		}
		if ( $all && ! is_wp_error( $all[1] ) ) {
			printf( '<li><a href="%s"%s>%s</a></li>', esc_url( $all[1] ), $all[2] ? ' class="is-active" aria-current="page"' : '', esc_html( $all[0] ) );
		}
		foreach ( $terms as $t ) {
			$url = get_term_link( $t );
			if ( is_wp_error( $url ) ) {
				continue;
			}
			$active = $current instanceof \WP_Term && (int) $current->term_id === (int) $t->term_id;
			printf( '<li><a href="%s"%s>%s</a></li>', esc_url( $url ), $active ? ' class="is-active" aria-current="page"' : '', esc_html( $t->name ) );
		}
		echo '</ul></nav>';
		echo '<style>
		.nkzmp-catpills{margin:0 0 20px}
		.nkzmp-catpills ul{display:flex;flex-wrap:wrap;gap:10px;list-style:none;margin:0;padding:0}
		html body .nkzmp-catpills a{display:inline-flex;align-items:center;padding:10px 18px;border-radius:999px;background:#f1f2f4;color:#111;-webkit-text-fill-color:#111;font-weight:600;font-size:15px;line-height:1.2;text-decoration:none!important;white-space:nowrap;transition:background .15s,color .15s}
		html body .nkzmp-catpills a:hover{background:#e3e8f5;color:#0060FF;-webkit-text-fill-color:#0060FF}
		html body .nkzmp-catpills a.is-active{background:#0060FF;color:#fff;-webkit-text-fill-color:#fff}
		html body .nkzmp-catpills a.is-back{background:#fff;border:1.5px solid #d5dbe6;color:#111;-webkit-text-fill-color:#111}
		html body .nkzmp-catpills a.is-back:hover{border-color:#0060FF;color:#0060FF;-webkit-text-fill-color:#0060FF}
		@media (max-width:767px){
		.nkzmp-catpills{margin:0 -16px 16px}
		.nkzmp-catpills ul{flex-wrap:nowrap;overflow-x:auto;padding:2px 16px 6px;scrollbar-width:none;-webkit-overflow-scrolling:touch;scroll-snap-type:x proximity}
		.nkzmp-catpills ul::-webkit-scrollbar{display:none}
		.nkzmp-catpills li{flex:0 0 auto;scroll-snap-align:start}
		html body .nkzmp-catpills a{padding:9px 16px;font-size:14px}
		}
		</style>';
		// Aktivní pilulku posunout do zorného pole (mobil).
		echo '<script>(function(){var a=document.querySelector(".nkzmp-catpills a.is-active");if(a&&a.scrollIntoView){var u=a.closest("ul");if(u&&u.scrollWidth>u.clientWidth){u.scrollLeft=a.offsetLeft-16;}}})();</script>';
	}

	/** Neprázdné podkategorie (počty vč. vnořených), v pořadí z administrace. */
	private static function cat_children( int $parent, int $uncat ): array {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
			'parent'     => $parent,
			'orderby'    => 'menu_order',
		] );
		if ( is_wp_error( $terms ) ) {
			return [];
		}
		return array_values( array_filter( (array) $terms, static function ( $t ) use ( $uncat ) {
			if ( (int) $t->term_id === $uncat ) {
				return false;
			}
			$c = get_term_meta( $t->term_id, 'product_count_product_cat', true );
			return ( $c !== '' ? (int) $c : (int) $t->count ) > 0;
		} ) );
	}

	public function close_layout(): void {
		if ( ! self::applicable() ) {
			return;
		}
		echo '</div>'; // .nkzmp-shop-results
		echo '</div>'; // .nkzmp-shop-layout
	}

	/* ───────────────────────── Sidebar UI ───────────────────────── */

	/** Počty pro aktuální výběr (značky, graf cen) – viz facets(). */
	private static ?array $facets = null;

	private function render_sidebar(): void {
		$active = self::read_filters( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification

		// Počty ve filtru podle toho, co je vybrané (vč. stránky kategorie).
		$scope = $active;
		if ( empty( $scope['cat'] ) && function_exists( 'is_product_category' ) && is_product_category() ) {
			$t = get_queried_object();
			if ( $t instanceof \WP_Term ) {
				$scope['cat'] = [ $t->slug ];
			}
		}
		$any = ! empty( $scope['cat'] ) || ! empty( $scope['vendor'] ) || $scope['min_price'] !== null || $scope['max_price'] !== null || $scope['instock'] || $scope['q'] !== '';
		self::$facets = $any ? self::facets( $scope ) : null;

		// Vzhled přímo ve stránce (šablona jinak přebíjí tlačítka růžovou).
		echo '<style>
		html body .nkzmp-filters .nkzmp-filters__list.nkzmp-filters__cattree{max-height:none!important;overflow:visible!important;padding-right:0!important}
		html body .nkzmp-filters button.nkzmp-filters__more,html body .nkzmp-filters button.nkzmp-filters__more:hover,html body .nkzmp-filters button.nkzmp-filters__more:focus,html body .nkzmp-filters button.nkzmp-filters__more:active{display:inline-flex!important;align-items:center;gap:6px;margin:6px 0 0!important;padding:8px 14px!important;background:transparent!important;border:1.5px solid #0060FF!important;border-radius:999px!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;font-size:14px!important;font-weight:600!important;text-decoration:none!important;box-shadow:none!important}
		html body .nkzmp-filters button.nkzmp-filters__more:hover{background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
		html body .nkzmp-filters button.nkzmp-filters__more[hidden],html body .nkzmp-filters .nkzmp-filters__list--vendors li.is-zero{display:none!important}
		html body .nkzmp-filters button.nkzmp-filters__clear,html body .nkzmp-filters button.nkzmp-filters__clear:hover,html body .nkzmp-filters button.nkzmp-filters__clear:focus,html body .nkzmp-filters button.nkzmp-filters__clear:active{background:transparent!important;border:0!important;box-shadow:none!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;text-decoration:none!important;padding:4px 0!important}
		html body .nkzmp-filters button.nkzmp-filters__clear:hover{text-decoration:underline!important}
		html body .nkzmp-filters button.nkzmp-filters__done,html body .nkzmp-filters button.nkzmp-filters__done:hover,html body .nkzmp-filters button.nkzmp-filters__done:focus,html body .nkzmp-filters button.nkzmp-filters__done:active{background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important;border:0!important;border-radius:999px!important;box-shadow:none!important}
		.nkzmp-active-chips{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 18px}
		.nkzmp-active-chips[hidden]{display:none}
		html body .nkzmp-active-chips button,html body .nkzmp-active-chips button:hover,html body .nkzmp-active-chips button:focus,html body .nkzmp-active-chips button:active{display:inline-flex!important;align-items:center;gap:8px;margin:0!important;padding:7px 12px 7px 14px!important;border:1.5px solid #0060FF!important;border-radius:999px!important;background:#f2f6ff!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;font-size:14px!important;font-weight:500!important;line-height:1.2!important;cursor:pointer;box-shadow:none!important;text-decoration:none!important}
		html body .nkzmp-active-chips button:hover{background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
		html body .nkzmp-active-chips button .x{font-size:16px;line-height:1;opacity:.8}
		/* Počítač: filtry jako řada tlačítek nahoře (vzor Vinted/Zalando). */
		.nkzmp-bar-btn{display:none}
		@media (min-width:1025px){
		html body .nkzmp-shop-layout.is-bar{display:block!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-shop-filters{position:relative!important;top:auto!important;max-height:none!important;overflow:visible!important;width:auto!important;padding:0!important;margin:0 0 18px!important;background:none!important;border:0!important;box-shadow:none!important;z-index:20}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters{flex-direction:row!important;flex-wrap:wrap!important;align-items:center!important;gap:10px!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__head,html body .nkzmp-shop-layout.is-bar .nkzmp-filters__done{display:none!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group{position:relative!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group.has-panel > legend{display:none!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group--search > legend{display:none!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group--search .nkzmp-filters__search{width:240px!important;border-radius:999px!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn,html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn:hover,html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn:focus,html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn:active{display:inline-flex!important;align-items:center;gap:8px;margin:0!important;padding:10px 16px!important;border:1.5px solid #d5dbe6!important;border-radius:999px!important;background:#fff!important;color:#111!important;-webkit-text-fill-color:#111!important;font-size:15px!important;font-weight:500!important;line-height:1.2!important;box-shadow:none!important;cursor:pointer;text-transform:none!important;letter-spacing:normal!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn:hover{border-color:#0060FF!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group.is-active > .nkzmp-bar-btn{border-color:#0060FF!important;background:#f2f6ff!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important}
		html body .nkzmp-shop-layout.is-bar .nkzmp-bar-btn::after{content:"";width:7px;height:7px;border-right:2px solid currentColor;border-bottom:2px solid currentColor;transform:translateY(-2px) rotate(45deg);transition:transform .15s}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group.is-open > .nkzmp-bar-btn::after{transform:translateY(2px) rotate(-135deg)}
		html body .nkzmp-shop-layout.is-bar .nkzmp-bar-panel{display:none;position:absolute;top:calc(100% + 8px);left:0;z-index:60;width:340px;max-height:70vh;overflow:auto;padding:18px;background:#fff;border-radius:16px;box-shadow:0 12px 40px rgba(0,0,0,.14)}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group.is-open > .nkzmp-bar-panel{display:block}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group:last-child .nkzmp-bar-panel{left:auto;right:0}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters__group[data-nkzmp-group="cat"]:has(.is-multi) .nkzmp-bar-panel{width:620px}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters .nkzmp-filters__list.nkzmp-filters__cattree.is-multi{display:block!important;columns:2;column-gap:32px}
		html body .nkzmp-shop-layout.is-bar .nkzmp-filters .nkzmp-filters__cattree.is-multi > .nkzmp-filters__cat{break-inside:avoid;-webkit-column-break-inside:avoid;display:block;margin:0 0 14px!important}
		}
		.nkzmp-empty{margin:8px 0 24px;padding:28px 22px;border-radius:16px;background:#f7f8fb;text-align:center}
		.nkzmp-empty h3{margin:0 0 6px;font-size:20px}
		.nkzmp-empty p{margin:0 0 16px;color:#6b7280}
		.nkzmp-empty-actions{display:flex;flex-wrap:wrap;gap:10px;justify-content:center}
		html body .nkzmp-empty-actions button,html body .nkzmp-empty-actions button:hover,html body .nkzmp-empty-actions button:focus{padding:10px 18px!important;border-radius:999px!important;border:1.5px solid #0060FF!important;background:#fff!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;font-weight:600!important;box-shadow:none!important;cursor:pointer}
		html body .nkzmp-empty-actions button.is-primary{background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important}
		html body .nkzmp-load-more,html body .nkzmp-load-more:hover,html body .nkzmp-load-more:focus{display:block!important;width:100%!important;margin:20px 0 8px!important;padding:14px 20px!important;border-radius:999px!important;border:1.5px solid #0060FF!important;background:#fff!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;font-size:16px!important;font-weight:600!important;box-shadow:none!important;cursor:pointer}
		html body .nkzmp-load-more[disabled]{opacity:.6}
		html body .nkzmp-active-chips button.is-clear,html body .nkzmp-active-chips button.is-clear:hover{border-color:transparent!important;background:transparent!important;color:#0060FF!important;-webkit-text-fill-color:#0060FF!important;text-decoration:underline!important}
		</style>';
		$layout = class_exists( Settings::class ) ? (string) ( Settings::get()['filters_layout'] ?? 'top' ) : 'top';
		echo '<form class="nkzmp-filters" method="get" action="' . esc_url( self::base_url() ) . '" data-layout="' . esc_attr( $layout === 'side' ? 'side' : 'top' ) . '">';

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$t = get_queried_object();
			if ( $t instanceof \WP_Term ) {
				echo '<input type="hidden" name="cat_scope" value="' . esc_attr( $t->slug ) . '" data-nkzmp-scope>';
			}
		}
		echo '<div class="nkzmp-filters__head">';
		echo '<h2 class="nkzmp-filters__title">' . esc_html__( 'Filtry', 'nkz-mp-storefront' ) . '</h2>';
		echo '<button type="button" class="nkzmp-filters__clear" data-nkzmp-clear>' . esc_html__( 'Vymazat', 'nkz-mp-storefront' ) . '</button>';
		echo '</div>';

		$this->render_search( $active['q'] );
		$this->render_categories( $active['cat'] );
		$this->render_price( $active['min_price'], $active['max_price'] );
		$this->render_vendors( $active['vendor'], (array) ( $active['cat'] ?? [] ) );
		$this->render_stock( $active['instock'] );

		// No-JS submit.
		echo '<noscript><button type="submit" class="nkzmp-filters__submit">' . esc_html__( 'Použít filtry', 'nkz-mp-storefront' ) . '</button></noscript>';

		// Mobilni "Hotovo" - viditelne jen v bottom-sheet rezimu (CSS).
		global $wp_query;
		$total = isset( $wp_query->found_posts ) ? (int) $wp_query->found_posts : 0;
		printf(
			'<button type="button" class="nkzmp-filters__done" data-nkzmp-done data-total="%d">%s</button>',
			$total,
			esc_html( self::show_label( $total ) )
		);

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
			'hide_empty' => false,
			'orderby'    => 'menu_order', // pořadí z Produkty → Kategorie (přetažením)
		] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return;
		}
		// Na stránce kategorie tu kategorii předvybereme.
		$current_id = 0;
		if ( is_product_taxonomy() ) {
			$current = get_queried_object();
			if ( $current instanceof \WP_Term ) {
				// Stránka kategorie NENÍ filtr – nepředvybírat ji (jinak visí
				// štítek „Vše z …" s ×, které nikam nevede). Výsledky drží
				// v kategorii skrytý cat_scope.
				$current_id = (int) $current->term_id;
			}
		}

		// Strom: hlavní kategorie → podkategorie. Počet bereme z WooCommerce
		// včetně podkategorií (u hlavní by jinak bylo 0, když jsou produkty
		// zařazené jen v podkategoriích). Prázdné nezobrazujeme.
		$uncat    = (int) get_option( 'default_product_cat', 0 );
		$children = [];
		$parents  = [];
		foreach ( $terms as $t ) {
			$count = get_term_meta( $t->term_id, 'product_count_product_cat', true );
			$t->nkzmp_count = $count !== '' ? (int) $count : (int) $t->count;
			$picked = in_array( $t->slug, $selected, true );
			if ( ( $t->nkzmp_count <= 0 || (int) $t->term_id === $uncat ) && ! $picked ) {
				continue;
			}
			if ( (int) $t->parent === 0 ) {
				$parents[] = $t;
			} else {
				$children[ (int) $t->parent ][] = $t;
			}
		}
		// Na stránce kategorie jen její větev (hlavní kategorie + podkategorie)
		// – na jiné kategorie vede řada kategorií nad produkty.
		if ( $current_id > 0 ) {
			$anc  = get_ancestors( $current_id, 'product_cat', 'taxonomy' );
			$root = $anc ? (int) end( $anc ) : $current_id;
			$only = array_values( array_filter( $parents, static fn( $p ) => (int) $p->term_id === $root ) );
			if ( $only ) {
				$parents = $only;
			}
		}
		if ( ! $parents ) {
			return;
		}

		$item = static function ( \WP_Term $t, array $selected ): string {
			$id = 'nkzmp-cat-' . $t->term_id;
			return sprintf(
				'<label for="%1$s"><input type="checkbox" id="%1$s" name="cat[]" value="%2$s"%3$s> <span>%4$s</span> <em>%5$d</em></label>',
				esc_attr( $id ),
				esc_attr( $t->slug ),
				in_array( $t->slug, $selected, true ) ? ' checked' : '',
				esc_html( $t->name ),
				(int) $t->nkzmp_count
			);
		};

		echo '<fieldset class="nkzmp-filters__group" data-nkzmp-group="cat">';
		echo '<legend>' . esc_html__( 'Kategorie', 'nkz-mp-storefront' ) . '</legend>';
		echo '<ul class="nkzmp-filters__list nkzmp-filters__cattree' . ( count( $parents ) > 1 ? ' is-multi' : '' ) . '">';
		foreach ( $parents as $p ) {
			$kids = $children[ (int) $p->term_id ] ?? [];
			// Jednotně u všech hlavních kategorií: malý nadpis, pod ním
			// zaškrtávátka v jednom sloupci – „Vše z …" a podkategorie
			// (vždy vidět, nic se nerozklikává).
			echo '<li class="nkzmp-filters__cat is-open' . ( $kids ? ' has-sub' : '' ) . '" data-term="' . (int) $p->term_id . '">';
			printf( '<div class="nkzmp-filters__cathead">%s</div>', esc_html( $p->name ) );
			echo '<ul class="nkzmp-filters__sub">';
			printf(
				'<li class="nkzmp-filters__all"><label for="%1$s"><input type="checkbox" id="%1$s" name="cat[]" value="%2$s" data-nkzmp-catall%3$s> <span>%4$s</span> <em>%5$d</em></label></li>',
				esc_attr( 'nkzmp-cat-' . $p->term_id ),
				esc_attr( $p->slug ),
				in_array( $p->slug, $selected, true ) ? ' checked' : '',
				/* translators: %s: hlavní kategorie */
				esc_html( sprintf( __( 'Vše z %s', 'nkz-mp-storefront' ), $p->name ) ),
				(int) $p->nkzmp_count
			);
			foreach ( $kids as $k ) {
				echo '<li>' . $item( $k, $selected ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno v $item.
			}
			echo '</ul>';
			echo '</li>';
		}
		echo '</ul>';
		echo '</fieldset>';
		?>
		<style>
		.nkzmp-filters__cattree > .nkzmp-filters__cat{margin:0 0 16px}
		html body .nkzmp-filters .nkzmp-filters__cattree .nkzmp-filters__cathead{margin:0 0 6px;font-size:12px!important;font-weight:600!important;letter-spacing:.06em;text-transform:uppercase;color:#6b7280!important;line-height:1.3!important}
		html body .nkzmp-filters .nkzmp-filters__cattree .nkzmp-filters__sub{display:block!important;list-style:none;margin:0!important;padding:0!important;border:0!important}
		html body .nkzmp-filters .nkzmp-filters__cattree .nkzmp-filters__sub li{margin:0!important;padding:3px 0!important;font-size:inherit}
		html body .nkzmp-filters .nkzmp-filters__cattree .nkzmp-filters__all{font-weight:500}
		</style>
		<script>
		(function () {
			var tree = document.querySelector('.nkzmp-filters__cattree');
			if (!tree) { return; }
			// „Vše z …" a podkategorie se vylučují (zachytí se dřív, než
			// formulář pošle filtr – posluchač je blíž než formulář).
			tree.addEventListener('change', function (e) {
				var inp = e.target;
				if (!inp.checked || inp.name !== 'cat[]') { return; }
				var sub = inp.closest('.nkzmp-filters__sub');
				if (!sub) { return; }
				if (inp.hasAttribute('data-nkzmp-catall')) {
					sub.querySelectorAll('input[name="cat[]"]:not([data-nkzmp-catall])').forEach(function (o) { o.checked = false; });
				} else {
					var all = sub.querySelector('[data-nkzmp-catall]');
					if (all) { all.checked = false; }
				}
			});
		})();
		</script>
		<?php
	}

	private function render_price( ?int $min, ?int $max ): void {
		$data = self::price_data();
		if ( ! $data ) {
			return;
		}
		$lo     = $data['lo'];
		$hi     = $data['hi'];   // horní mez stupnice (~95 % cen), výš = „hi+"
		$capped = $data['capped'];

		echo '<fieldset class="nkzmp-filters__group nkzmp-filters__price" data-nkzmp-group="price">';
		echo '<legend>' . esc_html__( 'Cena', 'nkz-mp-storefront' ) . '</legend>';

		// Graf rozložení cen posazený na osu posuvníku (vzor Airbnb) – stejná
		// logaritmická stupnice, ať sloupce sedí nad cenami pod nimi.
		$hist = self::$facets['hist'] ?? $data['hist'];
		$peak = $hist ? max( $hist ) : 0;
		echo '<div class="nkzmp-filters__pricebox">';
		if ( $peak > 0 ) {
			echo '<div class="nkzmp-filters__hist" aria-hidden="true">';
			foreach ( $hist as $c ) {
				printf( '<span style="height:%s%%"></span>', esc_attr( (string) ( $c > 0 ? max( 5, round( $c / $peak * 100 ) ) : 0 ) ) );
			}
			echo '</div>';
		}
		$pos = static fn( int $p ): int => (int) round( 1000 * log( max( $lo, min( $hi, $p ) ) / $lo ) / log( $hi / $lo ) );
		printf(
			'<div class="nkzmp-filters__range" data-lo="%1$d" data-hi="%2$d"><div class="nkzmp-filters__range-bg"></div><div class="nkzmp-filters__range-fill" data-nkzmp-range-fill></div><input type="range" min="0" max="1000" step="1" value="%3$d" data-nkzmp-range="min" aria-label="%5$s"><input type="range" min="0" max="1000" step="1" value="%4$d" data-nkzmp-range="max" aria-label="%6$s"></div>',
			$lo,
			$hi,
			$min !== null ? $pos( $min ) : 0,
			$max !== null ? $pos( $max ) : 1000,
			esc_attr__( 'Cena od', 'nkz-mp-storefront' ),
			esc_attr__( 'Cena do', 'nkz-mp-storefront' )
		);
		echo '</div>';

		// Prázdné pole = bez omezení; zástupný text ukazuje rozsah obchodu.
		echo '<div class="nkzmp-filters__price-inputs">';
		printf(
			'<label class="nkzmp-filters__pricefield"><small>%1$s</small><span><input type="number" name="min_price" inputmode="numeric" min="%2$d" value="%3$s" placeholder="%2$d" data-nkzmp-price="min" aria-label="%4$s"><i>Kč</i></span></label>',
			esc_html__( 'Minimum', 'nkz-mp-storefront' ),
			$lo,
			$min !== null ? (int) $min : '',
			esc_attr__( 'Cena od', 'nkz-mp-storefront' )
		);
		printf(
			'<label class="nkzmp-filters__pricefield is-max"><small>%1$s</small><span><input type="number" name="max_price" inputmode="numeric" min="%2$d" value="%3$s" placeholder="%4$s" data-nkzmp-price="max" aria-label="%5$s"><i>Kč</i></span></label>',
			esc_html__( 'Maximum', 'nkz-mp-storefront' ),
			$lo,
			$max !== null ? (int) $max : '',
			esc_attr( $hi . ( $capped ? '+' : '' ) ),
			esc_attr__( 'Cena do', 'nkz-mp-storefront' )
		);
		echo '</div>';
		echo '<style>
		.nkzmp-filters__pricebox{position:relative;padding-top:6px}
		.nkzmp-filters__hist{display:flex;align-items:flex-end;gap:2px;height:56px;margin:0 11px -12px;position:relative;pointer-events:none}
		.nkzmp-filters__hist span{flex:1 1 0;min-width:0;background:#c9d3e6;border-radius:2px 2px 0 0;transition:background .15s}
		.nkzmp-filters__hist.is-filtered span{background:#dfe4ee}
		.nkzmp-filters__hist.is-filtered span.is-in,.nkzmp-filters__hist span.is-in{background:#0060FF}
		html body .nkzmp-filters__pricebox .nkzmp-filters__range{margin-top:0!important}
		.nkzmp-filters__price-inputs{display:flex;justify-content:space-between;gap:12px;margin-top:14px}
		.nkzmp-filters__pricefield{display:flex;flex-direction:column;gap:4px;max-width:48%;margin:0!important}
		.nkzmp-filters__pricefield.is-max{align-items:flex-end;text-align:right}
		.nkzmp-filters__pricefield small{font-size:12px;color:#6b7280}
		.nkzmp-filters__pricefield span{display:flex;align-items:center;gap:6px;border:1px solid #d9dce3;border-radius:999px;padding:0 14px;background:#fff}
		.nkzmp-filters__pricefield span:focus-within{border-color:#0060FF;box-shadow:0 0 0 3px rgba(0,96,255,.12)}
		html body .nkzmp-filters .nkzmp-filters__pricefield input[type=number]{width:72px!important;min-width:0;height:auto!important;padding:10px 0!important;border:0!important;background:transparent!important;box-shadow:none!important;outline:none!important;font-size:15px!important;-moz-appearance:textfield}
		.nkzmp-filters__pricefield input::-webkit-outer-spin-button,.nkzmp-filters__pricefield input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
		.nkzmp-filters__pricefield i{font-style:normal;color:#6b7280;font-size:14px}
		</style>';
		echo '</fieldset>';
	}

	/**
	 * Cenová data filtru: nejnižší cena, horní mez stupnice (95. percentil,
	 * pěkně zaokrouhlený – drahé výjimky jsou „hi+") a 40 sloupců grafu.
	 *
	 * @return array{lo:int,hi:int,capped:bool,hist:int[]}|null
	 */
	private static function price_data(): ?array {
		$key    = 'nkzmp_shop_price_hist';
		$cached = get_transient( $key );
		if ( is_array( $cached ) && isset( $cached['lo'], $cached['hi'], $cached['hist'] ) ) {
			return $cached;
		}
		global $wpdb;
		$prices = array_map( 'floatval', (array) $wpdb->get_col(
			"SELECT MIN(CAST(pm.meta_value AS DECIMAL(10,2)))
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_price' AND pm.meta_value != ''
			   AND p.post_type = 'product' AND p.post_status = 'publish'
			 GROUP BY pm.post_id"
		) );
		$prices = array_values( array_filter( $prices, static fn( $p ) => $p > 0 ) );
		if ( count( $prices ) < 2 ) {
			return null;
		}
		sort( $prices );
		$lo  = max( 1, (int) floor( $prices[0] ) );
		$max = (int) ceil( end( $prices ) );
		$p95 = $prices[ (int) floor( 0.95 * ( count( $prices ) - 1 ) ) ];
		$step = $p95 < 2000 ? 50 : ( $p95 < 20000 ? 500 : 1000 );
		$hi   = (int) ( ceil( $p95 / $step ) * $step );
		$capped = $hi < $max;
		if ( ! $capped ) {
			$hi = $max;
		}
		if ( $hi <= $lo ) {
			return null;
		}
		$buckets = 40;
		$hist    = array_fill( 0, $buckets, 0 );
		$den     = log( $hi / $lo );
		foreach ( $prices as $pr ) {
			$pr = max( $lo, min( $hi, $pr ) ); // dražší než mez → poslední sloupec
			$i  = (int) floor( log( $pr / $lo ) / $den * $buckets );
			++$hist[ min( $buckets - 1, max( 0, $i ) ) ];
		}
		$out = [ 'lo' => $lo, 'hi' => $hi, 'capped' => $capped, 'hist' => $hist ];
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	private function render_vendors( array $selected, array $cats = [] ): void {
		$vendors = self::product_vendors();
		if ( empty( $vendors ) ) {
			return;
		}

		// Počty podle aktuálního výběru (kategorie, cena, hledání… – bez
		// filtru značek). Značky s nulou se schovají, ale zůstávají ve
		// stránce, ať je JS po změně filtru může zase ukázat.
		$counts = self::$facets['brands'] ?? null;
		$live   = 0;
		foreach ( $vendors as $vid => $v ) {
			$n = $counts !== null ? (int) ( $counts[ $vid ] ?? 0 ) : (int) $v['count'];
			$vendors[ $vid ]['count'] = $n;
			$vendors[ $vid ]['zero']  = $n === 0 && ! in_array( (int) $vid, $selected, true );
			if ( ! $vendors[ $vid ]['zero'] ) {
				++$live;
			}
		}
		unset( $cats );

		echo '<fieldset class="nkzmp-filters__group" data-nkzmp-group="vendor">';
		printf(
			'<legend>%s <em class="nkzmp-filters__legend-count">%d</em></legend>',
			esc_html__( 'Značky', 'nkz-mp-storefront' ),
			$live
		);
		// Seznam BEZ vlastního posouvání (vnořené posouvání v mobilním
		// panelu se pralo). Abecedně, prvních N vidět, další se odkrývají
		// po dávkách; hledání prohledává celý seznam.
		$visible = (int) apply_filters( 'nkzmp/v1/storefront/filter_vendors_visible', 8 );
		$total   = $live;

		$order = array_keys( $vendors ); // jen abecedně (klient: řazení podle počtu produktů pryč)
		$more  = $total > $visible;

		echo '<div class="nkzmp-filters__vendorwrap" data-nkzmp-vendorwrap>';
		if ( $more ) {
			printf(
				'<input type="search" class="nkzmp-filters__vendorsearch" placeholder="%s" aria-label="%s" data-nkzmp-vendorsearch autocomplete="off">',
				/* translators: %d: počet značek */
				esc_attr( sprintf( __( 'Najít značku (%d)…', 'nkz-mp-storefront' ), $total ) ),
				esc_attr__( 'Najít značku', 'nkz-mp-storefront' )
			);
		}
		echo '<ul class="nkzmp-filters__list nkzmp-filters__list--vendors" style="max-height:none!important;overflow:visible!important;">';
		$shown = 0;
		foreach ( $order as $vid ) {
			$v     = $vendors[ $vid ];
			$id    = 'nkzmp-vendor-' . $vid;
			$name  = (string) $v['name'];
			$count = (int) $v['count'];
			$on    = in_array( (int) $vid, $selected, true );
			$zero  = ! empty( $v['zero'] );
			$extra = ! $zero && $shown >= $visible;
			if ( ! $zero ) {
				++$shown;
			}
			// Zaškrtnuté značky nikdy neschováváme – uživatel by nevěděl,
			// proč se mu filtruje.
			printf(
				'<li data-nkzmp-vendor-name="%6$s"%7$s%8$s><label for="%1$s"><input type="checkbox" id="%1$s" name="vendor[]" value="%2$d"%3$s> <span>%4$s</span><em>%5$s</em></label></li>',
				esc_attr( $id ),
				(int) $vid,
				$on ? ' checked' : '',
				esc_html( $name ),
				$count > 0 ? (int) $count : '',
				esc_attr( self::fold( $name ) ),
				$extra ? ( $on ? ' data-nkzmp-vendor-extra' : ' hidden data-nkzmp-vendor-extra' ) : '',
				$zero ? ' class="is-zero"' : ''
			);
		}
		echo '</ul>';
		if ( $more ) {
			printf(
				'<button type="button" class="nkzmp-filters__more" data-nkzmp-vendormore data-step="%d">%s</button>',
				(int) apply_filters( 'nkzmp/v1/storefront/filter_vendors_step', 30 ),
				esc_html( sprintf( /* translators: %d: počet značek */ __( 'Zobrazit všechny značky (%d)', 'nkz-mp-storefront' ), $total ) )
			);
		}
		echo '</div>';
		echo '</fieldset>';
		$this->vendor_list_script();
	}

	/** „Zobrazit 23 produktů" (česká množná čísla). */
	public static function show_label( int $n ): string {
		if ( $n === 1 ) {
			return __( 'Zobrazit 1 produkt', 'nkz-mp-storefront' );
		}
		if ( $n >= 2 && $n <= 4 ) {
			/* translators: %d: počet 2–4 */
			return sprintf( __( 'Zobrazit %d produkty', 'nkz-mp-storefront' ), $n );
		}
		if ( $n === 0 ) {
			return __( 'Žádné produkty – uprav filtr', 'nkz-mp-storefront' );
		}
		/* translators: %d: počet */
		return sprintf( __( 'Zobrazit %d produktů', 'nkz-mp-storefront' ), $n );
	}

	/** @param array<string,string> $options */
	public function orderby_options( $options ): array {
		$options = (array) $options;
		// Bez „oblíbenosti" a „hodnocení"; výchozí jsou Novinky (obchod
		// postupně přibývá), takže ruční „Výchozí třídění" taky pryč.
		unset( $options['popularity'], $options['rating'], $options['menu_order'] );
		$out = [ 'date' => __( 'Novinky', 'nkz-mp-storefront' ) ];
		foreach ( $options as $k => $label ) {
			if ( $k !== 'date' ) {
				$out[ $k ] = $label;
			}
		}
		return $out;
	}

	/** Výchozí řazení: Novinky (nejnovější nahoře). */
	public function default_orderby( $orderby ): string {
		return in_array( (string) $orderby, [ 'popularity', 'rating', 'menu_order', '' ], true ) ? 'date' : (string) $orderby;
	}

	/** Bez diakritiky a malými písmeny – ať „sperky" najde „Šperky". */
	public static function fold( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	private function vendor_list_script(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
		.nkzmp-filters .nkzmp-filters__vendorsearch,.nkzmp-filters input.nkzmp-filters__vendorsearch[type=search]{display:block;width:100%!important;height:auto!important;margin:0 0 12px!important;padding:10px 16px 10px 40px!important;border:2px solid #0060FF!important;border-radius:999px!important;background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%230060FF' stroke-width='2.4' stroke-linecap='round'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='M20 20l-3.5-3.5'/%3E%3C/svg%3E") no-repeat 15px center!important;font-size:15px!important;line-height:1.3!important;color:inherit!important;box-shadow:none!important;box-sizing:border-box;-webkit-appearance:none;appearance:none}
		.nkzmp-filters .nkzmp-filters__vendorsearch:focus{outline:none!important;box-shadow:0 0 0 3px rgba(0,96,255,.15)!important}
		.nkzmp-filters__more{background:none;border:0;padding:8px 0 0;color:#0060FF;font-weight:600;cursor:pointer;font-size:14px}
		.nkzmp-filters__more:hover{text-decoration:underline}

		</style>
		<script>
		(function () {
			function fold(s) {
				return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
			}
			document.querySelectorAll('[data-nkzmp-vendorwrap]').forEach(function (wrap) {
				var more = wrap.querySelector('[data-nkzmp-vendormore]');
				var search = wrap.querySelector('[data-nkzmp-vendorsearch]');
				var items = Array.prototype.slice.call(wrap.querySelectorAll('li[data-nkzmp-vendor-name]'));
				var extras = [];
				var step = more ? (parseInt(more.getAttribute('data-step'), 10) || 30) : 30;
				var shown = 0; // kolik značek z A–Z je odkrytých

				function apply() {
					extras = items.filter(function (li) { return li.hasAttribute('data-nkzmp-vendor-extra'); });
					var q = search ? fold(search.value.trim()) : '';
					items.forEach(function (li) {
						var checked = li.querySelector('input:checked');
						if (q) {
							li.hidden = li.dataset.nkzmpVendorName.indexOf(q) === -1 && !checked;
						} else if (li.hasAttribute('data-nkzmp-vendor-extra')) {
							li.hidden = extras.indexOf(li) >= shown && !checked;
						} else {
							li.hidden = false;
						}
					});
					if (more) {
						var left = extras.length - shown;
						more.hidden = !!q || left <= 0;
						if (shown > 0 && left > 0) {
							more.textContent = more.textContent.replace(/\(.*\)$/, '').replace(/všechny značky/i, 'další').trim() + ' (' + left + ')';
						}
					}
				}

				if (more) {
					more.addEventListener('click', function () { shown += step; apply(); });
				}
				// Po přepočtu počtů (jiný filtr) zase od začátku.
				wrap.nkzmpRefresh = function (label) {
					shown = 0;
					if (more && label) { more.textContent = label; }
					apply();
				};
				if (search) {
					search.addEventListener('input', apply);
					// Enter v hledání nesmí odeslat filtr celého obchodu.
					search.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); } });
				}
			});
		})();
		</script>
		<?php
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
		// Hledání: text + značka + kategorie, s opravou překlepu (SearchBoost).
		// Vlastní pole „q" i nativní WooCommerce hledání (?s=).
		$term = ! empty( $filters['q'] ) ? (string) $filters['q'] : (string) $q->get( 's' );
		if ( $term !== '' ) {
			$ids = SearchBoost::ids( $term );
			$q->set( 'post__in', $ids ?: [ 0 ] );
			// Textovou podmínku vypnout – výběr už dělá post__in (jinak by
			// produkty značky bez slova v názvu vypadly). „s" zůstává kvůli
			// nadpisu výsledků hledání.
			$q->set( 's', $term );
			add_filter(
				'posts_search',
				static function ( $search, $query ) use ( $q ) {
					return $query === $q ? '' : $search;
				},
				10,
				2
			);
		}
	}

	/* ───────────────────────── AJAX ───────────────────────── */

	public function ajax_filter(): void {
		// Nonce ověříme NEzávazně: endpoint je veřejný read-only (jen vrací
		// seznam produktů, nic nemění), takže tu CSRF nehrozí. Blokující nonce
		// způsoboval 400 u guestů / v Safari (ITP cookies) / z page cache.
		check_ajax_referer( self::NONCE, 'nonce', false );

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
			$ids              = SearchBoost::ids( (string) $filters['q'] );
			$args['post__in'] = $ids ?: [ 0 ];
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

		SearchBoost::note();

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
			'html'   => $html,
			'total'  => (int) $q->found_posts,
			'facets' => self::facets( $filters ),
		] );
	}

	/* ───────────────────────── Počty pro výběr ───────────────────────── */

	/**
	 * ID produktů odpovídajících filtru (bez stránkování).
	 *
	 * @return int[]
	 */
	private static function matching_ids( array $f ): array {
		$c    = self::build_clauses( $f );
		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'tax_query'      => array_merge( $c['tax_query'], [ [ 'taxonomy' => 'product_visibility', 'field' => 'name', 'terms' => [ 'exclude-from-catalog' ], 'operator' => 'NOT IN' ] ] ), // phpcs:ignore WordPress.DB.SlowDBQuery
		];
		if ( $c['meta_query'] ) {
			$args['meta_query'] = $c['meta_query']; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		if ( ! empty( $f['q'] ) ) {
			$args['post__in'] = SearchBoost::ids( (string) $f['q'] ) ?: [ 0 ];
		}
		return array_map( 'intval', get_posts( $args ) );
	}

	/**
	 * Počty ve filtru pro aktuální výběr – jako na Zalandu: u značek se
	 * nepočítá filtr značek, u grafu cen filtr ceny (jinak by šlo jen
	 * zužovat, nikdy rozšířit).
	 *
	 * @return array{brands:array<int,int>,hist:int[]}
	 */
	public static function facets( array $f ): array {
		global $wpdb;
		$out = [ 'brands' => [], 'hist' => [] ];

		$ids = self::matching_ids( array_merge( $f, [ 'vendor' => [] ] ) );
		if ( $ids ) {
			$in   = implode( ',', $ids );
			$rows = $wpdb->get_results(
				"SELECT meta_value AS vid, COUNT(DISTINCT post_id) AS n FROM {$wpdb->postmeta}
				 WHERE post_id IN ({$in}) AND meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id') AND meta_value != '' AND meta_value != '0'
				 GROUP BY meta_value" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- jen celá čísla.
			);
			foreach ( (array) $rows as $r ) {
				$out['brands'][ (int) $r->vid ] = max( $out['brands'][ (int) $r->vid ] ?? 0, (int) $r->n );
			}
		}

		$data = self::price_data();
		if ( $data ) {
			$ids  = self::matching_ids( array_merge( $f, [ 'min_price' => null, 'max_price' => null ] ) );
			$hist = array_fill( 0, count( $data['hist'] ), 0 );
			if ( $ids ) {
				$in     = implode( ',', $ids );
				$prices = (array) $wpdb->get_col(
					"SELECT MIN(CAST(meta_value AS DECIMAL(10,2))) FROM {$wpdb->postmeta}
					 WHERE post_id IN ({$in}) AND meta_key = '_price' AND meta_value != '' GROUP BY post_id" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				);
				$n   = count( $hist );
				$den = log( $data['hi'] / $data['lo'] );
				foreach ( $prices as $pr ) {
					$pr = max( $data['lo'], min( $data['hi'], (float) $pr ) );
					if ( $pr <= 0 ) {
						continue;
					}
					$i = (int) floor( log( $pr / $data['lo'] ) / $den * $n );
					++$hist[ min( $n - 1, max( 0, $i ) ) ];
				}
			}
			$out['hist'] = $hist;
		}
		return $out;
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

		// Stránka kategorie: bez vybrané (pod)kategorie platí celá kategorie.
		if ( ! $cat && ! empty( $src['cat_scope'] ) ) {
			$cat = [ sanitize_title( wp_unslash( (string) $src['cat_scope'] ) ) ];
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
		$orderby = $orderby !== '' ? $orderby : (string) apply_filters( 'woocommerce_default_catalog_orderby', get_option( 'woocommerce_default_catalog_orderby', 'menu_order' ) );
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

	/**
	 * Prodejci, kteří mají aspoň jeden publikovaný produkt.
	 *
	 * @return array<int,string> id => name
	 */
	public static function product_vendors(): array {
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
		// Počty produktů na prodejce – ať filtr ukazuje totéž co kategorie.
		$counts = [];
		$rows   = $wpdb->get_results(
			"SELECT pm.meta_value AS vid, COUNT(DISTINCT p.ID) AS cnt
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id')
			   AND pm.meta_value != '' AND pm.meta_value != '0'
			   AND p.post_type = 'product' AND p.post_status = 'publish'
			 GROUP BY pm.meta_value"
		);
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->vid ] = (int) $row->cnt;
		}

		$out = [];
		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $vid ) {
			if ( $vid <= 0 ) {
				continue;
			}
			$post = get_post( $vid );
			if ( $post && $post->post_status === 'publish' && self::vendor_visible( $vid ) ) {
				$out[ $vid ] = [
					'name'  => $post->post_title,
					'count' => $counts[ $vid ] ?? 0,
				];
			}
		}
		uasort( $out, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
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

	/**
	 * Prodejce, který v obchodě reálně prodává: schválený (ne pozastavený /
	 * čekající) a – je-li zapnuté členství – se zaplaceným členstvím.
	 */
	private static function vendor_visible( int $vid ): bool {
		$status = (string) get_post_meta( $vid, '_nkzmp_vendor_status', true );
		if ( '' === $status ) {
			$status = (string) get_post_meta( $vid, '_nkv_vendor_status', true );
		}
		$visible = '' === $status || 'active' === $status;
		return (bool) apply_filters( 'nkzmp/v1/storefront/vendor_visible', $visible, $vid );
	}

	/** Invalidace cache (volat při změně produktů). */
	public static function forget_cache(): void {
		delete_transient( 'nkzmp_shop_price_bounds' );
		delete_transient( 'nkzmp_shop_price_hist' );
		SearchBoost::forget();
		delete_transient( 'nkzmp_shop_product_vendors' );
	}
}
