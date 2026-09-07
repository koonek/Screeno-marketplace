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
			'vendor'          => __( 'Přehled', 'nkz-mp-vendor-dashboard' ),
			'vendor-products' => __( 'Produkty', 'nkz-mp-vendor-dashboard' ),
			'vendor-orders'   => __( 'Objednávky', 'nkz-mp-vendor-dashboard' ),
			'vendor-payouts'  => __( 'Výplaty', 'nkz-mp-vendor-dashboard' ),
			'vendor-profile'  => __( 'Profil', 'nkz-mp-vendor-dashboard' ),
		];

		// Schovej default WC položky, které vendorovi duplikují vendor sekci
		// nebo nedávají smysl (přehled má vlastní, stahování nevyužije).
		unset( $items['dashboard'], $items['downloads'] );

		// Zákaznické objednávky přejmenuj, ať se nepletou s vendor objednávkami.
		if ( isset( $items['orders'] ) ) {
			$items['orders'] = __( 'Mé objednávky', 'nkz-mp-vendor-dashboard' );
		}

		// Vendor položky první, pak zbytek (Mé objednávky, Adresy, Detaily, Odhlásit).
		// Předplatné vloží billing modul za vendor-profile (priorita 6).
		return $vendor_items + $items;
	}
}
