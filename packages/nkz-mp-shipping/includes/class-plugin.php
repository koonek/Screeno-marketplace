<?php
/**
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-shipping', false, dirname( plugin_basename( NKZMP_SHIPPING_FILE ) ) . '/languages' );

		Rate::instance(); // jen pro statické helpery, žádný init
		VendorRateAdmin::instance()->init();
		ProductShippingAdmin::instance()->init();
		Settings::instance()->init();

		// Když je v zóně dostupná naše per-vendor metoda, ostatní (Flat rate,
		// Free shipping, …) z dostupných sazeb odstraníme – per-vendor je
		// jediný model. Bez toho WC vybírá nejlevnější / první rate a souhrn
		// se rozjede s per-vendor čísly v cart UI.
		// Vypnutí: add_filter( 'nkzmp/v1/shipping/force_exclusive', '__return_false' );
		add_filter( 'woocommerce_package_rates', [ $this, 'force_exclusive' ], 99, 2 );
	}

	/**
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array                           $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public function force_exclusive( array $rates, array $package ): array {
		if ( ! apply_filters( 'nkzmp/v1/shipping/force_exclusive', true ) ) {
			return $rates;
		}
		$ours = [];
		foreach ( $rates as $id => $rate ) {
			if ( $rate instanceof \WC_Shipping_Rate && $rate->get_method_id() === 'nkzmp_vendor_shipping' ) {
				$ours[ $id ] = $rate;
			}
		}
		return ! empty( $ours ) ? $ours : $rates;
	}
}
