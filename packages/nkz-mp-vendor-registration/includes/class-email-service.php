<?php
/**
 * EmailService – načítá šablony ze Settings, vykresluje placeholdery,
 * baluje do HTML wrapperu v AOZ stylu.
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
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::tpl( 'email_applicant_pending_subject', $vars );
		$body    = self::tpl( 'email_applicant_pending_body', $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_admin_pending( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$s       = Settings::get();
		$to      = $s['admin_notification_email'];
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::tpl( 'email_admin_pending_subject', $vars );
		$body    = self::tpl( 'email_admin_pending_body', $vars );
		self::send( $to, $subject, $body );
	}

	public static function send_approved_awaiting_kyc( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::tpl( 'email_approved_subject', $vars );
		$body    = self::tpl( 'email_approved_body', $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_active( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::tpl( 'email_active_subject', $vars );
		$body    = self::tpl( 'email_active_body', $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_rejected( int $vendor_id, string $reason = '' ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$vars                 = self::base_vars( $vendor_id, $vendor );
		$vars['reason_block'] = $reason !== '' ? sprintf( "Pro úplnost: %s\n\n", $reason ) : '';
		$subject              = self::tpl( 'email_rejected_subject', $vars );
		$body                 = self::tpl( 'email_rejected_body', $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	/**
	 * Lookup šablony přes core EmailSettings (s fallbackem na legacy registrační option
	 * a na hardcoded default uvnitř EmailSettings).
	 */
	private static function tpl( string $key, array $vars ): string {
		if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			return \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( $key ), $vars );
		}
		// Fallback pokud by core nebyl naloaděný (multi-plugin standalone).
		$s = Settings::get();
		return self::interpolate( (string) ( $s[ $key ] ?? '' ), $vars );
	}

	/**
	 * Veřejný entrypoint pro plaintext e-mail bez settings šablony.
	 * Použito např. pro password setup po auto-create WP usera.
	 */
	public static function send_raw( string $to, string $subject, string $body ): void {
		self::send( $to, $subject, $body );
	}

	private static function base_vars( int $vendor_id, array $vendor ): array {
		$stripe_link = '';
		if ( class_exists( \NKVSVS\Onboarding_Controller::class ) ) {
			$stripe_link = \NKVSVS\Onboarding_Controller::vendor_start_url( $vendor_id );
		}
		$post        = get_post( $vendor_id );
		$slug        = $post ? $post->post_name : '';
		$profile_url = $slug ? home_url( '/vendor/' . $slug ) : '';
		$status_url  = StatusPage::url_for( $vendor_id );

		// Přihlášení / panel prodejce. Funkce wc_* jsou dostupné jen když WC běží.
		$login_url     = function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/muj-ucet' );
		$dashboard_url = function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'vendor' ) : $login_url;

		// E-maily se posílají z frontendu (anonymní žadatel), takže
		// get_edit_post_link() vrací prázdno – nemá cap. Stavíme URL ručně,
		// je to jen redirect endpoint který si admin login vyřeší.
		// Preferujeme NKZ admin Vendor detail (read-only konsolidace), fallback
		// na WP post.php edit screen pokud core admin třída není dostupná.
		if ( class_exists( \NKZMP\Admin\VendorDetailPage::class ) ) {
			$edit_url = \NKZMP\Admin\VendorDetailPage::url( $vendor_id );
		} else {
			$edit_url = admin_url( 'post.php?post=' . $vendor_id . '&action=edit' );
		}

		return [
			'name'        => (string) $vendor['name'],
			'email'       => (string) $vendor['email'],
			'ico'         => (string) get_post_meta( $vendor_id, '_nkv_vendor_ico', true ),
			'website'     => (string) get_post_meta( $vendor_id, '_nkv_vendor_website', true ),
			'bio'         => (string) $vendor['bio'],
			'stripe_link' => $stripe_link,
			'profile_url' => $profile_url,
			'status_url'  => $status_url,
			'edit_url'    => $edit_url,
			'login_url'     => $login_url,
			'dashboard_url' => $dashboard_url,
			'site_name'   => (string) get_bloginfo( 'name' ),
			'site_url'    => (string) home_url( '/' ),
		];
	}

	private static function interpolate( string $template, array $vars ): string {
		$keys   = array_map( static fn( $k ) => '{' . $k . '}', array_keys( $vars ) );
		$values = array_values( $vars );
		return str_replace( $keys, $values, $template );
	}

	/**
	 * Pošle HTML e-mail zabalený v AOZ wrapperu. Tělo je v "plain-like"
	 * formátu — wrapper auto-konvertuje odřádkování + linky.
	 *
	 * PHPMailer default CharSet je často ISO-8859-1 (záleží na serveru),
	 * což rozbije diakritiku v subjektu i v From name. Vynucujeme UTF-8
	 * + base64 transfer encoding přes phpmailer_init dočasným hookem.
	 *
	 * From EMAIL nepřepisujeme – některé SMTP relayey (Forpsi, Active24,
	 * SendGrid restricted senders) vyžadují aby MAIL FROM == SMTP login
	 * a každý vlastní setFrom() ho zhodí (550 5.7.1). Necháme to na SMTP
	 * pluginu / wp_mail_from filtru. Brandujeme jen From NAME.
	 */
	private static function send( string $to, string $subject, string $body ): void {
		if ( ! is_email( $to ) ) {
			return;
		}

		$s          = Settings::get();
		$from_name  = (string) $s['from_name'];
		$html       = self::wrap_html( $body, $subject );
		$headers    = [
			'Content-Type: text/html; charset=UTF-8',
		];

		$force_utf8 = static function ( $phpmailer ) use ( $from_name ): void {
			$phpmailer->CharSet  = 'UTF-8';
			$phpmailer->Encoding = 'base64';
			if ( $from_name !== '' ) {
				// Jen FromName – From email zůstává tak jak ho nastavil SMTP plugin
				// nebo WP default (wp_mail_from filter).
				$phpmailer->FromName = $from_name;
			}
		};
		add_action( 'phpmailer_init', $force_utf8 );

		try {
			wp_mail( $to, $subject, $html, $headers );
		} finally {
			remove_action( 'phpmailer_init', $force_utf8 );
		}
	}

	private static function wrap_html( string $body, string $subject ): string {
		$site_name = (string) get_bloginfo( 'name' );
		$site_url  = (string) home_url( '/' );

		$html_body = self::text_to_html( $body );

		// Table-based email layout, inline styles pro Outlook/Gmail kompatibilitu.
		ob_start();
		?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:#f5f5f5;font-family:Helvetica,Arial,sans-serif;color:#000;-webkit-font-smoothing:antialiased;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f5f5f5;">
  <tr>
	<td align="center" style="padding:40px 16px;">

	  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:#ffffff;">

		<!-- Header -->
		<tr>
		  <td style="padding:48px 48px 0;">
			<div style="font-size:11px;font-weight:500;letter-spacing:0.14em;text-transform:uppercase;color:#0060FF;">
			  <?php echo esc_html( $site_name ); ?>
			</div>
		  </td>
		</tr>

		<!-- Headline -->
		<tr>
		  <td style="padding:24px 48px 0;">
			<h1 style="margin:0;font-size:28px;font-weight:400;line-height:1.2;letter-spacing:-0.02em;color:#000;">
			  <?php echo esc_html( $subject ); ?>
			</h1>
		  </td>
		</tr>

		<!-- Divider -->
		<tr>
		  <td style="padding:32px 48px 0;">
			<div style="height:1px;background:#000;line-height:1px;font-size:1px;">&nbsp;</div>
		  </td>
		</tr>

		<!-- Body -->
		<tr>
		  <td style="padding:32px 48px;font-size:16px;line-height:1.65;color:#000;">
			<?php echo $html_body; // phpcs:ignore – pre-sanitized via text_to_html ?>
		  </td>
		</tr>

		<!-- Footer -->
		<tr>
		  <td style="padding:32px 48px 48px;border-top:1px solid #000;font-size:13px;color:rgba(0,0,0,0.55);">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
			  <tr>
				<td>
				  <a href="<?php echo esc_url( $site_url ); ?>" style="color:rgba(0,0,0,0.55);text-decoration:none;border-bottom:1px solid rgba(0,0,0,0.2);">
					<?php echo esc_html( preg_replace( '#^https?://#', '', $site_url ) ); ?>
				  </a>
				</td>
				<td align="right" style="color:rgba(0,0,0,0.4);font-size:12px;">
				  <?php echo esc_html( $site_name ); ?>
				</td>
			  </tr>
			</table>
		  </td>
		</tr>

	  </table>

	  <div style="font-size:11px;color:rgba(0,0,0,0.35);padding:16px 0;">
		<?php echo esc_html( __( 'Tento e-mail je automatický, ale my ne. Stačí odpovědět.', 'nkz-mp-vendor-registration' ) ); ?>
	  </div>

	</td>
  </tr>
</table>

</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Bezpečná konverze plain-text-with-URLs na HTML s inline stylingem.
	 */
	private static function text_to_html( string $text ): string {
		$escaped = esc_html( $text );

		// Linkify URLs — accent modrou s podtržením.
		$escaped = preg_replace_callback(
			'#(https?://[^\s<]+)#i',
			static function ( $m ) {
				// Pokud je URL na samostatném řádku (potenciální CTA), styluj jako button.
				return '<a href="' . esc_url( $m[1] ) . '" style="color:#0060FF;text-decoration:none;border-bottom:1px solid #0060FF;word-break:break-all;">' . esc_html( $m[1] ) . '</a>';
			},
			$escaped
		);

		// Bloky oddělené prázdným řádkem = <p>; samostatné newliny = <br>.
		$blocks = preg_split( '/\n{2,}/', $escaped );
		$html   = '';
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( $block === '' ) {
				continue;
			}
			$block = nl2br( $block, false );
			$html .= '<p style="margin:0 0 18px;">' . $block . '</p>' . "\n";
		}
		return $html;
	}

	private static function vendor( int $vendor_id ): ?array {
		if ( ! class_exists( \NKZMP\Vendor\Repository::class ) ) {
			return null;
		}
		return ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
	}
}
