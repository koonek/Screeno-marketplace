<?php
/**
 * Settings – default paušál + WC settings tab integrace.
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_shipping_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
		add_action( 'admin_menu', [ $this, 'menu' ], 30 );
	}

	public static function get(): array {
		$defaults = [
			'default_flat' => 79,
			// Spodní hranice poštovného, které si prodejce může nastavit.
			'min_flat'     => 99,
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public function register(): void {
		register_setting( 'nkzmp_shipping', self::OPTION );
	}

	public function menu(): void {
		$parent = defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce';
		add_submenu_page(
			$parent,
			__( 'Doprava', 'nkz-mp-shipping' ),
			__( 'Doprava', 'nkz-mp-shipping' ),
			'manage_woocommerce',
			'nkz-mp-shipping',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		$s = self::get();
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace – Doprava', 'nkz-mp-shipping' ) . '</h1>';
		echo '<p>' . esc_html__( 'Per-vendor paušální doprava. Aktivuj shipping method „Doprava od prodejců" v WooCommerce → Settings → Shipping → tvoje zóna.', 'nkz-mp-shipping' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_shipping' );
		echo '<table class="form-table"><tr>';
		echo '<th><label for="default_flat">' . esc_html__( 'Výchozí paušál (Kč)', 'nkz-mp-shipping' ) . '</label></th>';
		echo '<td><input id="default_flat" type="number" min="0" step="1" name="' . esc_attr( self::OPTION ) . '[default_flat]" value="' . esc_attr( (string) $s['default_flat'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Použije se pro vendory, kteří nemají vlastní paušál nastavený.', 'nkz-mp-shipping' ) . '</p></td>';
		echo '</tr><tr>';
		echo '<th><label for="min_flat">' . esc_html__( 'Minimální poštovné (Kč)', 'nkz-mp-shipping' ) . '</label></th>';
		echo '<td><input id="min_flat" type="number" min="0" step="1" name="' . esc_attr( self::OPTION ) . '[min_flat]" value="' . esc_attr( (string) $s['min_flat'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Nejnižší částka, kterou si prodejce může nastavit (u sebe i u produktu). Nižší hodnota se automaticky zvedne na tuto. 0 = bez omezení.', 'nkz-mp-shipping' ) . '</p></td>';
		echo '</tr></table>';
		submit_button();
		echo '</form></div>';
	}
}
