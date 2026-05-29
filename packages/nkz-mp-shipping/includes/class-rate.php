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

	/**
	 * Per-produkt override poštovného. Vrátí null pokud není nastaveno
	 * (pak se použije vendor paušál). 0 je validní hodnota (= doprava zdarma).
	 */
	public static function product_shipping_override( int $product_id ): ?float {
		if ( ! defined( 'NKZMP_SHIPPING_PRODUCT_OVERRIDE_META' ) ) {
			return null;
		}
		$raw = get_post_meta( $product_id, NKZMP_SHIPPING_PRODUCT_OVERRIDE_META, true );
		if ( $raw === '' || $raw === null || ! is_numeric( $raw ) ) {
			return null;
		}
		return (float) $raw;
	}

	public static function set_product_shipping_override( int $product_id, $amount ): void {
		if ( ! defined( 'NKZMP_SHIPPING_PRODUCT_OVERRIDE_META' ) ) {
			return;
		}
		if ( $amount === '' || $amount === null || ! is_numeric( $amount ) ) {
			delete_post_meta( $product_id, NKZMP_SHIPPING_PRODUCT_OVERRIDE_META );
			return;
		}
		update_post_meta( $product_id, NKZMP_SHIPPING_PRODUCT_OVERRIDE_META, (float) $amount );
	}

	/**
	 * Cena dopravy za balíček jednoho vendora podle produktů v košíku.
	 *
	 * Logika: produkt s overridem přispěje svým overridem, produkt bez něj
	 * přispěje vendor paušálem. Výsledek = strategie:
	 *  - 'max' (default): nejvyšší z příspěvků („vše v jednom balíku, cena
	 *    dle největšího")
	 *  - 'sum': součet (každý produkt vlastní balík)
	 * Filtr: `nkzmp/v1/shipping/strategy`.
	 *
	 * @param int                        $vendor_id
	 * @param array<int,\WC_Product>     $products fyzické produkty vendora
	 */
	public static function vendor_package_cost( int $vendor_id, array $products ): float {
		$candidates    = [];
		$needs_flat    = false;
		foreach ( $products as $product ) {
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$pid      = $product->get_parent_id() ?: $product->get_id();
			$override = self::product_shipping_override( $pid );
			if ( $override === null && $product->get_id() !== $pid ) {
				$override = self::product_shipping_override( $product->get_id() );
			}
			if ( $override !== null ) {
				$candidates[] = $override;
			} else {
				$needs_flat = true;
			}
		}
		if ( $needs_flat ) {
			$candidates[] = $vendor_id > 0 ? self::vendor_flat( $vendor_id ) : self::default_flat();
		}
		if ( empty( $candidates ) ) {
			return 0.0;
		}
		$strategy = (string) apply_filters( 'nkzmp/v1/shipping/strategy', 'max', $vendor_id, $products );
		return $strategy === 'sum' ? (float) array_sum( $candidates ) : (float) max( $candidates );
	}
}
