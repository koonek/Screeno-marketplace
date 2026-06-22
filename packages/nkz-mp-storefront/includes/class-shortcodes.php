<?php
/**
 * Shortcodes pro vkládání marketplace komponent kdekoli na webu.
 *
 * [nkzmp_latest_products limit="4" columns="4" category="" button="no"]
 *   Grid nejnovějších produktů (orderby=date desc), stejný card markup jako
 *   /obchod/ – obrázek, název, „OD <prodejce>" badge, cena. Tlačítko
 *   „Přidat do košíku / Číst více" je default vypnuté (teaser na landingu).
 *
 * [nkzmp_product_categories limit="6" columns="4" parent="0" hide_empty="yes"]
 *   Grid kategorií produktů. Wrappuje WC builtin [product_categories] +
 *   vynuti storefront CSS i mimo /obchod/.
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
			'limit'      => '6',
			'columns'    => '4',
			'parent'     => '0',
			'orderby'    => 'name',
			'order'      => 'ASC',
			'hide_empty' => 'yes',
			'ids'        => '',
		], (array) $atts, 'nkzmp_product_categories' );

		$hide_empty = in_array( strtolower( (string) $a['hide_empty'] ), [ 'yes', 'true', '1' ], true ) ? '1' : '0';

		$inner = sprintf(
			'[product_categories number="%d" columns="%d" parent="%s" orderby="%s" order="%s" hide_empty="%s"%s]',
			(int) $a['limit'],
			(int) $a['columns'],
			esc_attr( $a['parent'] ),
			esc_attr( $a['orderby'] ),
			esc_attr( $a['order'] ),
			$hide_empty,
			$a['ids'] !== '' ? ' ids="' . esc_attr( $a['ids'] ) . '"' : ''
		);

		Assets::ensure_storefront_css();
		Assets::ensure_wc_css();

		$GLOBALS['nkzmp_force_shop_loop'] = true;
		$out = '<div class="woocommerce nkzmp-product-categories">' . do_shortcode( $inner ) . '</div>';
		unset( $GLOBALS['nkzmp_force_shop_loop'] );

		return $out;
	}
}
