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

		// Přepis ceny Zásilkovny (Packeta) na součet per-vendor sazeb.
		// Důvod: Packeta nabízí jednu fix sazbu pro celý košík, ale my máme
		// víc vendorů a každý posílá vlastní balík. Cenu rate nahradíme
		// součtem Rate::vendor_package_cost() přes všechny vendory v packagi.
		// Widget pro výběr výdejny zůstává funkční (rate zůstává viditelná).
		// Vypnutí: add_filter( 'nkzmp/v1/shipping/override_packeta', '__return_false' );
		add_filter( 'woocommerce_package_rates', [ $this, 'override_packeta_cost' ], 100, 2 );
	}

	/**
	 * Přepíše cost u všech rates, jejichž method_id obsahuje 'packet'
	 * (kryje 'packetery_shipping_method' i 'packeta'), na součet
	 * per-vendor sazeb v aktuálním packagi.
	 *
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array                           $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public function override_packeta_cost( array $rates, array $package ): array {
		if ( ! apply_filters( 'nkzmp/v1/shipping/override_packeta', true ) ) {
			return $rates;
		}
		$contents = $package['contents'] ?? [];
		if ( ! is_array( $contents ) || empty( $contents ) ) {
			return $rates;
		}

		// Seskup fyzické produkty per vendor.
		$by_vendor = [];
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( ! Rate::product_requires_shipping( $product ) ) {
				continue;
			}
			$pid = $product->get_parent_id() ?: $product->get_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			$by_vendor[ max( 0, $vid ) ][] = $product;
		}
		if ( empty( $by_vendor ) ) {
			return $rates;
		}

		$total = 0.0;
		foreach ( $by_vendor as $vid => $products ) {
			$total += Rate::vendor_package_cost( (int) $vid, $products );
		}

		foreach ( $rates as $id => $rate ) {
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			$mid = (string) $rate->get_method_id();
			if ( stripos( $mid, 'packet' ) === false ) {
				continue;
			}
			$rate->set_cost( (string) $total );
			// Vynuluj tax na rate – cena už je finální v Kč, daňové třídy
			// se nevyhodnocují přes per-vendor sazby (AOZ nezdaňuje dopravu).
			$rate->set_taxes( [] );
		}
		return $rates;
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
