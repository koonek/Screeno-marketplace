<?php
/**
 * Listener — chytá nkzmp/v1/vendor/status_changed.
 *
 * Při přechodu na approved_awaiting_kyc:
 *  1. Vytvoří WP usera s rolí nkzmp_vendor pokud ještě neexistuje
 *  2. Propojí ho s vendor CPT přes _nkzmp_wp_user_id meta
 *  3. Pošle vendor e-mail s Stripe Connect linkem + password setup linkem
 *  4. Wp-admin notification jen pokud byl user nově vytvořený
 *
 * Při approved_awaiting_kyc / active / rejected pošle příslušné e-maily.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

use NKZMP\Vendor\Repository as VendorRepository;
use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Listener {

	private static ?Listener $instance = null;

	public static function instance(): Listener {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/vendor/status_changed', [ $this, 'on_status' ], 10, 4 );

		// Prodluž platnost reset/password-setup klíče. WP default je 24 h, což je
		// na onboarding e-mail „nastav si heslo" krátké (lidi neklikají hned) →
		// klíč vyprší a odkaz „nefunguje". Default 7 dní, filtrovatelné.
		add_filter( 'password_reset_expiration', static function ( $seconds ) {
			return (int) apply_filters( 'nkzmp/v1/registration/reset_expiration', 7 * DAY_IN_SECONDS );
		} );

		// Když si prodejce nastaví heslo, smaž příznak „čeká na nastavení hesla".
		add_action( 'after_password_reset', static function ( $user ): void {
			if ( $user instanceof \WP_User ) {
				delete_user_meta( $user->ID, '_nkzmp_needs_pw_setup' );
			}
		} );
	}

	public function on_status( int $vendor_id, string $from, string $to, array $context = [] ): void {
		switch ( $to ) {
			case 'approved_awaiting_kyc':
				$this->ensure_wp_user( $vendor_id );
				EmailService::send_approved_awaiting_kyc( $vendor_id );
				break;
			case 'active':
				$this->ensure_wp_user( $vendor_id ); // safety net pokud někdo přeskočil přes approved
				EmailService::send_active( $vendor_id );
				break;
			case 'rejected':
				EmailService::send_rejected( $vendor_id, (string) ( $context['reason'] ?? '' ) );
				break;
		}
	}

	/**
	 * Auto-create WP user + link s vendor CPT.
	 * Idempotentní – pokud user už existuje (podle emailu) jen propojí + zajistí roli.
	 */
	private function ensure_wp_user( int $vendor_id ): void {
		$vendor = ( new VendorRepository() )->find( $vendor_id );
		if ( ! $vendor || empty( $vendor['email'] ) ) {
			return;
		}

		// Pokud už mapping existuje, nic nedělej.
		$existing_link = (int) get_post_meta( $vendor_id, '_nkzmp_wp_user_id', true );
		if ( $existing_link <= 0 ) {
			$existing_link = (int) get_post_meta( $vendor_id, '_nkv_wp_user_id', true );
		}
		if ( $existing_link > 0 && get_user_by( 'id', $existing_link ) ) {
			$this->ensure_role( $existing_link );
			return;
		}

		// Najdi nebo vytvoř WP usera podle e-mailu.
		$user = get_user_by( 'email', (string) $vendor['email'] );
		$created = false;
		if ( ! $user ) {
			$username = $this->unique_username( (string) $vendor['email'], (string) $vendor['name'] );
			$user_id  = wp_create_user( $username, wp_generate_password( 24, true, true ), (string) $vendor['email'] );
			if ( is_wp_error( $user_id ) ) {
				error_log( '[NKZMP] auto-create user failed for vendor #' . $vendor_id . ': ' . $user_id->get_error_message() );
				return;
			}
			$user    = get_user_by( 'id', $user_id );
			$created = true;

			// Příznak: tenhle účet jsme vytvořili my a čeká na nastavení hesla.
			// Necháme ho, dokud si prodejce heslo nenastaví (after_password_reset).
			update_user_meta( $user_id, '_nkzmp_needs_pw_setup', 1 );

			// Display name = vendor name pokud user nemá nick.
			wp_update_user( [
				'ID'           => $user_id,
				'display_name' => (string) $vendor['name'],
				'first_name'   => (string) $vendor['name'],
			] );
		}

		if ( ! $user ) {
			return;
		}

		$this->ensure_role( (int) $user->ID );

		// Propojit s vendor CPT (oba klíče, mirror).
		update_post_meta( $vendor_id, '_nkzmp_wp_user_id', (int) $user->ID );
		update_post_meta( $vendor_id, '_nkv_wp_user_id', (int) $user->ID );

		// Pošli password setup link když:
		//  - user byl právě vytvořen, NEBO
		//  - user existuje, ale ještě si NEnastavil heslo (příznak
		//    _nkzmp_needs_pw_setup). Řeší re-registraci/re-schválení na e-mail,
		//    kde WP účet zůstal (odkaz vypršel, nový e-mail by jinak nedorazil).
		// NEspamujeme existující reálné účty (admin/zákazník) – ty příznak nemají.
		$needs_setup = (bool) get_user_meta( (int) $user->ID, '_nkzmp_needs_pw_setup', true );
		if ( $created || $needs_setup ) {
			$this->send_password_setup_email( (int) $user->ID );
		}
	}

	private function ensure_role( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		if ( in_array( Capabilities::ROLE_VENDOR, (array) $user->roles, true ) ) {
			return;
		}
		// Existující administrator / shop_manager nepřevažujeme rolí — jen přidáme vendor jako dodatečnou.
		if ( in_array( 'administrator', (array) $user->roles, true ) || in_array( 'shop_manager', (array) $user->roles, true ) ) {
			$user->add_role( Capabilities::ROLE_VENDOR );
			return;
		}
		$user->set_role( Capabilities::ROLE_VENDOR );
	}

	private function unique_username( string $email, string $name ): string {
		$base = sanitize_user( substr( $email, 0, strpos( $email, '@' ) ?: strlen( $email ) ), true );
		if ( $base === '' ) {
			$base = sanitize_user( $name, true );
		}
		if ( $base === '' ) {
			$base = 'vendor';
		}
		$candidate = $base;
		$i         = 2;
		while ( username_exists( $candidate ) ) {
			$candidate = $base . $i;
			$i++;
		}
		return $candidate;
	}

	/**
	 * Pošle WP-mail s password setup linkem (přes standardní reset_password flow).
	 * Použijeme AOZ wrapper přes náš `wp_mail` filter neutrálně — wpmail zkrátka
	 * vezme to, co retrieve_password() pošle.
	 */
	private function send_password_setup_email( int $user_id ): void {
		$user = get_user_by( 'id', $user_id );
		if ( ! $user ) {
			return;
		}
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return;
		}
		// Použij wp_login_url() – security pluginy (skrytý login /prihlaseni)
		// tuhle URL filtrují na svůj slug, takže odkaz nevede na blokovaný
		// wp-login.php (jinak 404). Filtrovatelné pro krajní případy.
		$login_url = (string) apply_filters( 'nkzmp/v1/registration/reset_login_url', wp_login_url() );
		$reset_url = add_query_arg(
			[
				'action' => 'rp',
				'key'    => $key,
				'login'  => rawurlencode( $user->user_login ),
			],
			$login_url
		);

		$vars = [
			'name'         => $user->display_name ?: $user->user_login,
			'username'     => $user->user_login,
			'password_url' => $reset_url,
			'login_url'    => wc_get_page_permalink( 'myaccount' ) ?: home_url( '/muj-ucet' ),
			'site_name'    => (string) get_bloginfo( 'name' ),
		];

		$subject = class_exists( \NKZMP\Admin\EmailSettings::class )
			? \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_password_setup_subject' ), $vars )
			: sprintf( 'Nastav si heslo — %s', $vars['site_name'] );
		$body    = class_exists( \NKZMP\Admin\EmailSettings::class )
			? \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_password_setup_body' ), $vars )
			: sprintf( "Ahoj %s,\n\n%s\n\n", $vars['name'], $reset_url );

		EmailService::send_raw( (string) $user->user_email, $subject, $body );
	}
}

