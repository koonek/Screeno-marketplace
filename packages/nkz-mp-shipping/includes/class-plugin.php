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

		// AOZ jede pouze Zásilkovnu – per-vendor metoda se v praxi nepoužívá.
		// Filter je proto defaultně VYPNUTÝ. Pokud někdo do zóny per-vendor
		// metodu přidá a chce ji exkluzivně, zapne:
		// add_filter( 'nkzmp/v1/shipping/force_exclusive', '__return_true' );
		add_filter( 'woocommerce_package_rates', [ $this, 'force_exclusive' ], 99, 2 );
	}

	/**
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array                           $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public function force_exclusive( array $rates, array $package ): array {
		if ( ! apply_filters( 'nkzmp/v1/shipping/force_exclusive', false ) ) {
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
