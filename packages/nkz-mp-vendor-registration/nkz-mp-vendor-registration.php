<?php
/**
 * Plugin Name: NKZ Marketplace – Vendor Registration
 * Description: Frontend registrační formulář + 2-stage approval workflow + AOZ tone-of-voice e-maily. Závisí na nkz-marketplace core + nkz-mp-stripe adapter.
 * Version: 0.11.0
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-vendor-registration
 *
 * @package NKZMP\Registration
 */

defined( 'ABSPATH' ) || exit;

// Modul je nainstalovaný dvakrát (samostatný plugin i uvnitř balíku) – už
// běží jiná kopie. Druhou nespouštět: dvojí háčky by zdvojily i akce
// (převody, e-maily) a konstanty hlásí varování.
if ( defined( 'NKZMP_REGISTRATION_VERSION' ) ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> je nainstalovaný dvakrát – druhá kopie se nenačítá: <code>%s</code>. Deaktivuj a smaž ji v Pluginech (stačí jedna kopie, např. v balíku NKZ Marketplace).</p></div>',
				esc_html( 'NKZ Marketplace – Vendor Registration' ),
				esc_html( wp_normalize_path( __FILE__ ) )
			);
		}
	);
	return;
}

define( 'NKZMP_REGISTRATION_VERSION', '0.11.0' );
define( 'NKZMP_REGISTRATION_FILE', __FILE__ );
define( 'NKZMP_REGISTRATION_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_REGISTRATION_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Registration\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Registration\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_REGISTRATION_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
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
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return;
		}
		\NKZMP\Registration\Plugin::instance()->init();
	},
	25
);
