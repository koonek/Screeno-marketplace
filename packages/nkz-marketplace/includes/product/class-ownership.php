<?php
/**
 * Product Ownership – read helpers pro „kdo vlastní produkt".
 *
 * Pure read API; admin UI (panel ve WC product editoru) a capability guard
 * jsou samostatné třídy, které se přidají v dalším kroku Fáze 0.
 *
 * Legacy fallback: čte `_nkv_vendor_id` ze Stripe adapter v0.6.x pokud
 * `_nkzmp_vendor_id` ještě není nastaven.
 *
 * @package NKZMP
 */

namespace NKZMP\Product;

defined( 'ABSPATH' ) || exit;

final class Ownership {

	/**
	 * Vrátí vendor_id pro daný produkt nebo 0, pokud není přiřazen.
	 * Pro variace vrátí vendora rodičovského produktu.
	 *
	 * @param int|\WC_Product $product
	 */
	public static function vendor_id( $product ): int {
		$product_id = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
		if ( $product_id <= 0 ) {
			return 0;
		}

		// U variací použij rodiče.
		$parent_id = (int) wp_get_post_parent_id( $product_id );
		if ( $parent_id > 0 && get_post_type( $parent_id ) === 'product' ) {
			$product_id = $parent_id;
		}

		$value = self::meta_with_legacy( $product_id, MetaKeys::VENDOR_ID, '_nkv_vendor_id' );
		return (int) $value;
	}

	/**
	 * Zda produkt vyžaduje shipping. Default `yes` (fyzický). Vendor může
	 * vypnout u digital produktů přes `_nkzmp_requires_shipping = 'no'`.
	 *
	 * @param int|\WC_Product $product
	 */
	public static function requires_shipping( $product ): bool {
		$product_id = $product instanceof \WC_Product ? $product->get_id() : (int) $product;
		if ( $product_id <= 0 ) {
			return true;
		}

		$value = get_post_meta( $product_id, MetaKeys::REQUIRES_SHIPPING, true );

		// Default = WC virtual flag respektovat. Pokud je produkt WC-virtual, default false.
		if ( '' === $value || null === $value || false === $value ) {
			$wc_product = $product instanceof \WC_Product ? $product : ( function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null );
			$default    = $wc_product ? ! $wc_product->is_virtual() : true;
			return (bool) apply_filters( 'nkzmp/v1/shipping/requires', $default, $wc_product );
		}

		return 'yes' === $value;
	}

	/**
	 * Read s legacy fallbackem na konkrétní starý klíč.
	 *
	 * @return mixed
	 */
	private static function meta_with_legacy( int $product_id, string $new_key, string $legacy_key ) {
		$value = get_post_meta( $product_id, $new_key, true );
		if ( $value !== '' && $value !== null && $value !== false ) {
			return $value;
		}

		if ( apply_filters( 'nkzmp/v1/product/disable_legacy_meta_fallback', false ) ) {
			return $value;
		}

		return get_post_meta( $product_id, $legacy_key, true );
	}
}
