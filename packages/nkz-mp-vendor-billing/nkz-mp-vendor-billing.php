<?php
/**
 * Plugin Name: NKZ Marketplace – Vendor Billing
 * Description: Měsíční předplatné prodejců přes Stripe Billing (CZK, konfigurovatelná částka). Neplatící prodejce → suspended (produkty nedostupné). Závisí na core + Stripe adapteru.
 * Version: 0.7.0
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-vendor-billing
 *
 * @package NKZMP\Billing
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_BILLING_VERSION', '0.6.0' );
define( 'NKZMP_BILLING_FILE', __FILE__ );
define( 'NKZMP_BILLING_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_BILLING_URL', plugin_dir_url( __FILE__ ) );

// Meta klíče.
define( 'NKZMP_BILLING_CUSTOMER_META', '_nkzmp_billing_customer_id' );
define( 'NKZMP_BILLING_SUBSCRIPTION_META', '_nkzmp_billing_subscription_id' );
define( 'NKZMP_BILLING_STATUS_META', '_nkzmp_billing_status' ); // active|past_due|canceled|none
define( 'NKZMP_BILLING_AMOUNT_OVERRIDE_META', '_nkzmp_billing_amount_override' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Billing\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Billing\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_BILLING_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return;
		}
		\NKZMP\Billing\Plugin::instance()->init();
	},
	35
);
