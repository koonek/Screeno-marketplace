<?php
/**
 * Checkout – vytvoří Stripe Checkout subscription session pro vendora
 * a přesměruje. admin-post.php akce nkzmp_billing_start.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class Checkout {

	public const ACTION_START  = 'nkzmp_billing_start';
	public const ACTION_PORTAL = 'nkzmp_billing_portal';

	private static ?Checkout $instance = null;

	public static function instance(): Checkout {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_' . self::ACTION_START, [ $this, 'start' ] );
		add_action( 'admin_post_' . self::ACTION_PORTAL, [ $this, 'portal' ] );
	}

	public function start(): void {
		check_admin_referer( self::ACTION_START );
		[ $vendor_id, $vendor ] = $this->require_vendor();

		$api = new StripeApi();
		if ( ! $api->is_ready() ) {
			$this->bail( __( 'Platba není nakonfigurovaná. Ozvi se provozovateli.', 'nkz-mp-vendor-billing' ) );
		}

		$s        = Settings::get();
		$amount   = Settings::amount_for_vendor( $vendor_id );
		$customer = $this->ensure_customer( $api, $vendor_id, $vendor );
		if ( ! $customer ) {
			$this->bail( __( 'Nepodařilo se založit zákazníka u Stripe.', 'nkz-mp-vendor-billing' ) );
		}

		$price_id = $api->ensure_price( $amount, (string) $s['currency'], (string) $s['product_name'] );
		if ( ! $price_id ) {
			$this->bail( __( 'Nepodařilo se připravit předplatné.', 'nkz-mp-vendor-billing' ) );
		}

		$return = wc_get_account_endpoint_url( 'vendor-billing' );
		$session = $api->create_subscription_checkout(
			$customer,
			$price_id,
			add_query_arg( 'nkzmp_billing', 'success', $return ),
			add_query_arg( 'nkzmp_billing', 'cancel', $return ),
			[ 'vendor_id' => (string) $vendor_id ]
		);

		if ( ! $session || empty( $session['url'] ) ) {
			$this->bail( __( 'Stripe nevrátil platební odkaz.', 'nkz-mp-vendor-billing' ) );
		}

		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	public function portal(): void {
		check_admin_referer( self::ACTION_PORTAL );
		[ $vendor_id ] = $this->require_vendor();

		$customer = (string) get_post_meta( $vendor_id, NKZMP_BILLING_CUSTOMER_META, true );
		if ( ! $customer ) {
			$this->bail( __( 'Zatím nemáš předplatné.', 'nkz-mp-vendor-billing' ) );
		}
		$api = new StripeApi();
		$session = $api->create_portal_session( $customer, wc_get_account_endpoint_url( 'vendor-billing' ) );
		if ( ! $session || empty( $session['url'] ) ) {
			$this->bail( __( 'Portál se nepodařilo otevřít.', 'nkz-mp-vendor-billing' ) );
		}
		wp_redirect( esc_url_raw( $session['url'] ) );
		exit;
	}

	private function ensure_customer( StripeApi $api, int $vendor_id, array $vendor ): ?string {
		$existing = (string) get_post_meta( $vendor_id, NKZMP_BILLING_CUSTOMER_META, true );
		if ( $existing ) {
			return $existing;
		}
		$res = $api->create_customer(
			(string) ( $vendor['email'] ?? '' ),
			(string) ( $vendor['name'] ?? ( 'Vendor #' . $vendor_id ) ),
			[ 'vendor_id' => (string) $vendor_id ]
		);
		if ( ! $res || empty( $res['id'] ) ) {
			return null;
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_CUSTOMER_META, $res['id'] );
		return (string) $res['id'];
	}

	/**
	 * @return array{0:int,1:array}
	 */
	private function require_vendor(): array {
		if ( ! is_user_logged_in() || ! \NKZMP\Dashboard\VendorContext::user_is_vendor() ) {
			$this->bail( __( 'Nepřihlášený prodejce.', 'nkz-mp-vendor-billing' ) );
		}
		$vendor_id = \NKZMP\Dashboard\VendorContext::current_vendor_id();
		$vendor    = ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
		if ( $vendor_id <= 0 || ! $vendor ) {
			$this->bail( __( 'Účet není propojený s prodejcem.', 'nkz-mp-vendor-billing' ) );
		}
		return [ $vendor_id, $vendor ];
	}

	private function bail( string $msg ): void {
		wp_safe_redirect( add_query_arg( 'nkzmp_billing_err', rawurlencode( $msg ), wc_get_account_endpoint_url( 'vendor-billing' ) ) );
		exit;
	}
}
