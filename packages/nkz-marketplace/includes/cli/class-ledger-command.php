<?php
/**
 * `wp nkzmp ledger` – ledger CLI nástroje.
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Support\Money;

defined( 'ABSPATH' ) || exit;

final class LedgerCommand {

	/**
	 * Vypíše posledních N ledger záznamů.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Default 20.
	 *
	 * [--vendor=<id>]
	 * : Filtr na vendor_id.
	 *
	 * [--order=<id>]
	 * : Filtr na order_id.
	 *
	 * [--format=<format>]
	 * : table | json | csv. Default table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nkzmp ledger list
	 *     wp nkzmp ledger list --vendor=42 --limit=50
	 *     wp nkzmp ledger list --order=1234 --format=json
	 */
	public function list( array $args, array $assoc_args ): void {
		global $wpdb;

		$limit  = isset( $assoc_args['limit'] ) ? max( 1, (int) $assoc_args['limit'] ) : 20;
		$vendor = isset( $assoc_args['vendor'] ) ? (int) $assoc_args['vendor'] : null;
		$order  = isset( $assoc_args['order'] ) ? (int) $assoc_args['order'] : null;
		$format = $assoc_args['format'] ?? 'table';

		$table  = LedgerSchema::table_name();
		$where  = [ '1=1' ];
		$params = [];
		if ( $vendor !== null ) {
			$where[]  = 'vendor_id = %d';
			$params[] = $vendor;
		}
		if ( $order !== null ) {
			$where[]  = 'order_id = %d';
			$params[] = $order;
		}
		$where_sql = implode( ' AND ', $where );

		$sql = "SELECT id, vendor_id, type, amount_minor, currency, order_id, source_adapter, source_ref, occurred_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d";
		$params[] = $limit;

		$rows = $wpdb->get_results( $params ? $wpdb->prepare( $sql, ...$params ) : $sql, ARRAY_A ); // phpcs:ignore

		$out = [];
		foreach ( (array) $rows as $r ) {
			$out[] = [
				'id'       => (int) $r['id'],
				'vendor'   => (int) $r['vendor_id'] === 0 ? 'platform' : '#' . (int) $r['vendor_id'],
				'type'     => $r['type'],
				'amount'   => Money::from_minor_display( (int) $r['amount_minor'], (string) $r['currency'] ),
				'order'    => $r['order_id'] ? '#' . (int) $r['order_id'] : '—',
				'source'   => trim( ( $r['source_adapter'] ?? '' ) . ( $r['source_ref'] ? ' / ' . $r['source_ref'] : '' ) ),
				'occurred' => gmdate( 'Y-m-d H:i', (int) $r['occurred_at'] ),
			];
		}

		if ( empty( $out ) ) {
			\WP_CLI::log( 'Žádné záznamy.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $out, [ 'id', 'vendor', 'type', 'amount', 'order', 'source', 'occurred' ] );
	}

	/**
	 * Vrátí balance vendora v dané měně.
	 *
	 * ## OPTIONS
	 *
	 * <vendor>
	 * : Vendor ID.
	 *
	 * [--currency=<iso>]
	 * : ISO kód měny. Default CZK.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nkzmp ledger balance 42
	 *     wp nkzmp ledger balance 42 --currency=EUR
	 */
	public function balance( array $args, array $assoc_args ): void {
		$vendor_id = (int) ( $args[0] ?? 0 );
		$currency  = strtoupper( (string) ( $assoc_args['currency'] ?? 'CZK' ) );
		if ( $vendor_id <= 0 ) {
			\WP_CLI::error( 'Vendor ID je povinné.' );
		}

		$repo    = new \NKZMP\Ledger\Repository();
		$balance = $repo->vendor_balance( $vendor_id, $currency );

		\WP_CLI::log( sprintf( 'Vendor #%d balance: %s', $vendor_id, Money::from_minor_display( $balance['balance_minor'], $balance['currency'] ) ) );
	}
}
