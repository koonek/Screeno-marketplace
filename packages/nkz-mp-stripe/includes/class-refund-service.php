<?php
/**
 * Refund / reversal logic. Conservative: never moves money without explicit signal.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Refund_Service {

	private static ?Refund_Service $instance = null;
	public static function instance(): Refund_Service { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'woocommerce_order_refunded', [ $this, 'on_order_refunded' ], 10, 2 );
	}

	public function on_order_refunded( int $order_id, int $refund_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$ts = Transfer_Service::instance();
		$records = $ts->get_transfer_records( $order );
		if ( empty( $records ) ) {
			return;
		}
		$order->add_order_note( sprintf( __( 'NKV: Refund #%d — zkontrolujte, zda je třeba vytvořit reversal vendor transferů.', 'nkz-woo-stripe-vendor-split' ), $refund_id ) );

		$settings = Plugin::settings();
		if ( 'yes' !== $settings['auto_reversal_on_full_refund'] ) {
			return;
		}
		if ( (float) $order->get_total_refunded() < (float) $order->get_total() ) {
			return; // not a full refund
		}

		foreach ( $records as $record ) {
			if ( 'completed' !== $record['status'] || empty( $record['transfer_id'] ) ) {
				continue;
			}
			$remaining = (int) $record['amount_minor'] - self::reversed_amount_minor( $record );
			if ( $remaining <= 0 ) {
				continue;
			}
			try {
				$this->reverse( $order, $record['vendor_id'], $remaining, sprintf( 'auto_full_refund_%d', $refund_id ) );
			} catch ( \Throwable $e ) {
				Logger::error( 'Auto reversal failed', [ 'order_id' => $order_id, 'vendor_id' => $record['vendor_id'], 'err' => $e->getMessage() ] );
			}
		}
	}

	/**
	 * Compute suggested reversal per vendor for a given refund.
	 *
	 * @return array<int,int> vendor_id => suggested reversal minor units
	 */
	public static function suggested_reversal_minor( \WC_Order $order, \WC_Order_Refund $refund ): array {
		$ts      = Transfer_Service::instance();
		$records = $ts->get_transfer_records( $order );
		$out     = [];

		// Build per-vendor refunded subtotal from refund line items.
		$refunded_per_vendor = [];
		foreach ( $refund->get_items( 'line_item' ) as $r_item ) {
			$product_id = $r_item->get_product_id();
			$vendor_id  = (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
			if ( $vendor_id <= 0 ) {
				continue;
			}
			$refunded_per_vendor[ $vendor_id ] ??= 0;
			// Refund line totals are negative; take absolute.
			$amt = abs( (float) $r_item->get_total() );
			$refunded_per_vendor[ $vendor_id ] += nkvsvs_to_minor( $amt, $order->get_currency() );
		}

		foreach ( $records as $record ) {
			if ( 'completed' !== $record['status'] ) {
				continue;
			}
			$vendor_id = (int) $record['vendor_id'];
			if ( empty( $refunded_per_vendor[ $vendor_id ] ) || (int) $record['base_minor'] <= 0 ) {
				$out[ $vendor_id ] = 0;
				continue;
			}
			$ratio = $refunded_per_vendor[ $vendor_id ] / max( 1, (int) $record['base_minor'] );
			$ratio = min( 1.0, $ratio );
			$suggested = (int) floor( $record['amount_minor'] * $ratio );
			$remaining = (int) $record['amount_minor'] - self::reversed_amount_minor( $record );
			$out[ $vendor_id ] = max( 0, min( $suggested, $remaining ) );
		}
		return $out;
	}

	public static function reversed_amount_minor( array $record ): int {
		$sum = 0;
		foreach ( ( $record['reversals'] ?? [] ) as $r ) {
			$sum += (int) ( $r['amount_minor'] ?? 0 );
		}
		return $sum;
	}

	/**
	 * Execute a reversal for a given vendor.
	 */
	public function reverse( \WC_Order $order, int $vendor_id, int $amount_minor, string $reason ): array {
		$ts      = Transfer_Service::instance();
		$records = $ts->get_transfer_records( $order );

		$idx = null;
		foreach ( $records as $i => $r ) {
			if ( (int) $r['vendor_id'] === $vendor_id ) { $idx = $i; break; }
		}
		if ( null === $idx ) {
			throw new \RuntimeException( 'No transfer record for vendor' );
		}
		$record = $records[ $idx ];
		if ( 'completed' !== $record['status'] || empty( $record['transfer_id'] ) ) {
			throw new \RuntimeException( 'Transfer not in a reversible state' );
		}
		$remaining = (int) $record['amount_minor'] - self::reversed_amount_minor( $record );
		if ( $amount_minor <= 0 || $amount_minor > $remaining ) {
			throw new \RuntimeException( 'Invalid reversal amount' );
		}

		$client = new Stripe_Client();
		$idem   = sprintf( 'wc_order_%d_vendor_%d_reversal_%s_v1', $order->get_id(), $vendor_id, md5( $reason . $amount_minor ) );
		$res    = $client->reverse_transfer(
			$record['transfer_id'],
			[
				'amount'   => $amount_minor,
				'metadata' => [
					'wc_order_id' => (string) $order->get_id(),
					'vendor_id'   => (string) $vendor_id,
					'reason'      => $reason,
				],
			],
			$idem
		);

		$record['reversals'][] = [
			'reversal_id' => $res['id'] ?? null,
			'amount_minor'=> $amount_minor,
			'created_at'  => time(),
			'reason'      => $reason,
		];
		$records[ $idx ] = $record;
		$ts->save_transfer_records( $order, $records );

		$order->add_order_note(
			sprintf(
				__( 'NKV: Reversal %s pro vendora #%d ve výši %s.', 'nkz-woo-stripe-vendor-split' ),
				$res['id'] ?? '?',
				$vendor_id,
				nkvsvs_from_minor_display( $amount_minor, $order->get_currency() )
			)
		);
		return $record;
	}
}
