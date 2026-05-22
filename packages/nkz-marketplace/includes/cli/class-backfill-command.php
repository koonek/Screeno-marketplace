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

use NKZMP\Integration\BackfillService;

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

		$stats = BackfillService::run( $dry_run, $limit, $since );

		\WP_CLI::log( sprintf( 'Orderů: %d', $stats['orders'] ) );
		\WP_CLI::log( sprintf( 'Transfer records: %d', $stats['records'] ) );
		\WP_CLI::log( sprintf( 'Completed (zapsáno): %d', $stats['completed'] ) );
		\WP_CLI::log( sprintf( 'Skipped (non-completed): %d', $stats['skipped'] ) );
		\WP_CLI::log( sprintf( 'Errors: %d', $stats['errors'] ) );
		foreach ( $stats['error_msgs'] as $msg ) {
			\WP_CLI::warning( $msg );
		}
		\WP_CLI::success( $dry_run ? 'Dry-run dokončen.' : 'Backfill dokončen.' );
	}
}
