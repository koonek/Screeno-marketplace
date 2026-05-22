<?php
/**
 * Frontend CSS.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private static ?Assets $instance = null;

	public static function instance(): Assets {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		// Načítáme jen na storefront stránkách.
		if ( ! get_query_var( 'nkzmp_vendor_slug' ) && ! get_query_var( 'nkzmp_vendor_archive' ) && ! is_product() ) {
			return;
		}
		wp_enqueue_style(
			'nkz-mp-storefront',
			NKZMP_STOREFRONT_URL . 'assets/storefront.css',
			[],
			NKZMP_STOREFRONT_VERSION
		);
	}
}
