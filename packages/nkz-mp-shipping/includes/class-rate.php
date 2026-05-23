<?php
/**
 * Rate – helper pro čtení/zápis per-vendor paušálu + product shipping flagu.
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class Rate {

	private static ?Rate $instance = null;

	public static function instance(): Rate {
		return self::$instance ??= new self();
	}

	/**
	 * Paušál vendora v major units (Kč). Fallback na globální default.
	 */
	public static function vendor_flat( int $vendor_id ): float {
		$raw = get_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META, true );
		if ( $raw !== '' && is_numeric( $raw ) ) {
			return (float) $raw;
		}
		return self::default_flat();
	}

	public static function set_vendor_flat( int $vendor_id, float $amount ): void {
		update_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META, $amount );
	}

	public static function has_explicit_rate( int $vendor_id ): bool {
		$raw = get_post_meta( $vendor_id, NKZMP_SHIPPING_VENDOR_RATE_META, true );
		return $raw !== '' && is_numeric( $raw );
	}

	public static function default_flat(): float {
		$s = Settings::get();
		return (float) $s['default_flat'];
	}

	/**
	 * Vyžaduje produkt dopravu? Respektuje WC virtual flag + náš meta flag.
	 */
	public static function product_requires_shipping( \WC_Product $product ): bool {
		if ( $product->is_virtual() || $product->is_downloadable() ) {
			return false;
		}
		$flag = get_post_meta( $product->get_id(), NKZMP_SHIPPING_PRODUCT_REQUIRES_META, true );
		// Default = yes (prázdné meta = vyžaduje dopravu).
		return $flag !== 'no';
	}

	/**
	 * Vendor ID produktu (přes _nkzmp_vendor_id / _nkv_vendor_id meta).
	 */
	public static function product_vendor_id( int $product_id ): int {
		$vid = (int) get_post_meta( $product_id, '_nkzmp_vendor_id', true );
		if ( $vid > 0 ) {
			return $vid;
		}
		return (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
	}
}
