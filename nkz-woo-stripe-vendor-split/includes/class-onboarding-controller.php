<?php
/**
 * Stripe Connect onboarding (Express, CZ).
 *
 * Two entry points:
 *  - Admin: in vendor edit screen — generate/copy/send onboarding link, refresh status, open Express Dashboard.
 *  - Public (vendor): permanent URL with signed HMAC token (admin-post.php nopriv) — always generates a fresh
 *    Stripe Account Link on each visit. After Stripe, redirects back to a public thank-you page.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Onboarding_Controller {

	private static ?Onboarding_Controller $instance = null;
	public static function instance(): Onboarding_Controller { return self::$instance ??= new self(); }

	public function init(): void {
		// Admin-only actions.
		add_action( 'admin_post_nkv_stripe_dashboard', [ $this, 'handle_dashboard' ] );
		add_action( 'admin_post_nkv_stripe_sync',      [ $this, 'handle_sync' ] );
		add_action( 'admin_post_nkv_stripe_email',     [ $this, 'handle_email' ] );

		// Public (vendor-facing) — both logged-in and anonymous.
		add_action( 'admin_post_nopriv_nkv_stripe_vendor_start',  [ $this, 'handle_vendor_start' ] );
		add_action( 'admin_post_nkv_stripe_vendor_start',         [ $this, 'handle_vendor_start' ] );
		add_action( 'admin_post_nopriv_nkv_stripe_vendor_return', [ $this, 'handle_vendor_return' ] );
		add_action( 'admin_post_nkv_stripe_vendor_return',        [ $this, 'handle_vendor_return' ] );
	}

	/* ---------------------------------------------------------------------
	 * Token + URL helpers.
	 * ------------------------------------------------------------------- */

	public static function vendor_token( int $vendor_id ): string {
		return hash_hmac( 'sha256', 'nkv_vendor_' . $vendor_id, wp_salt( 'auth' ) );
	}

	private static function vendor_token_valid( int $vendor_id, string $token ): bool {
		return hash_equals( self::vendor_token( $vendor_id ), $token );
	}

	public static function vendor_start_url( int $vendor_id ): string {
		return add_query_arg(
			[
				'action' => 'nkv_stripe_vendor_start',
				'v'      => $vendor_id,
				't'      => self::vendor_token( $vendor_id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	private static function vendor_return_url( int $vendor_id ): string {
		return add_query_arg(
			[
				'action' => 'nkv_stripe_vendor_return',
				'v'      => $vendor_id,
				't'      => self::vendor_token( $vendor_id ),
			],
			admin_url( 'admin-post.php' )
		);
	}

	public static function dashboard_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_dashboard&vendor_id=' . $vendor_id ),
			'nkv_stripe_dashboard_' . $vendor_id,
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

	public static function email_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_email&vendor_id=' . $vendor_id ),
			'nkv_stripe_email_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	/* ---------------------------------------------------------------------
	 * Public (vendor) handlers.
	 * ------------------------------------------------------------------- */

	public function handle_vendor_start(): void {
		[ $vendor_id, $vendor ] = $this->authorize_public();

		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			$this->public_error( __( 'Platforma nemá nakonfigurovaný Stripe. Kontaktuj prosím provozovatele.', 'nkz-woo-stripe-vendor-split' ) );
		}

		try {
			$account_id = $vendor['stripe_account_id'];
			if ( '' === $account_id ) {
				$email = is_email( $vendor['email'] ) ? $vendor['email'] : '';
				$account = $client->create_account(
					[
						'type'             => 'express',
						'country'          => 'CZ',
						'email'            => $email,
						'capabilities'     => [
							'card_payments' => [ 'requested' => 'true' ],
							'transfers'     => [ 'requested' => 'true' ],
						],
						'business_profile' => [ 'name' => $vendor['name'] ],
						'metadata'         => [
							'nkv_vendor_id' => (string) $vendor_id,
							'site'          => home_url(),
						],
					],
					'nkv_acct_create_v1_' . $vendor_id
				);
				$account_id = (string) ( $account['id'] ?? '' );
				if ( '' === $account_id ) {
					throw new \RuntimeException( 'Stripe nevrátil ID účtu.' );
				}
				update_post_meta( $vendor_id, '_nkv_stripe_account_id', $account_id );
				update_post_meta( $vendor_id, '_nkv_stripe_account_status', 'pending' );
			}

			$link = $client->create_account_link(
				[
					'account'     => $account_id,
					'refresh_url' => self::vendor_start_url( $vendor_id ),
					'return_url'  => self::vendor_return_url( $vendor_id ),
					'type'        => 'account_onboarding',
				]
			);
			wp_redirect( (string) $link['url'] );
			exit;
		} catch ( \Throwable $e ) {
			Logger::error( 'Vendor onboarding start failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			$this->public_error( __( 'Nepodařilo se zahájit Stripe onboarding. Zkus to za chvíli znovu nebo kontaktuj provozovatele.', 'nkz-woo-stripe-vendor-split' ) );
		}
	}

	public function handle_vendor_return(): void {
		[ $vendor_id, $vendor ] = $this->authorize_public();
		if ( '' !== $vendor['stripe_account_id'] ) {
			$this->sync_account_status( $vendor_id, $vendor['stripe_account_id'] );
		}
		$this->render_thank_you( $vendor_id );
	}

	private function authorize_public(): array {
		$vendor_id = (int) ( $_GET['v'] ?? 0 );
		$token     = isset( $_GET['t'] ) ? sanitize_text_field( wp_unslash( $_GET['t'] ) ) : '';
		if ( $vendor_id <= 0 || ! self::vendor_token_valid( $vendor_id, $token ) ) {
			status_header( 403 );
			$this->public_error( __( 'Neplatný nebo expirovaný odkaz.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$vendor = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor ) {
			status_header( 404 );
			$this->public_error( __( 'Účet prodejce nenalezen.', 'nkz-woo-stripe-vendor-split' ) );
		}
		return [ $vendor_id, $vendor ];
	}

	private function render_thank_you( int $vendor_id ): void {
		$vendor = Vendor_Repository::get( $vendor_id );
		$status = $vendor['stripe_account_status'] ?? 'unknown';
		$site   = get_bloginfo( 'name' );

		$messages = [
			'enabled'    => [ __( 'Hotovo!', 'nkz-woo-stripe-vendor-split' ), __( 'Tvůj Stripe účet je aktivní. Můžeš začít prodávat.', 'nkz-woo-stripe-vendor-split' ), '#46b450' ],
			'pending'    => [ __( 'Děkujeme!', 'nkz-woo-stripe-vendor-split' ), __( 'Tvoje údaje se ověřují. Obvykle to trvá pár minut, někdy až 24 hodin. Pak ti dáme vědět.', 'nkz-woo-stripe-vendor-split' ), '#ffb900' ],
			'restricted' => [ __( 'Ještě něco chybí', 'nkz-woo-stripe-vendor-split' ), __( 'Stripe potřebuje další informace. Otevři prosím onboarding znovu pomocí tvého odkazu a dokonči zbývající kroky.', 'nkz-woo-stripe-vendor-split' ), '#dc3232' ],
			'unknown'    => [ __( 'Děkujeme', 'nkz-woo-stripe-vendor-split' ), __( 'Tvoje žádost byla zaznamenána.', 'nkz-woo-stripe-vendor-split' ), '#888' ],
		];
		$m = $messages[ $status ] ?? $messages['unknown'];

		status_header( 200 );
		nocache_headers();
		?><!doctype html>
		<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $m[0] ); ?></title>
		<style>
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px; }
			.card { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
			.badge { display: inline-block; padding: 4px 12px; border-radius: 999px; color: #fff; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 16px; }
			h1 { margin: 0 0 12px; font-size: 24px; color: #1d2327; }
			p { color: #50575e; line-height: 1.6; }
			.footer { margin-top: 24px; font-size: 13px; color: #8c8f94; }
		</style></head><body>
		<div class="card">
			<span class="badge" style="background: <?php echo esc_attr( $m[2] ); ?>;"><?php echo esc_html( $status ); ?></span>
			<h1><?php echo esc_html( $m[0] ); ?></h1>
			<p><?php echo esc_html( $m[1] ); ?></p>
			<p class="footer"><?php printf( esc_html__( 'Tuto stránku můžeš zavřít. — %s', 'nkz-woo-stripe-vendor-split' ), esc_html( $site ) ); ?></p>
		</div>
		</body></html><?php
		exit;
	}

	private function public_error( string $msg ): void {
		nocache_headers();
		?><!doctype html><html lang="cs"><head><meta charset="utf-8"><title><?php esc_html_e( 'Chyba', 'nkz-woo-stripe-vendor-split' ); ?></title>
		<style>body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;background:#f5f5f5;padding:40px 20px;}.card{max-width:480px;margin:40px auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,0.08);}h1{color:#dc3232;font-size:20px;margin:0 0 12px;}p{color:#50575e;}</style>
		</head><body><div class="card"><h1><?php esc_html_e( 'Něco se nepovedlo', 'nkz-woo-stripe-vendor-split' ); ?></h1><p><?php echo esc_html( $msg ); ?></p></div></body></html><?php
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Admin handlers.
	 * ------------------------------------------------------------------- */

	public function handle_sync(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_sync_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( $vendor && '' !== $vendor['stripe_account_id'] ) {
			$this->sync_account_status( $vendor_id, $vendor['stripe_account_id'] );
		}
		wp_safe_redirect( add_query_arg( 'nkv_onboarding', 'synced', get_edit_post_link( $vendor_id, 'url' ) ) );
		exit;
	}

	public function handle_dashboard(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_dashboard_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor || '' === $vendor['stripe_account_id'] ) {
			$this->admin_bail( $vendor_id, __( 'Prodejce nemá připojený Stripe účet.', 'nkz-woo-stripe-vendor-split' ) );
		}
		try {
			$link = ( new Stripe_Client() )->create_login_link( $vendor['stripe_account_id'] );
			wp_redirect( (string) $link['url'] );
			exit;
		} catch ( \Throwable $e ) {
			Logger::error( 'Login link failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			$this->admin_bail( $vendor_id, $e->getMessage() );
		}
	}

	public function handle_email(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_email_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor ) {
			$this->admin_bail( $vendor_id, __( 'Prodejce nenalezen.', 'nkz-woo-stripe-vendor-split' ) );
		}
		if ( ! is_email( $vendor['email'] ) ) {
			$this->admin_bail( $vendor_id, __( 'Prodejce nemá vyplněný platný email.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$link    = self::vendor_start_url( $vendor_id );
		$site    = get_bloginfo( 'name' );
		$subject = sprintf( __( '[%s] Dokonči svou registraci přes Stripe', 'nkz-woo-stripe-vendor-split' ), $site );
		$body    = sprintf(
			/* translators: 1: vendor name, 2: site name, 3: onboarding URL */
			__( "Ahoj %1\$s,\n\nabys mohl/a na platformě %2\$s přijímat platby, dokonči prosím registraci u našeho platebního partnera Stripe na tomto odkazu:\n\n%3\$s\n\nOdkaz je trvalý — pokud onboarding přerušíš, můžeš se přes něj kdykoliv vrátit.\n\nDíky,\ntým %2\$s", 'nkz-woo-stripe-vendor-split' ),
			$vendor['name'] ?: __( 'prodejce', 'nkz-woo-stripe-vendor-split' ),
			$site,
			$link
		);
		$sent = wp_mail( $vendor['email'], $subject, $body );
		$flash = $sent ? 'email_sent' : 'email_failed';
		wp_safe_redirect( add_query_arg( 'nkv_onboarding', $flash, get_edit_post_link( $vendor_id, 'url' ) ) );
		exit;
	}

	private function authorize_admin( string $nonce_prefix ): int {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$vendor_id = (int) ( $_GET['vendor_id'] ?? 0 );
		$nonce     = isset( $_GET['_nkv_nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_nkv_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, $nonce_prefix . $vendor_id ) ) {
			wp_die( esc_html__( 'Neplatný bezpečnostní token.', 'nkz-woo-stripe-vendor-split' ) );
		}
		return $vendor_id;
	}

	private function admin_bail( int $vendor_id, string $msg ): void {
		$url = add_query_arg(
			[ 'nkv_onboarding' => 'error', 'nkv_msg' => rawurlencode( $msg ) ],
			get_edit_post_link( $vendor_id, 'url' ) ?: admin_url()
		);
		wp_safe_redirect( $url );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Status sync (shared).
	 * ------------------------------------------------------------------- */

	public function sync_account_status( int $vendor_id, string $account_id ): void {
		try {
			$account = ( new Stripe_Client() )->retrieve_account( $account_id );
		} catch ( \Throwable $e ) {
			Logger::error( 'Account retrieve failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			return;
		}
		if ( ! is_array( $account ) || isset( $account['error'] ) ) {
			return;
		}

		$charges_enabled = ! empty( $account['charges_enabled'] );
		$payouts_enabled = ! empty( $account['payouts_enabled'] );
		$disabled_reason = $account['requirements']['disabled_reason'] ?? null;
		$currently_due   = $account['requirements']['currently_due'] ?? [];

		if ( $charges_enabled && $payouts_enabled ) {
			$status = 'enabled';
		} elseif ( $disabled_reason && empty( $currently_due ) ) {
			$status = 'restricted';
		} else {
			$status = 'pending';
		}

		update_post_meta( $vendor_id, '_nkv_stripe_account_status', $status );
		update_post_meta( $vendor_id, '_nkv_stripe_charges_enabled', $charges_enabled ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_payouts_enabled', $payouts_enabled ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_requirements_due', wp_json_encode( $currently_due ) );
	}
}
