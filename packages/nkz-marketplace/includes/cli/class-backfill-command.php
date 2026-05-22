<?php
/**
 * `wp nkzmp backfill` – importuje historické Stripe transfery do nového ledgeru.
 *
 * Čte order meta `_nkv_split_transfers` (legacy Stripe adapter) ze všech
 * objednávek a pro každý `status=completed` record vytvoří odpovídající
 * ledger + payouts řádky. Idempotentní – opakovaný běh nevytvoří duplikáty
 * díky deterministickým idempotency keys v observeru.
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Integration\LegacyStripeObserver;

defined( 'ABSPATH' ) || exit;

final class BackfillCommand {

	/**
	 * Importuje historické legacy Stripe transfery do nového ledgeru.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Jen napočítá, nezapisuje.
	 *
	 * [--limit=<n>]
	 * : Maximální počet zpracovaných orderů. Default 0 = neomezeno.
	 *
	 * [--since=<date>]
	 * : Jen ordery vytvořené po datu (Y-m-d). Default vše.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nkzmp backfill --dry-run
	 *     wp nkzmp backfill --since=2024-01-01
	 *     wp nkzmp backfill --limit=100
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = ! empty( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$since   = $assoc_args['since'] ?? null;

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

		\WP_CLI::log( 'Hledám ordery s legacy transfer records…' );
		$order_ids = wc_get_orders( $query_args );

		if ( empty( $order_ids ) ) {
			\WP_CLI::success( 'Žádné ordery nenalezeny.' );
			return;
		}

		\WP_CLI::log( sprintf( 'Nalezeno %d orderů, zpracovávám%s.', count( $order_ids ), $dry_run ? ' (dry-run)' : '' ) );

		$observer = LegacyStripeObserver::instance();
		$stats    = [
			'orders'    => 0,
			'records'   => 0,
			'completed' => 0,
			'skipped'   => 0,
			'errors'    => 0,
		];

		$progress = function_exists( 'WP_CLI\Utils\make_progress_bar' )
			? \WP_CLI\Utils\make_progress_bar( 'Backfill', count( $order_ids ) )
			: null;

		foreach ( $order_ids as $oid ) {
			$order = wc_get_order( $oid );
			if ( ! $order instanceof \WC_Order ) {
				if ( $progress ) {
					$progress->tick();
				}
				continue;
			}

			$stats['orders']++;

			$raw = $order->get_meta( '_nkv_split_transfers' );
			if ( is_string( $raw ) && '' !== $raw ) {
				$records = json_decode( $raw, true );
			} else {
				$records = is_array( $raw ) ? $raw : [];
			}
			if ( ! is_array( $records ) || empty( $records ) ) {
				if ( $progress ) {
					$progress->tick();
				}
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
					\WP_CLI::warning( sprintf( 'Order #%d: %s', $oid, $e->getMessage() ) );
				}
			}

			if ( $progress ) {
				$progress->tick();
			}
		}

		if ( $progress ) {
			$progress->finish();
		}

		\WP_CLI::log( '' );
		\WP_CLI::log( sprintf( 'Orderů: %d', $stats['orders'] ) );
		\WP_CLI::log( sprintf( 'Transfer records: %d', $stats['records'] ) );
		\WP_CLI::log( sprintf( 'Completed (zapsáno): %d', $stats['completed'] ) );
		\WP_CLI::log( sprintf( 'Skipped (non-completed): %d', $stats['skipped'] ) );
		\WP_CLI::log( sprintf( 'Errors: %d', $stats['errors'] ) );

		if ( $dry_run ) {
			\WP_CLI::success( 'Dry-run dokončen. Reálný import: spusť bez --dry-run.' );
		} else {
			\WP_CLI::success( 'Backfill dokončen. Idempotency keys zabraňují duplikátům – opakované spuštění je bezpečné.' );
		}
	}
}
