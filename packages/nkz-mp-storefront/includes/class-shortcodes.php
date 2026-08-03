<?php
/**
 * Shortcodes pro vkládání marketplace komponent kdekoli na webu.
 *
 * [nkzmp_latest_products limit="4" columns="4" category="" button="no"]
 *   Grid nejnovějších produktů (orderby=date desc), stejný card markup jako
 *   /obchod/ – obrázek, název, „OD <prodejce>" badge, cena. Tlačítko
 *   „Přidat do košíku / Číst více" je default vypnuté (teaser na landingu).
 *
 * [nkzmp_product_categories limit="6" columns="4" parent="0" hide_empty="yes" show_count="no" hide_uncategorized="yes"]
 *   Grid kategorií produktů. Wrappuje WC builtin [product_categories] +
 *   vynuti storefront CSS i mimo /obchod/. Default schova „Nezařazené"
 *   a počty (1) u nazvu – cistsi vzhled na landingu.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Shortcodes {

	private static ?Shortcodes $instance = null;

	public static function instance(): Shortcodes {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( 'nkzmp_latest_products', [ $this, 'latest_products' ] );
		add_shortcode( 'nkzmp_product_categories', [ $this, 'product_categories' ] );
	}

	/**
	 * @param array<string,string> $atts
	 */
	public function latest_products( $atts = [] ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$a = shortcode_atts( [
			'limit'      => '4',
			'columns'    => '4',
			'category'   => '',
			'orderby'    => 'date',
			'order'      => 'DESC',
			'button'     => 'no',
			// Odkaz „Vidět vše" pod gridem (default zapnutý).
			'more'       => 'yes',
			'more_text'  => '',
			'more_url'   => '',
		], (array) $atts, 'nkzmp_latest_products' );

		$show_button = in_array( strtolower( (string) $a['button'] ), [ 'yes', 'true', '1' ], true );
		$show_more   = in_array( strtolower( (string) $a['more'] ), [ 'yes', 'true', '1' ], true );

		$inner = sprintf(
			'[products limit="%d" columns="%d" orderby="%s" order="%s"%s]',
			(int) $a['limit'],
			(int) $a['columns'],
			esc_attr( $a['orderby'] ),
			esc_attr( $a['order'] ),
			$a['category'] !== '' ? ' category="' . esc_attr( $a['category'] ) . '"' : ''
		);

		// Vynutit storefront CSS (Assets standardne ladi jen na WC strankach).
		Assets::ensure_storefront_css();
		Assets::ensure_wc_css();

		// Vynucení shop-loop hooků (vendor badge "OD ...") i mimo /obchod/.
		$GLOBALS['nkzmp_force_shop_loop'] = true;

		// Tlacitko 'Pridat do kosiku / Cist vice' default off.
		if ( ! $show_button ) {
			remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		}

		$out = '<div class="woocommerce nkzmp-latest-products">' . do_shortcode( $inner ) . '</div>';

		if ( $show_more ) {
			$out .= $this->more_link( (string) $a['more_url'], (string) $a['more_text'], (string) $a['category'] );
		}

		if ( ! $show_button ) {
			add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		}
		unset( $GLOBALS['nkzmp_force_shop_loop'] );

		return $out;
	}

	/**
	 * Tlačítko „Vidět vše" pod gridem – vede do obchodu. Když je grid omezený
	 * na kategorii, odkaz vede rovnou na tu kategorii.
	 *
	 * @param string $url      Vlastní URL (prázdné = obchod).
	 * @param string $text     Vlastní text (prázdné = „Vidět vše").
	 * @param string $category Slug kategorie z gridu (volitelné).
	 */
	private function more_link( string $url, string $text, string $category = '' ): string {
		if ( '' === $url ) {
			// Kategorie má přednost – uživatel klikne na to, co vidí nad tlačítkem.
			if ( '' !== $category ) {
				$slug = trim( explode( ',', $category )[0] );
				$term = $slug !== '' ? get_term_by( 'slug', $slug, 'product_cat' ) : null;
				if ( $term && ! is_wp_error( $term ) ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$url = (string) $link;
					}
				}
			}
			if ( '' === $url ) {
				$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
				$url  = $shop ?: home_url( '/obchod/' );
			}
		}
		$url  = (string) apply_filters( 'nkzmp/v1/storefront/latest_more_url', $url, $category );
		$text = $text !== '' ? $text : __( 'Vidět vše', 'nkz-mp-storefront' );

		return sprintf(
			'<div class="nkzmp-latest-more"><a class="nkzmp-latest-more__link" href="%s"><span>%s</span>'
			. '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" aria-hidden="true">'
			. '<path d="M5 12h14M13 6l6 6-6 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
			. '</svg></a></div>',
			esc_url( $url ),
			esc_html( $text )
		);
	}

	/**
	 * @param array<string,string> $atts
	 */
	public function product_categories( $atts = [] ): string {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		$a = shortcode_atts( [
			'limit'              => '6',
			'columns'            => '4',
			'parent'             => '0',
			'orderby'            => 'name',
			'order'              => 'ASC',
			'hide_empty'         => 'yes',
			'ids'                => '',
			'exclude'            => '',
			'show_count'         => 'no',
			'hide_uncategorized' => 'yes',
			'color'              => '',
		], (array) $atts, 'nkzmp_product_categories' );

		$hide_empty   = in_array( strtolower( (string) $a['hide_empty'] ), [ 'yes', 'true', '1' ], true ) ? '1' : '0';
		$show_count   = in_array( strtolower( (string) $a['show_count'] ), [ 'yes', 'true', '1' ], true );
		$hide_uncat   = in_array( strtolower( (string) $a['hide_uncategorized'] ), [ 'yes', 'true', '1' ], true );

		$columns = max( 1, (int) $a['columns'] );

		// Exclude Uncategorized – zkusime vic zdroju (option, slug, nazev).
		$exclude_ids = [];
		if ( $hide_uncat ) {
			$uncat_id = (int) get_option( 'default_product_cat' );
			if ( $uncat_id > 0 ) {
				$exclude_ids[] = $uncat_id;
			}
			foreach ( [ 'uncategorized', 'nezarazene', 'nezarazeno' ] as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$exclude_ids[] = (int) $term->term_id;
				}
			}
			foreach ( [ 'Uncategorized', 'Nezařazené', 'Nezařazeno' ] as $name ) {
				$term = get_term_by( 'name', $name, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$exclude_ids[] = (int) $term->term_id;
				}
			}
		}
		// Manualni exclude z atributu.
		if ( $a['exclude'] !== '' ) {
			foreach ( explode( ',', (string) $a['exclude'] ) as $id ) {
				$id = (int) trim( $id );
				if ( $id > 0 ) {
					$exclude_ids[] = $id;
				}
			}
		}
		$exclude_ids  = array_values( array_unique( array_filter( $exclude_ids ) ) );

		$inner = sprintf(
			'[product_categories number="%d" columns="%d" parent="%s" orderby="%s" order="%s" hide_empty="%s"%s]',
			(int) $a['limit'],
			$columns,
			esc_attr( $a['parent'] ),
			esc_attr( $a['orderby'] ),
			esc_attr( $a['order'] ),
			$hide_empty,
			$a['ids'] !== '' ? ' ids="' . esc_attr( $a['ids'] ) . '"' : ''
		);

		Assets::ensure_storefront_css();
		Assets::ensure_wc_css();

		// WC [product_categories] NEpodporuje exclude atribut, takze ho injektneme
		// pres woocommerce_product_categories_shortcode_args filter.
		$exclude_filter = null;
		if ( $exclude_ids ) {
			$exclude_filter = function ( array $args ) use ( $exclude_ids ): array {
				$existing = ! empty( $args['exclude'] ) ? (array) $args['exclude'] : [];
				$args['exclude'] = array_values( array_unique( array_merge( $existing, $exclude_ids ) ) );
				return $args;
			};
			add_filter( 'woocommerce_product_categories_shortcode_args', $exclude_filter );
		}

		// Schovat (count) za nazvem.
		if ( ! $show_count ) {
			add_filter( 'woocommerce_subcategory_count_html', '__return_empty_string', 99 );
		}

		$GLOBALS['nkzmp_force_shop_loop'] = true;
		$uid = 'nkzmp-cats-' . wp_unique_id();
		$style = self::grid_style_for( $uid, $columns, (string) $a['color'] );
		$rendered = do_shortcode( $inner );
		// Belt-and-suspenders: i kdyby WC filter selhal, smazneme <li> obsahujici excludovane kategorie podle nazvu termu.
		if ( $exclude_ids ) {
			$excl_names = [];
			foreach ( $exclude_ids as $eid ) {
				$t = get_term( $eid, 'product_cat' );
				if ( $t && ! is_wp_error( $t ) ) {
					$excl_names[] = $t->name;
				}
			}
			$rendered = self::strip_categories_by_name( $rendered, $excl_names );
		}
		$debug = sprintf( '<!-- nkzmp_product_categories v%s exclude_ids=%s -->', NKZMP_STOREFRONT_VERSION, implode( ',', $exclude_ids ) );
		$out = $debug . $style . '<div id="' . esc_attr( $uid ) . '" class="woocommerce nkzmp-product-categories">' . $rendered . '</div>';
		unset( $GLOBALS['nkzmp_force_shop_loop'] );

		if ( ! $show_count ) {
			remove_filter( 'woocommerce_subcategory_count_html', '__return_empty_string', 99 );
		}
		if ( $exclude_filter ) {
			remove_filter( 'woocommerce_product_categories_shortcode_args', $exclude_filter );
		}

		return $out;
	}

	/**
	 * Vystrihne <li> kategoriove karty, ktere maji <h2> textovou shodou s nazvem v $names.
	 * Pouziva DOMDocument – odolne vuci ruzne strukture WC sablon.
	 */
	private static function strip_categories_by_name( string $html, array $names ): string {
		if ( empty( $names ) || trim( $html ) === '' ) {
			return $html;
		}
		$names_lc = array_map( static fn( $n ) => mb_strtolower( trim( $n ) ), $names );
		$prev = libxml_use_internal_errors( true );
		$doc  = new \DOMDocument( '1.0', 'UTF-8' );
		// Wrapper + meta encoding hint, jinak DOMDocument scrambluje UTF-8.
		$doc->loadHTML( '<?xml encoding="UTF-8"?><div id="nkzmp-wrap">' . $html . '</div>' );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );

		$xpath = new \DOMXPath( $doc );
		$titles = $xpath->query( '//li[contains(@class,"product-category")]//*[self::h2 or self::h3 or self::span][contains(concat(" ",normalize-space(@class)," ")," woocommerce-loop-category__title ")]' );
		if ( ! $titles ) {
			return $html;
		}
		$removed = false;
		foreach ( $titles as $t ) {
			$text = mb_strtolower( trim( $t->textContent ) );
			// Strip pripadny "(count)" suffix, kdyby uzivatel show_count=yes.
			$text = preg_replace( '/\s*\(\d+\)\s*$/u', '', $text );
			if ( in_array( $text, $names_lc, true ) ) {
				$li = $t;
				while ( $li && strtolower( $li->nodeName ) !== 'li' ) {
					$li = $li->parentNode;
				}
				if ( $li && $li->parentNode ) {
					$li->parentNode->removeChild( $li );
					$removed = true;
				}
			}
		}
		if ( ! $removed ) {
			return $html;
		}
		$wrap = $doc->getElementById( 'nkzmp-wrap' );
		if ( ! $wrap ) {
			return $html;
		}
		$out = '';
		foreach ( $wrap->childNodes as $child ) {
			$out .= $doc->saveHTML( $child );
		}
		return $out;
	}

	private static function grid_style_for( string $uid, int $columns, string $color = '' ): string {
		$mobile = max( 1, min( 2, $columns ) );
		$tablet = max( 1, min( 3, $columns ) );
		$color_css = '';
		if ( $color !== '' ) {
			$color_css = sprintf(
				'#%1$s ul.products li a,#%1$s ul.products li a *{color:%2$s!important;}',
				esc_attr( $uid ),
				esc_attr( $color )
			);
		} else {
			// Default: zdedit od kontextu (zabrani ruzovemu Elementor brand color).
			$color_css = sprintf(
				'#%1$s ul.products li a,#%1$s ul.products li a h2,#%1$s ul.products li a h3,#%1$s ul.products li a .woocommerce-loop-category__title{color:inherit;text-decoration:none;}',
				esc_attr( $uid )
			);
		}
		return sprintf(
			'<style>'
			. '#%1$s ul.products{display:grid!important;grid-template-columns:repeat(%2$d,minmax(0,1fr))!important;gap:24px!important;list-style:none!important;padding:0!important;margin:0!important;}'
			. '#%1$s ul.products li{width:auto!important;float:none!important;margin:0!important;clear:none!important;max-width:none!important;flex:none!important;}'
			. '%5$s'
			. '@media (max-width:980px){#%1$s ul.products{grid-template-columns:repeat(%3$d,minmax(0,1fr))!important;}}'
			. '@media (max-width:600px){#%1$s ul.products{grid-template-columns:repeat(%4$d,minmax(0,1fr))!important;}}'
			. '</style>',
			esc_attr( $uid ),
			$columns,
			$tablet,
			$mobile,
			$color_css
		);
	}
}
