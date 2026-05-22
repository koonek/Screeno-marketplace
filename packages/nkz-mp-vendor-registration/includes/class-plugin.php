<?php
/**
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-vendor-registration', false, dirname( plugin_basename( NKZMP_REGISTRATION_FILE ) ) . '/languages' );

		Settings::instance()->init();
		Shortcode::instance()->init();
		FormHandler::instance()->init();
		Listener::instance()->init();
		MetaWatcher::instance()->init();
		StatusPage::instance()->init();
		Assets::instance()->init();
	}
}
