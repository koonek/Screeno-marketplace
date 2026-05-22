<?php
/**
 * REST endpointy /vendors/*.
 *
 *  GET /vendors/{id}                  – základní info (admin nebo self)
 *  GET /vendors/{id}/balance?currency – součet ledgeru (admin nebo self)
 *  GET /vendors/{id}/payouts          – seznam payoutů vendora (admin nebo self)
 *
 * @package NKZMP
 */

namespace NKZMP\Rest;

use NKZMP\Ledger\Repository as LedgerRepository;
use NKZMP\Payout\Schema as PayoutSchema;
use NKZMP\Vendor\OwnershipGuard;
use NKZMP\Vendor\Repository as VendorRepository;

defined( 'ABSPATH' ) || exit;

final class VendorsController extends ControllerBase {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/vendors/(?P<id>\d+)',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_vendor' ],
				'permission_callback' => [ $this, 'can_read_vendor' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/vendors/(?P<id>\d+)/balance',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_balance' ],
				'permission_callback' => [ $this, 'can_read_vendor_finance' ],
				'args'                => [
					'id'       => [ 'type' => 'integer', 'required' => true ],
					'currency' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/vendors/(?P<id>\d+)/payouts',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_payouts' ],
				'permission_callback' => [ $this, 'can_read_vendor_finance' ],
				'args'                => [
					'id'     => [ 'type' => 'integer', 'required' => true ],
					'state'  => [ 'type' => 'string', 'required' => false ],
					'limit'  => [ 'type' => 'integer', 'required' => false, 'default' => 50 ],
					'offset' => [ 'type' => 'integer', 'required' => false, 'default' => 0 ],
				],
			]
		);
	}

	public function can_read_vendor( \WP_REST_Request $req ): bool {
		return OwnershipGuard::can_view_vendor( $this->current_user_id(), (int) $req['id'] );
	}

	public function can_read_vendor_finance( \WP_REST_Request $req ): bool {
		return OwnershipGuard::can_view_vendor_finance( $this->current_user_id(), (int) $req['id'] );
	}

	public function get_vendor( \WP_REST_Request $req ) {
		$vendor = ( new VendorRepository() )->find( (int) $req['id'] );
		if ( $vendor === null ) {
			return $this->error_not_found();
		}
		// Pro vendor-self odfiltruj citlivá pole (interní poznámka).
		if ( ! current_user_can( \NKZMP\Support\Capabilities::MANAGE_VENDORS ) ) {
			unset( $vendor['internal_note'] );
		}
		return rest_ensure_response( $vendor );
	}

	public function get_balance( \WP_REST_Request $req ) {
		$currency = strtoupper( (string) $req['currency'] );
		if ( ! preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return $this->error_bad_request( 'Invalid currency code' );
		}
		$balance = ( new LedgerRepository() )->vendor_balance( (int) $req['id'], $currency );
		return rest_ensure_response( $balance );
	}

	public function get_payouts( \WP_REST_Request $req ) {
		global $wpdb;
		$table = PayoutSchema::table_name();

		$vendor_id = (int) $req['id'];
		$limit     = min( 200, max( 1, (int) ( $req['limit'] ?? 50 ) ) );
		$offset    = max( 0, (int) ( $req['offset'] ?? 0 ) );
		$state     = (string) ( $req['state'] ?? '' );

		$where  = [ 'vendor_id = %d' ];
		$params = [ $vendor_id ];
		if ( $state !== '' ) {
			$where[]  = 'state = %s';
			$params[] = $state;
		}

		$sql      = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		$params[] = $limit;
		$params[] = $offset;

		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		$items = array_map(
			static fn( array $r ): array => [
				'id'              => (int) $r['id'],
				'vendor_id'       => (int) $r['vendor_id'],
				'amount_minor'    => (int) $r['amount_minor'],
				'currency'        => (string) $r['currency'],
				'state'           => (string) $r['state'],
				'adapter'         => $r['adapter'],
				'adapter_ref'     => $r['adapter_ref'],
				'idempotency_key' => (string) $r['idempotency_key'],
				'created_at'      => (int) $r['created_at'],
				'updated_at'      => (int) $r['updated_at'],
				'completed_at'    => $r['completed_at'] !== null ? (int) $r['completed_at'] : null,
				'scheduled_for'   => $r['scheduled_for'] !== null ? (int) $r['scheduled_for'] : null,
			],
			$rows ?: []
		);

		return rest_ensure_response( [
			'items' => $items,
			'limit' => $limit,
			'offset' => $offset,
		] );
	}
}
