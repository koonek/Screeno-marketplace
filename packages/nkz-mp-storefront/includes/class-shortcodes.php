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
			'limit'    => '4',
			'columns'  => '4',
			'category' => '',
			'orderby'  => 'date',
			'order'    => 'DESC',
			'button'   => 'no',
		], (array) $atts, 'nkzmp_latest_products' );

		$show_button = in_array( strtolower( (string) $a['button'] ), [ 'yes', 'true', '1' ], true );

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

		if ( ! $show_button ) {
			add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart', 10 );
		}
		unset( $GLOBALS['nkzmp_force_shop_loop'] );

		return $out;
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
			'show_count'         => 'no',
			'hide_uncategorized' => 'yes',
		], (array) $atts, 'nkzmp_product_categories' );

		$hide_empty   = in_array( strtolower( (string) $a['hide_empty'] ), [ 'yes', 'true', '1' ], true ) ? '1' : '0';
		$show_count   = in_array( strtolower( (string) $a['show_count'] ), [ 'yes', 'true', '1' ], true );
		$hide_uncat   = in_array( strtolower( (string) $a['hide_uncategorized'] ), [ 'yes', 'true', '1' ], true );

		$columns = max( 1, (int) $a['columns'] );

		// Exclude Uncategorized (a libovolne ids z atributu).
		$exclude_ids = [];
		if ( $hide_uncat ) {
			$uncat_id = (int) get_option( 'default_product_cat' );
			if ( $uncat_id > 0 ) {
				$exclude_ids[] = $uncat_id;
			}
		}
		$exclude_attr = $exclude_ids ? ' exclude="' . esc_attr( implode( ',', $exclude_ids ) ) . '"' : '';

		$inner = sprintf(
			'[product_categories number="%d" columns="%d" parent="%s" orderby="%s" order="%s" hide_empty="%s"%s%s]',
			(int) $a['limit'],
			$columns,
			esc_attr( $a['parent'] ),
			esc_attr( $a['orderby'] ),
			esc_attr( $a['order'] ),
			$hide_empty,
			$a['ids'] !== '' ? ' ids="' . esc_attr( $a['ids'] ) . '"' : '',
			$exclude_attr
		);

		Assets::ensure_storefront_css();
		Assets::ensure_wc_css();

		// Schovat (count) za nazvem.
		if ( ! $show_count ) {
			add_filter( 'woocommerce_subcategory_count_html', '__return_empty_string', 99 );
		}

		$GLOBALS['nkzmp_force_shop_loop'] = true;
		$uid = 'nkzmp-cats-' . wp_unique_id();
		$style = self::grid_style_for( $uid, $columns );
		$out = $style . '<div id="' . esc_attr( $uid ) . '" class="woocommerce nkzmp-product-categories">' . do_shortcode( $inner ) . '</div>';
		unset( $GLOBALS['nkzmp_force_shop_loop'] );

		if ( ! $show_count ) {
			remove_filter( 'woocommerce_subcategory_count_html', '__return_empty_string', 99 );
		}

		return $out;
	}

	private static function grid_style_for( string $uid, int $columns ): string {
		$mobile = max( 1, min( 2, $columns ) );
		$tablet = max( 1, min( 3, $columns ) );
		return sprintf(
			'<style>'
			. '#%1$s ul.products{display:grid;grid-template-columns:repeat(%2$d,minmax(0,1fr));gap:24px;list-style:none;padding:0;margin:0;}'
			. '#%1$s ul.products li{width:auto!important;float:none!important;margin:0!important;clear:none!important;}'
			. '@media (max-width:980px){#%1$s ul.products{grid-template-columns:repeat(%3$d,minmax(0,1fr));}}'
			. '@media (max-width:600px){#%1$s ul.products{grid-template-columns:repeat(%4$d,minmax(0,1fr));}}'
			. '</style>',
			esc_attr( $uid ),
			$columns,
			$tablet,
			$mobile
		);
	}
}
