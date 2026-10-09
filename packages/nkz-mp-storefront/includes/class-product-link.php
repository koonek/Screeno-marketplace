<?php
/**
 * Na product stránce vykreslí „Prodejce: <name>" link.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ProductLink {

	private static ?ProductLink $instance = null;

	public static function instance(): ProductLink {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Nahrazeno novejsim 'single_vendor_badge' (avatar + jmeno pill)
		// v ShopLoop @woocommerce_single_product_summary:6. Tato trida
		// uz se nevykresluje, at na produktu neni dvakrat "od / Prodejce".
		// Settings flag 'enable_product_link' nechan kvuli zpetne kompatibilite.
		return;
	}

	public function render_link(): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$vendor_id = (int) get_post_meta( $product->get_id(), '_nkzmp_vendor_id', true );
		if ( $vendor_id <= 0 ) {
			$vendor_id = (int) get_post_meta( $product->get_id(), '_nkv_vendor_id', true );
		}
		if ( $vendor_id <= 0 ) {
			return;
		}
		$post = get_post( $vendor_id );
		if ( ! $post ) {
			return;
		}
		$slug_base = Settings::get()['single_slug'];
		$url       = home_url( '/' . $slug_base . '/' . $post->post_name );

		echo '<p class="nkzmp-product-vendor"><span>' . esc_html__( 'Prodejce:', 'nkz-mp-storefront' ) . '</span> ';
		echo '<a href="' . esc_url( $url ) . '">' . esc_html( $post->post_title ) . '</a></p>';
	}
}
