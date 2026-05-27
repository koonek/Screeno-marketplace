<?php
/**
 * ProductEmails – AOZ-styled e-maily okolo product workflow.
 *
 * Po submit: vendor confirmation + admin notification.
 * Po publish: vendor "tvůj produkt je živý" (volá DashboardPage handler).
 *
 * Pokud je dostupný registration EmailService::send_raw (HTML wrapper s
 * AOZ stylingem), použijeme ho. Jinak fallback na wp_mail s plain text.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ProductEmails {

	public static function on_submitted( int $product_id, int $vendor_id, bool $is_edit ): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		self::send_vendor_confirmation( $product, $vendor_id, $is_edit );
		self::send_admin_notification( $product, $vendor_id, $is_edit );
	}

	private static function send_vendor_confirmation( \WC_Product $product, int $vendor_id, bool $is_edit ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor || empty( $vendor['email'] ) ) {
			return;
		}
		$site = (string) get_bloginfo( 'name' );
		if ( $is_edit ) {
			$subject = sprintf( 'Tvoji úpravu jsme dostali — %s', $site );
			$body    = sprintf(
				"Ahoj %s,\n\n" .
				"úpravu produktu „%s\" jsme dostali. Projdeme ji v týmu a zase publikujeme.\n\n" .
				"Mezitím produkt visí v tvém panelu pod stavem „Čeká schválení\":\n%s\n\n" .
				"Tým %s",
				$vendor['name'],
				$product->get_name(),
				wc_get_account_endpoint_url( 'vendor-products' ),
				$site
			);
		} else {
			$subject = sprintf( 'Tvůj produkt jsme dostali — %s', $site );
			$body    = sprintf(
				"Ahoj %s,\n\n" .
				"produkt „%s\" jsme přijali. Projdeme ho a pokud sedne k Art of život, publikujeme ho.\n\n" .
				"Není to automat — každý produkt si projdeme osobně. Můžeme se ozvat s otázkami.\n\n" .
				"V tvém panelu máš produkt pod stavem „Čeká schválení\":\n%s\n\n" .
				"Tým %s",
				$vendor['name'],
				$product->get_name(),
				wc_get_account_endpoint_url( 'vendor-products' ),
				$site
			);
		}
		self::send( (string) $vendor['email'], $subject, $body );
	}

	private static function send_admin_notification( \WC_Product $product, int $vendor_id, bool $is_edit ): void {
		$vendor = self::vendor( $vendor_id );
		$site   = (string) get_bloginfo( 'name' );
		$to     = self::admin_recipient();
		if ( ! is_email( $to ) ) {
			return;
		}

		$subject_word = $is_edit ? 'Úprava' : 'Nový produkt';
		$body_word    = $is_edit ? 'úpravu produktu' : 'nový produkt';
		$subject      = sprintf( '[%s] %s ke schválení: %s', $site, $subject_word, $product->get_name() );

		$edit_url   = (string) get_edit_post_link( $product->get_id(), '' );
		$dash_url   = admin_url( 'admin.php?page=' . ( defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'nkz-marketplace' ) );

		$body = sprintf(
			"Prodejce poslal %s ke schválení.\n\n" .
			"Produkt: %s\n" .
			"Cena: %s\n" .
			"Prodejce: %s%s\n\n" .
			"Schválit publikování můžeš v Dashboardu (sekce „Čekající produkty“):\n%s\n\n" .
			"Detail produktu:\n%s\n",
			$body_word,
			$product->get_name(),
			wp_strip_all_tags( (string) $product->get_price_html() ),
			$vendor ? $vendor['name'] : ( '#' . $vendor_id ),
			$vendor && ! empty( $vendor['email'] ) ? ' <' . $vendor['email'] . '>' : '',
			$dash_url,
			$edit_url
		);

		self::send( $to, $subject, $body );
	}

	private static function admin_recipient(): string {
		// Pokud existuje registration settings, použij admin_notification_email,
		// jinak fallback na admin_email.
		if ( class_exists( \NKZMP\Registration\Settings::class ) ) {
			$reg = \NKZMP\Registration\Settings::get();
			if ( ! empty( $reg['admin_notification_email'] ) ) {
				return (string) $reg['admin_notification_email'];
			}
		}
		return (string) get_option( 'admin_email' );
	}

	private static function vendor( int $vendor_id ): ?array {
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return null;
		}
		return ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
	}

	private static function send( string $to, string $subject, string $body ): void {
		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			return;
		}
		// Fallback plain text. PHPMailer default CharSet bývá ISO-8859-1, což
		// rozbije diakritiku v subjektu i těle – vynutíme UTF-8 + base64.
		$force_utf8 = static function ( $phpmailer ): void {
			$phpmailer->CharSet  = 'UTF-8';
			$phpmailer->Encoding = 'base64';
		};
		add_action( 'phpmailer_init', $force_utf8 );
		try {
			wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		} finally {
			remove_action( 'phpmailer_init', $force_utf8 );
		}
	}
}
