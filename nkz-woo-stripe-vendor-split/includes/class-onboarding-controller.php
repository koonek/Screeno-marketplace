<?php
/**
 * Stripe Connect onboarding (Express, CZ, admin-driven).
 *
 * Flow:
 *  - Admin opens vendor edit screen → if no acct_id, "Connect to Stripe" button.
 *  - Click → admin-post handler creates Express account, stores acct_id, creates Account Link, redirects.
 *  - Stripe redirects back to return_url → we refetch account, persist status.
 *  - refresh_url loops the user back into a fresh Account Link if the previous one expired.
 *  - For already-connected accounts: "Continue onboarding" (if requirements) or "Open Stripe Dashboard" (login_link).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Onboarding_Controller {

	private static ?Onboarding_Controller $instance = null;
	public static function instance(): Onboarding_Controller { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'admin_post_nkv_stripe_connect',   [ $this, 'handle_connect' ] );
		add_action( 'admin_post_nkv_stripe_refresh',   [ $this, 'handle_refresh' ] );
		add_action( 'admin_post_nkv_stripe_return',    [ $this, 'handle_return' ] );
		add_action( 'admin_post_nkv_stripe_dashboard', [ $this, 'handle_dashboard' ] );
		add_action( 'admin_post_nkv_stripe_sync',      [ $this, 'handle_sync' ] );
	}

	/* ---------------------------------------------------------------------
	 * Public helpers (used by the vendor meta box UI).
	 * ------------------------------------------------------------------- */

	public static function connect_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_connect&vendor_id=' . $vendor_id ),
			'nkv_stripe_connect_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	public static function sync_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_sync&vendor_id=' . $vendor_id ),
			'nkv_stripe_sync_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	public static function dashboard_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_dashboard&vendor_id=' . $vendor_id ),
			'nkv_stripe_dashboard_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	/* ---------------------------------------------------------------------
	 * Handlers.
	 * ------------------------------------------------------------------- */

	public function handle_connect(): void {
		$vendor_id = $this->authorize( 'nkv_stripe_connect_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor ) {
			wp_die( esc_html__( 'Vendor not found.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			$this->bail( $vendor_id, __( 'Missing Stripe secret key in settings.', 'nkz-woo-stripe-vendor-split' ) );
		}

		try {
			$account_id = $vendor['stripe_account_id'];
			if ( '' === $account_id ) {
				$email = is_email( $vendor['email'] ) ? $vendor['email'] : '';
				if ( '' === $email ) {
					$this->bail( $vendor_id, __( 'Vendor email is required before connecting to Stripe — fill it in and save the vendor first.', 'nkz-woo-stripe-vendor-split' ) );
				}
				$params = [
					'type'             => 'express',
					'country'          => 'CZ',
					'email'            => $email,
					'capabilities'     => [
						'card_payments' => [ 'requested' => 'true' ],
						'transfers'     => [ 'requested' => 'true' ],
					],
					'business_profile' => [
						'name' => $vendor['name'],
					],
					'metadata'         => [
						'nkv_vendor_id' => (string) $vendor_id,
						'site'          => home_url(),
					],
				];
				$account = $client->create_account( $params, 'nkv_acct_create_v1_' . $vendor_id );
				$account_id = (string) ( $account['id'] ?? '' );
				if ( '' === $account_id ) {
					throw new \RuntimeException( 'Account ID missing in Stripe response.' );
				}
				update_post_meta( $vendor_id, '_nkv_stripe_account_id', $account_id );
				update_post_meta( $vendor_id, '_nkv_stripe_account_status', 'pending' );
			}

			$link = $client->create_account_link(
				[
					'account'     => $account_id,
					'refresh_url' => self::refresh_url( $vendor_id ),
					'return_url'  => self::return_url( $vendor_id ),
					'type'        => 'account_onboarding',
				]
			);

			wp_redirect( (string) $link['url'] );
			exit;
		} catch ( \Throwable $e ) {
			Logger::error( 'Onboarding connect failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			$this->bail( $vendor_id, $e->getMessage() );
		}
	}

	public function handle_refresh(): void {
		$vendor_id = $this->vendor_id_from_query();
		// refresh_url is hit when the previous Account Link expired — generate a new one.
		$nonce = wp_create_nonce( 'nkv_stripe_connect_' . $vendor_id );
		wp_safe_redirect( admin_url( 'admin-post.php?action=nkv_stripe_connect&vendor_id=' . $vendor_id . '&_nkv_nonce=' . $nonce ) );
		exit;
	}

	public function handle_return(): void {
		$vendor_id = $this->vendor_id_from_query();
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( $vendor && '' !== $vendor['stripe_account_id'] ) {
			$this->sync_account_status( $vendor_id, $vendor['stripe_account_id'] );
		}
		wp_safe_redirect( add_query_arg( 'nkv_onboarding', 'returned', get_edit_post_link( $vendor_id, 'url' ) ) );
		exit;
	}

	public function handle_sync(): void {
		$vendor_id = $this->authorize( 'nkv_stripe_sync_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( $vendor && '' !== $vendor['stripe_account_id'] ) {
			$this->sync_account_status( $vendor_id, $vendor['stripe_account_id'] );
		}
		wp_safe_redirect( add_query_arg( 'nkv_onboarding', 'synced', get_edit_post_link( $vendor_id, 'url' ) ) );
		exit;
	}

	public function handle_dashboard(): void {
		$vendor_id = $this->authorize( 'nkv_stripe_dashboard_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor || '' === $vendor['stripe_account_id'] ) {
			$this->bail( $vendor_id, __( 'Vendor has no connected Stripe account.', 'nkz-woo-stripe-vendor-split' ) );
		}
		try {
			$client = new Stripe_Client();
			$link   = $client->create_login_link( $vendor['stripe_account_id'] );
			wp_redirect( (string) $link['url'] );
			exit;
		} catch ( \Throwable $e ) {
			Logger::error( 'Login link failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			$this->bail( $vendor_id, $e->getMessage() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Internals.
	 * ------------------------------------------------------------------- */

	/**
	 * Maps Stripe account snapshot to our four-state enum and persists it.
	 */
	public function sync_account_status( int $vendor_id, string $account_id ): void {
		try {
			$client  = new Stripe_Client();
			$account = $client->retrieve_account( $account_id );
		} catch ( \Throwable $e ) {
			Logger::error( 'Account retrieve failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			return;
		}
		if ( ! is_array( $account ) || isset( $account['error'] ) ) {
			return;
		}

		$charges_enabled  = ! empty( $account['charges_enabled'] );
		$payouts_enabled  = ! empty( $account['payouts_enabled'] );
		$disabled_reason  = $account['requirements']['disabled_reason'] ?? null;
		$currently_due    = $account['requirements']['currently_due'] ?? [];

		if ( $charges_enabled && $payouts_enabled ) {
			$status = 'enabled';
		} elseif ( $disabled_reason ) {
			$status = 'restricted';
		} elseif ( ! empty( $currently_due ) ) {
			$status = 'pending';
		} else {
			$status = 'pending';
		}

		update_post_meta( $vendor_id, '_nkv_stripe_account_status', $status );
		update_post_meta( $vendor_id, '_nkv_stripe_charges_enabled', $charges_enabled ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_payouts_enabled', $payouts_enabled ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_requirements_due', wp_json_encode( $currently_due ) );
	}

	private static function return_url( int $vendor_id ): string {
		return admin_url( 'admin-post.php?action=nkv_stripe_return&vendor_id=' . $vendor_id );
	}

	private static function refresh_url( int $vendor_id ): string {
		return admin_url( 'admin-post.php?action=nkv_stripe_refresh&vendor_id=' . $vendor_id );
	}

	private function authorize( string $nonce_prefix ): int {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$vendor_id = (int) ( $_GET['vendor_id'] ?? 0 );
		$nonce     = isset( $_GET['_nkv_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nkv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_prefix . $vendor_id ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'nkz-woo-stripe-vendor-split' ) );
		}
		return $vendor_id;
	}

	private function vendor_id_from_query(): int {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nkz-woo-stripe-vendor-split' ) );
		}
		return (int) ( $_GET['vendor_id'] ?? 0 );
	}

	private function bail( int $vendor_id, string $msg ): void {
		$url = add_query_arg(
			[
				'nkv_onboarding' => 'error',
				'nkv_msg'        => rawurlencode( $msg ),
			],
			get_edit_post_link( $vendor_id, 'url' ) ?: admin_url()
		);
		wp_safe_redirect( $url );
		exit;
	}
}
