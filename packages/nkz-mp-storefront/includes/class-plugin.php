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
		Strings::instance()->init();
		CartGrouping::instance()->init();
		ThankYou::instance()->init();
		ShopLoop::instance()->init();
		ShopFilters::instance()->init();
		Shortcodes::instance()->init();
		ProductReadmore::instance()->init();

		// Cache filtrů (cenové rozpětí, seznam prodejců) invalidovat při
		// uložení/smazání produktu.
		add_action( 'save_post_product', [ ShopFilters::class, 'forget_cache' ] );
		add_action( 'deleted_post', [ ShopFilters::class, 'forget_cache' ] );

		// Elementor integration je opt-in podle dostupnosti pluginu.
		if ( did_action( 'elementor/loaded' ) || class_exists( \Elementor\Plugin::class ) ) {
			\NKZMP\Storefront\Elementor\ElementorIntegration::instance()->init();
		} else {
			// Registrace přes pozdější hook, kdyby se Elementor načítal později.
			add_action( 'elementor/loaded', static function () {
				\NKZMP\Storefront\Elementor\ElementorIntegration::instance()->init();
			} );
		}
	}
}
