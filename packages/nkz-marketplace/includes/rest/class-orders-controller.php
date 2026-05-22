<?php
/**
 * REST /orders/{id}/ledger – výpis ledger řádků pro objednávku.
 *
 * Admin (`nkzmp_manage_payouts`) vidí vše. Vendor vidí jen řádky, které
 * patří jemu (přes OwnershipGuard).
 *
 * @package NKZMP
 */

namespace NKZMP\Rest;

use NKZMP\Ledger\Repository as LedgerRepository;
use NKZMP\Support\Capabilities;
use NKZMP\Vendor\OwnershipGuard;

defined( 'ABSPATH' ) || exit;

final class OrdersController extends ControllerBase {

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/orders/(?P<id>\d+)/ledger',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_ledger' ],
				'permission_callback' => [ $this, 'can_read' ],
				'args'                => [
					'id' => [ 'type' => 'integer', 'required' => true ],
				],
			]
		);
	}

	public function can_read( \WP_REST_Request $req ): bool {
		return OwnershipGuard::can_view_order_ledger( $this->current_user_id(), (int) $req['id'] );
	}

	public function get_ledger( \WP_REST_Request $req ) {
		$entries = ( new LedgerRepository() )->find_for_order( (int) $req['id'] );

		$is_admin = current_user_can( Capabilities::MANAGE_PAYOUTS );
		if ( ! $is_admin ) {
			$vendor_id = OwnershipGuard::user_vendor_id( $this->current_user_id() );
			$entries   = array_values( array_filter( $entries, static fn( $e ) => $e->vendor_id === $vendor_id ) );
		}

		return rest_ensure_response( [
			'order_id' => (int) $req['id'],
			'items'    => array_map( static fn( $e ) => $e->to_array(), $entries ),
		] );
	}
}
