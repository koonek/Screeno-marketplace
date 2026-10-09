<?php
/**
 * Plugin Name: NKZ Marketplace – Invoices
 * Description: Samofakturace: po zaplacení vystaví doklad za každého prodejce (jeho jménem, na základě zmocnění) a za Art of život (doprava, servisní poplatek). PDF do e-mailu, ke stažení v účtu, dobropisy při refundaci.
 * Version: 1.7.1
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-invoices
 *
 * @package NKZMP\Invoices
 */

defined( 'ABSPATH' ) || exit;

// Modul je nainstalovaný dvakrát (samostatný plugin i uvnitř balíku) – už
// běží jiná kopie. Druhou nespouštět: dvojí háčky by zdvojily i akce
// (převody, e-maily) a konstanty hlásí varování.
if ( defined( 'NKZMP_INVOICES_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> je nainstalovaný dvakrát – druhá kopie se nenačítá: <code>%s</code>. Deaktivuj a smaž ji v Pluginech (stačí jedna kopie, např. v balíku NKZ Marketplace).</p></div>',
				esc_html( 'NKZ Marketplace – Invoices' ),
				esc_html( wp_normalize_path( __FILE__ ) )
			);
		}
	);
	return;
}

define( 'NKZMP_INVOICES_VERSION', '1.7.1' );
define( 'NKZMP_INVOICES_FILE', __FILE__ );
define( 'NKZMP_INVOICES_DIR', plugin_dir_path( __FILE__ ) );

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
		if ( ! str_starts_with( $class, 'NKZMP\\Invoices\\' ) ) {
			return;
		}
		$file = substr( $class, strlen( 'NKZMP\\Invoices\\' ) );
		$file = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path = NKZMP_INVOICES_DIR . 'includes/' . $file;
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
		\NKZMP\Invoices\Plugin::instance()->init();
	},
	40
);
