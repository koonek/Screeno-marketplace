<?php
/**
 * VendorContext – kdo je přihlášený vendor + jeho data.
 *
 * Resolve order:
 *  1. NKZMP\Vendor\OwnershipGuard::user_vendor_id (přes _nkzmp_wp_user_id meta)
 *  2. legacy _nkv_wp_user_id meta
 *  3. (zatím) žádná auto-link logika
 *
 * Pro produkci je potřeba aby vendor (po schválení a vytvoření WP usera)
 * měl ve své vendor CPT meta _nkzmp_wp_user_id = WP user ID.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

use NKZMP\Vendor\OwnershipGuard;
use NKZMP\Vendor\Repository as VendorRepository;

defined( 'ABSPATH' ) || exit;

final class VendorContext {

	private static ?array $cache = null;

	public static function current_vendor_id(): int {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return 0;
		}
		if ( class_exists( OwnershipGuard::class ) ) {
			return OwnershipGuard::user_vendor_id( $user_id );
		}
		return 0;
	}

	public static function current_vendor(): ?array {
		if ( self::$cache !== null ) {
			return self::$cache;
		}
		$id = self::current_vendor_id();
		if ( $id <= 0 ) {
			return null;
		}
		self::$cache = ( new VendorRepository() )->find( $id );
		return self::$cache;
	}

	public static function user_is_vendor(): bool {
		return self::current_vendor_id() > 0;
	}

	/** Stripe/KYC hotové (status = active)? */
	public static function is_kyc_done( int $vendor_id ): bool {
		$status = (string) get_post_meta( $vendor_id, '_nkzmp_vendor_status', true );
		if ( $status === '' ) {
			$status = (string) get_post_meta( $vendor_id, '_nkv_vendor_status', true );
		}
		return $status === 'active';
	}

	/** Aktivní předplatné (nebo billing modul vypnutý)? */
	public static function is_billing_ok( int $vendor_id ): bool {
		$billing_on = class_exists( \NKZMP\Billing\Settings::class ) && \NKZMP\Billing\Settings::is_enabled();
		if ( ! $billing_on ) {
			return true;
		}
		return (string) get_post_meta( $vendor_id, '_nkzmp_billing_status', true ) === 'active';
	}

	/**
	 * Smí prodejce přidávat produkty? Odemkne se až po dokončení Stripe (KYC
	 * active) A aktivaci předplatného. Filtrovatelné.
	 */
	public static function can_add_products( int $vendor_id ): bool {
		$ok = self::is_kyc_done( $vendor_id ) && self::is_billing_ok( $vendor_id );
		return (bool) apply_filters( 'nkzmp/v1/dashboard/can_add_products', $ok, $vendor_id );
	}
}
