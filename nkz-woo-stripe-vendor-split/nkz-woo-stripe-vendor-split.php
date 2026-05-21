<?php
/**
 * Plugin Name: NKZ Woo Stripe Vendor Split
 * Description: Rozdělení plateb mezi platformu a vendory přes Stripe Connect (separate charges & transfers).
 * Version: 0.6.0
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * WC tested up to: 9.4
 * Text Domain: nkz-woo-stripe-vendor-split
 *
 * @package NKVSVS
 */

defined( 'ABSPATH' ) || exit;

define( 'NKVSVS_VERSION', '0.6.0' );
define( 'NKVSVS_PLUGIN_FILE', __FILE__ );
define( 'NKVSVS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKVSVS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// HPOS compatibility declaration.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// Composer / bundled Stripe SDK autoload (only if present).
if ( file_exists( NKVSVS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once NKVSVS_PLUGIN_DIR . 'vendor/autoload.php';
}

// Internal autoloader.
spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'NKVSVS\\' ) ) {
			return;
		}
		$relative = strtolower( str_replace( [ 'NKVSVS\\', '_', '\\' ], [ '', '-', '/' ], $class ) );
		$file     = NKVSVS_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
		if ( file_exists( $file ) ) {
			require_once $file;
		}
	}
);

require_once NKVSVS_PLUGIN_DIR . 'includes/helpers.php';

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'NKZ Woo Stripe Vendor Split vyžaduje aktivní WooCommerce.', 'nkz-woo-stripe-vendor-split' ) .
						'</p></div>';
				}
			);
			return;
		}
		\NKVSVS\Plugin::instance()->init();
	}
);
