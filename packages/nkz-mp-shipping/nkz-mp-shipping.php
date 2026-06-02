<?php
/**
 * Plugin Name: NKZ Marketplace – Shipping
 * Description: Per-vendor paušální doprava. Pro každého prodejce v košíku s fyzickým produktem se přičte jeho paušál. Digital produkty dopravu nevyžadují.
 * Version: 0.2.3
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-shipping
 *
 * @package NKZMP\Shipping
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_SHIPPING_VERSION', '0.2.3' );
define( 'NKZMP_SHIPPING_FILE', __FILE__ );
define( 'NKZMP_SHIPPING_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_SHIPPING_URL', plugin_dir_url( __FILE__ ) );

// Meta klíče (sdílené s vendor-dashboard / product editorem).
define( 'NKZMP_SHIPPING_VENDOR_RATE_META', '_nkzmp_shipping_flat' );
define( 'NKZMP_SHIPPING_PRODUCT_REQUIRES_META', '_nkzmp_requires_shipping' );
// Volitelný per-produkt override poštovného (přebije vendor paušál).
define( 'NKZMP_SHIPPING_PRODUCT_OVERRIDE_META', '_nkzmp_shipping_override' );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Shipping\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Shipping\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_SHIPPING_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\NKZMP\Shipping\Plugin::instance()->init();
	},
	30
);

// Registrace WC shipping method.
add_filter(
	'woocommerce_shipping_methods',
	static function ( array $methods ): array {
		$methods['nkzmp_vendor_shipping'] = \NKZMP\Shipping\Method::class;
		return $methods;
	}
);
