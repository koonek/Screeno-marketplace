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
		add_action( 'admin_post_nkv_stripe_reset',     [ $this, 'handle_reset' ] );
		add_action( 'admin_post_nkv_stripe_diagnose',  [ $this, 'handle_diagnose' ] );

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

	public static function reset_url( int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=nkv_stripe_reset&vendor_id=' . $vendor_id ),
			'nkv_stripe_reset_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	/**
	 * READ-ONLY diagnostika účtu. Nic nemění, jen vypíše sanitizovaný stav.
	 *
	 * `account` je volitelný – dá se jím zdiagnostikovat i účet, který
	 * u žádného prodejce zapsaný není (typicky při dohledávání s podporou).
	 */
	public static function diagnose_url( int $vendor_id, string $account_id = '' ): string {
		$args = [ 'action' => 'nkv_stripe_diagnose', 'vendor_id' => $vendor_id ];
		if ( '' !== $account_id ) {
			$args['account'] = $account_id;
		}
		return wp_nonce_url(
			add_query_arg( $args, admin_url( 'admin-post.php' ) ),
			'nkv_stripe_diagnose_' . $vendor_id,
			'_nkv_nonce'
		);
	}

	/**
	 * Země, ve kterých umíme založit Stripe Connect účet. Země je u Stripe účtu
	 * NEMĚNNÁ po vytvoření – slovenský prodejce potřebuje účet rovnou pro SK,
	 * jinak mu Stripe pole „země" zašedne a nepustí ho dál. Filtrovatelné.
	 *
	 * @return array<string,string> ISO kód => název
	 */
	public static function allowed_countries(): array {
		return (array) apply_filters(
			'nkv/v1/onboarding/allowed_countries',
			[
				'CZ' => __( 'Česko', 'nkz-woo-stripe-vendor-split' ),
				'SK' => __( 'Slovensko', 'nkz-woo-stripe-vendor-split' ),
			]
		);
	}

	/** Země prodejce pro založení Stripe účtu (default CZ, validovaná proti allowlistu). */
	public static function vendor_country( int $vendor_id ): string {
		$c       = strtoupper( (string) get_post_meta( $vendor_id, '_nkv_stripe_country', true ) );
		$allowed = self::allowed_countries();
		return isset( $allowed[ $c ] ) ? $c : 'CZ';
	}

	/* ---------------------------------------------------------------------
	 * Public (vendor) handlers.
	 * ------------------------------------------------------------------- */

	public function handle_vendor_start(): void {
		[ $vendor_id, $vendor ] = $this->authorize_public();

		// IČO není povinné – prodávat může i nepodnikající tvůrce a Stripe si
		// identifikaci vyžádá sám (u fyzické osoby doklad, ne IČO). Tvrdý gate
		// tu blokoval přesně ty prodejce, které registrace od 0.75.0 pouští dál.
		// Vypnutí/zapnutí: filtr `nkv/v1/onboarding/require_ico`.
		if ( apply_filters( 'nkv/v1/onboarding/require_ico', false ) && '' === trim( (string) $vendor['ico'] ) ) {
			$this->public_error( __( 'Pro registraci v Stripe je potřeba IČO. Pokud podnikáš pod jiným identifikátorem, ozvi se prosím provozovateli platformy.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			$this->public_error( __( 'Platforma nemá nakonfigurovaný Stripe. Kontaktuj prosím provozovatele.', 'nkz-woo-stripe-vendor-split' ) );
		}

		try {
			$account_id = $vendor['stripe_account_id'];
			if ( '' === $account_id ) {
				$country = self::vendor_country( $vendor_id );
				$params = [
					'type'             => 'express',
					'country'          => $country,
					'capabilities'     => [
						'card_payments' => [ 'requested' => 'true' ],
						'transfers'     => [ 'requested' => 'true' ],
					],
					'business_profile' => [ 'name' => $vendor['name'] ],
					'metadata'         => [
						'nkv_vendor_id' => (string) $vendor_id,
						'site'          => home_url(),
					],
				];
				if ( is_email( $vendor['email'] ) ) {
					$params['email'] = $vendor['email'];
				}
				// Bump attempt before each create so a stale Stripe idempotency cache can't block re-onboarding.
				$attempt = (int) get_post_meta( $vendor_id, '_nkv_stripe_create_attempt', true ) + 1;
				update_post_meta( $vendor_id, '_nkv_stripe_create_attempt', $attempt );
				$idem    = 'nkv_acct_create_v' . $attempt . '_' . $vendor_id . '_' . substr( md5( (string) $attempt . wp_salt( 'auth' ) ), 0, 8 );
				$account = $client->create_account( $params, $idem );
				$account_id = (string) ( $account['id'] ?? '' );
				if ( '' === $account_id ) {
					throw new \RuntimeException( 'Stripe nevrátil ID účtu.' );
				}
				update_post_meta( $vendor_id, '_nkv_stripe_account_id', $account_id );
				update_post_meta( $vendor_id, '_nkv_stripe_account_status', 'pending' );
				// Ulož zemi, se kterou byl účet reálně vytvořen (pro varování v adminu,
				// když někdo později přepne výběr země – změna vyžaduje reset účtu).
				update_post_meta( $vendor_id, '_nkv_stripe_account_country', $country );
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
			$detail = $e->getMessage();
			$this->public_error(
				__( 'Nepodařilo se zahájit Stripe onboarding.', 'nkz-woo-stripe-vendor-split' ) .
				( $detail ? ' (' . $detail . ')' : '' )
			);
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
		// Stav čteme ze snapshotu, který právě doběhl ze Stripe v
		// handle_vendor_return() – ne z toho, co bylo v DB před onboardingem.
		$snapshot = self::snapshot( $vendor_id );
		$state    = (string) ( $snapshot['state'] ?? Account_State::UNKNOWN );
		$site     = get_bloginfo( 'name' );

		$colors = [
			Account_State::VERIFIED             => '#46b450',
			Account_State::PENDING_VERIFICATION => '#2271b1',
			Account_State::ACTION_REQUIRED      => '#ffb900',
			Account_State::PAST_DUE             => '#dc3232',
			Account_State::VERIFICATION_ERROR   => '#dc3232',
			Account_State::ACCOUNT_RESTRICTED   => '#dc3232',
		];
		$color = $colors[ $state ] ?? '#888';

		$title = Account_State::label( $state );
		$body  = $snapshot ? Account_State::description( $snapshot ) : __( 'Tvoje žádost byla zaznamenána.', 'nkz-woo-stripe-vendor-split' );

		// Odkaz na dokončení nabízíme JEN když Stripe opravdu něco chce.
		// Dřív se formulář nabízel i lidem, kteří mají jen čekat – proto ten
		// dojem „pořád dokola mě to nutí ověřovat se znovu".
		$show_retry = Account_State::needs_user_action( $state );
		$retry_url  = self::vendor_start_url( $vendor_id );

		$missing     = [];
		$missing_head = __( 'Stripe ještě potřebuje:', 'nkz-woo-stripe-vendor-split' );
		if ( $show_retry && $snapshot ) {
			// Když Stripe nabízí náhradní cestu (doklady místo vyplňování),
			// vypisujeme JEN ji. Původní seznam polí by prodejce poslal zpátky
			// do smyčky, ve které mu ověření pokaždé znovu spadne.
			$alt = Account_State::alternative_labels( $snapshot );
			if ( $alt ) {
				$missing      = $alt;
				$missing_head = __( 'Stripe místo vyplňování přijme:', 'nkz-woo-stripe-vendor-split' );
			} else {
				$missing = Account_State::requirement_labels(
					array_merge( (array) ( $snapshot['past_due'] ?? [] ), (array) ( $snapshot['currently_due'] ?? [] ) )
				);
			}
		}

		status_header( 200 );
		nocache_headers();
		?><!doctype html>
		<html lang="cs"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?php echo esc_html( $title ); ?></title>
		<style>
			body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 40px 20px; }
			.card { max-width: 480px; margin: 40px auto; background: #fff; border-radius: 8px; padding: 32px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
			.badge { display: inline-block; padding: 4px 12px; border-radius: 999px; color: #fff; font-size: 12px; font-weight: 600; margin-bottom: 16px; }
			h1 { margin: 0 0 12px; font-size: 24px; color: #1d2327; }
			p { color: #50575e; line-height: 1.6; }
			ul { color: #50575e; line-height: 1.6; }
			.cta { display: inline-block; margin-top: 8px; padding: 12px 24px; border-radius: 999px; background: #0060FF; color: #fff; text-decoration: none; font-weight: 600; }
			.footer { margin-top: 24px; font-size: 13px; color: #8c8f94; }
		</style></head><body>
		<div class="card">
			<span class="badge" style="background: <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $title ); ?></span>
			<h1><?php echo esc_html( $title ); ?></h1>
			<p><?php echo esc_html( $body ); ?></p>
			<?php if ( $missing ) : ?>
				<p><strong><?php echo esc_html( $missing_head ); ?></strong></p>
				<ul><?php foreach ( $missing as $m ) : ?><li><?php echo esc_html( $m ); ?></li><?php endforeach; ?></ul>
			<?php endif; ?>
			<?php if ( $show_retry ) : ?>
				<p><a class="cta" href="<?php echo esc_url( $retry_url ); ?>"><?php esc_html_e( 'Dokončit ověření u Stripe', 'nkz-woo-stripe-vendor-split' ); ?></a></p>
			<?php endif; ?>
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

	public function handle_reset(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_reset_' );
		delete_post_meta( $vendor_id, '_nkv_stripe_account_id' );
		delete_post_meta( $vendor_id, '_nkv_stripe_account_status' );
		delete_post_meta( $vendor_id, '_nkv_stripe_charges_enabled' );
		delete_post_meta( $vendor_id, '_nkv_stripe_payouts_enabled' );
		delete_post_meta( $vendor_id, '_nkv_stripe_transfers_capability' );
		delete_post_meta( $vendor_id, '_nkv_stripe_requirements_due' );
		delete_post_meta( $vendor_id, '_nkv_stripe_account_country' );
		// Bump attempt counter so the next create call uses a fresh Stripe idempotency key.
		$attempt = (int) get_post_meta( $vendor_id, '_nkv_stripe_create_attempt', true );
		update_post_meta( $vendor_id, '_nkv_stripe_create_attempt', $attempt + 1 );
		Logger::info( 'Vendor Stripe account reset', [ 'vendor' => $vendor_id, 'attempt' => $attempt + 1 ] );
		wp_safe_redirect( add_query_arg( 'nkv_onboarding', 'reset', get_edit_post_link( $vendor_id, 'url' ) ) );
		exit;
	}

	public function handle_sync(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_sync_' );
		$vendor    = Vendor_Repository::get( $vendor_id );
		$err = null;
		if ( $vendor && '' !== $vendor['stripe_account_id'] ) {
			$err = $this->sync_account_status( $vendor_id, $vendor['stripe_account_id'] );
		}
		if ( null === $err ) {
			wp_safe_redirect( add_query_arg( 'nkv_onboarding', 'synced', get_edit_post_link( $vendor_id, 'url' ) ) );
		} else {
			wp_safe_redirect( add_query_arg(
				[ 'nkv_onboarding' => 'sync_failed', 'nkv_msg' => rawurlencode( $err ) ],
				get_edit_post_link( $vendor_id, 'url' )
			) );
		}
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

	/**
	 * READ-ONLY diagnostika: načte účet ze Stripe a vypíše sanitizovaný stav.
	 *
	 * Nic nezapisuje do Stripe ani do DB. Výstup je schválně bez osobních
	 * údajů — jsou v něm jen NÁZVY chybějících polí, příznaky a chybové kódy,
	 * takže se dá bez obav poslat podpoře.
	 */
	public function handle_diagnose(): void {
		$vendor_id = $this->authorize_admin( 'nkv_stripe_diagnose_' );

		$account_id = isset( $_GET['account'] ) ? sanitize_text_field( wp_unslash( $_GET['account'] ) ) : '';
		if ( '' === $account_id ) {
			$account_id = (string) get_post_meta( $vendor_id, '_nkv_stripe_account_id', true );
		}
		if ( ! preg_match( '/^acct_[A-Za-z0-9]+$/', $account_id ) ) {
			wp_die( esc_html__( 'Chybí nebo je neplatné ID Stripe účtu.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			wp_die( esc_html__( 'Stripe klíč není nakonfigurovaný.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$account = $client->retrieve_account( $account_id );
		if ( ! is_array( $account ) || isset( $account['error'] ) ) {
			$msg = is_array( $account ) ? (string) ( $account['error']['message'] ?? '' ) : '';
			wp_die( esc_html( sprintf(
				/* translators: %s: chybová hláška Stripe */
				__( 'Stripe účet se nepodařilo načíst. %s', 'nkz-woo-stripe-vendor-split' ),
				$msg
			) ) );
		}

		$snapshot = Account_State::evaluate( $account );

		$out = [
			'account' => [
				'id'                => (string) ( $account['id'] ?? '' ),
				'type'              => (string) ( $account['type'] ?? '' ),
				'business_type'     => (string) ( $account['business_type'] ?? '' ),
				'country'           => (string) ( $account['country'] ?? '' ),
				// `controller` je objekt bez PII (typ platformy, kdo platí fees).
				'controller'        => is_array( $account['controller'] ?? null ) ? $account['controller'] : null,
				'details_submitted' => ! empty( $account['details_submitted'] ),
				'charges_enabled'   => ! empty( $account['charges_enabled'] ),
				'payouts_enabled'   => ! empty( $account['payouts_enabled'] ),
				'capabilities'      => is_array( $account['capabilities'] ?? null ) ? $account['capabilities'] : [],
			],
			'requirements' => [
				'disabled_reason'      => $snapshot['disabled_reason'],
				'current_deadline'     => $snapshot['current_deadline'],
				'currently_due'        => $snapshot['currently_due'],
				'past_due'             => $snapshot['past_due'],
				'pending_verification' => $snapshot['pending_verification'],
				'eventually_due'       => $snapshot['eventually_due'],
				'errors'               => $snapshot['errors'],
				'alternatives'         => $snapshot['alternatives'],
			],
			'future_requirements' => [
				'currently_due'        => $snapshot['future_currently_due'],
				'eventually_due'       => $snapshot['future_eventually_due'],
				'pending_verification' => $snapshot['future_pending_verification'],
			],
			'derived' => [
				'state'             => $snapshot['state'],
				'legacy_status'     => Account_State::to_legacy( (string) $snapshot['state'] ),
				'needs_user_action' => Account_State::needs_user_action( (string) $snapshot['state'] ),
				'needs_hosted_flow' => Account_State::needs_hosted_flow(
					array_merge( $snapshot['currently_due'], $snapshot['past_due'] )
				),
			],
			// Co máme uložené u prodejce – kvůli odhalení zastaralého stavu v DB.
			'stored' => [
				'vendor_id'      => $vendor_id,
				'legacy_status'  => (string) get_post_meta( $vendor_id, '_nkv_stripe_account_status', true ),
				'state'          => (string) get_post_meta( $vendor_id, '_nkv_stripe_account_state', true ),
				'snapshot_at'    => (int) ( ( self::snapshot( $vendor_id )['synced_at'] ?? 0 ) ),
			],
			'individual' => self::sanitize_person( $account['individual'] ?? null ),
		];

		self::log_diagnostics( $vendor_id, $account_id, $snapshot );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Stav ověření osoby BEZ osobních údajů.
	 *
	 * Ze `individual` bereme výhradně verification.status/details_code
	 * a booleovské „je vyplněno" – žádná jména, adresy, data narození
	 * ani čísla dokladů.
	 *
	 * @param mixed $individual
	 * @return array|null
	 */
	private static function sanitize_person( $individual ): ?array {
		if ( ! is_array( $individual ) ) {
			return null;
		}
		$v = is_array( $individual['verification'] ?? null ) ? $individual['verification'] : [];

		return [
			'verification_status'        => (string) ( $v['status'] ?? '' ),
			'verification_details_code'  => (string) ( $v['details_code'] ?? '' ),
			// `details` může obsahovat volný text od Stripe – vynecháváme.
			'document_front_present'     => ! empty( $v['document']['front'] ),
			'document_back_present'      => ! empty( $v['document']['back'] ),
			'document_details_code'      => (string) ( $v['document']['details_code'] ?? '' ),
			'has_first_name'             => ! empty( $individual['first_name'] ),
			'has_last_name'              => ! empty( $individual['last_name'] ),
			'has_dob'                    => ! empty( $individual['dob']['year'] ),
			'has_address_line1'          => ! empty( $individual['address']['line1'] ),
			'has_address_city'           => ! empty( $individual['address']['city'] ),
			'has_address_postal_code'    => ! empty( $individual['address']['postal_code'] ),
			'has_phone'                  => ! empty( $individual['phone'] ),
			'has_id_number'              => ! empty( $individual['id_number_provided'] ),
			'requirements_currently_due' => isset( $individual['requirements']['currently_due'] )
				? array_map( 'strval', (array) $individual['requirements']['currently_due'] )
				: [],
			'requirements_past_due'      => isset( $individual['requirements']['past_due'] )
				? array_map( 'strval', (array) $individual['requirements']['past_due'] )
				: [],
			'requirements_pending_verification' => isset( $individual['requirements']['pending_verification'] )
				? array_map( 'strval', (array) $individual['requirements']['pending_verification'] )
				: [],
		];
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

	/** Meta s celým (sanitizovaným) snapshotem stavu účtu. */
	public const SNAPSHOT_META = '_nkv_stripe_account_snapshot';

	public function sync_account_status( int $vendor_id, string $account_id ): ?string {
		try {
			$account = ( new Stripe_Client() )->retrieve_account( $account_id );
		} catch ( \Throwable $e ) {
			Logger::error( 'Account retrieve failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			return $e->getMessage();
		}
		if ( ! is_array( $account ) ) {
			return 'Stripe nevrátil odpověď.';
		}
		if ( isset( $account['error'] ) ) {
			$msg = (string) ( $account['error']['message'] ?? 'Stripe vrátil chybu.' );
			Logger::error( 'Account sync rejected', [ 'vendor' => $vendor_id, 'account' => $account_id, 'err' => $msg ] );
			return $msg;
		}

		$snapshot = Account_State::evaluate( $account );

		// Capability `transfers` musí být 'active', jinak transfer prodejci
		// selže s „destination account needs transfers capability". Pokud
		// chybí / je inactive (ne jen pending), znovu ji vyžádáme. Posíláme
		// VÝHRADNĚ capabilities – žádná pole s osobními údaji, aby nešlo
		// přepsat už ověřené informace prázdnou hodnotou.
		$transfers_state = (string) ( $account['capabilities']['transfers'] ?? '' );
		if ( $transfers_state === '' || $transfers_state === 'inactive' ) {
			try {
				( new Stripe_Client() )->update_account( $account_id, [
					'capabilities' => [ 'transfers' => [ 'requested' => 'true' ] ],
				] );
				Logger::info( 'Re-requested transfers capability', [ 'vendor' => $vendor_id, 'account' => $account_id ] );
			} catch ( \Throwable $e ) {
				Logger::error( 'Transfers capability re-request failed', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
			}
		}

		$state  = (string) $snapshot['state'];
		$status = Account_State::to_legacy( $state );

		update_post_meta( $vendor_id, '_nkv_stripe_account_status', $status );
		update_post_meta( $vendor_id, '_nkv_stripe_account_state', $state );
		update_post_meta( $vendor_id, '_nkv_stripe_charges_enabled', $snapshot['charges_enabled'] ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_payouts_enabled', $snapshot['payouts_enabled'] ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_transfers_capability', $snapshot['transfers_active'] ? 1 : 0 );
		update_post_meta( $vendor_id, '_nkv_stripe_requirements_due', wp_json_encode( $snapshot['currently_due'] ) );

		$snapshot['synced_at'] = time();
		update_post_meta( $vendor_id, self::SNAPSHOT_META, wp_json_encode( $snapshot ) );

		self::log_diagnostics( $vendor_id, $account_id, $snapshot );

		// KYC dokončené → aktivuj vendora čekajícího na KYC. Jen v bundle
		// režimu s NKZ core; standalone adapter (Screeno) řídí stav ručně.
		if ( Account_State::VERIFIED === $state ) {
			$this->maybe_activate_after_kyc( $vendor_id );
		}

		return null;
	}

	/**
	 * Načtený snapshot stavu účtu, nebo null.
	 *
	 * @return array|null
	 */
	public static function snapshot( int $vendor_id ): ?array {
		$raw = (string) get_post_meta( $vendor_id, self::SNAPSHOT_META, true );
		if ( '' === $raw ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Diagnostický záznam o stavu účtu.
	 *
	 * Schválně jen názvy požadavků a příznaky – žádné osobní údaje, žádné
	 * klíče. Díky tomu se dá log poslat podpoře i nalepit do ticketu.
	 */
	public static function log_diagnostics( int $vendor_id, string $account_id, array $snapshot ): void {
		Logger::info( 'Stripe account diagnostics', [
			'vendorId'            => $vendor_id,
			'accountId'           => $account_id,
			'state'               => $snapshot['state'] ?? '',
			'detailsSubmitted'    => ! empty( $snapshot['details_submitted'] ),
			'chargesEnabled'      => ! empty( $snapshot['charges_enabled'] ),
			'payoutsEnabled'      => ! empty( $snapshot['payouts_enabled'] ),
			'transfersActive'     => ! empty( $snapshot['transfers_active'] ),
			'disabledReason'      => $snapshot['disabled_reason'] ?? null,
			'currentlyDue'        => $snapshot['currently_due'] ?? [],
			'pastDue'             => $snapshot['past_due'] ?? [],
			'pendingVerification' => $snapshot['pending_verification'] ?? [],
			'eventuallyDue'       => $snapshot['eventually_due'] ?? [],
			'errors'              => $snapshot['errors'] ?? [],
		] );
	}

	/**
	 * Po dokončení Stripe Connect KYC překlopí vendora
	 * approved_awaiting_kyc → active přes core StatusService. Bez core
	 * (standalone adapter) je no-op.
	 */
	private function maybe_activate_after_kyc( int $vendor_id ): void {
		if ( ! class_exists( \NKZMP\Vendor\StatusService::class ) ) {
			return;
		}
		$current = (string) get_post_meta( $vendor_id, '_nkzmp_vendor_status', true );
		if ( \NKZMP\Vendor\Status::APPROVED_AWAITING_KYC->value !== $current ) {
			return; // aktivujeme jen z čekání na KYC; ostatní stavy neřešíme
		}
		try {
			( new \NKZMP\Vendor\StatusService() )->transition(
				$vendor_id,
				\NKZMP\Vendor\Status::ACTIVE,
				[ 'source' => 'stripe_connect_kyc' ]
			);
			Logger::info( 'Vendor aktivován po dokončení Stripe KYC', [ 'vendor' => $vendor_id ] );
		} catch ( \Throwable $e ) {
			Logger::error( 'Auto-aktivace po KYC selhala', [ 'vendor' => $vendor_id, 'err' => $e->getMessage() ] );
		}
	}
}
