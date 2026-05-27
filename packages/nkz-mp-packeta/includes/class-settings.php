<?php
/**
 * Settings – Packeta API klíč (pro widget) + výchozí cena fallback.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_packeta_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		if ( class_exists( \NKZMP\Admin\SettingsHub::class ) && \NKZMP\Admin\SettingsHub::available() ) {
			add_filter( 'nkzmp/v1/admin/settings/tabs', [ $this, 'register_tab' ] );
		} else {
			add_action( 'admin_menu', [ $this, 'menu' ], 35 );
		}
	}

	public function register_tab( array $tabs ): array {
		$tabs[] = [
			'id'       => 'packeta',
			'label'    => __( 'Zásilkovna', 'nkz-mp-packeta' ),
			'render'   => [ $this, 'render_panel' ],
			'priority' => 30,
		];
		return $tabs;
	}

	public static function get(): array {
		$defaults = [
			'api_key'        => '',   // Packeta widget API key (veřejný, pro widget)
			'api_password'   => '',   // Packeta REST API heslo (tajné, pro createPacket/štítky)
			'sender_label'   => '',   // výchozí odesílatel (eshop label v Packeta účtu)
			'default_weight' => 1,    // kg, fallback když produkt nemá vyplněnou váhu
			'default_price'  => 0,    // fallback pokud nkz-mp-shipping není aktivní
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public static function api_key(): string {
		return (string) self::get()['api_key'];
	}

	public static function is_configured(): bool {
		return self::api_key() !== '';
	}

	/** REST API heslo pro zakládání zásilek / štítky. */
	public static function api_password(): string {
		return (string) self::get()['api_password'];
	}

	/** Je nakonfigurované zakládání zásilek (auto-štítky)? */
	public static function api_configured(): bool {
		return self::api_password() !== '';
	}

	public static function sender_label(): string {
		return (string) self::get()['sender_label'];
	}

	public static function default_weight(): float {
		$w = (float) self::get()['default_weight'];
		return $w > 0 ? $w : 1.0;
	}

	public function register(): void {
		register_setting( 'nkzmp_packeta', self::OPTION );
	}

	public function menu(): void {
		$parent = defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Zásilkovna', 'nkz-mp-packeta' ),
			__( 'Zásilkovna', 'nkz-mp-packeta' ),
			'manage_woocommerce',
			'nkz-mp-packeta',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace – Zásilkovna', 'nkz-mp-packeta' ) . '</h1>';
		$this->render_panel();
		echo '</div>';
	}

	public function render_panel(): void {
		$s = self::get();

		if ( ! self::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Zatím není vyplněný API klíč. Bez něj se widget pro výběr výdejny nenačte.', 'nkz-mp-packeta' ) . '</p></div>';
		}

		echo '<p>' . wp_kses_post( __( 'Údaje najdeš v <strong>Klientská sekce Zásilkovny → Nastavení → API</strong>. <strong>API klíč</strong> (veřejný) je pro widget výběru výdejny, <strong>API heslo</strong> (tajné) je pro zakládání zásilek a tisk štítků.', 'nkz-mp-packeta' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Cena dopravy se bere z per-vendor paušálu (NKZ Marketplace → Doprava). Bez API hesla jedou štítky ručně; s heslem si je prodejci generují přímo v dashboardu.', 'nkz-mp-packeta' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_packeta' );
		echo '<table class="form-table"><tr>';
		echo '<th><label for="api_key">' . esc_html__( 'Packeta API klíč (widget)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="api_key" type="text" name="' . esc_attr( self::OPTION ) . '[api_key]" value="' . esc_attr( (string) $s['api_key'] ) . '" style="width:420px" />';
		echo '<p class="description">' . esc_html__( 'Veřejný klíč pro widget výběru výdejny v checkoutu.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="api_password">' . esc_html__( 'Packeta API heslo (štítky)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="api_password" type="text" name="' . esc_attr( self::OPTION ) . '[api_password]" value="' . esc_attr( (string) $s['api_password'] ) . '" style="width:420px" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Tajné API heslo pro zakládání zásilek a tisk štítků. Bez něj se auto-štítky nevytvoří.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="sender_label">' . esc_html__( 'Výchozí odesílatel (eshop label)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="sender_label" type="text" name="' . esc_attr( self::OPTION ) . '[sender_label]" value="' . esc_attr( (string) $s['sender_label'] ) . '" style="width:420px" />';
		echo '<p class="description">' . esc_html__( 'Název odesílatele tak, jak je nakonfigurovaný v Packeta účtu. Použije se, když prodejce nemá vlastní. Prodejce si může nastavit vlastní v profilu.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="default_weight">' . esc_html__( 'Výchozí váha balíku (kg)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="default_weight" type="number" min="0.1" step="0.1" name="' . esc_attr( self::OPTION ) . '[default_weight]" value="' . esc_attr( (string) $s['default_weight'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se, když produkt nemá vyplněnou váhu. Výdejní místa berou zhruba do 5 kg.', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="default_price">' . esc_html__( 'Fallback cena (Kč)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="default_price" type="number" min="0" name="' . esc_attr( self::OPTION ) . '[default_price]" value="' . esc_attr( (string) $s['default_price'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se jen pokud není aktivní modul Doprava (per-vendor paušál).', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr></table>';
		submit_button();
		echo '</form>';
	}
}
