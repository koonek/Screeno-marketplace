<?php
/**
 * Form POST handler — admin-post.php endpoint.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class FormHandler {

	public const ACTION = 'nkzmp_vendor_register';
	public const NONCE  = 'nkzmp_vendor_register';

	private static ?FormHandler $instance = null;

	public static function instance(): FormHandler {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
	}

	public function handle(): void {
		// Nonce ověřujeme NEzávazně. Veřejná /registrace bývá cachovaná →
		// token se uloží do cache a při odeslání je už „vypršelý" → WP by
		// jinak zabil request legitimním lidem ("Vámi sledovaný odkaz vypršel").
		// Proti zneužití chrání antibot (honeypot + time gate + rate limit)
		// + honeypot níže. Filtr pro vynucení striktního nonce:
		//   add_filter( 'nkzmp/v1/registration/strict_nonce', '__return_true' );
		$nonce   = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$valid   = (bool) wp_verify_nonce( $nonce, self::NONCE );
		$strict  = (bool) apply_filters( 'nkzmp/v1/registration/strict_nonce', false );
		if ( ! $valid ) {
			if ( $strict ) {
				$this->redirect_error( __( 'Odkaz vypršel. Obnov prosím stránku a zkus to znovu.', 'nkz-mp-vendor-registration' ) );
			}
			error_log( '[NKZMP] registrace: neplatny/vyprsely nonce – pokracuji (chrani antibot). IP=' . ( $_SERVER['REMOTE_ADDR'] ?? '?' ) );
		}

		// Honeypot.
		if ( ! empty( $_POST['nkzmp_hp'] ) ) {
			$this->redirect_ok();
		}

		$name    = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$email   = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
		$ico     = sanitize_text_field( wp_unslash( $_POST['ico'] ?? '' ) );
		$website = esc_url_raw( wp_unslash( $_POST['website'] ?? '' ) );
		$bio     = sanitize_textarea_field( wp_unslash( $_POST['bio'] ?? '' ) );
		$terms   = ! empty( $_POST['terms'] );
		$gdpr    = ! empty( $_POST['gdpr'] );

		// Země podnikání – validujeme proti allowlistu Stripe modulu (fallback CZ).
		$allowed_countries = class_exists( \NKVSVS\Onboarding_Controller::class )
			? array_keys( \NKVSVS\Onboarding_Controller::allowed_countries() )
			: [ 'CZ', 'SK' ];
		$country = strtoupper( sanitize_text_field( wp_unslash( $_POST['country'] ?? '' ) ) );
		if ( ! in_array( $country, $allowed_countries, true ) ) {
			$country = 'CZ';
		}

		if ( $name === '' || ! is_email( $email ) || $ico === '' || $bio === '' || ! $terms || ! $gdpr ) {
			$this->redirect_error( __( 'Vyplň prosím všechna povinná pole a odsouhlas oba checkboxy.', 'nkz-mp-vendor-registration' ) );
		}

		// Duplicate email check.
		$existing = get_posts( [
			'post_type'      => [ 'nkv_vendor', 'nkzmp_vendor' ],
			'meta_key'       => '_nkv_vendor_email',
			'meta_value'     => $email,
			'posts_per_page' => 1,
			'fields'         => 'ids',
		] );
		if ( ! empty( $existing ) ) {
			$this->redirect_error( __( 'Pod tímhle e-mailem už přihláška existuje. Pokud ses ozval(a) a čekáš na odpověď, počkej prosím, ozveme se sami.', 'nkz-mp-vendor-registration' ) );
		}

		// Create vendor post.
		$post_type = post_type_exists( 'nkv_vendor' ) ? 'nkv_vendor' : 'nkzmp_vendor';
		$vendor_id = wp_insert_post( [
			'post_type'   => $post_type,
			'post_title'  => $name,
			'post_status' => 'publish',
			'post_name'   => sanitize_title( $name ),
		] );
		if ( is_wp_error( $vendor_id ) || ! $vendor_id ) {
			$this->redirect_error( __( 'Něco se nepovedlo na naší straně. Ozvi se prosím e-mailem.', 'nkz-mp-vendor-registration' ) );
		}

		// Meta (legacy klíče – aby legacy adapter viděl data, plus nové).
		update_post_meta( $vendor_id, '_nkv_vendor_email', $email );
		update_post_meta( $vendor_id, '_nkzmp_vendor_email', $email );
		update_post_meta( $vendor_id, '_nkv_vendor_ico', $ico );
		update_post_meta( $vendor_id, '_nkzmp_vendor_ico', $ico );
		if ( $website ) {
			update_post_meta( $vendor_id, '_nkv_vendor_website', $website );
			update_post_meta( $vendor_id, '_nkzmp_vendor_website', $website );
		}
		update_post_meta( $vendor_id, '_nkv_vendor_bio', $bio );
		update_post_meta( $vendor_id, '_nkzmp_vendor_bio', $bio );
		update_post_meta( $vendor_id, '_nkzmp_vendor_status', 'pending' );
		update_post_meta( $vendor_id, '_nkzmp_registration_submitted_at', time() );
		// Země pro Stripe onboarding (per-vendor, čte ji Onboarding_Controller).
		update_post_meta( $vendor_id, '_nkv_stripe_country', $country );

		// Nepovinná adresa pro odeslání (odesílatel na štítku Zásilkovny).
		$sender = [
			'_nkzmp_sender_name'   => sanitize_text_field( wp_unslash( $_POST['sender_name'] ?? '' ) ),
			'_nkzmp_sender_street' => sanitize_text_field( wp_unslash( $_POST['sender_street'] ?? '' ) ),
			'_nkzmp_sender_city'   => sanitize_text_field( wp_unslash( $_POST['sender_city'] ?? '' ) ),
			'_nkzmp_sender_zip'    => sanitize_text_field( wp_unslash( $_POST['sender_zip'] ?? '' ) ),
			'_nkzmp_sender_phone'  => sanitize_text_field( wp_unslash( $_POST['sender_phone'] ?? '' ) ),
		];
		foreach ( $sender as $meta_key => $meta_val ) {
			if ( $meta_val !== '' ) {
				update_post_meta( $vendor_id, $meta_key, $meta_val );
			}
		}

		// Emails: applicant + admin.
		EmailService::send_applicant_pending( $vendor_id );
		EmailService::send_admin_pending( $vendor_id );

		// Audit log entry (přes recorder).
		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'vendor.registration_submitted',
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     sprintf( 'New registration: %s <%s>', $name, $email ),
				payload:     [ 'ico' => $ico, 'website' => $website ],
				actor_label: 'registration_form',
			);
		}

		do_action( 'nkzmp/v1/registration/submitted', $vendor_id, [
			'name'    => $name,
			'email'   => $email,
			'ico'     => $ico,
			'website' => $website,
			'bio'     => $bio,
		] );

		$this->redirect_ok();
	}

	private function redirect_ok(): void {
		$redirect = Settings::get()['success_redirect'];
		$target   = $redirect !== '' ? $redirect : wp_get_referer();
		$url      = add_query_arg( 'nkzmp_reg', 'ok', $target ?: home_url( '/' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function redirect_error( string $msg ): void {
		$target = wp_get_referer() ?: home_url( '/' );
		$url    = add_query_arg( [ 'nkzmp_reg' => 'err', 'nkzmp_err' => rawurlencode( $msg ) ], $target );
		wp_safe_redirect( $url );
		exit;
	}
}
