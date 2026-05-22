<?php
/**
 * E-mailové zprávy v AOZ tone-of-voice (Komunikační manuál).
 *
 *  - Suchý inteligentní humor, přirozená autorita, podporují, inspirující, upřímná.
 *  - "Art of život" vždy s velkým A, neskloňuje se.
 *  - 3. osoba jednotného čísla pro značku, 1. osoba množného pro mluvčí.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class EmailService {

	public static function send_applicant_pending( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$subject = __( 'Tvoji přihlášku jsme dostali — Art of život', 'nkz-mp-vendor-registration' );
		$body    = sprintf(
			"Ahoj %s,\n\n" .
			"přihlášku jsme přijali a otevřeli. Projdeme ji v týmu Art of život a vrátíme se ti.\n\n" .
			"Není to automat. Každou tvorbu si projdeme osobně — proto to může chvíli trvat.\n\n" .
			"Mezitím se klidně podívej, kdo všechno už u Art of život je.\n\n" .
			"Tým Art of život",
			$vendor['name']
		);
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_admin_pending( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$to      = Settings::get()['admin_notification_email'];
		$subject = sprintf( '[Art of život] Nová přihláška: %s', $vendor['name'] );
		$edit    = get_edit_post_link( $vendor_id, '' );
		$body    = sprintf(
			"Nová přihláška na Art of život.\n\n" .
			"Jméno: %s\n" .
			"E-mail: %s\n" .
			"IČO: %s\n" .
			"Web: %s\n\n" .
			"Popis tvorby:\n%s\n\n" .
			"Schválit nebo zamítnout v adminu:\n%s\n",
			$vendor['name'],
			$vendor['email'],
			get_post_meta( $vendor_id, '_nkv_vendor_ico', true ),
			get_post_meta( $vendor_id, '_nkv_vendor_website', true ),
			$vendor['bio'],
			$edit
		);
		self::send( $to, $subject, $body );
	}

	public static function send_approved_awaiting_kyc( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}

		$stripe_link = '';
		if ( class_exists( \NKVSVS\Onboarding_Controller::class ) ) {
			$stripe_link = \NKVSVS\Onboarding_Controller::vendor_start_url( $vendor_id );
		}

		$subject = __( 'Jsi v Art of život. Zbývá jeden krok.', 'nkz-mp-vendor-registration' );
		$body    = sprintf(
			"Ahoj %s,\n\n" .
			"vybrali jsme tě. Tvoje práce do Art of život patří.\n\n" .
			"Než se to spustí, musí proběhnout jedna formalita: registrace platby přes Stripe. " .
			"Trvá to pár minut, vyplníš všechno přímo u nich, my k tomu nemáme přístup.\n\n" .
			"Tady je tvůj odkaz (jen pro tebe):\n%s\n\n" .
			"Až to dokončíš, dáme ti vědět a tvoje produkty pustíme do prodeje.\n\n" .
			"Tým Art of život",
			$vendor['name'],
			$stripe_link ?: __( '(Stripe link bude doplněn ručně — kontaktuj nás.)', 'nkz-mp-vendor-registration' )
		);
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_active( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$slug      = get_post( $vendor_id ) ? get_post( $vendor_id )->post_name : '';
		$profile   = $slug ? home_url( '/vendor/' . $slug ) : home_url( '/vendors' );
		$subject   = __( 'Vítej v Art of život. Můžeš prodávat.', 'nkz-mp-vendor-registration' );
		$body      = sprintf(
			"Ahoj %s,\n\n" .
			"je to oficiální — tvůj profil v Art of život je živý a tvoje produkty se mohou prodávat.\n\n" .
			"Tvůj profil:\n%s\n\n" .
			"V adminu si můžeš přidávat produkty, upravit popis a nahrát obrázek. " .
			"S čímkoli se ozvi, jsme tady.\n\n" .
			"Tým Art of život",
			$vendor['name'],
			$profile
		);
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_rejected( int $vendor_id, string $reason = '' ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$subject = __( 'Tvoje přihláška — Art of život', 'nkz-mp-vendor-registration' );
		$body    = sprintf(
			"Ahoj %s,\n\n" .
			"děkujeme za přihlášku a důvěru. Letošní ročník jsme koncipovali jiným směrem a do výběru jsme tě tentokrát nezařadili.\n\n" .
			"Tvorby je víc než prostoru, a to je vlastně dobrá zpráva.\n\n" .
			"%s" .
			"Tým Art of život",
			$vendor['name'],
			$reason ? sprintf( "Pro úplnost: %s\n\n", $reason ) : ''
		);
		self::send( $vendor['email'], $subject, $body );
	}

	private static function send( string $to, string $subject, string $body ): void {
		if ( ! is_email( $to ) ) {
			return;
		}
		$headers = [
			'Content-Type: text/plain; charset=UTF-8',
			'From: ' . sprintf( 'Art of život <%s>', get_option( 'admin_email' ) ),
		];
		wp_mail( $to, $subject, $body, $headers );
	}

	private static function vendor( int $vendor_id ): ?array {
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return null;
		}
		return ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
	}
}
