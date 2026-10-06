<?php
/**
 * Settings – Art of život jako dodavatel a obecné nastavení dokladů.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_invoices_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) && \NKZMP\Admin\SettingsHub::available() ) {
			add_filter( 'nkzmp/v1/admin/settings/tabs', [ $this, 'register_tab' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'menu' ], 40 );
		}
		add_action( 'admin_notices', [ $this, 'incomplete_notice' ] );
	}

	public static function defaults(): array {
		return [
			'enabled'         => 'yes',
			// Art of život jako dodavatel dopravy a servisního poplatku.
			'name'            => 'Art of život',
			'street'          => '',
			'city'            => '',
			'zip'             => '',
			'country'         => 'Česká republika',
			'ico'             => '',
			'dic'             => '',
			'vat_payer'       => 'no',
			'vat_rate'        => 21,
			'registry'        => '', // např. „Zapsáno v OR vedeném KS v Ostravě, oddíl C, vložka 12345"
			// Číselné řady.
			'prefix_platform' => 'AOZ',
			'prefix_vendor'   => 'P{vendor}-',
			'prefix_credit'   => 'D',
			'attach_email'    => 'yes',
			// Doklady k jedné objednávce na jedné stránce (jako GoOut).
			'combined'        => 'yes',
			// Faktury Art of život prodejcům za členství (místo faktur ze Stripe).
			'membership'      => 'yes',
		];
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, [] );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : [] );
	}

	public static function enabled(): bool {
		return self::get()['enabled'] === 'yes';
	}

	public static function platform_is_vat_payer(): bool {
		return self::get()['vat_payer'] === 'yes';
	}

	/** Chybí povinné údaje Art of život na dokladu? */
	public static function platform_incomplete(): array {
		$s       = self::get();
		$missing = [];
		foreach ( [ 'name' => 'název', 'street' => 'ulice', 'city' => 'město', 'zip' => 'PSČ', 'ico' => 'IČO' ] as $k => $label ) {
			if ( trim( (string) $s[ $k ] ) === '' ) {
				$missing[] = $label;
			}
		}
		if ( $s['vat_payer'] === 'yes' && trim( (string) $s['dic'] ) === '' ) {
			$missing[] = 'DIČ';
		}
		return $missing;
	}

	public function register(): void {
		register_setting( 'nkzmp_invoices', self::OPTION, [ 'sanitize_callback' => [ $this, 'sanitize' ] ] );
	}

	public function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : [];
		$out = self::defaults();
		foreach ( [ 'name', 'street', 'city', 'zip', 'country', 'ico', 'dic', 'registry', 'prefix_platform', 'prefix_vendor', 'prefix_credit' ] as $k ) {
			$out[ $k ] = sanitize_text_field( wp_unslash( (string) ( $in[ $k ] ?? '' ) ) );
		}
		foreach ( [ 'enabled', 'vat_payer', 'attach_email', 'combined', 'membership' ] as $k ) {
			$out[ $k ] = ! empty( $in[ $k ] ) ? 'yes' : 'no';
		}
		$out['vat_rate'] = max( 0, min( 100, (int) ( $in['vat_rate'] ?? 21 ) ) );
		return $out;
	}

	public function register_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'invoices',
			'label'    => __( 'Faktury', 'nkz-mp-invoices' ),
			'render'   => [ $this, 'render_panel' ],
			'priority' => 35,
		];
		return $tabs;
	}

	public function menu(): void {
		add_submenu_page(
			defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce',
			__( 'Faktury', 'nkz-mp-invoices' ),
			__( 'Faktury', 'nkz-mp-invoices' ),
			'manage_woocommerce',
			'nkz-mp-invoices',
			function () {
				echo '<div class="wrap"><h1>' . esc_html__( 'Faktury', 'nkz-mp-invoices' ) . '</h1>';
				$this->render_panel();
				echo '</div>';
			}
		);
	}

	public static function admin_url(): string {
		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) && \NKZMP\Admin\SettingsHub::available() ) {
			return admin_url( 'admin.php?page=' . \NKZMP\Admin\SettingsHub::SLUG . '&tab=invoices' );
		}
		return admin_url( 'admin.php?page=nkz-mp-invoices' );
	}

	public function render_panel(): void {
		$s = self::get();
		$o = self::OPTION;
		echo '<p style="max-width:760px;">' . esc_html__( 'Po zaplacení objednávky se automaticky vystaví doklad za každého prodejce (jeho jménem, na základě zmocnění v podmínkách pro prodejce) a doklad Art of život za dopravu a servisní poplatek. Zákazník je dostane v jednom PDF v příloze e-mailu a ke stažení ve svém účtu.', 'nkz-mp-invoices' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_invoices' );
		echo '<table class="form-table">';

		$row = static function ( string $label, string $html, string $help = '' ): void {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- html je escapované níž.
			if ( $help !== '' ) {
				echo '<p class="description">' . esc_html( $help ) . '</p>';
			}
			echo '</td></tr>';
		};
		$text = static fn( string $k, string $ph = '', string $w = '360px' ) => sprintf(
			'<input type="text" name="%1$s[%2$s]" value="%3$s" placeholder="%4$s" style="width:%5$s" />',
			esc_attr( $o ), esc_attr( $k ), esc_attr( (string) $s[ $k ] ), esc_attr( $ph ), esc_attr( $w )
		);
		$check = static fn( string $k, string $label ) => sprintf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( $o ), esc_attr( $k ), checked( $s[ $k ], 'yes', false ), esc_html( $label )
		);

		$row( __( 'Vystavovat doklady', 'nkz-mp-invoices' ), $check( 'enabled', __( 'zapnuto', 'nkz-mp-invoices' ) ) );
		echo '<tr><th colspan="2"><h2 style="margin:12px 0 0;">' . esc_html__( 'Art of život jako dodavatel', 'nkz-mp-invoices' ) . '</h2></th></tr>';
		$row( __( 'Název / obchodní firma', 'nkz-mp-invoices' ), $text( 'name' ) );
		$row( __( 'Ulice a číslo', 'nkz-mp-invoices' ), $text( 'street' ) );
		$row( __( 'Město', 'nkz-mp-invoices' ), $text( 'city' ) );
		$row( __( 'PSČ', 'nkz-mp-invoices' ), $text( 'zip', '', '120px' ) );
		$row( __( 'Země', 'nkz-mp-invoices' ), $text( 'country' ) );
		$row( __( 'IČO', 'nkz-mp-invoices' ), $text( 'ico', '', '160px' ) );
		$row( __( 'Plátce DPH', 'nkz-mp-invoices' ), $check( 'vat_payer', __( 'Art of život je plátce DPH', 'nkz-mp-invoices' ) ), __( 'Určuje, jestli bude mít doprava a servisní poplatek na dokladu DPH.', 'nkz-mp-invoices' ) );
		$row( __( 'DIČ', 'nkz-mp-invoices' ), $text( 'dic', 'CZ12345678', '160px' ) );
		$row( __( 'Sazba DPH na dopravu a poplatek (%)', 'nkz-mp-invoices' ), sprintf( '<input type="number" min="0" max="100" name="%s[vat_rate]" value="%d" style="width:80px" />', esc_attr( $o ), (int) $s['vat_rate'] ) );
		$row( __( 'Zápis v rejstříku', 'nkz-mp-invoices' ), $text( 'registry', __( 'např. Zapsáno v OR vedeném KS v Ostravě, oddíl C, vložka 12345', 'nkz-mp-invoices' ), '520px' ), __( 'Nepovinné. U firem zapsaných v obchodním rejstříku má být na dokladu.', 'nkz-mp-invoices' ) );

		echo '<tr><th colspan="2"><h2 style="margin:12px 0 0;">' . esc_html__( 'Číselné řady', 'nkz-mp-invoices' ) . '</h2></th></tr>';
		$row( __( 'Prefix dokladů Art of život', 'nkz-mp-invoices' ), $text( 'prefix_platform', '', '160px' ), __( 'Např. AOZ → AOZ-2026-00001', 'nkz-mp-invoices' ) );
		$row( __( 'Prefix dokladů prodejců', 'nkz-mp-invoices' ), $text( 'prefix_vendor', '', '160px' ), __( '{vendor} se nahradí číslem prodejce, např. P3613-2026-00001. Každý prodejce má vlastní řadu.', 'nkz-mp-invoices' ) );
		$row( __( 'Označení dobropisů', 'nkz-mp-invoices' ), $text( 'prefix_credit', '', '80px' ), __( 'Vkládá se do čísla dobropisu, např. AOZ-D-2026-00001.', 'nkz-mp-invoices' ) );
		$row( __( 'E-mail', 'nkz-mp-invoices' ), $check( 'attach_email', __( 'přiložit PDF k potvrzení objednávky', 'nkz-mp-invoices' ) ) );
		$row(
			__( 'Podoba PDF', 'nkz-mp-invoices' ),
			$check( 'combined', __( 'doklady k jedné objednávce spojit do jednoho přehledu (jako GoOut)', 'nkz-mp-invoices' ) ),
			__( 'Zákazník dostane jednu stránku: každý dodavatel (prodejci, Art of život) má svůj blok se svým číslem dokladu, dole celková částka. Po vypnutí má každý doklad vlastní stránku.', 'nkz-mp-invoices' )
		);
		$row(
			__( 'Faktury za členství', 'nkz-mp-invoices' ),
			$check( 'membership', __( 'vystavovat prodejcům faktury za členství (stejná řada a podoba jako ostatní doklady)', 'nkz-mp-invoices' ) ),
			__( 'Důležité: ve Stripe pak vypni rozesílání jeho faktur (Settings → Billing → Subscriptions and emails → „Email finalized invoices to customers"). Jinak prodejce dostane za jednu platbu dva doklady s různými čísly.', 'nkz-mp-invoices' )
		);

		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/** Bez údajů Art of život nejde vystavit platný doklad. */
	public function incomplete_notice(): void {
		if ( ! self::enabled() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$missing = self::platform_incomplete();
		if ( ! $missing ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Faktury: chybí údaje Art of život.', 'nkz-mp-invoices' ),
			esc_html( sprintf( /* translators: %s: seznam */ __( 'Bez nich nebudou doklady úplné (%s).', 'nkz-mp-invoices' ), implode( ', ', $missing ) ) ),
			esc_url( self::admin_url() ),
			esc_html__( 'Doplnit', 'nkz-mp-invoices' )
		);
	}
}
