<?php
/**
 * WC Settings tab.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Admin_Settings {

	private const TAB = 'nkv_stripe_split';

	private static ?Admin_Settings $instance = null;
	public static function instance(): Admin_Settings { return self::$instance ??= new self(); }

	public function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', [ $this, 'register_tab' ], 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB, [ $this, 'render' ] );
		add_action( 'woocommerce_update_options_' . self::TAB, [ $this, 'save' ] );
	}

	public function register_tab( array $tabs ): array {
		$tabs[ self::TAB ] = __( 'Stripe Vendor Split', 'nkz-woo-stripe-vendor-split' );
		return $tabs;
	}

	private function fields(): array {
		return [
			[ 'type' => 'title', 'title' => __( 'NKZ Stripe Vendor Split', 'nkz-woo-stripe-vendor-split' ), 'id' => 'nkv_svs_section' ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__enabled', 'title' => __( 'Enable plugin', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'select', 'id' => 'nkv_svs__mode', 'title' => __( 'Mode', 'nkz-woo-stripe-vendor-split' ), 'options' => [ 'test' => 'Test', 'live' => 'Live' ] ],
			[ 'type' => 'password', 'id' => 'nkv_svs_secret_test', 'title' => __( 'Stripe secret key (test)', 'nkz-woo-stripe-vendor-split' ), 'autoload' => false ],
			[ 'type' => 'password', 'id' => 'nkv_svs_secret_live', 'title' => __( 'Stripe secret key (live)', 'nkz-woo-stripe-vendor-split' ), 'autoload' => false ],
			[ 'type' => 'number', 'id' => 'nkv_svs__default_fee_percent', 'title' => __( 'Default platform fee (%)', 'nkz-woo-stripe-vendor-split' ), 'custom_attributes' => [ 'step' => '0.01', 'min' => '0', 'max' => '100' ] ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__split_includes_tax', 'title' => __( 'Include tax in vendor base', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__split_includes_shipping', 'title' => __( 'Include shipping in split', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__deduct_coupons_proportionally', 'title' => __( 'Deduct coupons proportionally', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__deduct_stripe_fee_from_vendor', 'title' => __( 'Deduct Stripe fee from vendor', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__automatic_transfers', 'title' => __( 'Automatic transfers', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__log_only_mode', 'title' => __( 'Log-only (dry-run) mode', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'number', 'id' => 'nkv_svs__minimum_transfer_amount', 'title' => __( 'Minimum transfer amount (major units)', 'nkz-woo-stripe-vendor-split' ), 'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ] ],
			[ 'type' => 'select', 'id' => 'nkv_svs__transfer_hook', 'title' => __( 'Trigger hook', 'nkz-woo-stripe-vendor-split' ), 'options' => [ 'payment_complete' => 'payment_complete (recommended)', 'processing' => 'order status processing', 'completed' => 'order status completed' ] ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__require_currency_match', 'title' => __( 'Require vendor currency to match order', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__auto_reversal_on_full_refund', 'title' => __( 'Auto reversal on full refund', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'checkbox', 'id' => 'nkv_svs__debug_logging', 'title' => __( 'Debug logging', 'nkz-woo-stripe-vendor-split' ) ],
			[ 'type' => 'sectionend', 'id' => 'nkv_svs_section' ],
		];
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// Prefill from current settings.
		$settings = Plugin::settings();
		$fields   = $this->fields();
		foreach ( $fields as &$f ) {
			if ( isset( $f['id'] ) && str_starts_with( $f['id'], 'nkv_svs__' ) ) {
				$key = substr( $f['id'], strlen( 'nkv_svs__' ) );
				$f['value'] = $settings[ $key ] ?? '';
			} elseif ( in_array( $f['id'] ?? '', [ 'nkv_svs_secret_test', 'nkv_svs_secret_live' ], true ) ) {
				// Masked: show placeholder, never reveal full key.
				$existing = (string) get_option( $f['id'], '' );
				$f['value'] = '';
				$f['desc']  = $existing ? sprintf( __( 'Currently set: %s', 'nkz-woo-stripe-vendor-split' ), self::mask( $existing ) ) : __( 'Not set.', 'nkz-woo-stripe-vendor-split' );
			}
		}
		woocommerce_admin_fields( $fields );
	}

	public function save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$settings = Plugin::settings();
		foreach ( $this->fields() as $f ) {
			if ( ! isset( $f['id'] ) || ! str_starts_with( $f['id'], 'nkv_svs__' ) ) {
				continue;
			}
			$key = substr( $f['id'], strlen( 'nkv_svs__' ) );
			$raw = $_POST[ $f['id'] ] ?? null;
			switch ( $f['type'] ) {
				case 'checkbox':
					$settings[ $key ] = ( '1' === $raw || 'yes' === $raw ) ? 'yes' : 'no';
					break;
				case 'number':
					$settings[ $key ] = (float) $raw;
					break;
				case 'select':
					$settings[ $key ] = sanitize_text_field( (string) $raw );
					break;
				default:
					$settings[ $key ] = sanitize_text_field( (string) $raw );
			}
		}
		update_option( 'nkv_svs_settings', $settings );

		// Secret keys: only overwrite when non-empty submitted value (preserve existing).
		foreach ( [ 'nkv_svs_secret_test', 'nkv_svs_secret_live' ] as $opt ) {
			$val = isset( $_POST[ $opt ] ) ? trim( (string) wp_unslash( $_POST[ $opt ] ) ) : '';
			if ( '' !== $val ) {
				update_option( $opt, $val, false );
			}
		}
	}

	private static function mask( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', max( 4, $len - 8 ) ) . substr( $key, -4 );
	}
}
