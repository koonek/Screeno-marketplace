<?php
/**
 * Plugin Name: NKZ Marketplace – Platform Fee
 * Description: 1% servisní poplatek z mezisoučtu produktů, min 5 Kč. Platí kupující, jde celý platformě. Konfigurovatelné přes filtry.
 * Version: 0.2.0
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

define( 'NKZMP_PLATFORM_FEE_VERSION', '0.2.3' );

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
	// WC Blocks (Cart/Checkout) – server-side filter se nepoužije, tooltip nalepíme JSkem v DOM.
	add_action( 'wp_enqueue_scripts', 'nkzmp_platform_fee_enqueue_tooltip_js' );
}, 20 );

/**
 * Tooltip JS+CSS pro WC Blocks rendering. Skript je inline a běží na
 * frontendu (idempotentní – pokud na stránce není fee row, nic neudělá).
 * Použivá CSS popover místo nativního title – konzistentní vzhled napříč
 * prohlížeči, nepřekrývá další obsah, lépe vypadá.
 */
function nkzmp_platform_fee_enqueue_tooltip_js(): void {
	if ( is_admin() ) {
		return;
	}
	$label   = (string) apply_filters(
		'nkzmp/v1/platform_fee/label',
		__( 'Servisní poplatek', 'nkz-mp-platform-fee' )
	);
	$tooltip = nkzmp_platform_fee_tooltip_text();
	if ( '' === $tooltip ) {
		return;
	}
	$data = wp_json_encode( [
		'label'   => $label,
		'tooltip' => $tooltip,
	] );
	$css = '
.nkzmp-fee-tooltip{position:relative;display:inline-block;width:16px;height:16px;line-height:16px;text-align:center;border-radius:50%;background:#e6e6e6;color:#333;font-size:11px;font-weight:600;cursor:help;vertical-align:middle;margin-left:6px;font-family:Georgia,serif;font-style:italic;outline:none;flex:0 0 auto;}
.nkzmp-fee-tooltip:hover,.nkzmp-fee-tooltip:focus{background:#d0d0d0;}
.nkzmp-fee-tooltip::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 10px);left:50%;transform:translateX(-50%);background:#1a1a1a;color:#fff;padding:10px 14px;border-radius:6px;font-size:12px;font-weight:400;font-style:normal;font-family:inherit;line-height:1.45;white-space:normal;width:260px;text-align:left;opacity:0;visibility:hidden;pointer-events:none;transition:opacity .15s ease,visibility .15s ease;z-index:99999;box-shadow:0 4px 14px rgba(0,0,0,.15);}
.nkzmp-fee-tooltip::before{content:"";position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);border:6px solid transparent;border-top-color:#1a1a1a;opacity:0;visibility:hidden;transition:opacity .15s ease,visibility .15s ease;z-index:99999;}
.nkzmp-fee-tooltip:hover::after,.nkzmp-fee-tooltip:focus::after,.nkzmp-fee-tooltip:hover::before,.nkzmp-fee-tooltip:focus::before{opacity:1;visibility:visible;}
/* Responsive table pattern: label je z ::before na td[data-title]. Ikona jako prvni dite TD se posune pres margin-right:auto, aby sedela vedle pseudo-labelu a cena zustala vpravo. */
td[data-title] > .nkzmp-fee-tooltip:first-child{margin-left:8px;margin-right:auto;}
@media (max-width:600px){.nkzmp-fee-tooltip::after{width:200px;left:auto;right:-8px;transform:none;}.nkzmp-fee-tooltip::before{left:auto;right:0;transform:none;}}
';
	$js = <<<JS
(function(){
	var cfg = $data;
	function buildIcon(){
		var i = document.createElement('span');
		i.className = 'nkzmp-fee-tooltip';
		i.setAttribute('tabindex','0');
		i.setAttribute('role','button');
		i.setAttribute('aria-label', cfg.tooltip);
		i.setAttribute('data-tip', cfg.tooltip);
		i.setAttribute('data-nkzmp-fee', '1');
		i.textContent = 'i';
		return i;
	}
	function alreadyDecorated(el){
		return el.querySelector && el.querySelector('[data-nkzmp-fee]');
	}
	function decorate(){
		// 1) WC Blocks (modern Checkout)
		document.querySelectorAll('.wc-block-components-totals-item__label').forEach(function(el){
			if (el.textContent.trim() === cfg.label && !alreadyDecorated(el)) {
				el.appendChild(buildIcon());
			}
		});
		// 2) Classic shortcode
		document.querySelectorAll('.cart_totals .fee th, .woocommerce-checkout-review-order-table .fee th').forEach(function(el){
			if (el.textContent.trim() === cfg.label && !alreadyDecorated(el)) {
				el.appendChild(buildIcon());
			}
		});
		// 3) Responsive table pattern (AOZ cart): label je z ::before via data-title.
		// Vlozime ikonu jako prvni dite TD, CSS pak margin-right:auto.
		document.querySelectorAll('td[data-title]').forEach(function(el){
			if (alreadyDecorated(el)) return;
			var t = (el.getAttribute('data-title') || '').trim();
			if (t !== cfg.label) return;
			el.insertBefore(buildIcon(), el.firstChild);
		});
		// 4) Generic fallback – custom theme / Elementor.
		// Najde leaf element jehoz vlastni textContent presne == label.
		document.querySelectorAll('span, div, th, td, p, dt, dd, label, strong').forEach(function(el){
			if (alreadyDecorated(el)) return;
			if (el.children.length !== 0) return;
			if (el.textContent.trim() !== cfg.label) return;
			el.appendChild(buildIcon());
		});
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', decorate);
	} else {
		decorate();
	}
	var scheduled = false;
	var mo = new MutationObserver(function(){
		if (scheduled) return;
		scheduled = true;
		requestAnimationFrame(function(){ scheduled = false; decorate(); });
	});
	mo.observe(document.body, { childList: true, subtree: true });
})();
JS;
	wp_register_style( 'nkzmp-platform-fee-tooltip', false, [], NKZMP_PLATFORM_FEE_VERSION );
	wp_enqueue_style( 'nkzmp-platform-fee-tooltip' );
	wp_add_inline_style( 'nkzmp-platform-fee-tooltip', $css );
	wp_register_script( 'nkzmp-platform-fee-tooltip', '', [], NKZMP_PLATFORM_FEE_VERSION, true );
	wp_enqueue_script( 'nkzmp-platform-fee-tooltip' );
	wp_add_inline_script( 'nkzmp-platform-fee-tooltip', $js );
}

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
