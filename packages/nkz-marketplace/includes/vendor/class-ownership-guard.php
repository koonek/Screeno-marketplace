<?php
/**
 * OwnershipGuard – rozhoduje, kdo smí číst/editovat danou vendor entitu.
 *
 * Pravidla:
 *  - administrator / shop_manager se schopností `nkzmp_manage_vendors` → vše
 *  - vendor uživatel (role `nkzmp_vendor`) → jen vlastní vendor + vlastní entity
 *  - ostatní → odepřeno
 *
 * Mapping user → vendor jde přes meta `_nkzmp_wp_user_id` na vendor CPT;
 * při legacy CPT (`nkv_vendor`) přes `_nkv_wp_user_id` (fallback).
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class OwnershipGuard {

	/**
	 * Smí uživatel číst data daného vendora?
	 */
	public static function can_view_vendor( int $user_id, int $vendor_id ): bool {
		if ( user_can( $user_id, Capabilities::MANAGE_VENDORS ) ) {
			return true;
		}
		return self::user_owns_vendor( $user_id, $vendor_id );
	}

	/**
	 * Smí uživatel editovat / měnit stav vendora? Vždy jen admin.
	 */
	public static function can_manage_vendor( int $user_id, int $vendor_id ): bool {
		return user_can( $user_id, Capabilities::MANAGE_VENDORS );
	}

	/**
	 * Smí uživatel vidět ledger / payouty vendora?
	 */
	public static function can_view_vendor_finance( int $user_id, int $vendor_id ): bool {
		if ( user_can( $user_id, Capabilities::MANAGE_PAYOUTS ) ) {
			return true;
		}
		if ( user_can( $user_id, Capabilities::VIEW_OWN_PAYOUTS ) && self::user_owns_vendor( $user_id, $vendor_id ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Smí uživatel vidět ledger / allocation konkrétní objednávky?
	 *
	 * Admin vidí vždy. Vendor vidí pokud je v allokaci té objednávky uveden.
	 */
	public static function can_view_order_ledger( int $user_id, int $order_id ): bool {
		if ( user_can( $user_id, Capabilities::MANAGE_PAYOUTS ) ) {
			return true;
		}
		if ( ! user_can( $user_id, Capabilities::VIEW_OWN_ORDERS ) ) {
			return false;
		}
		$vendor_id = self::user_vendor_id( $user_id );
		if ( $vendor_id <= 0 ) {
			return false;
		}
		global $wpdb;
		$table = \NKZMP\Ledger\Schema::table_name();
		$found = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE order_id = %d AND vendor_id = %d",
				$order_id,
				$vendor_id
			)
		);
		return $found > 0;
	}

	/**
	 * Vrátí vendor ID, který „vlastní" daný WP user, nebo 0.
	 *
	 * Order: nový meta → legacy meta → email match (auto-link forward).
	 */
	public static function user_vendor_id( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		global $wpdb;

		// Nový meta klíč.
		$id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta}
				 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				MetaKeys::WP_USER_ID,
				(string) $user_id
			)
		);
		if ( $id > 0 ) {
			return $id;
		}

		// Email fallback — pokud vendor CPT má registrovaný email shodný
		// s emailem WP usera, propojíme je (a uložíme link na příště).
		if ( apply_filters( 'nkzmp/v1/vendor/email_link_fallback', true ) ) {
			$user = get_user_by( 'id', $user_id );
			if ( $user && ! empty( $user->user_email ) ) {
				$vid = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta}
						 WHERE meta_key IN ('_nkzmp_vendor_email', '_nkv_vendor_email')
						   AND meta_value = %s LIMIT 1",
						(string) $user->user_email
					)
				);
				if ( $vid > 0 ) {
					update_post_meta( $vid, MetaKeys::WP_USER_ID, $user_id );
					return $vid;
				}
			}
		}

		// Legacy fallback.
		if ( ! apply_filters( 'nkzmp/v1/vendor/disable_legacy_meta_fallback', false ) ) {
			$legacy_key = array_search( MetaKeys::WP_USER_ID, MetaKeys::legacy_map(), true );
			if ( $legacy_key !== false ) {
				$id = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT post_id FROM {$wpdb->postmeta}
						 WHERE meta_key = %s AND meta_value = %s LIMIT 1",
						$legacy_key,
						(string) $user_id
					)
				);
				if ( $id > 0 ) {
					return $id;
				}
			}
		}

		return 0;
	}

	private static function user_owns_vendor( int $user_id, int $vendor_id ): bool {
		return $vendor_id > 0 && self::user_vendor_id( $user_id ) === $vendor_id;
	}
}
