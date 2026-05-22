<?php
/**
 * Plugin Name: NKZ Marketplace
 * Description: Marketplace jádro – vendor model, product ownership, allocation service, ledger, payout state machine. PSP integrace přes samostatné adaptéry (např. nkz-mp-stripe).
 * Version: 0.10.0-dev
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-marketplace
 *
 * @package NKZMP
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_VERSION', '0.10.0-dev' );
define( 'NKZMP_PLUGIN_FILE', __FILE__ );
define( 'NKZMP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Top-level admin menu slug. Sub-moduly (storefront, registration, …) ho
// používají jako parent v add_submenu_page() místo 'woocommerce'.
define( 'NKZMP_ADMIN_MENU_SLUG', 'nkz-marketplace' );

// HPOS compatibility.
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

// PSR-4 autoloader for NKZMP\ namespace → /includes/class-{kebab}.php
spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_PLUGIN_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

require_once NKZMP_PLUGIN_DIR . 'includes/helpers.php';

register_activation_hook(
	__FILE__,
	static function (): void {
		\NKZMP\Vendor\Registry::install_role();
		\NKZMP\Ledger\Schema::install();
		\NKZMP\Payout\Schema::install();
		\NKZMP\Audit\Schema::install();
		// Reconcile baseline: ignoruj PSP transfery starší než první aktivace.
		if ( ! get_option( 'nkzmp_reconcile_baseline_ts' ) ) {
			update_option( 'nkzmp_reconcile_baseline_ts', time(), false );
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		\NKZMP\Reconciliation\Cron::unschedule();
		flush_rewrite_rules();
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'NKZ Marketplace vyžaduje aktivní WooCommerce.', 'nkz-marketplace' ) . '</p></div>';
				}
			);
			return;
		}
		\NKZMP\Plugin::instance()->init();
	}
);
