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
		$vars = [
			'name'          => (string) $vendor['name'],
			'product_name'  => $product->get_name(),
			'dashboard_url' => wc_get_account_endpoint_url( 'vendor-products' ),
			'site_name'     => (string) get_bloginfo( 'name' ),
		];
		$key_subj = $is_edit ? 'email_product_edit_vendor_subject' : 'email_product_new_vendor_subject';
		$key_body = $is_edit ? 'email_product_edit_vendor_body'    : 'email_product_new_vendor_body';
		$subject  = self::render_tpl( $key_subj, $vars, sprintf( 'Tvůj produkt — %s', $vars['site_name'] ) );
		$body     = self::render_tpl( $key_body, $vars, sprintf( "Ahoj %s,\n\nprodukt „%s\" jsme přijali.\n\n%s\n\nTým %s",
			$vars['name'], $vars['product_name'], $vars['dashboard_url'], $vars['site_name'] ) );
		self::send( (string) $vendor['email'], $subject, $body );
	}

	private static function send_admin_notification( \WC_Product $product, int $vendor_id, bool $is_edit ): void {
		$vendor = self::vendor( $vendor_id );
		$to     = self::admin_recipient();
		if ( ! is_email( $to ) ) {
			return;
		}

		$vars = [
			'product_name'  => $product->get_name(),
			'product_price' => wp_strip_all_tags( (string) $product->get_price_html() ),
			'vendor_name'   => $vendor ? (string) $vendor['name'] : ( '#' . $vendor_id ),
			'vendor_email'  => $vendor && ! empty( $vendor['email'] ) ? (string) $vendor['email'] : '',
			'dashboard_url' => admin_url( 'admin.php?page=' . ( defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'nkz-marketplace' ) ),
			'edit_url'      => (string) get_edit_post_link( $product->get_id(), '' ),
			'submit_kind'   => $is_edit ? __( 'Úprava', 'nkz-mp-vendor-dashboard' ) : __( 'Nový produkt', 'nkz-mp-vendor-dashboard' ),
			'site_name'     => (string) get_bloginfo( 'name' ),
		];

		$subject = self::render_tpl( 'email_product_admin_subject', $vars,
			sprintf( '[%s] %s: %s', $vars['site_name'], $vars['submit_kind'], $vars['product_name'] ) );
		$body    = self::render_tpl( 'email_product_admin_body', $vars,
			sprintf( "Produkt: %s\nProdejce: %s\n\n%s", $vars['product_name'], $vars['vendor_name'], $vars['edit_url'] ) );

		self::send( $to, $subject, $body );
	}

	/**
	 * Šablona z core EmailSettings s fallbackem pokud option chybí.
	 */
	private static function render_tpl( string $key, array $vars, string $fallback ): string {
		if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			$raw = \NKZMP\Admin\EmailSettings::raw( $key );
			if ( $raw !== '' ) {
				return \NKZMP\Admin\EmailSettings::interpolate( $raw, $vars );
			}
		}
		return $fallback;
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
