<?php
/**
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-vendor-dashboard', false, dirname( plugin_basename( NKZMP_DASHBOARD_FILE ) ) . '/languages' );

		Endpoints::instance()->init();
		Redirect::instance()->init();
		Assets::instance()->init();
		AccountChrome::instance()->init();
		ProductSubmitController::instance()->init();
		ProductSubmitController::instance()->init_actions();
		ProfileSubmitController::instance()->init();
		OrderNotifications::instance()->init();
	}
}
