<?php
/**
 * Stripe reconciliation driver pro NKZ Marketplace core.
 *
 * Implementuje `NKZMP\Reconciliation\SourceDriver`. Stahuje Stripe transfers
 * v daném okně a normalizuje je do SourceRecord. Adapter name `stripe-legacy`
 * sedí s `LegacyStripeObserver::SOURCE_ADAPTER`.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

use NKZMP\Reconciliation\SourceDriver;
use NKZMP\Reconciliation\SourceRecord;

defined( 'ABSPATH' ) || exit;

final class Reconciliation_Driver implements SourceDriver {

	public const ADAPTER_NAME = 'stripe-legacy';

	public function name(): string {
		return self::ADAPTER_NAME;
	}

	public function fetch( int $from_ts, int $to_ts ): array {
		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			return [];
		}
		$transfers = $client->list_transfers( $from_ts, $to_ts );
		$records   = [];
		foreach ( $transfers as $tr ) {
			$id          = (string) ( $tr['id'] ?? '' );
			$amount      = (int) ( $tr['amount'] ?? 0 );
			$currency    = strtoupper( (string) ( $tr['currency'] ?? '' ) );
			$created     = (int) ( $tr['created'] ?? 0 );
			if ( $id === '' || $amount <= 0 || $currency === '' ) {
				continue;
			}
			$records[] = new SourceRecord(
				source_adapter: self::ADAPTER_NAME,
				source_ref:     $id,
				amount_minor:   $amount,
				currency:       $currency,
				occurred_at:    $created,
				type:           'transfer',
				meta:           [
					'destination'    => $tr['destination'] ?? null,
					'transfer_group' => $tr['transfer_group'] ?? null,
				],
			);
		}
		return $records;
	}
}
