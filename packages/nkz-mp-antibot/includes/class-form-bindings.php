<?php
/**
 * Napojeni Protector na konkretni formulare.
 *
 * Pokryto:
 *  - Vendor registrace (admin_post hook + form render via filter na shortcode output)
 *  - WP login / register
 *  - WC lost password
 *
 * @package NKZMP\Antibot
 */

namespace NKZMP\Antibot;

defined( 'ABSPATH' ) || exit;

final class FormBindings {

	private static ?FormBindings $instance = null;

	public static function instance(): FormBindings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Vendor registrace – form output (inject pred </form>) + handler (priority 5 = pred normalnim).
		add_filter( 'do_shortcode_tag', [ $this, 'inject_into_vendor_reg_shortcode' ], 10, 4 );
		add_action( 'admin_post_nopriv_nkzmp_vendor_register', [ $this, 'verify_vendor_reg' ], 1 );
		add_action( 'admin_post_nkzmp_vendor_register',        [ $this, 'verify_vendor_reg' ], 1 );

		// WP login.
		add_action( 'login_form', [ $this, 'render_login_fields' ] );
		add_filter( 'authenticate', [ $this, 'verify_login' ], 30, 3 );

		// WP register (pokud povoleno).
		add_action( 'register_form', [ $this, 'render_register_fields' ] );
		add_filter( 'registration_errors', [ $this, 'verify_register' ], 10, 1 );

		// WP lost password.
		add_action( 'lostpassword_form', [ $this, 'render_lostpassword_fields' ] );
		add_action( 'lostpassword_post', [ $this, 'verify_lostpassword' ], 1 );
	}

	/* ---------------- Vendor registration ---------------- */

	public function inject_into_vendor_reg_shortcode( string $output, string $tag, $attr, array $m ): string {
		if ( $tag !== 'nkzmp_vendor_registration' ) {
			return $output;
		}
		if ( ! Settings::is_form_protected( 'vendor_registration' ) ) {
			return $output;
		}
		// Inject fields pred prvnim </form> (form je generovany shortcode).
		ob_start();
		Protector::render_fields( 'vendor_registration' );
		$fields = ob_get_clean();
		if ( $fields === '' || strpos( $output, '</form>' ) === false ) {
			return $output;
		}
		return preg_replace( '#</form>#', $fields . '</form>', $output, 1 );
	}

	public function verify_vendor_reg(): void {
		$check = Protector::verify( 'vendor_registration' );
		if ( is_wp_error( $check ) ) {
			$ref = wp_get_referer() ?: home_url( '/' );
			wp_safe_redirect( add_query_arg( [
				'nkzmp_reg_error' => rawurlencode( $check->get_error_message() ),
			], $ref ) );
			exit;
		}
	}

	/* ---------------- WP login ---------------- */

	public function render_login_fields(): void {
		Protector::render_fields( 'wp_login' );
	}

	/**
	 * @param \WP_User|\WP_Error|null $user
	 * @param string $username
	 * @param string $password
	 * @return \WP_User|\WP_Error|null
	 */
	public function verify_login( $user, $username, $password ) {
		// Jen pokud byl POST se snahou o login (mame v $_POST 'log' nebo 'pwd').
		if ( empty( $_POST['log'] ) && empty( $_POST['pwd'] ) ) {
			return $user;
		}
		$check = Protector::verify( 'wp_login' );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $user;
	}

	/* ---------------- WP register ---------------- */

	public function render_register_fields(): void {
		Protector::render_fields( 'wp_register' );
	}

	public function verify_register( $errors ) {
		$check = Protector::verify( 'wp_register' );
		if ( is_wp_error( $check ) ) {
			if ( $errors instanceof \WP_Error ) {
				$errors->add( $check->get_error_code(), $check->get_error_message() );
				return $errors;
			}
			return $check;
		}
		return $errors;
	}

	/* ---------------- WP lost password ---------------- */

	public function render_lostpassword_fields(): void {
		Protector::render_fields( 'wc_lost_password' );
	}

	public function verify_lostpassword(): void {
		$check = Protector::verify( 'wc_lost_password' );
		if ( is_wp_error( $check ) ) {
			wp_die( esc_html( $check->get_error_message() ), '', [ 'back_link' => true, 'response' => 429 ] );
		}
	}
}
