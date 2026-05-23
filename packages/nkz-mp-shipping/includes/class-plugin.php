<?php
/**
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-shipping', false, dirname( plugin_basename( NKZMP_SHIPPING_FILE ) ) . '/languages' );

		Rate::instance(); // jen pro statické helpery, žádný init
		VendorRateAdmin::instance()->init();
		Settings::instance()->init();
	}
}
