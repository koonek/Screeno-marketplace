<?php
/**
 * Block checkout when cart contains products whose vendor cannot receive transfers.
 *
 * Without this guard, a buyer can pay for a product whose vendor is `restricted`
 * or otherwise un-payable, leading to a charge on the platform that we cannot
 * forward — money gets stuck on platform balance and the vendor has no way to fulfil.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Checkout_Guard {

	private static ?Checkout_Guard $instance = null;
	public static function instance(): Checkout_Guard { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'woocommerce_check_cart_items',       [ $this, 'check_cart_items' ] );
		add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_add_to_cart' ], 10, 2 );
	}

	/**
	 * Hard block at the checkout / cart page.
	 */
	public function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			$reason = $this->reason_to_block( (int) ( $cart_item['product_id'] ?? 0 ) );
			if ( null !== $reason ) {
				wc_add_notice( $reason, 'error' );
			}
		}
	}

	/**
	 * Soft block on add-to-cart — prevents the buyer from even putting the product in.
	 */
	public function validate_add_to_cart( bool $passed, int $product_id ): bool {
		$reason = $this->reason_to_block( $product_id );
		if ( null !== $reason ) {
			wc_add_notice( $reason, 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Returns a localized error message if the product cannot be sold right now, else null.
	 */
	private function reason_to_block( int $product_id ): ?string {
		if ( $product_id <= 0 ) {
			return null;
		}
		// Only guard products that opted into vendor split.
		$enabled = get_post_meta( $product_id, '_nkv_vendor_split_enabled', true );
		if ( 'no' === $enabled ) {
			return null;
		}
		$vendor_id = (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
		if ( $vendor_id <= 0 ) {
			return null; // no vendor → nothing to guard
		}
		$vendor = Vendor_Repository::get( $vendor_id );
		if ( ! $vendor ) {
			return null;
		}
		if ( Vendor_Repository::is_payable( $vendor ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );
		$name    = $product ? $product->get_name() : '#' . $product_id;

		return sprintf(
			/* translators: 1: product name, 2: vendor name */
			__( 'Produkt „%1$s" aktuálně nelze objednat — prodejce %2$s čeká na dokončení ověření Stripe účtu. Zkus to prosím později.', 'nkz-woo-stripe-vendor-split' ),
			$name,
			$vendor['name'] ?: __( 'prodejce', 'nkz-woo-stripe-vendor-split' )
		);
	}
}
