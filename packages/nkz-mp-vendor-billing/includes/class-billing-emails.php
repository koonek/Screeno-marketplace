<?php
/**
 * BillingEmails – zprávy prodejci kolem placení členství.
 *
 * Proč: dosud se prodejce o neúspěšné platbě dozvěděl jen tak, že se sám
 * přihlásil a všiml si stavu „Po splatnosti". Než mu vyprší ochranná lhůta
 * a produkty zmizí z prodeje, klidně o tom nemusel vůbec vědět.
 *
 * Posíláme dvě zprávy:
 *  1. Platba neprošla – s termínem, dokdy to jde napravit.
 *  2. Členství pozastaveno – produkty skryté, jak to obnovit.
 *
 * Idempotentní přes meta příznak (jedna zpráva na jedno selhání).
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class BillingEmails {

	private const FLAG_FAILED    = '_nkzmp_billing_mail_failed_at';
	private const FLAG_SUSPENDED = '_nkzmp_billing_mail_suspended_at';

	/** Platba neprošla → prodejce má čas do konce ochranné lhůty. */
	public static function payment_failed( int $vendor_id ): void {
		$failed_at = (int) get_post_meta( $vendor_id, '_nkzmp_billing_failed_at', true ) ?: time();
		// Jedna zpráva na jedno selhání (ne na každý retry Stripe).
		if ( (int) get_post_meta( $vendor_id, self::FLAG_FAILED, true ) === $failed_at ) {
			return;
		}
		update_post_meta( $vendor_id, self::FLAG_FAILED, $failed_at );

		$grace    = (int) Settings::get()['grace_days'];
		$deadline = $failed_at + $grace * DAY_IN_SECONDS;
		$vars     = self::vendor_vars( $vendor_id );
		if ( ! $vars ) {
			return;
		}

		[ $subject, $body ] = self::template(
			'email_billing_failed',
			$vars + [
				'deadline'   => wp_date( 'j. n. Y', $deadline ),
				'grace_days' => (string) max( 0, $grace ),
			],
			$vendor_id
		);

		self::send( $vars['email'], $subject, $body, 'payment_failed', $vendor_id );
	}

	/** Ochranná lhůta vypršela → členství pozastaveno, produkty skryté. */
	public static function suspended( int $vendor_id ): void {
		if ( (int) get_post_meta( $vendor_id, self::FLAG_SUSPENDED, true ) > 0 ) {
			return;
		}
		update_post_meta( $vendor_id, self::FLAG_SUSPENDED, time() );

		$vars = self::vendor_vars( $vendor_id );
		if ( ! $vars ) {
			return;
		}

		[ $subject, $body ] = self::template( 'email_billing_suspended', $vars, $vendor_id );

		self::send( $vars['email'], $subject, $body, 'suspended', $vendor_id );
	}

	/**
	 * Načte šablonu z NKZ Marketplace → E-maily (editovatelná adminem)
	 * a doplní placeholdery. Fallback na vestavěný text, kdyby modul chyběl.
	 *
	 * @return array{0:string,1:string} [subject, body]
	 */
	private static function template( string $key, array $vars, int $vendor_id ): array {
		$placeholders = [
			'name'          => (string) ( $vars['name'] ?? '' ),
			'name_vocative' => class_exists( \NKZMP\Services\VocativeService::class )
				? \NKZMP\Services\VocativeService::get( (string) ( $vars['name'] ?? '' ), $vendor_id )
				: (string) ( $vars['name'] ?? '' ),
			'billing_url'   => (string) ( $vars['billing_url'] ?? '' ),
			'site_name'     => (string) ( $vars['site'] ?? '' ),
			'deadline'      => (string) ( $vars['deadline'] ?? '' ),
			'grace_days'    => (string) ( $vars['grace_days'] ?? '' ),
		];

		if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			$subject = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( $key . '_subject' ), $placeholders );
			$body    = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( $key . '_body' ), $placeholders );
			if ( $subject !== '' && $body !== '' ) {
				return [ $subject, $body ];
			}
		}

		// Fallback – kdyby core modul nebyl aktivní.
		if ( $key === 'email_billing_suspended' ) {
			return [
				sprintf( __( 'Členství pozastaveno — %s', 'nkz-mp-vendor-billing' ), $placeholders['site_name'] ),
				sprintf(
					__( "Ahoj %1\$s,\n\nplatba za členství se nepodařila ani v náhradní lhůtě, takže jsme tvoje produkty dočasně skryli z obchodu. Účet ani produkty nemažeme.\n\nObnovit členství můžeš tady:\n%2\$s", 'nkz-mp-vendor-billing' ),
					$placeholders['name'],
					$placeholders['billing_url']
				),
			];
		}
		return [
			sprintf( __( 'Platba členství neprošla — %s', 'nkz-mp-vendor-billing' ), $placeholders['site_name'] ),
			sprintf(
				__( "Ahoj %1\$s,\n\nplatba za měsíční členství nám nedorazila. Prodávat můžeš dál do %2\$s, pak produkty dočasně skryjeme.\n\nOprav platební metodu tady:\n%3\$s", 'nkz-mp-vendor-billing' ),
				$placeholders['name'],
				$placeholders['deadline'],
				$placeholders['billing_url']
			),
		];
	}

	/** Platba prošla → vyčistíme příznaky, ať příště zprávy zase dorazí. */
	public static function reset( int $vendor_id ): void {
		delete_post_meta( $vendor_id, self::FLAG_FAILED );
		delete_post_meta( $vendor_id, self::FLAG_SUSPENDED );
	}

	/** @return array{name:string,email:string,site:string,billing_url:string}|null */
	private static function vendor_vars( int $vendor_id ): ?array {
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return null;
		}
		$vendor = ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
		if ( ! $vendor || empty( $vendor['email'] ) || ! is_email( (string) $vendor['email'] ) ) {
			return null;
		}
		return [
			'name'        => (string) ( $vendor['name'] ?? '' ),
			'email'       => (string) $vendor['email'],
			'site'        => (string) get_bloginfo( 'name' ),
			'billing_url' => function_exists( 'wc_get_account_endpoint_url' )
				? wc_get_account_endpoint_url( AccountSection::SLUG )
				: home_url( '/muj-ucet/' ),
		];
	}

	private static function send( string $to, string $subject, string $body, string $type, int $vendor_id ): void {
		$subject = (string) apply_filters( 'nkzmp/v1/billing/email_subject', $subject, $type, $vendor_id );
		$body    = (string) apply_filters( 'nkzmp/v1/billing/email_body', $body, $type, $vendor_id );

		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
		} else {
			wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		}

		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'billing.email_' . $type,
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     'Billing e-mail odeslán: ' . $type,
			);
		}
	}
}
