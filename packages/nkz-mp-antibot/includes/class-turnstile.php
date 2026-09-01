<?php
/**
 * Cloudflare Turnstile widget + server-side verify.
 *
 * Graceful no-op pokud chybí keys (vrací true z verify, nic nerenderuje).
 *
 * @package NKZMP\Antibot
 */

namespace NKZMP\Antibot;

defined( 'ABSPATH' ) || exit;

final class Turnstile {

	private static ?Turnstile $instance = null;
	private bool $script_enqueued       = false;

	public static function instance(): Turnstile {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// JS lib se enqueueuje az pri prvnim render_field().
	}

	public static function is_configured(): bool {
		$s = Settings::get();
		return $s['turnstile_site_key'] !== '' && $s['turnstile_secret_key'] !== '';
	}

	/** Vykresli widget div + zajisti enqueue Turnstile JS. */
	public function render_field(): void {
		if ( ! self::is_configured() ) {
			return;
		}
		$s = Settings::get();
		$this->maybe_enqueue_script();
		printf(
			'<div class="cf-turnstile nkzmp-antibot-turnstile" data-sitekey="%s" data-theme="auto" style="margin:12px 0;"></div>',
			esc_attr( $s['turnstile_site_key'] )
		);
	}

	private function maybe_enqueue_script(): void {
		if ( $this->script_enqueued ) {
			return;
		}
		wp_enqueue_script(
			'cf-turnstile',
			'https://challenges.cloudflare.com/turnstile/v0/api.js',
			[],
			null,
			[ 'strategy' => 'async', 'in_footer' => false ]
		);
		$this->script_enqueued = true;
	}

	/**
	 * Overi token z POST. Vraci true pokud OK, jinak WP_Error.
	 * Pokud Turnstile neni nakonfigurovan, vraci true (graceful no-op).
	 *
	 * @return true|\WP_Error
	 */
	public function verify() {
		if ( ! self::is_configured() ) {
			return true;
		}
		$token = isset( $_POST['cf-turnstile-response'] ) ? sanitize_text_field( wp_unslash( $_POST['cf-turnstile-response'] ) ) : '';
		if ( $token === '' ) {
			return new \WP_Error( 'turnstile_missing', __( 'Bezpečnostní ověření selhalo. Obnov stránku a zkus to znovu.', 'nkz-mp-antibot' ) );
		}
		$s = Settings::get();
		$resp = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'timeout' => 8,
			'body'    => [
				'secret'   => $s['turnstile_secret_key'],
				'response' => $token,
				'remoteip' => Protector::client_ip(),
			],
		] );
		if ( is_wp_error( $resp ) ) {
			// Sit za to nemuze – pustime dal, nez aby legit user uvazl.
			error_log( '[NKZMP Antibot] Turnstile API unreachable: ' . $resp->get_error_message() );
			return true;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $body ) || empty( $body['success'] ) ) {
			return new \WP_Error( 'turnstile_failed', __( 'Bezpečnostní ověření selhalo. Zkus to znovu.', 'nkz-mp-antibot' ) );
		}
		return true;
	}
}
