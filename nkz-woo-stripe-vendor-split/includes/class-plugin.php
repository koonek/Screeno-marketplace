<?php
/**
 * Plugin bootstrap.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	private function __construct() {}

	public function init(): void {
		// Admin layers.
		Vendors::instance()->init();
		Product_Fields::instance()->init();
		Admin_Settings::instance()->init();
		Order_Meta_Box::instance()->init();
		Onboarding_Controller::instance()->init();

		// Domain / service layer hooks.
		Transfer_Service::instance()->init();
		Refund_Service::instance()->init();

		load_plugin_textdomain( 'nkz-woo-stripe-vendor-split', false, dirname( plugin_basename( NKVSVS_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Read settings as merged array with defaults.
	 */
	public static function settings(): array {
		$defaults = [
			'enabled'                          => 'no',
			'mode'                             => 'test',
			'default_fee_percent'              => 15.0,
			'split_includes_tax'               => 'yes',
			'split_includes_shipping'          => 'no',
			'deduct_coupons_proportionally'    => 'yes',
			'deduct_stripe_fee_from_vendor'    => 'no',
			'automatic_transfers'              => 'yes',
			'log_only_mode'                    => 'no',
			'minimum_transfer_amount'          => 1.0,
			'debug_logging'                    => 'no',
			'transfer_hook'                    => 'payment_complete',
			'require_currency_match'           => 'yes',
			'auto_reversal_on_full_refund'     => 'no',
		];
		$saved = get_option( 'nkv_svs_settings', [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public static function secret_key(): string {
		$s = self::settings();
		$opt = 'live' === $s['mode'] ? 'nkv_svs_secret_live' : 'nkv_svs_secret_test';
		return (string) get_option( $opt, '' );
	}

	public static function is_enabled(): bool {
		$s = self::settings();
		return 'yes' === $s['enabled'];
	}

	public static function is_dry_run(): bool {
		$s = self::settings();
		return 'yes' === $s['log_only_mode'] || 'no' === $s['automatic_transfers'];
	}
}
