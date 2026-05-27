<?php
/**
 * Plugin Name: NKZ Marketplace – Vendor Dashboard
 * Description: Frontend vendor dashboard (rozšíření WC My Account). Vendoři vidí přehled, produkty, payouty pod /muj-ucet/. Vendor role je přesměrována z wp-admin na frontend.
 * Version: 0.9.1
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-vendor-dashboard
 *
 * @package NKZMP\Dashboard
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_DASHBOARD_VERSION', '0.9.1' );
define( 'NKZMP_DASHBOARD_FILE', __FILE__ );
define( 'NKZMP_DASHBOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_DASHBOARD_URL', plugin_dir_url( __FILE__ ) );

// HPOS compatibility (modul čte/píše objednávky přes WC_Order API).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Dashboard\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Dashboard\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_DASHBOARD_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\NKZMP\Dashboard\Endpoints::register_rewrites();
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return;
		}
		\NKZMP\Dashboard\Plugin::instance()->init();
	},
	30
);
