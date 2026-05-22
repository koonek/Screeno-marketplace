<?php
/**
 * Reconciliation Service.
 *
 * Pro daný adapter a časové okno:
 *  1. Driver dodá SourceRecord[] z PSP.
 *  2. Z ledgeru vytáhneme entries (source_adapter, occurred_at v okně).
 *  3. Matchujeme přes (source_adapter, source_ref).
 *  4. Vrátíme Report s drift entries.
 *
 * Service nikdy nezapisuje do ledgeru — drift je signál pro člověka /
 * další job. To je úmyslné: auto-repair je v této fázi out-of-scope.
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Ledger\Schema as LedgerSchema;

defined( 'ABSPATH' ) || exit;

final class Service {

	/**
	 * Spustí reconciliation pro daný adapter / okno.
	 *
	 * @param SourceDriver $driver
	 */
	public function run( SourceDriver $driver, int $from_ts, int $to_ts ): Report {
		$report = new Report( $driver->name(), $from_ts, $to_ts );

		$source_records = $driver->fetch( $from_ts, $to_ts );
		$report->source_count = count( $source_records );

		$ledger_rows = $this->fetch_ledger( $driver->name(), $from_ts, $to_ts );
		$report->ledger_count = count( $ledger_rows );

		// Index ledger podle source_ref pro O(1) lookup.
		$ledger_by_ref = [];
		foreach ( $ledger_rows as $row ) {
			$ref = (string) $row['source_ref'];
			if ( $ref === '' ) {
				continue;
			}
			// Pro stejný source_ref se v ledgeru očekává více řádků (order_credit + payout + commission).
			// Summary porovnání děláme proti součtu PAYOUT typu (to je to, co PSP transfer reprezentuje).
			if ( $row['type'] === 'payout' ) {
				$ledger_by_ref[ $ref ] = $row;
			}
		}

		// Match smyčka.
		$matched_refs = [];
		foreach ( $source_records as $rec ) {
			$ref = $rec->source_ref;
			if ( ! isset( $ledger_by_ref[ $ref ] ) ) {
				$report->add_drift( 'missing_in_ledger', $ref, [
					'amount_minor' => $rec->amount_minor,
					'currency'     => $rec->currency,
					'occurred_at'  => $rec->occurred_at,
					'type'         => $rec->type,
				] );
				continue;
			}
			$led = $ledger_by_ref[ $ref ];
			$matched_refs[ $ref ] = true;

			// PAYOUT řádek v ledgeru je záporný (odepsání), Stripe transfer kladný.
			$ledger_abs = abs( (int) $led['amount_minor'] );
			if ( $ledger_abs !== $rec->amount_minor ) {
				$report->add_drift( 'amount_mismatch', $ref, [
					'source_amount' => $rec->amount_minor,
					'ledger_amount' => $ledger_abs,
					'currency'      => $rec->currency,
				] );
				continue;
			}
			if ( strtoupper( $rec->currency ) !== strtoupper( (string) $led['currency'] ) ) {
				$report->add_drift( 'currency_mismatch', $ref, [
					'source_currency' => $rec->currency,
					'ledger_currency' => $led['currency'],
				] );
				continue;
			}
			$report->matched_count++;
		}

		// Ledger záznamy bez protějšku v source.
		foreach ( $ledger_by_ref as $ref => $row ) {
			if ( isset( $matched_refs[ $ref ] ) ) {
				continue;
			}
			$report->add_drift( 'missing_in_source', (string) $ref, [
				'ledger_amount' => abs( (int) $row['amount_minor'] ),
				'currency'      => $row['currency'],
				'occurred_at'   => (int) $row['occurred_at'],
			] );
		}

		do_action( 'nkzmp/v1/reconciliation/completed', $report );

		if ( $report->has_drift() ) {
			( new AuditRecorder() )->record(
				action:      'reconcile.drift_detected',
				entity_type: 'reconciliation',
				entity_id:   0,
				summary:     sprintf( '%s: %d drift entries in window %d–%d', $driver->name(), count( $report->drift ), $from_ts, $to_ts ),
				payload:     $report->to_array(),
				actor_label: 'reconcile',
			);
		}

		return $report;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function fetch_ledger( string $adapter, int $from_ts, int $to_ts ): array {
		global $wpdb;
		$table = LedgerSchema::table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE source_adapter = %s
				   AND occurred_at BETWEEN %d AND %d
				   AND id NOT IN (SELECT reverses_id FROM {$table} WHERE reverses_id IS NOT NULL)",
				$adapter,
				$from_ts,
				$to_ts
			),
			ARRAY_A
		);
		return $rows ?: [];
	}

	/**
	 * Vrátí registrované drivery (přes filter).
	 *
	 * @return array<string, SourceDriver>
	 */
	public static function drivers(): array {
		$drivers = apply_filters( 'nkzmp/v1/reconciliation/drivers', [] );
		$result  = [];
		foreach ( (array) $drivers as $key => $driver ) {
			if ( $driver instanceof SourceDriver ) {
				$result[ $driver->name() ] = $driver;
			}
		}
		return $result;
	}
}
