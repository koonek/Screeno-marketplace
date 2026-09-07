<?php
/**
 * WCEmailStyle – sladí defaultní WooCommerce transakční e-maily
 * (new order, processing, completed, refund, customer note, …)
 * s naším AOZ designem.
 *
 * Pracujeme CSS-only přístupem: necháváme defaultní WC template strukturu
 * (`#template_container`, `#template_header`, `#template_body`, …) a
 * přebíjíme inline CSS přes filter `woocommerce_email_styles`. Žádný
 * template override → kompatibilní napříč WC verzemi a UPS pluginy.
 *
 * Sdílí tokeny s naším EmailService (registration emails) přes filter
 * `nkzmp/v1/email/tokens`, takže Screeno přebije branding jednou na
 * obou frontách.
 *
 * Vypnutí: add_filter( 'nkzmp/v1/email/wc_styles', '__return_false' );
 *
 * @package NKZMP\Emails
 */

namespace NKZMP\Emails;

defined( 'ABSPATH' ) || exit;

final class WCEmailStyle {

	private static ?WCEmailStyle $instance = null;

	public static function instance(): WCEmailStyle {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/email/wc_styles', true ) ) {
			return;
		}
		add_filter( 'woocommerce_email_styles', [ $this, 'styles' ], 99, 2 );
		add_filter( 'woocommerce_email_from_name', [ $this, 'from_name' ], 99 );
		add_filter( 'woocommerce_email_footer_text', [ $this, 'footer_text' ], 99 );
	}

	/**
	 * Vrátí AOZ branding tokeny (sdílené s naším EmailService wrapperem).
	 *
	 * @return array<string,string>
	 */
	private static function tokens(): array {
		return array_merge( [
			'accent'      => '#0060FF',
			'accent_ink'  => '#0048cc',
			'bg'          => '#f3f0ea',
			'surface'     => '#ffffff',
			'text'        => '#111111',
			'muted'       => 'rgba(17,17,17,0.55)',
			'border'      => 'rgba(17,17,17,0.10)',
			'border_soft' => 'rgba(17,17,17,0.06)',
			'radius'      => '12px',
			'font'        => "'Helvetica Neue',Helvetica,Arial,sans-serif",
		], (array) apply_filters( 'nkzmp/v1/email/tokens', [] ) );
	}

	/** Kompletní rewrite inline CSS pro WC maily. */
	public function styles( $css, $email = null ): string {
		$t = self::tokens();

		// Pozn.: WC inline-uje CSS přes Emogrifier. Tj. selectory matchují
		// HTML strukturu z `templates/emails/email-header.php` +
		// `email-styles.php` (#wrapper, #template_container, #template_header,
		// #template_body, #body_content, #template_footer, .order-items, …).
		$accent = $t['accent']; $bg = $t['bg']; $surface = $t['surface'];
		$text = $t['text']; $muted = $t['muted']; $border = $t['border'];
		$soft = $t['border_soft']; $radius = $t['radius']; $font = $t['font'];

		return "
		body, html {
			background-color: {$bg} !important;
			color: {$text};
			font-family: {$font};
			-webkit-font-smoothing: antialiased;
			margin: 0;
			padding: 0;
		}
		#wrapper {
			background-color: {$bg};
			margin: 0;
			padding: 40px 16px;
			-webkit-text-size-adjust: none !important;
		}
		#template_container {
			background-color: {$surface};
			border: 1px solid {$border};
			border-radius: {$radius};
			box-shadow: none;
			overflow: hidden;
			max-width: 600px;
		}
		#template_header {
			background-color: {$surface};
			color: {$text};
			border-radius: {$radius} {$radius} 0 0;
			border-bottom: 1px solid {$soft};
			padding: 36px 44px 28px;
		}
		#template_header h1, #template_header h1 a {
			color: {$text};
			font-family: {$font};
			font-size: 26px;
			font-weight: 600;
			letter-spacing: -0.02em;
			line-height: 1.2;
			margin: 0;
			padding: 0;
			text-align: left;
			text-shadow: none;
		}
		#template_header_image {
			margin: 0 0 16px;
		}
		#template_header_image img {
			margin: 0;
			max-width: 220px;
			height: auto;
		}
		#template_body {
			background-color: {$surface};
			padding: 0;
		}
		#body_content {
			background-color: {$surface};
			color: {$text};
			padding: 0;
		}
		#body_content table {
			background-color: {$surface};
		}
		#body_content table td {
			padding: 36px 44px;
			font-family: {$font};
			color: {$text};
			font-size: 16px;
			line-height: 1.65;
		}
		#body_content td ul.wc-item-meta {
			font-size: 13px;
			color: {$muted};
			margin: 6px 0 0;
			padding: 0;
		}
		#body_content td ul.wc-item-meta li {
			margin: 0 0 2px;
		}
		#body_content p {
			margin: 0 0 16px;
		}
		#body_content_inner {
			color: {$text};
			font-family: {$font};
			font-size: 16px;
			line-height: 1.65;
			text-align: left;
		}
		.td, td.td, th.td {
			color: {$text};
			border-color: {$soft};
			vertical-align: middle;
			font-family: {$font};
		}
		.text {
			color: {$text};
			font-family: {$font};
		}
		h1, h2, h3, h4 {
			color: {$text};
			font-family: {$font};
			font-weight: 600;
			letter-spacing: -0.015em;
		}
		h1 { font-size: 26px; line-height: 1.2; margin: 0 0 18px; }
		h2 { font-size: 20px; line-height: 1.3; margin: 28px 0 12px; }
		h3 { font-size: 16px; line-height: 1.4; margin: 22px 0 8px; }
		a {
			color: {$accent};
			font-weight: 500;
			text-decoration: none;
			border-bottom: 1px solid {$accent};
		}
		a:hover { color: {$accent}; }
		img { border: none; display: inline-block; height: auto; outline: none; text-decoration: none; }
		/* Order items table */
		table.td {
			border: 0 !important;
			border-collapse: separate !important;
			border-spacing: 0 !important;
		}
		table.td th, table.td td {
			border: 0 !important;
			border-bottom: 1px solid {$soft} !important;
			padding: 12px 0 !important;
			background: transparent !important;
			color: {$text};
		}
		table.td th {
			font-size: 11px;
			font-weight: 600;
			letter-spacing: 0.12em;
			text-transform: uppercase;
			color: {$muted};
		}
		table.td tfoot th, table.td tfoot td {
			padding: 10px 0 !important;
			border-bottom: 1px solid {$soft} !important;
		}
		table.td tfoot tr:last-child th, table.td tfoot tr:last-child td {
			font-size: 16px;
			font-weight: 600;
			border-bottom: 0 !important;
			padding-top: 14px !important;
		}
		.order_item {
			background-color: transparent !important;
			border-bottom: 1px solid {$soft};
		}
		.order_item td {
			padding: 14px 0 !important;
			vertical-align: top !important;
		}
		address, .address {
			background-color: transparent !important;
			border: 0 !important;
			padding: 0 !important;
			margin: 0 !important;
			font-style: normal;
			line-height: 1.65;
			color: {$text};
		}
		/* Customer details block (2 sloupce billing/shipping) */
		.customer-details, .customer_details {
			background-color: transparent !important;
		}
		/* Footer */
		#template_footer td {
			background-color: {$surface};
			padding: 22px 44px 32px !important;
			border-top: 1px solid {$soft};
			color: {$muted};
			font-family: {$font};
			font-size: 13px;
			text-align: left;
		}
		#template_footer #credit {
			color: {$muted};
			font-size: 12px;
			font-family: {$font};
			text-align: left;
			border: 0;
			margin: 0;
			padding: 0;
			line-height: 1.6;
		}
		#template_footer #credit a {
			color: {$muted};
			border-bottom: 0;
		}
		";
	}

	/**
	 * From name pro WC maily – sjednotí s naším registračním from_name.
	 *
	 * @param string $name
	 */
	public function from_name( $name ): string {
		if ( ! class_exists( \NKZMP\Registration\Settings::class ) ) {
			return (string) $name;
		}
		$s = \NKZMP\Registration\Settings::get();
		$override = isset( $s['from_name'] ) ? trim( (string) $s['from_name'] ) : '';
		return $override !== '' ? $override : (string) $name;
	}

	/**
	 * Defaultní WC footer („© Site Name"). Necháme decentní řádek s odkazem.
	 *
	 * @param string $text
	 */
	public function footer_text( $text ): string {
		$site_name = (string) get_bloginfo( 'name' );
		$site_url  = (string) home_url( '/' );
		$host      = (string) preg_replace( '#^https?://#', '', $site_url );
		$host      = rtrim( $host, '/' );

		return sprintf(
			'<a href="%s" style="color:inherit;text-decoration:none;">%s</a> &middot; %s',
			esc_url( $site_url ),
			esc_html( $host ),
			esc_html( $site_name )
		);
	}
}
