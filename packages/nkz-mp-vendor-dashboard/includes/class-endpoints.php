<?php
/**
 * Endpoints – rozšíření WC My Account o vendor stránky.
 *
 * Slugy:
 *  - vendor            (dashboard overview)
 *  - vendor-products
 *  - vendor-payouts
 *
 * Endpointy se objeví v My Account menu jen pro uživatele s vendor vazbou.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class Endpoints {

	public const SLUGS = [ 'vendor', 'vendor-products', 'vendor-orders', 'vendor-payouts', 'vendor-profile' ];

	private static ?Endpoints $instance = null;

	public static function instance(): Endpoints {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ self::class, 'register_rewrites' ] );
		add_filter( 'woocommerce_account_menu_items', [ $this, 'menu_items' ], 5 );
		add_filter( 'woocommerce_get_query_vars', [ $this, 'query_vars' ] );

		foreach ( self::SLUGS as $slug ) {
			add_action( 'woocommerce_account_' . $slug . '_endpoint', [ Views\Router::class, 'render' ] );
			add_filter( 'woocommerce_endpoint_' . $slug . '_title', [ Views\Router::class, 'title' ], 10, 1 );
		}
	}

	public static function register_rewrites(): void {
		foreach ( self::SLUGS as $slug ) {
			add_rewrite_endpoint( $slug, EP_PAGES );
		}
	}

	public function query_vars( array $vars ): array {
		foreach ( self::SLUGS as $slug ) {
			$vars[ $slug ] = $slug;
		}
		return $vars;
	}

	/**
	 * Vlož vendor položky do My Account menu (jen pokud user je vendor).
	 */
	public function menu_items( array $items ): array {
		if ( ! VendorContext::user_is_vendor() ) {
			return $items;
		}

		$vendor_items = [
			'vendor'          => __( 'Vendor: Přehled', 'nkz-mp-vendor-dashboard' ),
			'vendor-products' => __( 'Vendor: Produkty', 'nkz-mp-vendor-dashboard' ),
			'vendor-orders'   => __( 'Vendor: Objednávky', 'nkz-mp-vendor-dashboard' ),
			'vendor-payouts'  => __( 'Vendor: Výplaty', 'nkz-mp-vendor-dashboard' ),
			'vendor-profile'  => __( 'Vendor: Profil', 'nkz-mp-vendor-dashboard' ),
		];

		// Vlož vendor položky hned po Dashboard.
		$new = [];
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'dashboard' ) {
				foreach ( $vendor_items as $vk => $vl ) {
					$new[ $vk ] = $vl;
				}
			}
		}
		// Fallback pokud dashboard slug neexistuje.
		if ( ! isset( $new['vendor'] ) ) {
			$new = $vendor_items + $new;
		}

		return $new;
	}
}
