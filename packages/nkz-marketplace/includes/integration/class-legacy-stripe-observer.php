<?php
/**
 * Legacy Stripe adapter observer.
 *
 * Naslouchá hookům legacy pluginu `nkz-woo-stripe-vendor-split` a píše
 * **paralelně** do nového ledgeru + payouts tabulky. **Žádná interakce
 * se samotným transferem** – pokud listener selže, Stripe transfer
 * stále proběhne. Try/catch wrapper to garantuje.
 *
 * Účel: validovat, že nové core datové modely sedí s realitou, a získat
 * pozorovatelná data na produkci ještě před tím, než cokoliv reálně
 * závisí na ledgeru.
 *
 * Vypnutí:
 *   add_filter( 'nkzmp/v1/integration/legacy_observer_enabled', '__return_false' );
 *
 * @package NKZMP
 */

namespace NKZMP\Integration;

use NKZMP\Ledger\Entry;
use NKZMP\Ledger\EntryType;
use NKZMP\Ledger\Repository as LedgerRepository;
use NKZMP\Payout\Repository as PayoutRepository;
use NKZMP\Payout\State as PayoutState;

defined( 'ABSPATH' ) || exit;

final class LegacyStripeObserver {

	public const SOURCE_ADAPTER = 'stripe-legacy';

	private static ?LegacyStripeObserver $instance = null;

	public static function instance(): LegacyStripeObserver {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/integration/legacy_observer_enabled', true ) ) {
			return;
		}
		// Stripe adapter může být buď samostatný plugin (Screeno) nebo modul
		// načtený přes AOZ bundle. Klíčové je, že jeho třídy + hooky jsou
		// dostupné v PHP runtime.
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$adapter_loaded = is_plugin_active( 'nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php' )
			|| class_exists( \NKVSVS\Plugin::class );
		if ( ! $adapter_loaded ) {
			return;
		}

		add_action( 'nkv_svs_after_create_transfer', [ $this, 'on_transfer_complete' ], 50, 2 );
		// Run after legacy Refund_Service (priority 10) to capture any
		// freshly added reversals on the order's records.
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 100, 2 );
	}

	public function on_order_refunded( int $order_id, int $refund_id ): void {
		try {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				return;
			}
			$raw = $order->get_meta( '_nkv_split_transfers' );
			$records = is_string( $raw ) && '' !== $raw ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : [] );
			if ( ! is_array( $records ) ) {
				return;
			}
			foreach ( $records as $record ) {
				$this->record_transfer( $order, $record );
			}
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] observer refund error: ' . $e->getMessage() . ' for order #' . $order_id );
		}
	}

	public function on_transfer_complete( \WC_Order $order, array $record ): void {
		try {
			$this->record_transfer( $order, $record );
		} catch ( \Throwable $e ) {
			// NEVER propagate – legacy Stripe transfer flow must not be affected.
			error_log( '[NKZMP] LegacyStripeObserver error: ' . $e->getMessage() . ' for order #' . $order->get_id() );
		}
	}

	/**
	 * Veřejný entrypoint pro backfill – přijímá WC_Order a transfer record
	 * v původním tvaru z meta `_nkv_split_transfers`. Bezpečné volat opakovaně.
	 */
	public function backfill_transfer( \WC_Order $order, array $record ): void {
		try {
			$this->record_transfer( $order, $record );
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] backfill error: ' . $e->getMessage() . ' for order #' . $order->get_id() );
		}
	}

	private function record_transfer( \WC_Order $order, array $record ): void {
		if ( ( $record['status'] ?? '' ) !== 'completed' ) {
			return;
		}

		$vendor_id       = (int) ( $record['vendor_id'] ?? 0 );
		$transfer_amount = (int) ( $record['amount_minor'] ?? 0 );
		$commission      = (int) ( $record['platform_fee_minor'] ?? 0 );
		$currency        = strtoupper( (string) ( $record['currency'] ?? $order->get_currency() ) );
		$transfer_id     = (string) ( $record['transfer_id'] ?? '' );
		$order_id        = $order->get_id();
		$occurred_at     = (int) ( $record['created_at'] ?? time() );

		if ( $vendor_id <= 0 || $transfer_amount <= 0 || $transfer_id === '' ) {
			return;
		}

		$ledger  = new LedgerRepository();
		$payouts = new PayoutRepository();

		$ledger->record( $this->entry(
			$vendor_id,
			EntryType::ORDER_CREDIT,
			$transfer_amount,
			$currency,
			$order_id,
			$transfer_id,
			"legacy_observe:order_credit:order_{$order_id}:vendor_{$vendor_id}",
			$occurred_at
		) );

		$ledger->record( $this->entry(
			$vendor_id,
			EntryType::PAYOUT,
			-$transfer_amount,
			$currency,
			$order_id,
			$transfer_id,
			"legacy_observe:payout_ledger:order_{$order_id}:vendor_{$vendor_id}:transfer_{$transfer_id}",
			$occurred_at
		) );

		if ( $commission > 0 ) {
			$ledger->record( $this->entry(
				0, // platform
				EntryType::PLATFORM_COMMISSION,
				$commission,
				$currency,
				$order_id,
				$transfer_id,
				"legacy_observe:commission:order_{$order_id}:vendor_{$vendor_id}",
				$occurred_at,
				[ 'from_vendor_id' => $vendor_id ]
			) );
		}

		$payout = $payouts->create(
			$vendor_id,
			$transfer_amount,
			$currency,
			"legacy_observe:payout:transfer_{$transfer_id}",
			[
				'adapter' => self::SOURCE_ADAPTER,
				'meta'    => [
					'order_id'      => $order_id,
					'transfer_id'   => $transfer_id,
					'observed_from' => 'nkv_svs_after_create_transfer',
				],
			]
		);

		if ( $payout->state === PayoutState::PENDING ) {
			$payout = $payouts->transition( $payout->id, PayoutState::PAYABLE );
		}
		if ( $payout->state === PayoutState::PAYABLE ) {
			$payout = $payouts->transition(
				$payout->id,
				PayoutState::PAID,
				[
					'adapter_ref'  => $transfer_id,
					'completed_at' => $occurred_at,
				]
			);
		}

		$this->record_reversals( $record, $vendor_id, $order_id, $transfer_id, $currency, $ledger, $payouts, $payout );
	}

	/**
	 * Pro každý reversal v $record['reversals'] zapíše REVERSAL ledger
	 * entry (a pokud je payout PAID → REVERSED).
	 */
	private function record_reversals(
		array $record,
		int $vendor_id,
		int $order_id,
		string $transfer_id,
		string $currency,
		LedgerRepository $ledger,
		PayoutRepository $payouts,
		$payout
	): void {
		$reversals = (array) ( $record['reversals'] ?? [] );
		if ( empty( $reversals ) ) {
			return;
		}

		$original_credit_key = "legacy_observe:order_credit:order_{$order_id}:vendor_{$vendor_id}";
		$original_credit     = $ledger->find_by_idempotency_key( $original_credit_key );
		$reverses_id         = $original_credit ? $original_credit->id : null;

		$total_reversed = 0;
		foreach ( $reversals as $r ) {
			$amount      = (int) ( $r['amount_minor'] ?? 0 );
			$reversal_id = (string) ( $r['reversal_id'] ?? '' );
			$created_at  = (int) ( $r['created_at'] ?? time() );
			if ( $amount <= 0 || $reversal_id === '' ) {
				continue;
			}

			$ledger->record( $this->entry(
				$vendor_id,
				EntryType::REVERSAL,
				-$amount,
				$currency,
				$order_id,
				$reversal_id,
				"legacy_observe:reversal:order_{$order_id}:vendor_{$vendor_id}:reversal_{$reversal_id}",
				$created_at,
				[
					'reverses_transfer' => $transfer_id,
					'reverses_entry_id' => $reverses_id,
				]
			) );

			$total_reversed += $amount;
		}

		// Pokud byl payout PAID a celá částka je nyní reversed → přechod do REVERSED.
		if ( $payout && $payout->state === PayoutState::PAID && $total_reversed >= $payout->amount_minor ) {
			try {
				$payouts->transition( $payout->id, PayoutState::REVERSED, [
					'meta' => [ 'reversed_total_minor' => $total_reversed ],
				] );
			} catch ( \InvalidArgumentException $e ) {
				// Already reversed – ignore.
			}
		}
	}

	private function entry(
		int $vendor_id,
		EntryType $type,
		int $amount_minor,
		string $currency,
		int $order_id,
		string $transfer_id,
		string $idempotency_key,
		int $occurred_at,
		array $extra_meta = []
	): Entry {
		return new Entry(
			id: null,
			vendor_id: $vendor_id,
			type: $type,
			amount_minor: $amount_minor,
			currency: $currency,
			order_id: $order_id,
			source_adapter: self::SOURCE_ADAPTER,
			source_ref: $transfer_id,
			idempotency_key: $idempotency_key,
			reverses_id: null,
			occurred_at: $occurred_at,
			recorded_at: time(),
			meta: array_merge( [ 'observed_from' => 'nkv_svs_after_create_transfer' ], $extra_meta ),
		);
	}
}
