<?php
/**
 * CartGrouping – Flér-style seskupení košíku/pokladny po prodejcích.
 *
 * Vizuální vrstva (nemění checkout logiku ani výpočet dopravy):
 *  - na každý cart/review řádek přidá třídu `nkzmp-vrow nkzmp-vrow-<id>`
 *  - do JS localizuje mapu { vendorId: name } pro prodejce v košíku
 *  - JS vloží hlavičku „Balíček od <name>" před první řádek skupiny
 *    a per-vendor mezisoučet za poslední; znovu se spustí na WC eventech
 *    (updated_cart_totals / updated_checkout).
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/cart_grouping', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class CartGrouping {

	private static ?CartGrouping $instance = null;

	public static function instance(): CartGrouping {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/cart_grouping', true ) ) {
			return;
		}
		add_filter( 'woocommerce_cart_item_class', [ $this, 'row_class' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_name', [ $this, 'tag_name' ], 10, 3 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
	}

	/**
	 * Přidá vendor třídu na <tr> řádku košíku (funguje i v review-order tabulce).
	 *
	 * @param string $class
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 */
	public function row_class( $class, $cart_item, $cart_item_key ): string {
		$vid = self::item_vendor_id( $cart_item );
		return trim( $class . ' nkzmp-vrow nkzmp-vrow-' . $vid );
	}

	/**
	 * Do jména produktu přidá neviditelný marker s vendor ID – review-order
	 * tabulka v pokladně nepoužívá woocommerce_cart_item_class, takže potřebujeme
	 * fallback čitelný z DOM i tam.
	 *
	 * @param string $name
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 */
	public function tag_name( $name, $cart_item, $cart_item_key ): string {
		$vid = self::item_vendor_id( $cart_item );
		return '<span class="nkzmp-vtag" data-vendor="' . esc_attr( (string) $vid ) . '" style="display:none"></span>' . $name;
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		// Mapa vendorId → název + fyzické produkty per vendor (pro výpočet dopravy).
		$names           = [ '0' => (string) apply_filters( 'nkzmp/v1/storefront/platform_label', __( 'Obchod', 'nkz-mp-storefront' ) ) ];
		$vendor_products = [];
		$has_shipping_mod = class_exists( \NKZMP\Shipping\Rate::class );
		foreach ( WC()->cart->get_cart() as $item ) {
			$vid = self::item_vendor_id( $item );
			if ( ! isset( $names[ (string) $vid ] ) && $vid > 0 ) {
				$title = get_the_title( $vid );
				$names[ (string) $vid ] = $title ?: ( '#' . $vid );
			}
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$needs = $has_shipping_mod ? \NKZMP\Shipping\Rate::product_requires_shipping( $product ) : true;
			if ( $needs ) {
				$vendor_products[ (string) $vid ][] = $product;
			}
		}

		// Per-vendor poštovné v kartách. Default VYPNUTO – AOZ jede pouze
		// Zásilkovnu (jeden poplatek za celý balík), takže per-vendor čísla
		// v kartách by uživatele jen mátla a nesedla by se souhrnem.
		// Zapnutí: add_filter( 'nkzmp/v1/cart/show_per_vendor_shipping', '__return_true' );
		$shipping = [];
		if ( $has_shipping_mod && apply_filters( 'nkzmp/v1/cart/show_per_vendor_shipping', false ) ) {
			foreach ( $vendor_products as $vid_str => $products ) {
				$cost = \NKZMP\Shipping\Rate::vendor_package_cost( (int) $vid_str, $products );
				if ( $cost > 0 ) {
					$shipping[ $vid_str ] = trim( html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ), ENT_QUOTES, 'UTF-8' ) );
				} else {
					$shipping[ $vid_str ] = (string) __( 'zdarma', 'nkz-mp-storefront' );
				}
			}
		}

		wp_register_script( 'nkz-mp-cart-grouping', NKZMP_STOREFRONT_URL . 'assets/cart-grouping.js', [], NKZMP_STOREFRONT_VERSION, true );
		wp_localize_script( 'nkz-mp-cart-grouping', 'nkzmpCartGroups', [
			'names'    => $names,
			'shipping' => $shipping,
			'i18n'     => [
				'package'  => __( 'Balíček od %s', 'nkz-mp-storefront' ),
				'subtotal' => __( 'Mezisoučet prodejce', 'nkz-mp-storefront' ),
				'shipping' => __( '+ Poštovné', 'nkz-mp-storefront' ),
			],
		] );
		wp_enqueue_script( 'nkz-mp-cart-grouping' );
	}

	/**
	 * Vendor ID z cart item (přes produkt meta, variace → parent).
	 *
	 * @param array $cart_item
	 */
	private static function item_vendor_id( $cart_item ): int {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		if ( $vid <= 0 && $product->get_id() !== $pid ) {
			$vid = (int) get_post_meta( $product->get_id(), '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $product->get_id(), '_nkv_vendor_id', true );
			}
		}
		return max( 0, $vid );
	}
}
