<?php
/**
 * WP-CLI: wp nkzmp reconcile
 *
 * ## OPTIONS
 *
 * [--adapter=<name>]
 * : Specifický adapter. Pokud chybí, projede všechny registrované drivery.
 *
 * [--since=<duration>]
 * : Časové okno (např. 24h, 7d, 1h). Default: 24h.
 *
 * [--until=<timestamp>]
 * : End timestamp (unix). Default: now.
 *
 * [--format=<format>]
 * : table | json | yaml. Default: table.
 *
 * ## EXAMPLES
 *
 *     wp nkzmp reconcile
 *     wp nkzmp reconcile --adapter=stripe-legacy --since=24h
 *     wp nkzmp reconcile --since=7d --format=json
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Reconciliation\Service;

defined( 'ABSPATH' ) || exit;

final class ReconcileCommand {

	public function __invoke( array $args, array $assoc_args ): void {
		$drivers = Service::drivers();
		if ( ! $drivers ) {
			\WP_CLI::warning( 'No reconciliation drivers registered. Stripe adapter must hook into nkzmp/v1/reconciliation/drivers.' );
			return;
		}

		$wanted = $assoc_args['adapter'] ?? null;
		if ( $wanted !== null && ! isset( $drivers[ $wanted ] ) ) {
			\WP_CLI::error( sprintf( 'Adapter %s not found. Available: %s', $wanted, implode( ', ', array_keys( $drivers ) ) ) );
		}

		$to_ts   = isset( $assoc_args['until'] ) ? (int) $assoc_args['until'] : time();
		$since   = (string) ( $assoc_args['since'] ?? '24h' );
		$from_ts = $to_ts - self::parse_duration( $since );

		$service = new Service();
		$reports = [];

		foreach ( $drivers as $name => $driver ) {
			if ( $wanted !== null && $name !== $wanted ) {
				continue;
			}
			try {
				$reports[ $name ] = $service->run( $driver, $from_ts, $to_ts );
			} catch ( \Throwable $e ) {
				\WP_CLI::warning( sprintf( '%s failed: %s', $name, $e->getMessage() ) );
			}
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			$out = [];
			foreach ( $reports as $name => $r ) {
				$out[ $name ] = $r->to_array();
			}
			\WP_CLI::line( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		foreach ( $reports as $name => $r ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( sprintf( 'Adapter: %s   Window: %s → %s', $name, gmdate( 'c', $r->from_ts ), gmdate( 'c', $r->to_ts ) ) );
			\WP_CLI::line( sprintf( 'Source: %d   Ledger: %d   Matched: %d   Drift: %d', $r->source_count, $r->ledger_count, $r->matched_count, count( $r->drift ) ) );
			if ( $r->has_drift() ) {
				$rows = [];
				foreach ( $r->drift as $d ) {
					$rows[] = [
						'kind'       => $d['kind'],
						'source_ref' => $d['source_ref'],
						'detail'     => wp_json_encode( $d['detail'] ),
					];
				}
				\WP_CLI\Utils\format_items( 'table', $rows, [ 'kind', 'source_ref', 'detail' ] );
			} else {
				\WP_CLI::success( sprintf( '%s: no drift', $name ) );
			}
		}
	}

	private static function parse_duration( string $s ): int {
		if ( preg_match( '/^(\d+)([smhd])$/i', trim( $s ), $m ) ) {
			$n    = (int) $m[1];
			$unit = strtolower( $m[2] );
			return match ( $unit ) {
				's' => $n,
				'm' => $n * MINUTE_IN_SECONDS,
				'h' => $n * HOUR_IN_SECONDS,
				'd' => $n * DAY_IN_SECONDS,
				default => $n * HOUR_IN_SECONDS,
			};
		}
		return 24 * HOUR_IN_SECONDS;
	}
}
