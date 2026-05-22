<?php
/**
 * Admin settings pro registration.
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

	public static function get(): array {
		$defaults = [
			'admin_notification_email' => get_option( 'admin_email' ),
			'success_message'          => __( 'Děkujeme. Tvoji přihlášku jsme přijali a vrátíme se ti e-mailem.', 'nkz-mp-vendor-registration' ),
			'terms_url'                => '',
			'success_redirect'         => '',
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'NKZ Registrace', 'nkz-mp-vendor-registration' ),
			__( 'NKZ Registrace', 'nkz-mp-vendor-registration' ),
			'manage_woocommerce',
			'nkz-mp-vendor-registration',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'nkzmp_registration', self::OPTION );
		add_settings_section( 'main', __( 'Registrace prodejců', 'nkz-mp-vendor-registration' ), '__return_false', 'nkzmp_registration' );

		$fields = [
			'admin_notification_email' => __( 'E-mail pro notifikace adminovi', 'nkz-mp-vendor-registration' ),
			'success_message'          => __( 'Zpráva po odeslání formuláře', 'nkz-mp-vendor-registration' ),
			'terms_url'                => __( 'URL podmínek (povinný checkbox)', 'nkz-mp-vendor-registration' ),
			'success_redirect'         => __( 'Redirect URL po odeslání (volitelné)', 'nkz-mp-vendor-registration' ),
		];
		foreach ( $fields as $key => $label ) {
			add_settings_field( $key, $label, [ $this, 'render_field' ], 'nkzmp_registration', 'main', [ 'key' => $key ] );
		}
	}

	public function render_field( array $args ): void {
		$key   = (string) $args['key'];
		$value = self::get()[ $key ];
		$name  = self::OPTION . '[' . $key . ']';
		if ( 'success_message' === $key ) {
			echo '<textarea name="' . esc_attr( $name ) . '" rows="3" cols="60">' . esc_textarea( (string) $value ) . '</textarea>';
		} else {
			echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" style="width:420px" />';
		}
	}

	public function render_page(): void {
		echo '<div class="wrap"><h1>NKZ Registrace prodejců</h1>';
		echo '<p>' . esc_html__( 'Formulář vlož do stránky shortcodem [nkzmp_vendor_registration] nebo blokem.', 'nkz-mp-vendor-registration' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_registration' );
		do_settings_sections( 'nkzmp_registration' );
		submit_button();
		echo '</form></div>';
	}
}
