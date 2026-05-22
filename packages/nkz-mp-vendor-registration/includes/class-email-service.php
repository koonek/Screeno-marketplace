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
		$s        = Settings::get();
		$vars     = self::base_vars( $vendor_id, $vendor );
		$subject  = self::interpolate( $s['email_applicant_pending_subject'], $vars );
		$body     = self::interpolate( $s['email_applicant_pending_body'], $vars );
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
		$subject = self::interpolate( $s['email_admin_pending_subject'], $vars );
		$body    = self::interpolate( $s['email_admin_pending_body'], $vars );
		self::send( $to, $subject, $body );
	}

	public static function send_approved_awaiting_kyc( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$s       = Settings::get();
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::interpolate( $s['email_approved_subject'], $vars );
		$body    = self::interpolate( $s['email_approved_body'], $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_active( int $vendor_id ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$s       = Settings::get();
		$vars    = self::base_vars( $vendor_id, $vendor );
		$subject = self::interpolate( $s['email_active_subject'], $vars );
		$body    = self::interpolate( $s['email_active_body'], $vars );
		self::send( $vendor['email'], $subject, $body );
	}

	public static function send_rejected( int $vendor_id, string $reason = '' ): void {
		$vendor = self::vendor( $vendor_id );
		if ( ! $vendor ) {
			return;
		}
		$s       = Settings::get();
		$vars    = self::base_vars( $vendor_id, $vendor );
		$vars['reason_block'] = $reason !== '' ? sprintf( "Pro úplnost: %s\n\n", $reason ) : '';
		$subject = self::interpolate( $s['email_rejected_subject'], $vars );
		$body    = self::interpolate( $s['email_rejected_body'], $vars );
		self::send( $vendor['email'], $subject, $body );
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

		return [
			'name'        => (string) $vendor['name'],
			'email'       => (string) $vendor['email'],
			'ico'         => (string) get_post_meta( $vendor_id, '_nkv_vendor_ico', true ),
			'website'     => (string) get_post_meta( $vendor_id, '_nkv_vendor_website', true ),
			'bio'         => (string) $vendor['bio'],
			'stripe_link' => $stripe_link,
			'profile_url' => $profile_url,
			'status_url'  => $status_url,
			'edit_url'    => (string) get_edit_post_link( $vendor_id, '' ),
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
	 */
	private static function send( string $to, string $subject, string $body ): void {
		if ( ! is_email( $to ) ) {
			return;
		}

		$s        = Settings::get();
		$from     = sprintf( '%s <%s>', $s['from_name'], get_option( 'admin_email' ) );
		$html     = self::wrap_html( $body, $subject );
		$headers  = [
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from,
		];
		wp_mail( $to, $subject, $html, $headers );
	}

	private static function wrap_html( string $body, string $subject ): string {
		$site_name = (string) get_bloginfo( 'name' );
		$site_url  = (string) home_url( '/' );

		// Konvertuj plain text na HTML:
		//  - URLs → <a>
		//  - newlines → <br>
		//  - dvojité newlines = <p>
		$html_body = self::text_to_html( $body );

		ob_start();
		?>
<!DOCTYPE html>
<html lang="cs">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $subject ); ?></title>
<style>
  body { margin:0; padding:0; background:#f5f5f5; font-family:'Fabio XM','Inter',Helvetica,Arial,sans-serif; color:#000; }
  .wrapper { max-width:600px; margin:0 auto; background:#fff; }
  .header { padding:32px 32px 0; }
  .header h1 { margin:0; font-size:20px; font-weight:400; letter-spacing:-0.01em; }
  .accent { color:#0060FF; }
  .content { padding:24px 32px 32px; font-size:16px; line-height:1.6; }
  .content p { margin:0 0 16px; }
  .content a { color:#0060FF; border-bottom:1px solid #0060FF; text-decoration:none; }
  .button { display:inline-block; margin:8px 0; padding:14px 24px; background:#000; color:#fff !important; text-decoration:none; font-weight:500; border:none; }
  .footer { padding:24px 32px; border-top:1px solid #000; font-size:13px; color:rgba(0,0,0,0.6); }
  .footer a { color:rgba(0,0,0,0.6); }
</style>
</head>
<body>
<div class="wrapper">
  <div class="header">
    <h1><?php echo esc_html( $site_name ); ?></h1>
  </div>
  <div class="content">
    <?php echo $html_body; // phpcs:ignore – pre-sanitized via text_to_html ?>
  </div>
  <div class="footer">
    <a href="<?php echo esc_url( $site_url ); ?>"><?php echo esc_html( $site_url ); ?></a>
  </div>
</div>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Bezpečná konverze plain-text-with-URLs na HTML.
	 */
	private static function text_to_html( string $text ): string {
		$escaped = esc_html( $text );

		// Linkify URLs (http/https) i jen-doménové linky (artofzivot.cz).
		$escaped = preg_replace_callback(
			'#(https?://[^\s<]+)#i',
			static fn( $m ) => '<a href="' . esc_url( $m[1] ) . '">' . esc_html( $m[1] ) . '</a>',
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
			$html .= '<p>' . $block . '</p>' . "\n";
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
