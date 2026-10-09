<?php
/**
 * Plugin Name: NKZ Woo Stripe Vendor Split
 * Description: Rozdělení plateb mezi platformu a vendory přes Stripe Connect (separate charges & transfers).
 * Version: 0.15.2
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

// Modul je nainstalovaný dvakrát (samostatný plugin i uvnitř balíku) – už
// běží jiná kopie. Druhou nespouštět: dvojí háčky by zdvojily i akce
// (převody, e-maily) a konstanty hlásí varování.
if ( defined( 'NKVSVS_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> je nainstalovaný dvakrát – druhá kopie se nenačítá: <code>%s</code>. Deaktivuj a smaž ji v Pluginech (stačí jedna kopie, např. v balíku NKZ Marketplace).</p></div>',
				esc_html( 'NKZ Woo Stripe Vendor Split' ),
				esc_html( wp_normalize_path( __FILE__ ) )
			);
		}
	);
	return;
}

define( 'NKVSVS_VERSION', '0.15.2' );
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
