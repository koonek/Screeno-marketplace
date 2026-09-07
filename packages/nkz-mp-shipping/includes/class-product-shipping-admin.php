<?php
/**
 * ProductShippingAdmin – pole „Poštovné za tento produkt" v admin editoru
 * produktu (WC → záložka Doprava). Override per-vendor paušálu.
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class ProductShippingAdmin {

	private static ?ProductShippingAdmin $instance = null;

	public static function instance(): ProductShippingAdmin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'woocommerce_product_options_shipping', [ $this, 'field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save' ] );
	}

	public function field(): void {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		woocommerce_wp_text_input( [
			'id'                => '_nkzmp_shipping_override',
			'label'             => __( 'Poštovné za produkt (NKZ)', 'nkz-mp-shipping' ),
			'desc_tip'          => true,
			'description'       => __( 'Volitelné. Přebije per-vendor paušál. Prázdné = paušál prodejce. 0 = doprava zdarma.', 'nkz-mp-shipping' ),
			'type'              => 'number',
			'custom_attributes' => [ 'step' => '1', 'min' => '0' ],
		] );
	}

	/**
	 * @param \WC_Product $product
	 */
	public function save( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC product save nonce řeší WC.
		$raw = isset( $_POST['_nkzmp_shipping_override'] ) ? trim( (string) wp_unslash( $_POST['_nkzmp_shipping_override'] ) ) : '';
		Rate::set_product_shipping_override( $product->get_id(), $raw === '' ? '' : $raw );
	}
}
