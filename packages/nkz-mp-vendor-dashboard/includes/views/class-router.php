<?php
/**
 * Router – mapuje endpoint slug na view.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

use NKZMP\Dashboard\VendorContext;

defined( 'ABSPATH' ) || exit;

final class Router {

	public static function render( $value = '' ): void {
		$slug = self::current_slug();
		if ( ! VendorContext::user_is_vendor() ) {
			self::render_not_vendor();
			return;
		}
		$vendor = VendorContext::current_vendor();
		if ( ! $vendor ) {
			self::render_not_vendor();
			return;
		}
		switch ( $slug ) {
			case 'vendor':          DashboardView::render( $vendor ); return;
			case 'vendor-products':
				if ( ! empty( $_GET['new'] ) ) {
					ProductFormView::render( $vendor, null );
					return;
				}
				if ( ! empty( $_GET['edit'] ) ) {
					ProductFormView::render( $vendor, (int) $_GET['edit'] );
					return;
				}
				ProductsView::render( $vendor );
				return;
			case 'vendor-payouts':  PayoutsView::render( $vendor ); return;
			case 'vendor-orders':   OrdersView::render( $vendor ); return;
			case 'vendor-profile':  ProfileFormView::render( $vendor ); return;
			default: DashboardView::render( $vendor );
		}
	}

	public static function title( string $title ): string {
		switch ( self::current_slug() ) {
			case 'vendor':          return __( 'Přehled', 'nkz-mp-vendor-dashboard' );
			case 'vendor-products': return __( 'Moje produkty', 'nkz-mp-vendor-dashboard' );
			case 'vendor-orders':   return __( 'Moje objednávky', 'nkz-mp-vendor-dashboard' );
			case 'vendor-payouts':  return __( 'Moje výplaty', 'nkz-mp-vendor-dashboard' );
			case 'vendor-profile':  return __( 'Můj profil', 'nkz-mp-vendor-dashboard' );
		}
		return $title;
	}

	private static function current_slug(): string {
		$wp = $GLOBALS['wp'] ?? null;
		if ( ! $wp instanceof \WP ) {
			return '';
		}
		foreach ( [ 'vendor', 'vendor-products', 'vendor-orders', 'vendor-payouts', 'vendor-profile' ] as $slug ) {
			if ( isset( $wp->query_vars[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	private static function render_not_vendor(): void {
		echo '<div class="nkzmp-vd-empty">';
		echo '<h2>' . esc_html__( 'Nejsi prodejce', 'nkz-mp-vendor-dashboard' ) . '</h2>';
		echo '<p>' . esc_html__( 'Tato sekce je dostupná jen schváleným prodejcům. Pokud máš pocit, že tu být máš, ozvi se nám.', 'nkz-mp-vendor-dashboard' ) . '</p>';
		echo '</div>';
	}
}
