<?php
/**
 * Per-order lock against concurrent transfer execution.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Order_Lock {

	private const TTL = 300; // seconds

	public static function acquire( \WC_Order $order ): bool {
		$key   = self::transient_key( $order->get_id() );
		$now   = time();

		// Check meta-based fallback first (works across object cache eviction).
		$expires = (int) $order->get_meta( '_nkv_split_lock' );
		if ( $expires > $now ) {
			return false;
		}

		// Transient as primary lock.
		if ( false !== get_transient( $key ) ) {
			return false;
		}

		set_transient( $key, $now, self::TTL );
		$order->update_meta_data( '_nkv_split_lock', $now + self::TTL );
		$order->save();
		return true;
	}

	public static function release( \WC_Order $order ): void {
		delete_transient( self::transient_key( $order->get_id() ) );
		$order->delete_meta_data( '_nkv_split_lock' );
		$order->save();
	}

	private static function transient_key( int $order_id ): string {
		return 'nkv_svs_lock_' . $order_id;
	}
}
