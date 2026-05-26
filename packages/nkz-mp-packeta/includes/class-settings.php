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
		add_action( 'admin_menu', [ $this, 'menu' ], 35 );
	}

	public static function get(): array {
		$defaults = [
			'api_key'       => '',   // Packeta widget API key
			'default_price' => 0,    // fallback pokud nkz-mp-shipping není aktivní
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
		$s = self::get();
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace – Zásilkovna', 'nkz-mp-packeta' ) . '</h1>';

		if ( ! self::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Zatím není vyplněný API klíč. Bez něj se widget pro výběr výdejny nenačte.', 'nkz-mp-packeta' ) . '</p></div>';
		}

		echo '<p>' . wp_kses_post( __( 'API klíč najdeš v <strong>Klientská sekce Zásilkovny → Nastavení → API</strong>. Pro widget stačí veřejný API klíč.', 'nkz-mp-packeta' ) ) . '</p>';
		echo '<p>' . esc_html__( 'Cena dopravy se bere z per-vendor paušálu (NKZ Marketplace → Doprava). Štítky se v této verzi generují ručně v Packeta klientovi podle výdejny uvedené u objednávky.', 'nkz-mp-packeta' ) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_packeta' );
		echo '<table class="form-table"><tr>';
		echo '<th><label for="api_key">' . esc_html__( 'Packeta API klíč', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="api_key" type="text" name="' . esc_attr( self::OPTION ) . '[api_key]" value="' . esc_attr( (string) $s['api_key'] ) . '" style="width:420px" /></td>';
		echo '</tr><tr>';
		echo '<th><label for="default_price">' . esc_html__( 'Fallback cena (Kč)', 'nkz-mp-packeta' ) . '</label></th>';
		echo '<td><input id="default_price" type="number" min="0" name="' . esc_attr( self::OPTION ) . '[default_price]" value="' . esc_attr( (string) $s['default_price'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se jen pokud není aktivní modul Doprava (per-vendor paušál).', 'nkz-mp-packeta' ) . '</p></td>';
		echo '</tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
