<?php
/**
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-vendor-billing', false, dirname( plugin_basename( NKZMP_BILLING_FILE ) ) . '/languages' );

		Settings::instance()->init();
		WebhookController::instance()->init();
		Enforcement::instance()->init();
		AccountSection::instance()->init();
		Checkout::instance()->init();
		AdminOverview::instance()->init();
		VendorMetaBox::instance()->init();
		GraceCron::instance()->init();
		HealthChecks::instance()->init();
	}
}
