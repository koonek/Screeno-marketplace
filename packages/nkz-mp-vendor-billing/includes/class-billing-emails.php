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

		$subject = sprintf( __( 'Platba členství neprošla — %s', 'nkz-mp-vendor-billing' ), $vars['site'] );
		$body    = sprintf(
			/* translators: 1: jméno, 2: datum, 3: počet dní, 4: odkaz */
			__( "Ahoj %1\$s,\n\nplatba za měsíční členství nám nedorazila – nejspíš vypršela nebo se zamítla karta.\n\nProdávat můžeš dál až do %2\$s (%3\$d dní). Pokud se do té doby platba nepodaří, tvoje produkty dočasně skryjeme z obchodu.\n\nOprav platební metodu tady:\n%4\$s\n\nDík!", 'nkz-mp-vendor-billing' ),
			$vars['name'],
			wp_date( 'j. n. Y', $deadline ),
			max( 0, $grace ),
			$vars['billing_url']
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

		$subject = sprintf( __( 'Členství pozastaveno — %s', 'nkz-mp-vendor-billing' ), $vars['site'] );
		$body    = sprintf(
			/* translators: 1: jméno, 2: odkaz */
			__( "Ahoj %1\$s,\n\nplatba za členství se nepodařila ani v náhradní lhůtě, takže jsme tvoje produkty dočasně skryli z obchodu. Účet ani produkty nemažeme – zůstávají ti uložené.\n\nJakmile členství obnovíš, produkty se vrátí do prodeje automaticky:\n%2\$s\n\nKdyby něco nebylo jasné, ozvi se nám.", 'nkz-mp-vendor-billing' ),
			$vars['name'],
			$vars['billing_url']
		);

		self::send( $vars['email'], $subject, $body, 'suspended', $vendor_id );
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
