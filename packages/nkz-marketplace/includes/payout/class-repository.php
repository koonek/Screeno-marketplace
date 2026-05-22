<?php
/**
 * Payout Repository.
 *
 * Vytvoření je idempotentní podle idempotency_key. Změna stavu prochází
 * `Transitions::allowed()` – nepovolený přechod hodí výjimku, ne tichý
 * fail. Každá změna fire-uje `nkzmp/v1/payout/transition`.
 *
 * @package NKZMP
 */

namespace NKZMP\Payout;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/**
	 * Vytvoří payout (idempotentně). Když idempotency_key existuje, vrátí stávající.
	 *
	 * @param array $context  Volitelné: scheduled_for, meta, adapter.
	 */
	public function create( int $vendor_id, int $amount_minor, string $currency, string $idempotency_key, array $context = [] ): Payout {
		$existing = $this->find_by_idempotency_key( $idempotency_key );
		if ( $existing !== null ) {
			return $existing;
		}

		global $wpdb;
		$now = time();

		$result = $wpdb->insert(
			Schema::table_name(),
			[
				'vendor_id'       => $vendor_id,
				'amount_minor'    => $amount_minor,
				'currency'        => strtoupper( $currency ),
				'state'           => State::PENDING->value,
				'adapter'         => $context['adapter'] ?? null,
				'adapter_ref'     => null,
				'idempotency_key' => $idempotency_key,
				'created_at'      => $now,
				'updated_at'      => $now,
				'scheduled_for'   => $context['scheduled_for'] ?? null,
				'completed_at'    => null,
				'meta_json'       => isset( $context['meta'] ) && $context['meta'] ? wp_json_encode( $context['meta'] ) : null,
			],
			[ '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' ]
		);

		if ( false === $result ) {
			$existing = $this->find_by_idempotency_key( $idempotency_key );
			if ( $existing !== null ) {
				return $existing;
			}
			throw new \RuntimeException( 'Failed to create payout: ' . $wpdb->last_error );
		}

		return $this->find( (int) $wpdb->insert_id );
	}

	/**
	 * Provede přechod stavu. Validuje povolenost; jinak `InvalidArgumentException`.
	 *
	 * @param array $patch Volitelné pole: adapter_ref, completed_at, meta.
	 */
	public function transition( int $payout_id, State $to, array $patch = [], array $context = [] ): Payout {
		$current = $this->find( $payout_id );
		if ( $current === null ) {
			throw new \InvalidArgumentException( 'Unknown payout id: ' . $payout_id );
		}
		if ( ! Transitions::allowed( $current->state, $to ) ) {
			throw new \InvalidArgumentException( sprintf( 'Disallowed transition %s → %s for payout #%d', $current->state->value, $to->value, $payout_id ) );
		}

		global $wpdb;
		$data    = [ 'state' => $to->value, 'updated_at' => time() ];
		$formats = [ '%s', '%d' ];

		if ( array_key_exists( 'adapter_ref', $patch ) ) {
			$data['adapter_ref'] = (string) $patch['adapter_ref'];
			$formats[]           = '%s';
		}
		if ( array_key_exists( 'completed_at', $patch ) ) {
			$data['completed_at'] = (int) $patch['completed_at'];
			$formats[]            = '%d';
		}
		if ( array_key_exists( 'meta', $patch ) ) {
			$data['meta_json'] = $patch['meta'] ? wp_json_encode( array_merge( $current->meta, (array) $patch['meta'] ) ) : null;
			$formats[]         = '%s';
		}

		$wpdb->update( Schema::table_name(), $data, [ 'id' => $payout_id ], $formats, [ '%d' ] );

		$next = $this->find( $payout_id );

		do_action( 'nkzmp/v1/payout/transition', $payout_id, $current->state, $to, $context );

		return $next;
	}

	public function find( int $id ): ?Payout {
		global $wpdb;
		$table = Schema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_by_idempotency_key( string $key ): ?Payout {
		global $wpdb;
		$table = Schema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Payouty čekající na zpracování (state = payable) podle scheduled_for.
	 *
	 * @return Payout[]
	 */
	public function find_payable_due( int $now_ts, int $limit = 100 ): array {
		global $wpdb;
		$table = Schema::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE state = %s
				   AND ( scheduled_for IS NULL OR scheduled_for <= %d )
				 ORDER BY COALESCE(scheduled_for, created_at) ASC
				 LIMIT %d",
				State::PAYABLE->value,
				$now_ts,
				$limit
			),
			ARRAY_A
		);
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ): Payout {
		return new Payout(
			id:              (int) $row['id'],
			vendor_id:       (int) $row['vendor_id'],
			amount_minor:    (int) $row['amount_minor'],
			currency:        (string) $row['currency'],
			state:           State::from( $row['state'] ),
			adapter:         $row['adapter'] !== null ? (string) $row['adapter'] : null,
			adapter_ref:     $row['adapter_ref'] !== null ? (string) $row['adapter_ref'] : null,
			idempotency_key: (string) $row['idempotency_key'],
			created_at:      (int) $row['created_at'],
			updated_at:      (int) $row['updated_at'],
			scheduled_for:   $row['scheduled_for'] !== null ? (int) $row['scheduled_for'] : null,
			completed_at:    $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
			meta:            $row['meta_json'] ? (array) json_decode( $row['meta_json'], true ) : [],
		);
	}
}
