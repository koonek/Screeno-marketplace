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
 * Stripe distribuce: nkz-mp-stripe používá separate transfers (platba na
 * platform account, pak Transfer_Service posílá vendorům jejich part).
 * Split_Calculator iteruje jen přes WC line_items, ne přes fees – proto tahle
 * WC fee row automaticky zůstává na platform účtu. Žádná Stripe úprava není
 * potřeba. (Pokud někdo někdy přejde na destination charges, je potřeba bumpnout
 * application_fee_amount o sumu fees.)
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
	add_filter( 'woocommerce_cart_totals_fee_label', 'nkzmp_platform_fee_tooltip_label', 10, 2 );
	add_action( 'woocommerce_order_refunded', 'nkzmp_platform_fee_on_refund', 10, 2 );
}, 20 );

/**
 * Po refundu připomene v order notes, že servisní poplatek zůstává platformě
 * (pokud ho admin do refundu neručně nezahrnul). Admin nic neztrácí, jen
 * dostane audit-friendly stopu.
 */
function nkzmp_platform_fee_on_refund( int $order_id, int $refund_id ): void {
	$order  = wc_get_order( $order_id );
	$refund = wc_get_order( $refund_id );
	if ( ! $order || ! $refund ) {
		return;
	}
	$label = (string) apply_filters(
		'nkzmp/v1/platform_fee/label',
		__( 'Servisní poplatek', 'nkz-mp-platform-fee' )
	);
	$order_fee = 0.0;
	foreach ( $order->get_fees() as $fee ) {
		if ( $fee->get_name() === $label ) {
			$order_fee += (float) $fee->get_total();
		}
	}
	if ( $order_fee <= 0 ) {
		return;
	}
	$refunded_fee = 0.0;
	foreach ( $refund->get_fees() as $fee ) {
		if ( $fee->get_name() === $label ) {
			$refunded_fee += abs( (float) $fee->get_total() );
		}
	}
	$remaining = $order_fee - $refunded_fee;
	if ( $remaining <= 0.005 ) {
		$order->add_order_note( sprintf(
			/* translators: %s = částka */
			__( 'Servisní poplatek (%s) byl plně refundován.', 'nkz-mp-platform-fee' ),
			wc_price( $order_fee, [ 'currency' => $order->get_currency() ] )
		) );
		return;
	}
	$order->add_order_note( sprintf(
		/* translators: 1: zůstávající fee 2: celkový fee */
		__( 'Pozn: Servisní poplatek %1$s (z %2$s) zůstává platformě. Pokud chcete refundovat i poplatek, ručně ho přidejte v dalším refundu.', 'nkz-mp-platform-fee' ),
		wc_price( $remaining, [ 'currency' => $order->get_currency() ] ),
		wc_price( $order_fee, [ 'currency' => $order->get_currency() ] )
	) );
}

/**
 * Vrátí text tooltipu (filtrovatelný).
 */
function nkzmp_platform_fee_tooltip_text(): string {
	return (string) apply_filters(
		'nkzmp/v1/platform_fee/tooltip',
		__( 'Drobný poplatek za provoz marketplace – pomáhá nám kurátorovat tvorbu prodejců a držet platformu v chodu.', 'nkz-mp-platform-fee' )
	);
}

/**
 * Přidá info ikonu s tooltipem za název fee v cart/checkout totals.
 *
 * @param string $label_html  Vyrenderovaný název fee (často už escapovaný).
 * @param object $fee         WC fee objekt (name, amount, …).
 */
function nkzmp_platform_fee_tooltip_label( string $label_html, $fee ): string {
	$label = (string) apply_filters(
		'nkzmp/v1/platform_fee/label',
		__( 'Servisní poplatek', 'nkz-mp-platform-fee' )
	);
	$fee_name = is_object( $fee ) && isset( $fee->name ) ? (string) $fee->name : '';
	if ( $fee_name !== $label ) {
		return $label_html;
	}
	$tooltip = nkzmp_platform_fee_tooltip_text();
	if ( '' === $tooltip ) {
		return $label_html;
	}
	$icon = sprintf(
		' <span class="nkzmp-fee-tooltip" tabindex="0" role="img" aria-label="%1$s" title="%1$s" style="display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%%;background:#e6e6e6;color:#333;font-size:11px;font-weight:600;cursor:help;vertical-align:middle;margin-left:4px;">i</span>',
		esc_attr( $tooltip )
	);
	return $label_html . $icon;
}

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
