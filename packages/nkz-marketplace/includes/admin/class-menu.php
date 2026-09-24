<?php
/**
 * Top-level NKZ Marketplace admin menu.
 *
 * Modul registruje parent menu (priority 5 — před ostatními moduly).
 * Dashboard je default první submenu (slug = parent slug).
 *
 * Ostatní moduly (storefront, registration, tools, status) přidávají vlastní
 * submenu přes `add_submenu_page( NKZMP_ADMIN_MENU_SLUG, ... )` v
 * standardním admin_menu hooku.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Menu {

	private static ?Menu $instance = null;

	public static function instance(): Menu {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register' ], 5 );
	}

	public function register(): void {
		add_menu_page(
			__( 'NKZ Marketplace', 'nkz-marketplace' ),
			__( 'NKZ Marketplace', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			NKZMP_ADMIN_MENU_SLUG,
			[ DashboardPage::class, 'render_static' ],
			'dashicons-store',
			56
		);
		// První submenu musí mít stejný slug jako parent, aby kliknutí na
		// parent menu vedlo na Dashboard (jinak WP nabere první pozdější submenu).
		add_submenu_page(
			NKZMP_ADMIN_MENU_SLUG,
			__( 'Dashboard', 'nkz-marketplace' ),
			__( 'Dashboard', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			NKZMP_ADMIN_MENU_SLUG,
			[ DashboardPage::class, 'render_static' ]
		);
	}
}
