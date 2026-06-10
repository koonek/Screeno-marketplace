<?php
/**
 * Plugin Name: NKZ Marketplace – Platform Fee
 * Description: 1% servisní poplatek z mezisoučtu produktů, min 5 Kč. Platí kupující, jde celý platformě. Konfigurovatelné přes filtry.
 * Version: 0.1.0
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-platform-fee
 *
 * Defaults:
 *  - 1 % z mezisoučtu produktů (cart subtotal bez dopravy a daně)
 *  - minimum 5 Kč
 *  - bez DPH
 *  - název pro zákazníka: „Servisní poplatek"
 *
 * Per-brand override (Screeno, jiný klient):
 *   add_filter( 'nkzmp/v1/platform_fee/percent',   fn() => 1.5 );
 *   add_filter( 'nkzmp/v1/platform_fee/min',       fn() => 10 );
 *   add_filter( 'nkzmp/v1/platform_fee/taxable',   '__return_true' );
 *   add_filter( 'nkzmp/v1/platform_fee/label',     fn() => 'Poplatek platformy' );
 *   add_filter( 'nkzmp/v1/platform_fee/enabled',   '__return_false' ); // vypnout celý modul
 *
 * Pozn k Stripe distribuci: fee se připočte k cart total a buyer ho zaplatí.
 * Pokud Stripe split routuje peníze rovnou vendorovi (Stripe Connect destination
 * charge), musí stripe adapter zvýšit application_fee_amount o tuhle částku,
 * jinak ji vendor obdrží spolu se svým payoutem. Hook bod: filter
 * `nkzmp/v1/stripe/application_fee_amount` (TODO: doplnit ve stripe modulu).
 *
 * @package NKZMP\PlatformFee
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_PLATFORM_FEE_VERSION', '0.1.0' );

add_action( 'plugins_loaded', static function (): void {
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}
	if ( ! apply_filters( 'nkzmp/v1/platform_fee/enabled', true ) ) {
		return;
	}
	add_action( 'woocommerce_cart_calculate_fees', 'nkzmp_platform_fee_apply' );
}, 20 );

/**
 * Připočte servisní poplatek do košíku.
 *
 * @param \WC_Cart $cart
 */
function nkzmp_platform_fee_apply( \WC_Cart $cart ): void {
	if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		return;
	}

	$subtotal = (float) $cart->get_subtotal(); // mezisoučet produktů bez dopravy/daně
	if ( $subtotal <= 0 ) {
		return;
	}

	$percent = (float) apply_filters( 'nkzmp/v1/platform_fee/percent', 1.0 );
	$min     = (float) apply_filters( 'nkzmp/v1/platform_fee/min', 5.0 );
	$taxable = (bool)  apply_filters( 'nkzmp/v1/platform_fee/taxable', false );
	$label   = (string) apply_filters(
		'nkzmp/v1/platform_fee/label',
		__( 'Servisní poplatek', 'nkz-mp-platform-fee' )
	);

	$fee = round( $subtotal * ( $percent / 100 ), 2 );
	if ( $fee < $min ) {
		$fee = $min;
	}
	$fee = (float) apply_filters( 'nkzmp/v1/platform_fee/amount', $fee, $subtotal, $cart );
	if ( $fee <= 0 ) {
		return;
	}

	$cart->add_fee( $label, $fee, $taxable );
}
