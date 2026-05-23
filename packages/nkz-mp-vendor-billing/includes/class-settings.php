<?php
/**
 * Settings – částka, měna, grace period, enable.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_billing_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 40 );
	}

	public static function get(): array {
		$defaults = [
			'enabled'       => 'no',
			'amount'        => 250,      // CZK / měsíc
			'currency'      => 'CZK',
			'grace_days'    => 7,
			'product_name'  => 'Členství prodejce – Art of život',
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public static function is_enabled(): bool {
		return 'yes' === self::get()['enabled'];
	}

	public static function amount_for_vendor( int $vendor_id ): int {
		$override = get_post_meta( $vendor_id, NKZMP_BILLING_AMOUNT_OVERRIDE_META, true );
		if ( $override !== '' && is_numeric( $override ) ) {
			return (int) $override;
		}
		return (int) self::get()['amount'];
	}

	public function register(): void {
		register_setting( 'nkzmp_billing', self::OPTION );
	}

	public function menu(): void {
		$parent = defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Billing', 'nkz-mp-vendor-billing' ),
			__( 'Billing', 'nkz-mp-vendor-billing' ),
			'manage_woocommerce',
			'nkz-mp-vendor-billing',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$s = self::get();
		$webhook_url = home_url( '/wp-json/nkzmp/v1/billing/webhook' );
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace – Billing', 'nkz-mp-vendor-billing' ) . '</h1>';
		echo '<p>' . esc_html__( 'Měsíční předplatné prodejců přes Stripe Billing. Stripe klíče se berou ze Stripe adapteru (WooCommerce → Stripe Vendor Split).', 'nkz-mp-vendor-billing' ) . '</p>';

		echo '<div class="notice notice-info inline"><p><strong>' . esc_html__( 'Stripe webhook URL:', 'nkz-mp-vendor-billing' ) . '</strong><br><code>' . esc_html( $webhook_url ) . '</code><br>';
		echo esc_html__( 'Přidej v Stripe Dashboard → Developers → Webhooks. Eventy: checkout.session.completed, invoice.paid, invoice.payment_failed, customer.subscription.deleted.', 'nkz-mp-vendor-billing' ) . '</p></div>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_billing' );
		echo '<table class="form-table">';

		$this->select_row( 'enabled', __( 'Předplatné zapnuté', 'nkz-mp-vendor-billing' ), [ 'no' => __( 'Ne', 'nkz-mp-vendor-billing' ), 'yes' => __( 'Ano', 'nkz-mp-vendor-billing' ) ], $s['enabled'] );
		$this->text_row( 'amount', __( 'Měsíční částka', 'nkz-mp-vendor-billing' ), $s['amount'], 'number' );
		$this->text_row( 'currency', __( 'Měna', 'nkz-mp-vendor-billing' ), $s['currency'] );
		$this->text_row( 'grace_days', __( 'Grace period (dny po neúspěšné platbě)', 'nkz-mp-vendor-billing' ), $s['grace_days'], 'number' );
		$this->text_row( 'product_name', __( 'Název položky na faktuře', 'nkz-mp-vendor-billing' ), $s['product_name'] );

		echo '</table>';
		submit_button();
		echo '</form></div>';
	}

	private function text_row( string $key, string $label, $value, string $type = 'text' ): void {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input id="%1$s" type="%4$s" name="%3$s[%1$s]" value="%5$s" style="width:320px" /></td></tr>',
			esc_attr( $key ), esc_html( $label ), esc_attr( self::OPTION ), esc_attr( $type ), esc_attr( (string) $value )
		);
	}

	private function select_row( string $key, string $label, array $opts, $value ): void {
		echo '<tr><th><label for="' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $key ) . '" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']">';
		foreach ( $opts as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $value, $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';
	}
}
