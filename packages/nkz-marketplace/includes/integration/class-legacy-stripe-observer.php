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
		// Plugin file: nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php
		// (path zůstává původní; přesun do packages/ je jen v repo, ne v WP).
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php' ) ) {
			return;
		}

		add_action( 'nkv_svs_after_create_transfer', [ $this, 'on_transfer_complete' ], 50, 2 );
	}

	public function on_transfer_complete( \WC_Order $order, array $record ): void {
		try {
			$this->record_transfer( $order, $record );
		} catch ( \Throwable $e ) {
			// NEVER propagate – legacy Stripe transfer flow must not be affected.
			error_log( '[NKZMP] LegacyStripeObserver error: ' . $e->getMessage() . ' for order #' . $order->get_id() );
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
			$payouts->transition(
				$payout->id,
				PayoutState::PAID,
				[
					'adapter_ref'  => $transfer_id,
					'completed_at' => $occurred_at,
				]
			);
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
