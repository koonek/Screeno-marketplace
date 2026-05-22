<?php
/**
 * GDPR exporter – data subject access request.
 *
 * Pro daný e-mail dohledá:
 *  - vendor profil (CPT + post meta) podle vendor.email nebo namapovaného wp_user
 *  - ledger záznamy patřící danému vendor_id
 *  - payouts
 *  - audit eventy, kde actor_user_id = user
 *
 * Vrací data ve formátu, který WP Personal Data Exporter očekává.
 *
 * @package NKZMP
 */

namespace NKZMP\Gdpr;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Payout\Schema as PayoutSchema;
use NKZMP\Vendor\MetaKeys;
use NKZMP\Vendor\OwnershipGuard;
use NKZMP\Vendor\Repository as VendorRepository;

defined( 'ABSPATH' ) || exit;

final class Exporter {

	public static function register( array $exporters ): array {
		$exporters['nkz-marketplace-vendor'] = [
			'exporter_friendly_name' => __( 'NKZ Marketplace – Vendor data', 'nkz-marketplace' ),
			'callback'               => [ self::class, 'export' ],
		];
		return $exporters;
	}

	public static function export( string $email, int $page = 1 ): array {
		$vendor_ids = self::vendor_ids_for_email( $email );
		$data       = [];

		foreach ( $vendor_ids as $vendor_id ) {
			$data = array_merge( $data, self::export_vendor( $vendor_id ) );
			$data = array_merge( $data, self::export_ledger( $vendor_id ) );
			$data = array_merge( $data, self::export_payouts( $vendor_id ) );
		}

		$data = array_merge( $data, self::export_audit_for_email( $email ) );

		return [ 'data' => $data, 'done' => true ];
	}

	/**
	 * @return int[]
	 */
	public static function vendor_ids_for_email( string $email ): array {
		global $wpdb;
		$ids = [];

		// 1) Vendor s e-mailem v meta (nový + legacy klíč).
		foreach ( [ MetaKeys::EMAIL, '_nkv_vendor_email' ] as $key ) {
			$found = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
					$key,
					$email
				)
			);
			foreach ( (array) $found as $id ) {
				$ids[ (int) $id ] = true;
			}
		}

		// 2) Vendor mapovaný na WP usera s daným e-mailem.
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$vid = OwnershipGuard::user_vendor_id( (int) $user->ID );
			if ( $vid > 0 ) {
				$ids[ $vid ] = true;
			}
		}

		return array_keys( $ids );
	}

	private static function export_vendor( int $vendor_id ): array {
		$vendor = ( new VendorRepository() )->find( $vendor_id );
		if ( $vendor === null ) {
			return [];
		}
		$rows = [];
		foreach ( $vendor as $k => $v ) {
			$rows[] = [ 'name' => $k, 'value' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ) ];
		}
		return [
			[
				'group_id'    => 'nkzmp-vendor',
				'group_label' => __( 'Vendor profil', 'nkz-marketplace' ),
				'item_id'     => 'vendor-' . $vendor_id,
				'data'        => $rows,
			],
		];
	}

	private static function export_ledger( int $vendor_id ): array {
		global $wpdb;
		$table = LedgerSchema::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY id ASC", $vendor_id ),
			ARRAY_A
		);
		if ( ! $rows ) {
			return [];
		}
		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'group_id'    => 'nkzmp-ledger',
				'group_label' => __( 'Účetní záznamy (ledger)', 'nkz-marketplace' ),
				'item_id'     => 'ledger-' . $r['id'],
				'data'        => [
					[ 'name' => 'type',         'value' => $r['type'] ],
					[ 'name' => 'amount_minor', 'value' => $r['amount_minor'] ],
					[ 'name' => 'currency',     'value' => $r['currency'] ],
					[ 'name' => 'order_id',     'value' => (string) ( $r['order_id'] ?? '' ) ],
					[ 'name' => 'occurred_at',  'value' => gmdate( 'c', (int) $r['occurred_at'] ) ],
				],
			];
		}
		return $items;
	}

	private static function export_payouts( int $vendor_id ): array {
		global $wpdb;
		$table = PayoutSchema::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE vendor_id = %d ORDER BY id ASC", $vendor_id ),
			ARRAY_A
		);
		if ( ! $rows ) {
			return [];
		}
		$items = [];
		foreach ( $rows as $r ) {
			$items[] = [
				'group_id'    => 'nkzmp-payouts',
				'group_label' => __( 'Výplaty', 'nkz-marketplace' ),
				'item_id'     => 'payout-' . $r['id'],
				'data'        => [
					[ 'name' => 'amount_minor', 'value' => $r['amount_minor'] ],
					[ 'name' => 'currency',     'value' => $r['currency'] ],
					[ 'name' => 'state',        'value' => $r['state'] ],
					[ 'name' => 'created_at',   'value' => gmdate( 'c', (int) $r['created_at'] ) ],
					[ 'name' => 'completed_at', 'value' => $r['completed_at'] ? gmdate( 'c', (int) $r['completed_at'] ) : '' ],
				],
			];
		}
		return $items;
	}

	private static function export_audit_for_email( string $email ): array {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return [];
		}
		$events = ( new AuditRecorder() )->query( [ 'actor_user_id' => (int) $user->ID, 'limit' => 500 ] );
		$items  = [];
		foreach ( $events as $e ) {
			$items[] = [
				'group_id'    => 'nkzmp-audit',
				'group_label' => __( 'Audit log (akce uživatele)', 'nkz-marketplace' ),
				'item_id'     => 'audit-' . $e->id,
				'data'        => [
					[ 'name' => 'action',      'value' => $e->action ],
					[ 'name' => 'entity',      'value' => $e->entity_type . ':' . $e->entity_id ],
					[ 'name' => 'occurred_at', 'value' => gmdate( 'c', $e->occurred_at ) ],
					[ 'name' => 'summary',     'value' => (string) $e->summary ],
				],
			];
		}
		return $items;
	}
}
