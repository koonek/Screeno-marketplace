<?php
/**
 * SettingsHub – jedna „Nastavení" stránka s taby místo roztroušených podstránek.
 *
 * Moduly registrují tab přes filter:
 *
 *   add_filter( 'nkzmp/v1/admin/settings/tabs', function ( array $tabs ) {
 *       $tabs[] = [ 'id' => 'packeta', 'label' => 'Zásilkovna',
 *                   'render' => [ $this, 'render_panel' ], 'priority' => 20 ];
 *       return $tabs;
 *   } );
 *
 * Modul, který tab zaregistruje, vynechá vlastní submenu (viz SettingsHub::available()).
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class SettingsHub {

	public const SLUG = 'nkz-marketplace-settings';

	private static ?SettingsHub $instance = null;

	public static function instance(): SettingsHub {
		return self::$instance ??= new self();
	}

	/** Moduly tím poznají, že se mají hlásit jako tab a ne jako submenu. */
	public static function available(): bool {
		return true;
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register' ], 7 );
	}

	public function register(): void {
		add_submenu_page(
			NKZMP_ADMIN_MENU_SLUG,
			__( 'Nastavení', 'nkz-marketplace' ),
			__( 'Nastavení', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			self::SLUG,
			[ self::class, 'render_static' ]
		);
	}

	public static function url( string $tab = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::SLUG );
		return $tab !== '' ? $url . '&tab=' . rawurlencode( $tab ) : $url;
	}

	public static function render_static(): void {
		self::instance()->render();
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$tabs = (array) apply_filters( 'nkzmp/v1/admin/settings/tabs', [] );
		$tabs = array_values( array_filter( $tabs, static fn( $t ) => is_array( $t ) && ! empty( $t['id'] ) && is_callable( $t['render'] ?? null ) ) );
		usort( $tabs, static fn( $a, $b ) => ( $a['priority'] ?? 50 ) <=> ( $b['priority'] ?? 50 ) );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'NKZ Marketplace – Nastavení', 'nkz-marketplace' ) . '</h1>';

		if ( empty( $tabs ) ) {
			echo '<p>' . esc_html__( 'Žádné moduly s nastavením nejsou aktivní.', 'nkz-marketplace' ) . '</p></div>';
			return;
		}

		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : (string) $tabs[0]['id'];
		$ids    = array_column( $tabs, 'id' );
		if ( ! in_array( $active, $ids, true ) ) {
			$active = (string) $tabs[0]['id'];
		}

		echo '<h2 class="nav-tab-wrapper" style="margin-bottom:24px;">';
		foreach ( $tabs as $tab ) {
			$is_active = ( $tab['id'] === $active ) ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( self::url( (string) $tab['id'] ) ) . '" class="nav-tab' . esc_attr( $is_active ) . '">' . esc_html( (string) $tab['label'] ) . '</a>';
		}
		echo '</h2>';

		foreach ( $tabs as $tab ) {
			if ( $tab['id'] === $active ) {
				call_user_func( $tab['render'] );
				break;
			}
		}

		echo '</div>';
	}
}
