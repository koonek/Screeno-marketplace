<?php
/**
 * Backfill service – sdílená logika mezi WP-CLI a admin UI tlačítkem.
 *
 * Iteruje WC ordery s meta `_nkv_split_transfers` (legacy Stripe adapter)
 * a každý completed record proveze přes LegacyStripeObserver::backfill_transfer.
 * Observer používá idempotency keys, takže opakované spuštění nevytvoří
 * duplikáty.
 *
 * @package NKZMP
 */

namespace NKZMP\Integration;

defined( 'ABSPATH' ) || exit;

final class BackfillService {

	/**
	 * @return array{orders:int, records:int, completed:int, skipped:int, errors:int, error_msgs:string[]}
	 */
	public static function run( bool $dry_run = false, int $limit = 0, ?string $since = null ): array {
		$query_args = [
			'limit'        => $limit > 0 ? $limit : -1,
			'status'       => [ 'wc-processing', 'wc-completed', 'wc-refunded', 'wc-on-hold' ],
			'meta_key'     => '_nkv_split_transfers',
			'meta_compare' => 'EXISTS',
			'return'       => 'ids',
			'orderby'      => 'date',
			'order'        => 'ASC',
		];
		if ( $since ) {
			$query_args['date_created'] = '>=' . $since;
		}
		$order_ids = wc_get_orders( $query_args );

		$stats = [
			'orders'     => 0,
			'records'    => 0,
			'completed'  => 0,
			'skipped'    => 0,
			'errors'     => 0,
			'error_msgs' => [],
		];

		if ( empty( $order_ids ) ) {
			return $stats;
		}

		$observer = LegacyStripeObserver::instance();

		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$stats['orders']++;

			$raw     = $order->get_meta( '_nkv_split_transfers' );
			$records = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
			if ( ! is_array( $records ) || empty( $records ) ) {
				continue;
			}

			foreach ( $records as $record ) {
				$stats['records']++;
				if ( ( $record['status'] ?? '' ) !== 'completed' ) {
					$stats['skipped']++;
					continue;
				}
				if ( $dry_run ) {
					$stats['completed']++;
					continue;
				}
				try {
					$observer->backfill_transfer( $order, $record );
					$stats['completed']++;
				} catch ( \Throwable $e ) {
					$stats['errors']++;
					$stats['error_msgs'][] = sprintf( 'Order #%d: %s', $oid, $e->getMessage() );
				}
			}
		}

		return $stats;
	}
}
