<?php
/**
 * Storefront settings – uloženo v `nkzmp_storefront_settings` option.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_storefront_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'register' ] );
	}

	public static function get(): array {
		$defaults = [
			'enable_archive'      => 'yes',
			'enable_single'       => 'yes',
			'enable_product_link' => 'yes',
			'archive_slug'        => 'vendors',
			'single_slug'         => 'vendor',
			'per_page'            => 24,
		];
		$saved = get_option( self::OPTION, [] );
		return array_merge( $defaults, is_array( $saved ) ? $saved : [] );
	}

	public function register(): void {
		register_setting( 'nkzmp_storefront', self::OPTION );
		add_settings_section( 'nkzmp_storefront_main', __( 'Storefront', 'nkz-mp-storefront' ), '__return_false', 'nkzmp_storefront' );

		$fields = [
			'enable_archive'      => __( 'Archive /vendors zapnut', 'nkz-mp-storefront' ),
			'enable_single'       => __( 'Single vendor /vendor/<slug>', 'nkz-mp-storefront' ),
			'enable_product_link' => __( 'Odkaz „Prodejce" na produktu', 'nkz-mp-storefront' ),
			'archive_slug'        => __( 'Archive base slug', 'nkz-mp-storefront' ),
			'single_slug'         => __( 'Single base slug', 'nkz-mp-storefront' ),
			'per_page'            => __( 'Produkty na vendor page', 'nkz-mp-storefront' ),
		];
		foreach ( $fields as $key => $label ) {
			add_settings_field(
				$key,
				$label,
				[ $this, 'render_field' ],
				'nkzmp_storefront',
				'nkzmp_storefront_main',
				[ 'key' => $key ]
			);
		}

		add_action( 'admin_menu', function () {
			add_submenu_page(
				'woocommerce',
				__( 'NKZ Storefront', 'nkz-mp-storefront' ),
				__( 'NKZ Storefront', 'nkz-mp-storefront' ),
				'manage_woocommerce',
				'nkz-mp-storefront',
				[ $this, 'render_page' ]
			);
		} );
	}

	public function render_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'NKZ Marketplace Storefront', 'nkz-mp-storefront' ) . '</h1>';
		echo '<p>' . esc_html__( 'Po změně slugů spusť Settings → Permalinks → Save (nebo deaktivuj/aktivuj plugin) aby se rewrite rules obnovily.', 'nkz-mp-storefront' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( 'nkzmp_storefront' );
		do_settings_sections( 'nkzmp_storefront' );
		submit_button();
		echo '</form></div>';
	}

	public function render_field( array $args ): void {
		$key   = (string) $args['key'];
		$value = self::get()[ $key ];
		$name  = self::OPTION . '[' . $key . ']';

		if ( in_array( $key, [ 'enable_archive', 'enable_single', 'enable_product_link' ], true ) ) {
			echo '<select name="' . esc_attr( $name ) . '">';
			echo '<option value="yes"' . selected( $value, 'yes', false ) . '>' . esc_html__( 'Ano', 'nkz-mp-storefront' ) . '</option>';
			echo '<option value="no"' . selected( $value, 'no', false ) . '>' . esc_html__( 'Ne', 'nkz-mp-storefront' ) . '</option>';
			echo '</select>';
		} elseif ( 'per_page' === $key ) {
			echo '<input type="number" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" min="1" max="100" />';
		} else {
			echo '<input type="text" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}
}
