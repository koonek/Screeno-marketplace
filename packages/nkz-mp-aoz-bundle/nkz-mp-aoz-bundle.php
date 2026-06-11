<?php
/**
 * Plugin Name: NKZ Marketplace AOZ
 * Description: Kompletní bundle pro Art of život – core + Stripe adapter + storefront. Phase 1 add-ony (registration, billing, shipping) přibydou s upgrady.
 * Version: 0.29.5
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-marketplace-aoz
 *
 * Tento plugin je tenký wrapper kolem samostatných modulů v `modules/`.
 * Každý modul si registruje vlastní hooky standardně přes plugins_loaded.
 * Bundle's activation hook se postará o instalaci všech modulů najednou.
 *
 * @package NKZMP\AOZ
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_AOZ_BUNDLE_VERSION', '0.29.5' );
define( 'NKZMP_AOZ_BUNDLE_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_AOZ_BUNDLE_FILE', __FILE__ );

/*
 * Load modules in dependency order:
 *  1. core (nkz-marketplace)            – DB schémata, doménové třídy
 *  2. Stripe adapter                    – PSP integrace + reconciliation driver
 *  3. storefront                        – vendor pages, Elementor tags
 */
$nkzmp_aoz_modules = [
	'modules/nkz-marketplace/nkz-marketplace.php',
	'modules/nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php',
	'modules/nkz-mp-storefront/nkz-mp-storefront.php',
	'modules/nkz-mp-vendor-registration/nkz-mp-vendor-registration.php',
	'modules/nkz-mp-vendor-dashboard/nkz-mp-vendor-dashboard.php',
	'modules/nkz-mp-shipping/nkz-mp-shipping.php',
	'modules/nkz-mp-vendor-billing/nkz-mp-vendor-billing.php',
	'modules/nkz-mp-packeta/nkz-mp-packeta.php',
	'modules/nkz-mp-platform-fee/nkz-mp-platform-fee.php',
];

foreach ( $nkzmp_aoz_modules as $relative ) {
	$path = NKZMP_AOZ_BUNDLE_DIR . $relative;
	if ( is_readable( $path ) ) {
		require_once $path;
	}
}

/*
 * Single activation hook — provede aktivaci všech modulů. WP nevolá
 * register_activation_hook v sub-plugin souborech (ty jsou jen include),
 * takže to musíme udělat ručně. Vše idempotentní – schema versioning
 * v core zajistí že opakovaná aktivace neudělá nic.
 */
register_activation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \NKZMP\Vendor\Registry::class ) ) {
			\NKZMP\Vendor\Registry::install_role();
		}
		if ( class_exists( \NKZMP\Ledger\Schema::class ) ) {
			\NKZMP\Ledger\Schema::install();
		}
		if ( class_exists( \NKZMP\Payout\Schema::class ) ) {
			\NKZMP\Payout\Schema::install();
		}
		if ( class_exists( \NKZMP\Audit\Schema::class ) ) {
			\NKZMP\Audit\Schema::install();
		}
		if ( ! get_option( 'nkzmp_reconcile_baseline_ts' ) ) {
			update_option( 'nkzmp_reconcile_baseline_ts', time(), false );
		}
		if ( class_exists( \NKZMP\Storefront\Rewrite::class ) ) {
			\NKZMP\Storefront\Rewrite::register_rules();
		}
		if ( class_exists( \NKZMP\Dashboard\Endpoints::class ) ) {
			\NKZMP\Dashboard\Endpoints::register_rewrites();
		}
		if ( class_exists( \NKZMP\Billing\AccountSection::class ) ) {
			\NKZMP\Billing\AccountSection::instance()->add_endpoint();
		}
		flush_rewrite_rules();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( class_exists( \NKZMP\Reconciliation\Cron::class ) ) {
			\NKZMP\Reconciliation\Cron::unschedule();
		}
		if ( class_exists( \NKZMP\Billing\GraceCron::class ) ) {
			\NKZMP\Billing\GraceCron::unschedule();
		}
		flush_rewrite_rules();
	}
);
