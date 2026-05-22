<?php
/**
 * Orchestrates split calculation + Stripe transfer creation.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Transfer_Service {

	private static ?Transfer_Service $instance = null;
	public static function instance(): Transfer_Service { return self::$instance ??= new self(); }

	public function init(): void {
		$settings = Plugin::settings();
		$primary  = $settings['transfer_hook'] ?? 'payment_complete';
		switch ( $primary ) {
			case 'completed':
				add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_create_transfers' ], 20 );
				break;
			case 'processing':
				add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_create_transfers' ], 20 );
				break;
			case 'payment_complete':
			default:
				add_action( 'woocommerce_payment_complete', [ $this, 'maybe_create_transfers' ], 20 );
		}
		// Safety net hooks.
		add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_create_transfers' ], 30 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_create_transfers' ], 30 );
	}

	/**
	 * Public entry point.
	 *
	 * @param int  $order_id
	 * @param bool $force_dry_run If true, override settings and just calculate/log.
	 */
	public function maybe_create_transfers( int $order_id, bool $force_dry_run = false ): void {
		if ( ! Plugin::is_enabled() ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Already processed?
		if ( 'completed' === $order->get_meta( '_nkv_split_status' ) ) {
			return;
		}

		if ( ! $this->is_stripe_payment( $order ) ) {
			Logger::debug( 'Skipping non-Stripe order', [ 'order_id' => $order_id, 'method' => $order->get_payment_method() ] );
			return;
		}

		if ( ! $order->is_paid() ) {
			Logger::debug( 'Skipping unpaid order', [ 'order_id' => $order_id, 'status' => $order->get_status() ] );
			return;
		}

		if ( ! Order_Lock::acquire( $order ) ) {
			Logger::info( 'Order is locked, skipping concurrent run', [ 'order_id' => $order_id ] );
			return;
		}

		try {
			$calc = Split_Calculator::calculate( $order );
			$order->update_meta_data( '_nkv_split_calculation', wp_json_encode( $calc ) );
			$order->save();

			do_action( 'nkv_svs_after_calculate_split', $order, $calc );

			if ( empty( $calc['vendors'] ) ) {
				$order->update_meta_data( '_nkv_split_status', 'none' );
				$order->add_order_note( __( 'NKV: No vendor items, nothing to split.', 'nkz-woo-stripe-vendor-split' ) );
				$order->save();
				return;
			}

			$dry_run    = $force_dry_run || Plugin::is_dry_run();
			$stripe_ids = $this->resolve_stripe_ids( $order );

			// Compute per-vendor Stripe fee share (only if configured & charge known).
			$settings  = Plugin::settings();
			$share_pct = (float) ( $settings['stripe_fee_vendor_share_percent'] ?? 0 );
			$fee_per_vendor_minor = []; // vendor_id => fee_share_minor

			if ( $share_pct > 0 && ! empty( $stripe_ids['charge_id'] ) ) {
				$total_fee_minor = $this->fetch_stripe_fee_minor( $stripe_ids['charge_id'], $order->get_currency() );
				$vendor_fee_pool = (int) floor( $total_fee_minor * $share_pct / 100 );
				if ( $vendor_fee_pool > 0 ) {
					$base_sum = 0;
					foreach ( $calc['vendors'] as $vs ) {
						if ( empty( $vs['reason_skipped'] ) ) {
							$base_sum += (int) $vs['base_minor'];
						}
					}
					if ( $base_sum > 0 ) {
						foreach ( $calc['vendors'] as $vs ) {
							if ( ! empty( $vs['reason_skipped'] ) ) {
								continue;
							}
							$fee_per_vendor_minor[ (int) $vs['vendor_id'] ] = (int) floor( $vendor_fee_pool * (int) $vs['base_minor'] / $base_sum );
						}
					}
				}
			}

			$existing  = $this->get_transfer_records( $order );
			$completed = 0;
			$failed    = 0;
			$skipped   = 0;

			foreach ( $calc['vendors'] as $vendor_split ) {
				// Inject Stripe fee deduction into the split before any further checks.
				$vid                                = (int) $vendor_split['vendor_id'];
				$vendor_split['stripe_fee_share_minor'] = $fee_per_vendor_minor[ $vid ] ?? 0;
				if ( $vendor_split['stripe_fee_share_minor'] > 0 ) {
					$vendor_split['transfer_amount_minor'] = max( 0, (int) $vendor_split['transfer_amount_minor'] - $vendor_split['stripe_fee_share_minor'] );
					if ( $vendor_split['transfer_amount_minor'] <= 0 && empty( $vendor_split['reason_skipped'] ) ) {
						$vendor_split['reason_skipped'] = 'zero_amount';
					}
				}
				$vendor_id = $vendor_split['vendor_id'];

				// Idempotence check per vendor.
				if ( $this->has_completed_transfer( $existing, $vendor_id ) ) {
					$completed++;
					continue;
				}

				// Hard skip cases (no money movement).
				if ( $vendor_split['reason_skipped'] ) {
					$this->record_skip( $order, $vendor_split, $vendor_split['reason_skipped'] );
					$skipped++;
					continue;
				}

				if ( $dry_run ) {
					$this->record_skip( $order, $vendor_split, 'dry_run' );
					$skipped++;
					continue;
				}

				try {
					$record = $this->create_transfer( $order, $vendor_split, $stripe_ids );
					$existing[] = $record;
					$completed++;
				} catch ( \Throwable $e ) {
					$record = [
						'vendor_id'         => $vendor_id,
						'stripe_account_id' => $vendor_split['stripe_account_id'],
						'amount_minor'      => $vendor_split['transfer_amount_minor'],
						'currency'          => strtolower( $calc['currency'] ),
						'platform_fee_minor'=> $vendor_split['platform_fee_minor'],
						'stripe_fee_share_minor' => (int) ( $vendor_split['stripe_fee_share_minor'] ?? 0 ),
						'base_minor'        => $vendor_split['base_minor'],
						'transfer_id'       => null,
						'payment_intent_id' => $stripe_ids['payment_intent_id'] ?? null,
						'charge_id'         => $stripe_ids['charge_id'] ?? null,
						'transfer_group'    => 'WC_ORDER_' . $order->get_id(),
						'idempotency_key'   => $this->idempotency_key( $order, $vendor_id ),
						'status'            => 'failed',
						'error'             => $e->getMessage(),
						'created_at'        => time(),
						'reversals'         => [],
					];
					$existing[] = $record;
					$failed++;
					Logger::error( 'Transfer failed', [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id, 'err' => $e->getMessage() ] );
					$order->add_order_note( sprintf( __( 'NKV: Transfer pro vendora #%d selhal: %s', 'nkz-woo-stripe-vendor-split' ), $vendor_id, $e->getMessage() ) );
					do_action( 'nkv_svs_transfer_failed', $order, $vendor_split, $e );
				}

				$this->save_transfer_records( $order, $existing );
			}

			// Final status.
			$status = 'none';
			if ( $failed > 0 && $completed > 0 ) {
				$status = 'partial';
			} elseif ( $failed > 0 ) {
				$status = 'failed';
			} elseif ( $completed > 0 ) {
				$status = 'completed';
			} elseif ( $skipped > 0 ) {
				$status = 'calculated';
			}
			$order->update_meta_data( '_nkv_split_status', $status );
			$order->save();
		} finally {
			Order_Lock::release( $order );
		}
	}

	private function is_stripe_payment( \WC_Order $order ): bool {
		$method = $order->get_payment_method();
		// WC Stripe Gateway uses 'stripe' plus 'stripe_*' for APMs.
		return 'stripe' === $method || str_starts_with( $method, 'stripe_' );
	}

	/**
	 * Resolve PaymentIntent + Charge IDs with fallback chain.
	 *
	 * @return array{payment_intent_id:?string, charge_id:?string}
	 */
	private function resolve_stripe_ids( \WC_Order $order ): array {
		$pi_id     = (string) $order->get_meta( '_stripe_intent_id' );
		$charge_id = (string) $order->get_meta( '_stripe_charge_id' );

		if ( '' === $charge_id ) {
			$txn = (string) $order->get_transaction_id();
			if ( str_starts_with( $txn, 'ch_' ) ) {
				$charge_id = $txn;
			} elseif ( '' === $pi_id && str_starts_with( $txn, 'pi_' ) ) {
				$pi_id = $txn;
			}
		}

		// If we have PI but no charge, resolve charge via API (allows source_transaction).
		if ( '' === $charge_id && '' !== $pi_id ) {
			$client = new Stripe_Client();
			if ( $client->is_ready() ) {
				$pi = $client->retrieve_payment_intent( $pi_id );
				if ( is_array( $pi ) ) {
					$charge_id = (string) ( $pi['latest_charge'] ?? '' );
				}
			}
		}

		return [
			'payment_intent_id' => $pi_id ?: null,
			'charge_id'         => $charge_id ?: null,
		];
	}

	/**
	 * Fetch the actual Stripe fee for a charge, in minor units of the platform settlement currency.
	 * Returns 0 if the fee cannot be resolved (transport error, currency mismatch, etc.).
	 */
	private function fetch_stripe_fee_minor( string $charge_id, string $order_currency ): int {
		try {
			$client = new Stripe_Client();
			if ( ! $client->is_ready() ) {
				return 0;
			}
			$charge = $client->retrieve_charge( $charge_id, [ 'balance_transaction' ] );
			if ( ! is_array( $charge ) || ! isset( $charge['balance_transaction']['fee'] ) ) {
				return 0;
			}
			$fee = (int) $charge['balance_transaction']['fee'];
			$bt_curr = strtolower( (string) ( $charge['balance_transaction']['currency'] ?? '' ) );
			if ( '' !== $bt_curr && strtolower( $order_currency ) !== $bt_curr ) {
				Logger::warning( 'Stripe fee currency mismatch — skipping vendor share deduction', [
					'charge'        => $charge_id,
					'order_curr'    => $order_currency,
					'fee_curr'      => $bt_curr,
				] );
				return 0;
			}
			return $fee;
		} catch ( \Throwable $e ) {
			Logger::warning( 'Could not fetch Stripe fee', [ 'charge' => $charge_id, 'err' => $e->getMessage() ] );
			return 0;
		}
	}

	private function idempotency_key( \WC_Order $order, int $vendor_id ): string {
		return sprintf( 'wc_order_%d_vendor_%d_transfer_v1', $order->get_id(), $vendor_id );
	}

	private function create_transfer( \WC_Order $order, array $vendor_split, array $stripe_ids ): array {
		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			throw new \RuntimeException( 'Stripe secret key not configured' );
		}

		$currency       = strtolower( $order->get_currency() );
		$transfer_group = 'WC_ORDER_' . $order->get_id();
		$idem           = $this->idempotency_key( $order, $vendor_split['vendor_id'] );

		// Pre-write "processing" record before the API call to defend against partial-failure replays.
		$pre = [
			'vendor_id'         => $vendor_split['vendor_id'],
			'stripe_account_id' => $vendor_split['stripe_account_id'],
			'amount_minor'      => $vendor_split['transfer_amount_minor'],
			'currency'          => $currency,
			'platform_fee_minor'=> $vendor_split['platform_fee_minor'],
			'stripe_fee_share_minor' => (int) ( $vendor_split['stripe_fee_share_minor'] ?? 0 ),
			'base_minor'        => $vendor_split['base_minor'],
			'transfer_id'       => null,
			'payment_intent_id' => $stripe_ids['payment_intent_id'],
			'charge_id'         => $stripe_ids['charge_id'],
			'transfer_group'    => $transfer_group,
			'idempotency_key'   => $idem,
			'status'            => 'processing',
			'error'             => null,
			'created_at'        => time(),
			'reversals'         => [],
		];
		$existing = $this->get_transfer_records( $order );
		$existing = $this->upsert_record( $existing, $pre );
		$this->save_transfer_records( $order, $existing );

		$params = [
			'amount'         => $vendor_split['transfer_amount_minor'],
			'currency'       => $currency,
			'destination'    => $vendor_split['stripe_account_id'],
			'transfer_group' => $transfer_group,
			'metadata'       => [
				'wc_order_id'     => (string) $order->get_id(),
				'vendor_id'       => (string) $vendor_split['vendor_id'],
				'platform_plugin' => 'nkz-woo-stripe-vendor-split',
				'transfer_group'  => $transfer_group,
			],
		];
		// Tying transfer to charge mitigates negative-balance windows.
		if ( ! empty( $stripe_ids['charge_id'] ) ) {
			$params['source_transaction'] = $stripe_ids['charge_id'];
		}

		do_action( 'nkv_svs_before_create_transfer', $order, $vendor_split, $params );

		$res = $client->create_transfer( $params, $idem );

		$record = $pre;
		$record['transfer_id'] = $res['id'] ?? null;
		$record['status']      = 'completed';
		$existing = $this->upsert_record( $existing, $record );
		$this->save_transfer_records( $order, $existing );

		$order->add_order_note(
			sprintf(
				__( 'NKV: Transfer %s pro vendora %s ve výši %s.', 'nkz-woo-stripe-vendor-split' ),
				$record['transfer_id'],
				$vendor_split['vendor_name'],
				nkvsvs_from_minor_display( $vendor_split['transfer_amount_minor'], $order->get_currency() )
			)
		);

		do_action( 'nkv_svs_after_create_transfer', $order, $record );

		return $record;
	}

	private function record_skip( \WC_Order $order, array $vendor_split, string $reason ): void {
		$existing = $this->get_transfer_records( $order );
		$record = [
			'vendor_id'         => $vendor_split['vendor_id'],
			'stripe_account_id' => $vendor_split['stripe_account_id'] ?? '',
			'amount_minor'      => $vendor_split['transfer_amount_minor'],
			'currency'          => strtolower( $order->get_currency() ),
			'platform_fee_minor'=> $vendor_split['platform_fee_minor'],
			'base_minor'        => $vendor_split['base_minor'],
			'transfer_id'       => null,
			'payment_intent_id' => null,
			'charge_id'         => null,
			'transfer_group'    => 'WC_ORDER_' . $order->get_id(),
			'idempotency_key'   => $this->idempotency_key( $order, $vendor_split['vendor_id'] ),
			'status'            => 'skipped',
			'error'             => $reason,
			'created_at'        => time(),
			'reversals'         => [],
		];
		$existing = $this->upsert_record( $existing, $record );
		$this->save_transfer_records( $order, $existing );

		$order->add_order_note( sprintf( __( 'NKV: Transfer pro vendora #%d přeskočen (%s).', 'nkz-woo-stripe-vendor-split' ), $vendor_split['vendor_id'], $reason ) );
		Logger::info( 'Transfer skipped', [ 'order_id' => $order->get_id(), 'vendor_id' => $vendor_split['vendor_id'], 'reason' => $reason ] );
	}

	/**
	 * Get current transfer records from order meta.
	 */
	public function get_transfer_records( \WC_Order $order ): array {
		$raw = $order->get_meta( '_nkv_split_transfers' );
		if ( is_string( $raw ) && '' !== $raw ) {
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return is_array( $raw ) ? $raw : [];
	}

	public function save_transfer_records( \WC_Order $order, array $records ): void {
		$order->update_meta_data( '_nkv_split_transfers', wp_json_encode( array_values( $records ) ) );
		$order->save();
	}

	private function has_completed_transfer( array $records, int $vendor_id ): bool {
		foreach ( $records as $r ) {
			if ( (int) $r['vendor_id'] === $vendor_id && 'completed' === $r['status'] ) {
				return true;
			}
		}
		return false;
	}

	private function upsert_record( array $records, array $record ): array {
		$found = false;
		foreach ( $records as &$r ) {
			if ( (int) $r['vendor_id'] === (int) $record['vendor_id'] ) {
				// Preserve reversals when replacing.
				if ( ! empty( $r['reversals'] ) && empty( $record['reversals'] ) ) {
					$record['reversals'] = $r['reversals'];
				}
				$r = $record;
				$found = true;
				break;
			}
		}
		unset( $r );
		if ( ! $found ) {
			$records[] = $record;
		}
		return $records;
	}
}
