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
		Webhook_Controller::instance()->init();
		Checkout_Guard::instance()->init();
		Cron_Sync::instance()->init();
		Elementor_Integration::instance()->init();
		Elementor_Dynamic_Tags::instance()->init();

		// Flush rewrite rules once after the vendor `/vendor/<slug>/` permalink
		// was introduced/changed, so pretty URLs work after a plugin file update
		// without anyone re-saving Settings → Permalinks. Runs after CPT registration.
		add_action( 'init', [ $this, 'maybe_flush_rewrites' ], 99 );

		// Domain / service layer hooks.
		Transfer_Service::instance()->init();
		Refund_Service::instance()->init();

		load_plugin_textdomain( 'nkz-woo-stripe-vendor-split', false, dirname( plugin_basename( NKVSVS_PLUGIN_FILE ) ) . '/languages' );

		// Reconciliation driver pro core (registruje se jen pokud je core přítomné).
		if ( interface_exists( \NKZMP\Reconciliation\SourceDriver::class ) ) {
			add_filter(
				'nkzmp/v1/reconciliation/drivers',
				static function ( array $drivers ): array {
					$drivers[ Reconciliation_Driver::ADAPTER_NAME ] = new Reconciliation_Driver();
					return $drivers;
				}
			);
		}
	}

	/**
	 * One-shot rewrite flush keyed on the plugin version. The vendor CPT is
	 * registered at the default `init` priority (10); this runs at 99 so the
	 * new rewrite rules already exist when we flush.
	 */
	public function maybe_flush_rewrites(): void {
		if ( get_option( 'nkv_svs_rewrite_version' ) === NKVSVS_VERSION ) {
			return;
		}
		flush_rewrite_rules( false );
		update_option( 'nkv_svs_rewrite_version', NKVSVS_VERSION );
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
			'deduct_stripe_fee_from_vendor'    => 'no', // legacy, kept for back-compat
			'stripe_fee_vendor_share_percent'  => 0,    // 0 = platform pays all, 50 = half, 100 = vendor pays all
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
