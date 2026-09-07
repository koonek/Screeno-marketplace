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

		$name          = (string) $vendor['name'];
		$name_vocative = class_exists( \NKZMP\Services\VocativeService::class )
			? \NKZMP\Services\VocativeService::get( $name, $vendor_id )
			: $name;

		return [
			'name'           => $name,
			'name_vocative'  => $name_vocative,
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

		// Tokeny – Screeno přebije přes nkzmp/v1/email/tokens bez editace kódu.
		$t = array_merge( [
			'accent'      => '#0060FF',
			'accent_ink'  => '#0048cc',
			'bg'          => '#f3f0ea',   // teplý krémový rámec
			'surface'     => '#ffffff',   // bílá karta
			'text'        => '#111111',
			'muted'       => 'rgba(17,17,17,0.55)',
			'border'      => 'rgba(17,17,17,0.10)',
			'radius'      => '12px',
			'font'        => "'Helvetica Neue',Helvetica,Arial,sans-serif",
			'logo'        => '',         // image URL; prázdné = textový kicker
			'logo_height' => '32',
			'footer_tag'  => __( 'Tento e-mail je automatický, ale my ne. Stačí odpovědět.', 'nkz-mp-vendor-registration' ),
		], (array) apply_filters( 'nkzmp/v1/email/tokens', [] ) );

		$html_body = self::text_to_html( $body, $t );

		// Header: buď logo, nebo textový kicker se site_name.
		if ( $t['logo'] !== '' ) {
			$header = sprintf(
				'<img src="%s" alt="%s" height="%s" style="display:block;border:0;outline:none;text-decoration:none;height:%spx;width:auto;" />',
				esc_url( (string) $t['logo'] ),
				esc_attr( $site_name ),
				esc_attr( (string) $t['logo_height'] ),
				esc_attr( (string) $t['logo_height'] )
			);
		} else {
			$header = sprintf(
				'<div style="font-size:11px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:%s;">%s</div>',
				esc_attr( (string) $t['accent'] ),
				esc_html( $site_name )
			);
		}

		ob_start();
		?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title><?php echo esc_html( $subject ); ?></title>
</head>
<body style="margin:0;padding:0;background:<?php echo esc_attr( (string) $t['bg'] ); ?>;font-family:<?php echo $t['font']; // phpcs:ignore ?>;color:<?php echo esc_attr( (string) $t['text'] ); ?>;-webkit-font-smoothing:antialiased;">

<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:<?php echo esc_attr( (string) $t['bg'] ); ?>;">
  <tr>
	<td align="center" style="padding:40px 16px;">

	  <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="max-width:600px;width:100%;background:<?php echo esc_attr( (string) $t['surface'] ); ?>;border:1px solid <?php echo esc_attr( (string) $t['border'] ); ?>;border-radius:<?php echo esc_attr( (string) $t['radius'] ); ?>;overflow:hidden;">

		<!-- Header -->
		<tr>
		  <td style="padding:40px 44px 12px;"><?php echo $header; // phpcs:ignore ?></td>
		</tr>

		<!-- Headline -->
		<tr>
		  <td style="padding:8px 44px 28px;">
			<h1 style="margin:0;font-size:26px;font-weight:600;line-height:1.2;letter-spacing:-0.02em;color:<?php echo esc_attr( (string) $t['text'] ); ?>;">
			  <?php echo esc_html( $subject ); ?>
			</h1>
		  </td>
		</tr>

		<!-- Body -->
		<tr>
		  <td style="padding:0 44px 36px;font-size:16px;line-height:1.65;color:<?php echo esc_attr( (string) $t['text'] ); ?>;">
			<?php echo $html_body; // phpcs:ignore – pre-sanitized via text_to_html ?>
		  </td>
		</tr>

		<!-- Footer -->
		<tr>
		  <td style="padding:24px 44px 32px;border-top:1px solid <?php echo esc_attr( (string) $t['border'] ); ?>;font-size:13px;color:<?php echo esc_attr( (string) $t['muted'] ); ?>;">
			<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">
			  <tr>
				<td>
				  <a href="<?php echo esc_url( $site_url ); ?>" style="color:<?php echo esc_attr( (string) $t['muted'] ); ?>;text-decoration:none;">
					<?php echo esc_html( preg_replace( '#^https?://#', '', $site_url ) ); ?>
				  </a>
				</td>
				<td align="right" style="color:<?php echo esc_attr( (string) $t['muted'] ); ?>;font-size:12px;">
				  <?php echo esc_html( $site_name ); ?>
				</td>
			  </tr>
			</table>
		  </td>
		</tr>

	  </table>

	  <?php if ( $t['footer_tag'] !== '' ) : ?>
	  <div style="font-size:11px;color:rgba(17,17,17,0.42);padding:16px 0;font-family:<?php echo $t['font']; // phpcs:ignore ?>;">
		<?php echo esc_html( (string) $t['footer_tag'] ); ?>
	  </div>
	  <?php endif; ?>

	</td>
  </tr>
</table>

</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Bezpečná konverze plain-text-with-URLs na HTML.
	 * URL na vlastním řádku = button (CTA). Inline URL = obyčejný link.
	 *
	 * @param array<string,string> $t tokens
	 */
	private static function text_to_html( string $text, array $t = [] ): string {
		$accent     = (string) ( $t['accent']     ?? '#0060FF' );
		$accent_ink = (string) ( $t['accent_ink'] ?? '#0048cc' );

		// Bloky oddělené prázdným řádkem.
		$blocks = preg_split( '/\n{2,}/', trim( $text ) );
		$html   = '';
		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( $block === '' ) {
				continue;
			}
			// Celý blok = jedna URL → renderuj jako CTA button.
			if ( preg_match( '#^(https?://\S+)$#i', $block, $m ) ) {
				$url = $m[1];
				$html .= '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 22px;"><tr><td style="border-radius:8px;background:' . esc_attr( $accent ) . ';">'
					. '<a href="' . esc_url( $url ) . '" style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:8px;background:' . esc_attr( $accent ) . ';">'
					. esc_html__( 'Otevřít odkaz', 'nkz-mp-vendor-registration' )
					. '</a>'
					. '</td></tr></table>';
				continue;
			}
			// Jinak: linkify inline URL a wrapni do <p>.
			$escaped = esc_html( $block );
			$escaped = preg_replace_callback(
				'#(https?://[^\s<]+)#i',
				static function ( $m ) use ( $accent, $accent_ink ) {
					return '<a href="' . esc_url( $m[1] ) . '" style="color:' . esc_attr( $accent ) . ';text-decoration:none;border-bottom:1px solid ' . esc_attr( $accent ) . ';word-break:break-all;">' . esc_html( $m[1] ) . '</a>';
				},
				$escaped
			);
			$escaped = nl2br( $escaped, false );
			$html   .= '<p style="margin:0 0 18px;">' . $escaped . '</p>' . "\n";
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
