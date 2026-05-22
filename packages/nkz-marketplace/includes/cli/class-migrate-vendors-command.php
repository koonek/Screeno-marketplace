<?php
/**
 * WP-CLI: wp nkzmp migrate-vendors
 *
 * Zkopíruje legacy `_nkv_*` post meta na nové `_nkzmp_*` klíče.
 *
 * ## OPTIONS
 *
 * [--dry-run]
 * : Pouze report, nic se nezapíše.
 *
 * [--limit=<n>]
 * : Maximální počet vendorů (default: bez limitu).
 *
 * [--format=<format>]
 * : table | json. Default: table.
 *
 * ## EXAMPLES
 *
 *     wp nkzmp migrate-vendors --dry-run
 *     wp nkzmp migrate-vendors --limit=10
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Vendor\MetaMigrator;

defined( 'ABSPATH' ) || exit;

final class MigrateVendorsCommand {

	public function __invoke( array $args, array $assoc_args ): void {
		$dry_run = isset( $assoc_args['dry-run'] );
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 0;
		$format  = (string) ( $assoc_args['format'] ?? 'table' );

		$summary = MetaMigrator::migrate_all( $dry_run, $limit );

		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( $dry_run ? '[DRY RUN] Žádné změny v databázi.' : 'Migrace dokončena.' );
		\WP_CLI::line( str_repeat( '-', 40 ) );
		\WP_CLI::line( sprintf( 'Vendoři zpracováno : %d', $summary['processed'] ) );
		\WP_CLI::line( sprintf( 'Klíče copied       : %d', $summary['copied'] ) );
		\WP_CLI::line( sprintf( 'Klíče skipped (existují) : %d', $summary['skipped_exists'] ) );
		\WP_CLI::line( sprintf( 'Klíče skipped (legacy prázdný) : %d', $summary['skipped_empty'] ) );
		\WP_CLI::line( '' );
		\WP_CLI::success( 'Done.' );
	}
}
