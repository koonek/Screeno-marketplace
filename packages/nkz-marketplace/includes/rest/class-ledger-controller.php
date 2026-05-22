<?php
/**
 * REST /ledger – obecný query (admin only).
 *
 * Vendor přístup vede přes /orders/{id}/ledger a /vendors/{id}/balance.
 *
 * @package NKZMP
 */

namespace NKZMP\Rest;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class LedgerController extends ControllerBase {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/ledger',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'query' ],
				'permission_callback' => [ $this, 'can_read' ],
				'args'                => [
					'vendor'   => [ 'type' => 'integer', 'required' => false ],
					'order'    => [ 'type' => 'integer', 'required' => false ],
					'type'     => [ 'type' => 'string',  'required' => false ],
					'currency' => [ 'type' => 'string',  'required' => false ],
					'limit'    => [ 'type' => 'integer', 'required' => false, 'default' => 100 ],
					'offset'   => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				],
			]
		);
	}

	public function can_read(): bool {
		return current_user_can( Capabilities::MANAGE_PAYOUTS );
	}

	public function query( \WP_REST_Request $req ) {
		global $wpdb;
		$table = LedgerSchema::table_name();

		$where  = [ '1=1' ];
		$params = [];

		if ( ! empty( $req['vendor'] ) ) {
			$where[]  = 'vendor_id = %d';
			$params[] = (int) $req['vendor'];
		}
		if ( ! empty( $req['order'] ) ) {
			$where[]  = 'order_id = %d';
			$params[] = (int) $req['order'];
		}
		if ( ! empty( $req['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = (string) $req['type'];
		}
		if ( ! empty( $req['currency'] ) ) {
			$where[]  = 'currency = %s';
			$params[] = strtoupper( (string) $req['currency'] );
		}

		$limit  = min( 500, max( 1, (int) ( $req['limit'] ?? 100 ) ) );
		$offset = max( 0, (int) ( $req['offset'] ?? 0 ) );

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$items = array_map(
			static fn( array $r ): array => [
				'id'              => (int) $r['id'],
				'vendor_id'       => (int) $r['vendor_id'],
				'type'            => (string) $r['type'],
				'amount_minor'    => (int) $r['amount_minor'],
				'currency'        => (string) $r['currency'],
				'order_id'        => $r['order_id'] !== null ? (int) $r['order_id'] : null,
				'source_adapter'  => $r['source_adapter'],
				'source_ref'      => $r['source_ref'],
				'idempotency_key' => (string) $r['idempotency_key'],
				'reverses_id'     => $r['reverses_id'] !== null ? (int) $r['reverses_id'] : null,
				'occurred_at'     => (int) $r['occurred_at'],
				'recorded_at'     => (int) $r['recorded_at'],
				'meta'            => $r['meta_json'] ? json_decode( $r['meta_json'], true ) : [],
			],
			$rows ?: []
		);

		return rest_ensure_response( [
			'items'  => $items,
			'limit'  => $limit,
			'offset' => $offset,
		] );
	}
}
