<?php
/**
 * Plugin Name: NKZ Marketplace – Storefront
 * Description: Vendor archive (`/vendors`) + single vendor pages (`/vendor/<slug>`) s product listingem. Závisí na nkz-marketplace core.
 * Version: 0.10.0
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-storefront
 *
 * @package NKZMP\Storefront
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_STOREFRONT_VERSION', '0.10.0' );
define( 'NKZMP_STOREFRONT_FILE', __FILE__ );
define( 'NKZMP_STOREFRONT_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_STOREFRONT_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Storefront\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Storefront\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_STOREFRONT_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

register_activation_hook(
	__FILE__,
	static function (): void {
		\NKZMP\Storefront\Rewrite::register_rules();
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
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'NKZ Marketplace Storefront vyžaduje WooCommerce.', 'nkz-mp-storefront' ) . '</p></div>';
			} );
			return;
		}
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			add_action( 'admin_notices', static function () {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'NKZ Marketplace Storefront vyžaduje aktivní plugin nkz-marketplace.', 'nkz-mp-storefront' ) . '</p></div>';
			} );
			return;
		}
		\NKZMP\Storefront\Plugin::instance()->init();
	},
	20
);
