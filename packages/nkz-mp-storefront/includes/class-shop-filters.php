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
		$this->render_vendors( $active['vendor'], (array) ( $active['cat'] ?? [] ) );
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
				$current_id = (int) $current->term_id;
				if ( empty( $selected ) ) {
					$selected = [ $current->slug ];
				}
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
		echo '<ul class="nkzmp-filters__list nkzmp-filters__cattree">';
		foreach ( $parents as $p ) {
			$kids = $children[ (int) $p->term_id ] ?? [];
			$open = in_array( $p->slug, $selected, true ) || (int) $p->term_id === $current_id;
			foreach ( $kids as $k ) {
				if ( in_array( $k->slug, $selected, true ) || (int) $k->term_id === $current_id ) {
					$open = true;
				}
			}
			echo '<li class="nkzmp-filters__cat' . ( $kids ? ' has-sub' : '' ) . ( $open ? ' is-open' : '' ) . '">';
			echo '<div class="nkzmp-filters__catrow">' . $item( $p, $selected ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escapováno v $item.
			if ( $kids ) {
				printf(
					'<button type="button" class="nkzmp-filters__caret" aria-expanded="%s" aria-label="%s" data-nkzmp-caret></button>',
					$open ? 'true' : 'false',
					/* translators: %s: kategorie */
					esc_attr( sprintf( __( 'Podkategorie: %s', 'nkz-mp-storefront' ), $p->name ) )
				);
			}
			echo '</div>';
			if ( $kids ) {
				echo '<ul class="nkzmp-filters__sub">';
				foreach ( $kids as $k ) {
					echo '<li>' . $item( $k, $selected ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				echo '</ul>';
			}
			echo '</li>';
		}
		echo '</ul>';
		echo '</fieldset>';
		?>
		<style>
		.nkzmp-filters__cattree .nkzmp-filters__catrow{display:flex;align-items:center;gap:6px}
		.nkzmp-filters__cattree .nkzmp-filters__catrow > label{flex:1 1 auto;min-width:0}
		.nkzmp-filters__caret{flex:0 0 auto;width:32px;height:32px;margin:0;padding:0!important;border:0!important;background:none!important;box-shadow:none!important;cursor:pointer;position:relative;border-radius:8px!important}
		.nkzmp-filters__caret::before{content:"";position:absolute;left:50%;top:50%;width:8px;height:8px;border-right:2px solid #0060FF;border-bottom:2px solid #0060FF;transform:translate(-50%,-70%) rotate(45deg);transition:transform .15s}
		.nkzmp-filters__cat.is-open > .nkzmp-filters__catrow .nkzmp-filters__caret::before{transform:translate(-50%,-30%) rotate(-135deg)}
		.nkzmp-filters__caret:hover{background:rgba(0,96,255,.08)!important}
		.nkzmp-filters__sub{list-style:none;margin:2px 0 6px 26px;padding:0 0 0 10px;border-left:1px solid #e3e6ee;display:none}
		.nkzmp-filters__cat.is-open > .nkzmp-filters__sub{display:block}
		.nkzmp-filters__sub li{font-size:.95em}
		</style>
		<script>
		document.querySelectorAll('[data-nkzmp-caret]').forEach(function (b) {
			b.addEventListener('click', function () {
				var li = b.closest('.nkzmp-filters__cat');
				var open = li.classList.toggle('is-open');
				b.setAttribute('aria-expanded', open ? 'true' : 'false');
			});
		});
		</script>
		<?php
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

	private function render_vendors( array $selected, array $cats = [] ): void {
		$vendors = self::product_vendors();
		if ( empty( $vendors ) ) {
			return;
		}

		// Ve zvolené kategorii jen značky, které v ní něco mají (s počty
		// za kategorii). Při stovkách značek je to největší úleva – v
		// „Šperky" nemá smysl nabízet keramiku.
		$term_ids = self::scope_term_ids( $cats );
		if ( $term_ids ) {
			$in_scope = self::vendor_counts_in_terms( $term_ids );
			$scoped   = [];
			foreach ( $vendors as $vid => $v ) {
				if ( isset( $in_scope[ $vid ] ) ) {
					$scoped[ $vid ] = [ 'name' => $v['name'], 'count' => $in_scope[ $vid ] ];
				} elseif ( in_array( (int) $vid, $selected, true ) ) {
					// Zaškrtnutou značku necháme, ať jde odškrtnout.
					$scoped[ $vid ] = [ 'name' => $v['name'], 'count' => 0 ];
				}
			}
			$vendors = $scoped;
			if ( ! $vendors ) {
				return;
			}
		}

		echo '<fieldset class="nkzmp-filters__group" data-nkzmp-group="vendor">';
		printf(
			'<legend>%s <em class="nkzmp-filters__legend-count">%d</em></legend>',
			esc_html__( 'Značky', 'nkz-mp-storefront' ),
			count( $vendors )
		);
		// Seznam BEZ vlastního posouvání (vnořené posouvání v mobilním
		// panelu se pralo). Abecedně, prvních N vidět, další se odkrývají
		// po dávkách; hledání prohledává celý seznam.
		$visible = (int) apply_filters( 'nkzmp/v1/storefront/filter_vendors_visible', 8 );
		$total   = count( $vendors );

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
		foreach ( $order as $i => $vid ) {
			$v     = $vendors[ $vid ];
			$id    = 'nkzmp-vendor-' . $vid;
			$name  = (string) $v['name'];
			$count = (int) $v['count'];
			$on    = in_array( (int) $vid, $selected, true );
			$extra = $i >= $visible;
			// Zaškrtnuté značky nikdy neschováváme – uživatel by nevěděl,
			// proč se mu filtruje.
			printf(
				'<li data-nkzmp-vendor-name="%6$s"%7$s><label for="%1$s"><input type="checkbox" id="%1$s" name="vendor[]" value="%2$d"%3$s> <span>%4$s</span>%5$s</label></li>',
				esc_attr( $id ),
				(int) $vid,
				$on ? ' checked' : '',
				esc_html( $name ),
				$count > 0 ? ' <em>' . (int) $count . '</em>' : '',
				esc_attr( self::fold( $name ) ),
				$extra ? ( $on ? ' data-nkzmp-vendor-extra' : ' hidden data-nkzmp-vendor-extra' ) : ''
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

	/**
	 * ID kategorií (vč. podkategorií), na které je obchod právě zúžený:
	 * archiv kategorie nebo zaškrtnuté kategorie ve filtru.
	 *
	 * @param string[] $slugs
	 * @return int[]
	 */
	private static function scope_term_ids( array $slugs ): array {
		$ids = [];
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$obj = get_queried_object();
			if ( $obj instanceof \WP_Term ) {
				$ids[] = (int) $obj->term_id;
			}
		}
		foreach ( $slugs as $slug ) {
			$t = get_term_by( 'slug', (string) $slug, 'product_cat' );
			if ( $t instanceof \WP_Term ) {
				$ids[] = (int) $t->term_id;
			}
		}
		$all = [];
		foreach ( array_unique( $ids ) as $id ) {
			$all[] = $id;
			$kids  = get_term_children( $id, 'product_cat' );
			if ( is_array( $kids ) ) {
				$all = array_merge( $all, array_map( 'intval', $kids ) );
			}
		}
		$all = array_values( array_unique( $all ) );
		sort( $all );
		return $all;
	}

	/**
	 * Počty zveřejněných produktů na značku v daných kategoriích (cache 1 h,
	 * smaže ji každá změna produktu – forget_cache zvedne verzi).
	 *
	 * @param int[] $term_ids
	 * @return array<int,int> vendor_id => počet
	 */
	private static function vendor_counts_in_terms( array $term_ids ): array {
		$key    = 'nkzmp_shop_cat_vendors_' . (int) get_option( 'nkzmp_shop_cache_ver', 1 ) . '_' . md5( implode( ',', $term_ids ) );
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $term_ids ) );
		$rows = $wpdb->get_results(
			"SELECT pm.meta_value AS vid, COUNT(DISTINCT p.ID) AS cnt
			 FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
			 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			 WHERE pm.meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id')
			   AND pm.meta_value != '' AND pm.meta_value != '0'
			   AND p.post_type = 'product' AND p.post_status = 'publish'
			   AND tt.taxonomy = 'product_cat' AND tt.term_id IN ({$in})
			 GROUP BY pm.meta_value" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- jen celá čísla.
		);
		$out = [];
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->vid ] = (int) $row->cnt;
		}
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	/** @param array<string,string> $options */
	public function orderby_options( $options ): array {
		$options = (array) $options;
		unset( $options['popularity'], $options['rating'] );
		return $options;
	}

	/** Výchozí řazení nesmí zůstat na odebrané volbě. */
	public function default_orderby( $orderby ): string {
		return in_array( (string) $orderby, [ 'popularity', 'rating' ], true ) ? 'menu_order' : (string) $orderby;
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
				var extras = items.filter(function (li) { return li.hasAttribute('data-nkzmp-vendor-extra'); });
				var step = more ? (parseInt(more.getAttribute('data-step'), 10) || 30) : 30;
				var shown = 0; // kolik značek z A–Z je odkrytých

				function apply() {
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
		if ( ! empty( $filters['q'] ) ) {
			$q->set( 's', $filters['q'] );
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
		delete_transient( 'nkzmp_shop_product_vendors' );
		update_option( 'nkzmp_shop_cache_ver', (int) get_option( 'nkzmp_shop_cache_ver', 1 ) + 1, false );
	}
}
