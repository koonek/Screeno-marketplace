<?php
/**
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-packeta', false, dirname( plugin_basename( NKZMP_PACKETA_FILE ) ) . '/languages' );

		Settings::instance()->init();
		CheckoutWidget::instance()->init();
		OrderDisplay::instance()->init();
	}
}
