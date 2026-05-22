<?php
/**
 * Storefront bootstrap.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-mp-storefront', false, dirname( plugin_basename( NKZMP_STOREFRONT_FILE ) ) . '/languages' );

		Settings::instance()->init();
		Rewrite::instance()->init();
		VendorPage::instance()->init();
		ArchivePage::instance()->init();
		ProductLink::instance()->init();
		Seo::instance()->init();
		Assets::instance()->init();
	}
}
