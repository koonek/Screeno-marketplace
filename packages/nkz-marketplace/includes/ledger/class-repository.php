<?php
/**
 * Ledger Repository – append-only write + read API.
 *
 * Záznam přes `record()` je idempotentní podle `idempotency_key`. Pokud
 * idempotency_key už existuje, vrátí existující entry a NEzapíše duplikát.
 * Tohle je klíčové pro webhook retry safety.
 *
 * @package NKZMP
 */

namespace NKZMP\Ledger;

defined( 'ABSPATH' ) || exit;

final class Repository {

	/**
	 * Zapíše nový ledger záznam (idempotentně). Vrátí finální Entry s id.
	 *
	 * @throws \RuntimeException Pokud zápis selže z jiného důvodu než dedup.
	 */
	public function record( Entry $entry ): Entry {
		global $wpdb;

		// Idempotency check first – avoid INSERT spam + capture existing.
		$existing = $this->find_by_idempotency_key( $entry->idempotency_key );
		if ( $existing !== null ) {
			return $existing;
		}

		$now      = $entry->recorded_at > 0 ? $entry->recorded_at : time();
		$occurred = $entry->occurred_at > 0 ? $entry->occurred_at : $now;

		$result = $wpdb->insert(
			Schema::table_name(),
			[
				'vendor_id'       => $entry->vendor_id,
				'type'            => $entry->type->value,
				'amount_minor'    => $entry->amount_minor,
				'currency'        => strtoupper( $entry->currency ),
				'order_id'        => $entry->order_id,
				'source_adapter'  => $entry->source_adapter,
				'source_ref'      => $entry->source_ref,
				'idempotency_key' => $entry->idempotency_key,
				'reverses_id'     => $entry->reverses_id,
				'occurred_at'     => $occurred,
				'recorded_at'     => $now,
				'meta_json'       => $entry->meta ? wp_json_encode( $entry->meta ) : null,
			],
			[ '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%s' ]
		);

		if ( false === $result ) {
			// Race: někdo zapsal mezitím se stejným key.
			$existing = $this->find_by_idempotency_key( $entry->idempotency_key );
			if ( $existing !== null ) {
				return $existing;
			}
			throw new \RuntimeException( 'Failed to record ledger entry: ' . $wpdb->last_error );
		}

		$id     = (int) $wpdb->insert_id;
		$stored = $this->find( $id );

		do_action( 'nkzmp/v1/ledger/entry_recorded', $stored );

		return $stored;
	}

	public function find( int $id ): ?Entry {
		global $wpdb;
		$table = Schema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	public function find_by_idempotency_key( string $key ): ?Entry {
		global $wpdb;
		$table = Schema::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE idempotency_key = %s", $key ), ARRAY_A );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Součet částek per vendor (s respektem k znaménkům).
	 * Pouze nereversed záznamy.
	 *
	 * @return array{currency:string,balance_minor:int}
	 */
	public function vendor_balance( int $vendor_id, string $currency ): array {
		global $wpdb;
		$table = Schema::table_name();
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_minor), 0)
				 FROM {$table}
				 WHERE vendor_id = %d
				   AND currency = %s
				   AND id NOT IN (SELECT reverses_id FROM {$table} WHERE reverses_id IS NOT NULL)",
				$vendor_id,
				strtoupper( $currency )
			)
		);
		return [
			'currency'      => strtoupper( $currency ),
			'balance_minor' => $total,
		];
	}

	/**
	 * Záznamy pro daný order. Užitečné pro reconciliation.
	 *
	 * @return Entry[]
	 */
	public function find_for_order( int $order_id ): array {
		global $wpdb;
		$table = Schema::table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE order_id = %d ORDER BY id ASC", $order_id ), ARRAY_A );
		return array_map( [ $this, 'hydrate' ], $rows ?: [] );
	}

	private function hydrate( array $row ): Entry {
		return new Entry(
			id:              (int) $row['id'],
			vendor_id:       (int) $row['vendor_id'],
			type:            EntryType::from( $row['type'] ),
			amount_minor:    (int) $row['amount_minor'],
			currency:        (string) $row['currency'],
			order_id:        $row['order_id'] !== null ? (int) $row['order_id'] : null,
			source_adapter:  $row['source_adapter'] !== null ? (string) $row['source_adapter'] : null,
			source_ref:      $row['source_ref'] !== null ? (string) $row['source_ref'] : null,
			idempotency_key: (string) $row['idempotency_key'],
			reverses_id:     $row['reverses_id'] !== null ? (int) $row['reverses_id'] : null,
			occurred_at:     (int) $row['occurred_at'],
			recorded_at:     (int) $row['recorded_at'],
			meta:            $row['meta_json'] ? (array) json_decode( $row['meta_json'], true ) : [],
		);
	}
}
