<?php
/**
 * Driver pro reconciliation zdroj pravdy.
 *
 * Implementace v adapter pluginu (např. nkz-mp-stripe). Registrace přes filter
 * `nkzmp/v1/reconciliation/drivers`:
 *
 *     add_filter( 'nkzmp/v1/reconciliation/drivers', function( array $drivers ): array {
 *         $drivers['stripe-legacy'] = new StripeReconciliationDriver();
 *         return $drivers;
 *     } );
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

defined( 'ABSPATH' ) || exit;

interface SourceDriver {

	/**
	 * Identifikátor adapteru (musí sedět s `ledger.source_adapter`).
	 */
	public function name(): string;

	/**
	 * Vrátí záznamy za časové okno [from_ts, to_ts] inkluzivní.
	 *
	 * @return SourceRecord[]
	 */
	public function fetch( int $from_ts, int $to_ts ): array;
}
