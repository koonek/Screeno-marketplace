<?php
/**
 * EmailSettings – centrální místo pro všechny e-mailové šablony.
 *
 * Option `nkzmp_email_templates` drží všechny šablony (subject + body) jako
 * flat asociativní pole. Sendery v jednotlivých modulech volají
 * `EmailSettings::tpl( $key, $vars )` který vrátí interpolovaný subject/body
 * s fallbackem na hardcoded default (definovaný zde v `defaults()`).
 *
 * Šablony z původního `nkzmp_registration_settings` (registrační flow) se
 * při prvním načtení zrcadlí do nového option (idempotentní migrace).
 *
 * Tab „E-maily" se registruje přes filter `nkzmp/v1/admin/settings/tabs`.
 *
 * @package NKZMP\Admin
 */

namespace NKZMP\Admin;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class EmailSettings {

	public const OPTION = 'nkzmp_email_templates';

	private static ?EmailSettings $instance = null;

	public static function instance(): EmailSettings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_init', [ $this, 'maybe_migrate_legacy' ], 11 );
		add_action( 'admin_init', [ $this, 'maybe_send_test' ], 12 );
		add_filter( 'nkzmp/v1/admin/settings/tabs', [ $this, 'register_tab' ] );
	}

	public function register(): void {
		register_setting( 'nkzmp_email_templates', self::OPTION, [
			'sanitize_callback' => [ $this, 'sanitize' ],
		] );
	}

	/**
	 * Idempotentní jednorázová migrace – pokud máme legacy registrační šablony
	 * a nový option je prázdný (nebo nemá registrační klíče), zkopírujeme je.
	 * Po migraci registrační Settings dál fungují jako fallback (kompatibilita).
	 */
	public function maybe_migrate_legacy(): void {
		$migrated = get_option( 'nkzmp_email_templates_migrated', '' );
		if ( $migrated === 'yes' ) {
			return;
		}
		$legacy = get_option( 'nkzmp_registration_settings', [] );
		if ( ! is_array( $legacy ) || empty( $legacy ) ) {
			update_option( 'nkzmp_email_templates_migrated', 'yes', false );
			return;
		}
		$keys = [
			'email_applicant_pending_subject', 'email_applicant_pending_body',
			'email_admin_pending_subject',     'email_admin_pending_body',
			'email_approved_subject',          'email_approved_body',
			'email_active_subject',            'email_active_body',
			'email_rejected_subject',          'email_rejected_body',
		];
		$current = get_option( self::OPTION, [] );
		if ( ! is_array( $current ) ) {
			$current = [];
		}
		foreach ( $keys as $k ) {
			if ( isset( $legacy[ $k ] ) && ! isset( $current[ $k ] ) ) {
				$current[ $k ] = (string) $legacy[ $k ];
			}
		}
		update_option( self::OPTION, $current, false );
		update_option( 'nkzmp_email_templates_migrated', 'yes', false );
	}

	public function sanitize( $input ): array {
		if ( ! is_array( $input ) ) {
			return [];
		}
		$out = [];
		foreach ( $input as $key => $value ) {
			$out[ sanitize_key( $key ) ] = is_string( $value ) ? wp_unslash( $value ) : '';
		}
		return $out;
	}

	/**
	 * Vrátí raw uloženou hodnotu pro klíč (subject/body) s fallbackem
	 * na default šablonu. NEINTERPOLUJE – sender si placeholdery dosadí sám.
	 */
	public static function raw( string $key ): string {
		$saved = get_option( self::OPTION, [] );
		if ( is_array( $saved ) && isset( $saved[ $key ] ) && $saved[ $key ] !== '' ) {
			return (string) $saved[ $key ];
		}
		// Fallback na legacy registrační option (zachová stará nastavení dokud
		// admin nepřejde do nového hubu a neuloží).
		$legacy = get_option( 'nkzmp_registration_settings', [] );
		if ( is_array( $legacy ) && isset( $legacy[ $key ] ) && $legacy[ $key ] !== '' ) {
			return (string) $legacy[ $key ];
		}
		$defaults = self::defaults();
		return (string) ( $defaults[ $key ] ?? '' );
	}

	/**
	 * Interpolace placeholderů {key} → hodnoty z $vars.
	 */
	public static function interpolate( string $template, array $vars ): string {
		$keys = array_map( static fn( $k ) => '{' . $k . '}', array_keys( $vars ) );
		return str_replace( $keys, array_values( $vars ), $template );
	}

	public function register_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'emails',
			'label'    => __( 'E-maily', 'nkz-marketplace' ),
			'priority' => 25,
			'render'   => [ self::class, 'render_panel' ],
		];
		return $tabs;
	}

	/** Mapa subject_key => lidský název pro výběr v test boxu. */
	private static function template_choices(): array {
		$out = [];
		foreach ( self::groups() as $group ) {
			foreach ( $group['items'] as $item ) {
				$out[ $item['subject'] ] = $group['label'] . ' — ' . $item['label'];
			}
		}
		return $out;
	}

	/** Box „Poslat testovací e-mail" nad formulářem. */
	private static function render_test_box(): void {
		$admin_email = (string) get_option( 'admin_email' );
		$to_default  = isset( $_GET['nkzmp_test_to'] ) ? sanitize_email( wp_unslash( $_GET['nkzmp_test_to'] ) ) : $admin_email;

		if ( isset( $_GET['nkzmp_test_sent'] ) ) {
			$ok = $_GET['nkzmp_test_sent'] === '1';
			printf(
				'<div class="notice notice-%s inline"><p>%s</p></div>',
				$ok ? 'success' : 'error',
				$ok
					? esc_html__( 'Testovací e-mail odeslán. Pokud nedorazí, zkontroluj SMTP / spam.', 'nkz-marketplace' )
					: esc_html__( 'Odeslání selhalo (wp_mail vrátil false). Zkontroluj SMTP nastavení.', 'nkz-marketplace' )
			);
		}
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px 20px;margin:8px 0 24px;max-width:760px;">
			<h2 style="margin:0 0 6px;font-size:15px;"><?php esc_html_e( 'Poslat testovací e-mail', 'nkz-marketplace' ); ?></h2>
			<p style="margin:0 0 12px;color:rgba(0,0,0,0.6);font-size:13px;">
				<?php esc_html_e( 'Vybranou šablonu pošleme na zadaný e-mail s ukázkovými daty (placeholdery vyplníme vzorovými hodnotami). Uloží se aktuálně nastavené texty — neukládá změny formuláře, takže nejdřív ulož, pak testuj.', 'nkz-marketplace' ); ?>
			</p>
			<form method="post" action="" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<?php wp_nonce_field( 'nkzmp_email_test', 'nkzmp_email_test_nonce' ); ?>
				<select name="nkzmp_test_template" style="min-width:340px;">
					<?php foreach ( self::template_choices() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="email" name="nkzmp_test_to" value="<?php echo esc_attr( $to_default ); ?>" placeholder="<?php esc_attr_e( 'kam poslat', 'nkz-marketplace' ); ?>" style="min-width:220px;" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Poslat test', 'nkz-marketplace' ); ?></button>
			</form>
		</div>
		<?php
	}

	/** Zpracuje odeslání testovacího e-mailu. */
	public function maybe_send_test(): void {
		if ( ! isset( $_POST['nkzmp_email_test_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkzmp_email_test_nonce'] ) ), 'nkzmp_email_test' ) ) {
			return;
		}
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			return;
		}
		$subject_key = isset( $_POST['nkzmp_test_template'] ) ? sanitize_key( wp_unslash( $_POST['nkzmp_test_template'] ) ) : '';
		$to          = isset( $_POST['nkzmp_test_to'] ) ? sanitize_email( wp_unslash( $_POST['nkzmp_test_to'] ) ) : '';
		if ( $subject_key === '' || ! is_email( $to ) ) {
			$this->redirect_test( false, $to );
		}
		$body_key = preg_replace( '/_subject$/', '_body', $subject_key );

		$vars    = self::sample_vars();
		$subject = '[TEST] ' . self::interpolate( self::raw( $subject_key ), $vars );
		$body    = self::interpolate( self::raw( (string) $body_key ), $vars );

		$sent = false;
		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			$sent = true; // send_raw nevrací stav; bereme jako odesláno (wp_mail interně)
		} else {
			$sent = (bool) wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
		}
		$this->redirect_test( $sent, $to );
	}

	private function redirect_test( bool $ok, string $to ): void {
		$url = add_query_arg(
			[ 'nkzmp_test_sent' => $ok ? '1' : '0', 'nkzmp_test_to' => rawurlencode( $to ) ],
			SettingsHub::url( 'emails' )
		);
		wp_safe_redirect( $url );
		exit;
	}

	/** Ukázková data pro všechny placeholdery napříč šablonami. */
	private static function sample_vars(): array {
		$site = (string) get_bloginfo( 'name' );
		return [
			'name'          => 'Jan Tvůrce',
			'email'         => 'jan@example.cz',
			'ico'           => '12345678',
			'website'       => 'https://example.cz',
			'bio'           => 'Dělám ručně malované hrnky a misky inspirované přírodou.',
			'stripe_link'   => home_url( '/?nkzmp_demo=stripe' ),
			'profile_url'   => home_url( '/vendor/jan-tvurce' ),
			'status_url'    => home_url( '/stav-prihlasky/?token=demo' ),
			'edit_url'      => admin_url( 'admin.php?page=nkz-marketplace-vendor&vendor_id=0' ),
			'login_url'     => function_exists( 'wc_get_page_permalink' ) ? (string) wc_get_page_permalink( 'myaccount' ) : home_url( '/muj-ucet' ),
			'dashboard_url' => function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'vendor' ) : home_url( '/muj-ucet' ),
			'reason_block'  => "Pro úplnost: kapacita letošního ročníku je naplněná.\n\n",
			'username'      => 'jan.tvurce',
			'password_url'  => home_url( '/wp-login.php?action=rp&key=demo&login=jan.tvurce' ),
			'product_name'  => 'Hrnek Ranní mlha',
			'product_price' => '690 Kč',
			'name_vocative' => 'Tvůrce',
			'vendor_name'   => 'Jan Tvůrce',
			'vendor_name_vocative' => 'Tvůrce',
			'vendor_email'  => 'jan@example.cz',
			'submit_kind'   => 'Nový produkt',
			'order_number'  => '1042',
			'order_date'    => date_i18n( get_option( 'date_format' ) ),
			'items'         => '  2× Hrnek Ranní mlha — 1 380 Kč',
			'subtotal'      => '1 380 Kč',
			'order_admin_url' => home_url( '/muj-ucet/vendor-orders' ),
			'tracking_code' => 'Z1234567890',
			'tracking_url'  => 'https://tracking.packeta.com/cs_CZ/?id=Z1234567890',
			'pickup_point'  => 'Z-BOX Praha, Vinohradská 12',
			'count'         => '3',
			'detail'        => "Adapter: stripe\nDrift: 3 záznamy",
			'tools_url'     => admin_url( 'admin.php?page=nkz-marketplace-tools' ),
			'site_name'     => $site,
			'site_url'      => home_url( '/' ),
		];
	}

	public static function render_panel(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			return;
		}
		$s = array_merge( self::defaults(), (array) get_option( self::OPTION, [] ) );
		// Doplň ze starého registračního optionu jako fallback (read-only zde,
		// úložiště je nový OPTION). Když admin uloží, hodnoty se uloží do nového.
		$legacy = (array) get_option( 'nkzmp_registration_settings', [] );
		foreach ( $legacy as $k => $v ) {
			if ( is_string( $v ) && $v !== '' && ! isset( $s[ $k ] ) ) {
				$s[ $k ] = $v;
			}
		}
		?>
		<?php self::render_test_box(); ?>
		<form method="post" action="options.php">
			<?php settings_fields( 'nkzmp_email_templates' ); ?>

			<p style="max-width:760px;color:rgba(0,0,0,0.65);">
				<?php esc_html_e( 'Texty všech e-mailů, které platforma rozesílá. Každá šablona má předmět a tělo. Placeholdery v složených závorkách se nahrazují za skutečné hodnoty při odeslání.', 'nkz-marketplace' ); ?>
			</p>

			<h2 style="margin-top:32px;"><?php esc_html_e( 'Oslovení v 5. pádu (vokativ)', 'nkz-marketplace' ); ?></h2>
			<p style="color:rgba(0,0,0,0.6);max-width:720px;">
				<?php
				printf(
					/* translators: 1: code placeholder, 2: external link */
					wp_kses(
						__( 'Placeholder %1$s vyplní jméno prodejce v 5. pádu (např. %3$s → %4$s). Personalizace běží přes externí službu %2$s — vlož sem API klíč (zaregistrovat se dá zdarma s testovacím limitem). Bez klíče se použije nominativ.', 'nkz-marketplace' ),
						[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ], 'code' => [] ]
					),
					'<code>{name_vocative}</code>',
					'<a href="https://www.sklonovani-jmen.cz/" target="_blank" rel="noopener">sklonovani-jmen.cz</a>',
					'<code>Jan</code>',
					'<code>Jane</code>'
				);
				?>
			</p>
			<table class="form-table">
				<?php self::text_row( $s, 'sklonovani_jmen_api_key', __( 'API klíč sklonovani-jmen.cz', 'nkz-marketplace' ) ); ?>
			</table>

			<?php foreach ( self::groups() as $group ) : ?>
				<h2 style="margin-top:32px;"><?php echo esc_html( $group['label'] ); ?></h2>
				<?php if ( ! empty( $group['hint'] ) ) : ?>
					<p style="color:rgba(0,0,0,0.6);max-width:720px;"><?php echo esc_html( $group['hint'] ); ?></p>
				<?php endif; ?>

				<?php foreach ( $group['items'] as $item ) : ?>
					<h3 style="margin:24px 0 4px;"><?php echo esc_html( $item['label'] ); ?></h3>
					<?php if ( ! empty( $item['hint'] ) ) : ?>
						<p style="color:rgba(0,0,0,0.55);margin:0 0 8px;max-width:720px;font-size:13px;">
							<?php echo esc_html( $item['hint'] ); ?>
						</p>
					<?php endif; ?>
					<?php if ( ! empty( $item['placeholders'] ) ) : ?>
						<p style="color:rgba(0,0,0,0.5);font-size:12px;margin:0 0 8px;">
							<strong><?php esc_html_e( 'Placeholdery:', 'nkz-marketplace' ); ?></strong>
							<code><?php echo esc_html( implode( '  ', array_map( static fn( $p ) => '{' . $p . '}', $item['placeholders'] ) ) ); ?></code>
						</p>
					<?php endif; ?>
					<table class="form-table">
						<?php self::text_row( $s, $item['subject'], __( 'Předmět', 'nkz-marketplace' ) ); ?>
						<?php self::textarea_row( $s, $item['body'], __( 'Tělo', 'nkz-marketplace' ), 9 ); ?>
					</table>
				<?php endforeach; ?>
			<?php endforeach; ?>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Struktura UI: skupiny -> položky -> subject/body klíče.
	 *
	 * @return array<int,array{label:string,hint?:string,items:array<int,array{label:string,hint?:string,subject:string,body:string,placeholders?:string[]}>}>
	 */
	private static function groups(): array {
		$reg_vars = [ 'name', 'name_vocative', 'email', 'ico', 'website', 'bio', 'stripe_link', 'profile_url', 'status_url', 'edit_url', 'login_url', 'dashboard_url', 'site_name', 'site_url' ];

		return [
			[
				'label' => __( 'Registrace prodejce', 'nkz-marketplace' ),
				'hint'  => __( 'Šablony okolo 2-stage onboarding flow.', 'nkz-marketplace' ),
				'items' => [
					[ 'label' => __( 'Žadatel: přihláška dorazila', 'nkz-marketplace' ),
					  'subject' => 'email_applicant_pending_subject', 'body' => 'email_applicant_pending_body',
					  'placeholders' => $reg_vars ],
					[ 'label' => __( 'Admin: nová přihláška ke schválení', 'nkz-marketplace' ),
					  'subject' => 'email_admin_pending_subject', 'body' => 'email_admin_pending_body',
					  'placeholders' => $reg_vars ],
					[ 'label' => __( 'Žadatel: přihláška schválena (Stripe Connect link)', 'nkz-marketplace' ),
					  'subject' => 'email_approved_subject', 'body' => 'email_approved_body',
					  'placeholders' => $reg_vars ],
					[ 'label' => __( 'Žadatel: účet aktivní (po dokončení KYC)', 'nkz-marketplace' ),
					  'subject' => 'email_active_subject', 'body' => 'email_active_body',
					  'placeholders' => $reg_vars ],
					[ 'label' => __( 'Žadatel: zamítnutí', 'nkz-marketplace' ),
					  'hint'  => __( '{reason_block} vloží odstavec s důvodem (pokud byl zadán), nebo zmizí.', 'nkz-marketplace' ),
					  'subject' => 'email_rejected_subject', 'body' => 'email_rejected_body',
					  'placeholders' => array_merge( $reg_vars, [ 'reason_block' ] ) ],
				],
			],
			[
				'label' => __( 'Onboarding po schválení', 'nkz-marketplace' ),
				'items' => [
					[ 'label' => __( 'Prodejce: nastavení hesla k účtu', 'nkz-marketplace' ),
					  'hint'  => __( 'Posílá se ihned po auto-create WP uživatele, obsahuje odkaz na nastavení hesla.', 'nkz-marketplace' ),
					  'subject' => 'email_password_setup_subject', 'body' => 'email_password_setup_body',
					  'placeholders' => [ 'name', 'name_vocative', 'username', 'password_url', 'login_url', 'site_name' ] ],
				],
			],
			[
				'label' => __( 'Produkty', 'nkz-marketplace' ),
				'items' => [
					[ 'label' => __( 'Prodejce: nový produkt přijat ke schválení', 'nkz-marketplace' ),
					  'subject' => 'email_product_new_vendor_subject', 'body' => 'email_product_new_vendor_body',
					  'placeholders' => [ 'name', 'name_vocative', 'product_name', 'dashboard_url', 'site_name' ] ],
					[ 'label' => __( 'Prodejce: úprava produktu přijata ke schválení', 'nkz-marketplace' ),
					  'subject' => 'email_product_edit_vendor_subject', 'body' => 'email_product_edit_vendor_body',
					  'placeholders' => [ 'name', 'name_vocative', 'product_name', 'dashboard_url', 'site_name' ] ],
					[ 'label' => __( 'Admin: produkt ke schválení', 'nkz-marketplace' ),
					  'subject' => 'email_product_admin_subject', 'body' => 'email_product_admin_body',
					  'placeholders' => [ 'product_name', 'product_price', 'vendor_name', 'vendor_email', 'dashboard_url', 'edit_url', 'submit_kind', 'site_name' ] ],
				],
			],
			[
				'label' => __( 'Objednávky', 'nkz-marketplace' ),
				'items' => [
					[ 'label' => __( 'Prodejce: nová objednávka', 'nkz-marketplace' ),
					  'hint'  => __( 'Posílá se prodejci při přechodu objednávky na processing / completed (jen za jeho položky).', 'nkz-marketplace' ),
					  'subject' => 'email_order_vendor_subject', 'body' => 'email_order_vendor_body',
					  'placeholders' => [ 'name', 'name_vocative', 'order_number', 'order_date', 'items', 'subtotal', 'order_admin_url', 'site_name' ] ],
					[ 'label' => __( 'Zákazník: zásilka je na cestě (Zásilkovna)', 'nkz-marketplace' ),
					  'hint'  => __( 'Posílá se zákazníkovi když prodejce podá zásilku přes Zásilkovnu (s tracking odkazem).', 'nkz-marketplace' ),
					  'subject' => 'email_shipment_subject', 'body' => 'email_shipment_body',
					  'placeholders' => [ 'name', 'name_vocative', 'vendor_name', 'vendor_name_vocative', 'order_number', 'tracking_code', 'tracking_url', 'pickup_point', 'site_name' ] ],
				],
			],
			[
				'label' => __( 'Provoz / monitoring', 'nkz-marketplace' ),
				'items' => [
					[ 'label' => __( 'Admin: reconciliation drift alert', 'nkz-marketplace' ),
					  'hint'  => __( 'Cron upozornění když Stripe transfers nesedí s ledgerem. Posílá se max. jednou za 12 h.', 'nkz-marketplace' ),
					  'subject' => 'email_drift_admin_subject', 'body' => 'email_drift_admin_body',
					  'placeholders' => [ 'count', 'detail', 'tools_url', 'site_name' ] ],
				],
			],
		];
	}

	/**
	 * Default šablony pro všechny klíče. Sender použije fallback sem,
	 * pokud option ani legacy nemá hodnotu.
	 */
	public static function defaults(): array {
		return [
			// === Registrace (původně v nkzmp_registration_settings) ===
			'email_applicant_pending_subject' => 'Tvoji přihlášku jsme dostali — Art of život',
			'email_applicant_pending_body'    =>
"Ahoj {name},\n\n" .
"přihlášku jsme přijali a otevřeli. Projdeme ji v týmu Art of život a vrátíme se ti.\n\n" .
"Není to automat. Každou tvorbu si projdeme osobně — proto to může chvíli trvat.\n\n" .
"Stav přihlášky si můžeš kdykoliv ověřit zde:\n{status_url}\n\n" .
"Tým Art of život",

			'email_admin_pending_subject' => '[Art of život] Nová přihláška: {name}',
			'email_admin_pending_body'    =>
"Nová přihláška na Art of život.\n\n" .
"Jméno: {name}\n" .
"E-mail: {email}\n" .
"IČO: {ico}\n" .
"Web: {website}\n\n" .
"Popis tvorby:\n{bio}\n\n" .
"Schválit / zamítnout v adminu:\n{edit_url}\n",

			'email_approved_subject' => 'Jsi v Art of život. Zbývá jeden krok.',
			'email_approved_body'    =>
"Ahoj {name},\n\n" .
"vybrali jsme tě. Tvoje práce do Art of život patří.\n\n" .
"Než to spustíme, musí proběhnout jedna formalita: registrace platby přes Stripe. Trvá to pár minut, vyplníš všechno přímo u nich, my k tomu nemáme přístup.\n\n" .
"Tady je tvůj odkaz (jen pro tebe):\n{stripe_link}\n\n" .
"Až to dokončíš, dáme ti vědět a tvoje produkty pustíme do prodeje.\n\n" .
"Tým Art of život",

			'email_active_subject' => 'Vítej v Art of život. Můžeš prodávat.',
			'email_active_body'    =>
"Ahoj {name},\n\n" .
"je to oficiální — tvůj profil v Art of život je živý a tvoje produkty se mohou prodávat.\n\n" .
"Tvůj veřejný profil:\n{profile_url}\n\n" .
"Přihlaš se do svého panelu a začni přidávat produkty:\n{dashboard_url}\n\n" .
"V panelu si můžeš přidávat produkty, upravit popis a nahrát obrázek. S čímkoli se ozvi, jsme tady.\n\n" .
"Tým Art of život",

			'email_rejected_subject' => 'Tvoje přihláška — Art of život',
			'email_rejected_body'    =>
"Ahoj {name},\n\n" .
"děkujeme za přihlášku a důvěru. Letošní ročník jsme koncipovali jiným směrem a do výběru jsme tě tentokrát nezařadili.\n\n" .
"Tvorby je víc než prostoru, a to je vlastně dobrá zpráva.\n\n" .
"{reason_block}" .
"Tým Art of život",

			// === Onboarding ===
			'email_password_setup_subject' => 'Nastav si heslo k účtu — {site_name}',
			'email_password_setup_body'    =>
"Ahoj {name},\n\n" .
"založili jsme ti účet v panelu prodejce. Pro vstup si nastav heslo:\n{password_url}\n\n" .
"Po nastavení hesla se přihlásíš zde:\n{login_url}\n\n" .
"Tvé uživatelské jméno: {username}\n\n" .
"Tým {site_name}",

			// === Produkty ===
			'email_product_new_vendor_subject' => 'Tvůj produkt jsme dostali — {site_name}',
			'email_product_new_vendor_body'    =>
"Ahoj {name},\n\n" .
"produkt „{product_name}\" jsme přijali. Projdeme ho a pokud sedne k Art of život, publikujeme ho.\n\n" .
"Není to automat — každý produkt si projdeme osobně. Můžeme se ozvat s otázkami.\n\n" .
"V tvém panelu máš produkt pod stavem „Čeká schválení\":\n{dashboard_url}\n\n" .
"Tým {site_name}",

			'email_product_edit_vendor_subject' => 'Tvoji úpravu jsme dostali — {site_name}',
			'email_product_edit_vendor_body'    =>
"Ahoj {name},\n\n" .
"úpravu produktu „{product_name}\" jsme dostali. Projdeme ji v týmu a zase publikujeme.\n\n" .
"Mezitím produkt visí v tvém panelu pod stavem „Čeká schválení\":\n{dashboard_url}\n\n" .
"Tým {site_name}",

			'email_product_admin_subject' => '[{site_name}] {submit_kind}: {product_name}',
			'email_product_admin_body'    =>
"Prodejce poslal produkt ke schválení.\n\n" .
"Produkt: {product_name}\n" .
"Cena: {product_price}\n" .
"Prodejce: {vendor_name} <{vendor_email}>\n\n" .
"Schválit publikování můžeš v Dashboardu (sekce „Čekající produkty\"):\n{dashboard_url}\n\n" .
"Detail produktu:\n{edit_url}\n",

			// === Objednávky ===
			'email_order_vendor_subject' => 'Nová objednávka #{order_number} — {site_name}',
			'email_order_vendor_body'    =>
"Ahoj {name},\n\n" .
"přišla ti objednávka #{order_number} ({order_date}).\n\n" .
"{items}\n" .
"Celkem za tvé položky: {subtotal}\n\n" .
"Detail objednávky:\n{order_admin_url}\n\n" .
"Tým {site_name}",

			// === Zásilka zákazníkovi ===
			'email_shipment_subject' => 'Tvoje zásilka od {vendor_name} je na cestě',
			'email_shipment_body'    =>
"Ahoj {name},\n\n" .
"{vendor_name} právě podal(a) tvoji zásilku z objednávky #{order_number}. Je na cestě k výdejnímu místu {pickup_point}.\n\n" .
"Sledovat zásilku můžeš tady:\n{tracking_url}\n\n" .
"Číslo zásilky: {tracking_code}\n\n" .
"Tým {site_name}",

			// === Provoz ===
			'email_drift_admin_subject' => '[{site_name}] Reconciliation drift: {count}',
			'email_drift_admin_body'    =>
"Reconciliation cron detekoval rozdíl mezi Stripe transfery a ledgerem.\n\n" .
"Počet driftnutých záznamů: {count}\n\n" .
"Detail:\n{detail}\n\n" .
"Pro ruční reconciliaci jdi na Tools:\n{tools_url}\n",
		];
	}

	private static function text_row( array $s, string $key, string $label ): void {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><input id="%1$s" type="text" name="%3$s[%1$s]" value="%4$s" class="regular-text" style="width:520px" /></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_attr( (string) ( $s[ $key ] ?? '' ) )
		);
	}

	private static function textarea_row( array $s, string $key, string $label, int $rows = 8 ): void {
		printf(
			'<tr><th><label for="%1$s">%2$s</label></th><td><textarea id="%1$s" name="%3$s[%1$s]" rows="%5$d" cols="80" class="large-text code" style="font-family:Menlo,Consolas,monospace;font-size:13px;">%4$s</textarea></td></tr>',
			esc_attr( $key ),
			esc_html( $label ),
			esc_attr( self::OPTION ),
			esc_textarea( (string) ( $s[ $key ] ?? '' ) ),
			$rows
		);
	}
}
