<?php
/**
 * Admin settings pro registration – formulářové texty, e-maily, status page.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_registration_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	public static function defaults(): array {
		return [
			'admin_notification_email' => get_option( 'admin_email' ),
			'success_redirect'         => '',
			'terms_url'                => '',
			'status_page_url'          => '',
			'from_name'                => 'Art of život',

			// Form copy.
			'form_lead'    => __( 'Prodávat umění, vlastní tvorbu, je v pořádku. A představit ji osobně ještě víc. Vyplň přihlášku — projdeme si ji a ozveme se.', 'nkz-mp-vendor-registration' ),
			'form_success' => __( 'Tvoje přihláška dorazila. Otevíráme ji v týmu Art of život. Ozveme se ti e-mailem na adresu, kterou jsi uvedl(a).', 'nkz-mp-vendor-registration' ),

			// E-maily — applicant pending (po odeslání formuláře).
			'email_applicant_pending_subject' => 'Tvoji přihlášku jsme dostali — Art of život',
			'email_applicant_pending_body'    =>
"Ahoj {name},\n\n" .
"přihlášku jsme přijali a otevřeli. Projdeme ji v týmu Art of život a vrátíme se ti.\n\n" .
"Není to automat. Každou tvorbu si projdeme osobně — proto to může chvíli trvat.\n\n" .
"Stav přihlášky si můžeš kdykoliv ověřit zde:\n{status_url}\n\n" .
"Tým Art of život",

			// Admin notification.
			'email_admin_pending_subject' => '[Art of život] Nová přihláška: {name}',
			'email_admin_pending_body'    =>
"Nová přihláška na Art of život.\n\n" .
"Jméno: {name}\n" .
"E-mail: {email}\n" .
"IČO: {ico}\n" .
"Web: {website}\n\n" .
"Popis tvorby:\n{bio}\n\n" .
"Schválit / zamítnout v adminu:\n{edit_url}\n",

			// Approved → KYC.
			'email_approved_subject' => 'Jsi v Art of život. Zbývá jeden krok.',
			'email_approved_body'    =>
"Ahoj {name},\n\n" .
"vybrali jsme tě. Tvoje práce do Art of život patří.\n\n" .
"Než to spustíme, musí proběhnout jedna formalita: registrace platby přes Stripe. Trvá to pár minut, vyplníš všechno přímo u nich, my k tomu nemáme přístup.\n\n" .
"Tady je tvůj odkaz (jen pro tebe):\n{stripe_link}\n\n" .
"Až to dokončíš, dáme ti vědět a tvoje produkty pustíme do prodeje.\n\n" .
"Tým Art of život",

			// Active (welcome).
			'email_active_subject' => 'Vítej v Art of život. Můžeš prodávat.',
			'email_active_body'    =>
"Ahoj {name},\n\n" .
"je to oficiální — tvůj profil v Art of život je živý a tvoje produkty se mohou prodávat.\n\n" .
"Tvůj profil:\n{profile_url}\n\n" .
"V adminu si můžeš přidávat produkty, upravit popis a nahrát obrázek. S čímkoli se ozvi, jsme tady.\n\n" .
"Tým Art of život",

			// Rejected.
			'email_rejected_subject' => 'Tvoje přihláška — Art of život',
			'email_rejected_body'    =>
"Ahoj {name},\n\n" .
"děkujeme za přihlášku a důvěru. Letošní ročník jsme koncipovali jiným směrem a do výběru jsme tě tentokrát nezařadili.\n\n" .
"Tvorby je víc než prostoru, a to je vlastně dobrá zpráva.\n\n" .
"{reason_block}" .
"Tým Art of život",
		];
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public function register_menu(): void {
		$parent = defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Registrace', 'nkz-mp-vendor-registration' ),
			__( 'Registrace', 'nkz-mp-vendor-registration' ),
			'manage_woocommerce',
			'nkz-mp-vendor-registration',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'nkzmp_registration', self::OPTION, [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $key => $value ) {
			$out[ $key ] = is_string( $value ) ? wp_unslash( $value ) : $value;
		}
		return $out;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-mp-vendor-registration' ) );
		}
		$s = self::get();
		?>
		<div class="wrap nkzmp-reg-settings">
			<h1>NKZ Registrace prodejců</h1>
			<p><?php esc_html_e( 'Formulář vlož na stránku shortcodem [nkzmp_vendor_registration]. Status page vlož shortcodem [nkzmp_vendor_status] na stránku, jejíž URL pak nastavíš dole jako "Status page URL".', 'nkz-mp-vendor-registration' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'nkzmp_registration' ); ?>

				<h2><?php esc_html_e( 'Obecné', 'nkz-mp-vendor-registration' ); ?></h2>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'from_name', __( 'Odesílatel e-mailů (From name)', 'nkz-mp-vendor-registration' ) );
					$this->text_row( $s, 'admin_notification_email', __( 'Admin notification e-mail', 'nkz-mp-vendor-registration' ) );
					$this->text_row( $s, 'terms_url', __( 'URL podmínek (povinný checkbox)', 'nkz-mp-vendor-registration' ) );
					$this->text_row( $s, 'success_redirect', __( 'Redirect po odeslání (volitelné)', 'nkz-mp-vendor-registration' ) );
					$this->text_row( $s, 'status_page_url', __( 'URL status page (kam vede {status_url})', 'nkz-mp-vendor-registration' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Form copy', 'nkz-mp-vendor-registration' ); ?></h2>
				<table class="form-table">
					<?php
					$this->textarea_row( $s, 'form_lead', __( 'Úvodní text nad formulářem', 'nkz-mp-vendor-registration' ), 3 );
					$this->textarea_row( $s, 'form_success', __( 'Zpráva po odeslání', 'nkz-mp-vendor-registration' ), 3 );
					?>
				</table>

				<h2><?php esc_html_e( 'E-mail: applicant po odeslání', 'nkz-mp-vendor-registration' ); ?></h2>
				<p><em><?php esc_html_e( 'Placeholdery: {name}, {email}, {status_url}', 'nkz-mp-vendor-registration' ); ?></em></p>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'email_applicant_pending_subject', __( 'Předmět', 'nkz-mp-vendor-registration' ) );
					$this->textarea_row( $s, 'email_applicant_pending_body', __( 'Tělo (Markdown/HTML)', 'nkz-mp-vendor-registration' ), 10 );
					?>
				</table>

				<h2><?php esc_html_e( 'E-mail: admin notifikace o nové přihlášce', 'nkz-mp-vendor-registration' ); ?></h2>
				<p><em><?php esc_html_e( 'Placeholdery: {name}, {email}, {ico}, {website}, {bio}, {edit_url}', 'nkz-mp-vendor-registration' ); ?></em></p>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'email_admin_pending_subject', __( 'Předmět', 'nkz-mp-vendor-registration' ) );
					$this->textarea_row( $s, 'email_admin_pending_body', __( 'Tělo', 'nkz-mp-vendor-registration' ), 10 );
					?>
				</table>

				<h2><?php esc_html_e( 'E-mail: po schválení adminem (Stripe Connect link)', 'nkz-mp-vendor-registration' ); ?></h2>
				<p><em><?php esc_html_e( 'Placeholdery: {name}, {stripe_link}', 'nkz-mp-vendor-registration' ); ?></em></p>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'email_approved_subject', __( 'Předmět', 'nkz-mp-vendor-registration' ) );
					$this->textarea_row( $s, 'email_approved_body', __( 'Tělo', 'nkz-mp-vendor-registration' ), 10 );
					?>
				</table>

				<h2><?php esc_html_e( 'E-mail: po dokončení KYC (welcome)', 'nkz-mp-vendor-registration' ); ?></h2>
				<p><em><?php esc_html_e( 'Placeholdery: {name}, {profile_url}', 'nkz-mp-vendor-registration' ); ?></em></p>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'email_active_subject', __( 'Předmět', 'nkz-mp-vendor-registration' ) );
					$this->textarea_row( $s, 'email_active_body', __( 'Tělo', 'nkz-mp-vendor-registration' ), 10 );
					?>
				</table>

				<h2><?php esc_html_e( 'E-mail: zamítnutí', 'nkz-mp-vendor-registration' ); ?></h2>
				<p><em><?php esc_html_e( 'Placeholdery: {name}, {reason_block} – {reason_block} vloží důvod v odstavci, pokud byl uveden při změně statusu.', 'nkz-mp-vendor-registration' ); ?></em></p>
				<table class="form-table">
					<?php
					$this->text_row( $s, 'email_rejected_subject', __( 'Předmět', 'nkz-mp-vendor-registration' ) );
					$this->textarea_row( $s, 'email_rejected_body', __( 'Tělo', 'nkz-mp-vendor-registration' ), 10 );
					?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private function text_row( array $s, string $key, string $label ): void {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input id="%1$s" type="text" name="%3$s[%1$s]" value="%4$s" class="regular-text" style="width:420px" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( (string) $s[ $key ] )
		);
	}

	private function textarea_row( array $s, string $key, string $label, int $rows = 5 ): void {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%3$s[%1$s]" rows="%5$d" cols="80" class="large-text code">%4$s</textarea></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_textarea( (string) $s[ $key ] ),
			$rows
		);
	}
}
