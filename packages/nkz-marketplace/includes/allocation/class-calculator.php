<?php
/**
 * Allocation\Calculator.
 *
 * **Bridge fáze** (0.7.0-dev):
 *  - Nepřepisuje math ze Stripe adapteru. Pouze mapuje výstup
 *    `NKVSVS\Split_Calculator::calculate()` na `Allocation[]` value objekty.
 *  - Adapter po výpočtu zavolá `Calculator::from_legacy_calc()` a vyhodí action
 *    `nkzmp/v1/allocation/calculated`.
 *
 * **Pure-math fáze** (TBD ~0.9.0):
 *  - `Calculator::calculate( WC_Order $order, array $settings ): Allocation[]`
 *    bude self-contained. Adapter bude jen wire-up. Zatím není implementováno
 *    – metoda hodí `RuntimeException` aby caller věděl.
 *
 * @package NKZMP
 */

namespace NKZMP\Allocation;

defined( 'ABSPATH' ) || exit;

final class Calculator {

	/**
	 * Mapuje legacy `Split_Calculator` výstup na Allocation[] value objects.
	 *
	 * Vstupní pole má strukturu:
	 *   [
	 *     'currency' => 'CZK',
	 *     'vendors' => [
	 *       [ 'vendor_id' => int, 'base_minor' => int, 'platform_fee_minor' => int,
	 *         'transfer_amount_minor' => int, 'stripe_fee_share_minor' => int,
	 *         'reason_skipped' => null|string, ... ],
	 *     ],
	 *   ]
	 *
	 * Pouze řádky bez `reason_skipped` se mapují na Allocation (skipped vendory
	 * nelze poslat do ledgeru – nedostali by nic).
	 *
	 * @return Allocation[]
	 */
	public static function from_legacy_calc( array $calc, \WC_Order $order ): array {
		$currency = strtoupper( (string) ( $calc['currency'] ?? $order->get_currency() ) );
		$out      = [];

		foreach ( (array) ( $calc['vendors'] ?? [] ) as $v ) {
			if ( ! empty( $v['reason_skipped'] ) ) {
				continue;
			}
			$vendor_id        = (int) ( $v['vendor_id'] ?? 0 );
			$gross            = (int) ( $v['base_minor'] ?? 0 );
			$commission       = (int) ( $v['platform_fee_minor'] ?? 0 );
			$fee_share        = (int) ( $v['stripe_fee_share_minor'] ?? 0 );
			$net              = (int) ( $v['transfer_amount_minor'] ?? max( 0, $gross - $commission - $fee_share ) );

			if ( $vendor_id <= 0 || $net <= 0 ) {
				continue;
			}

			$out[] = new Allocation(
				vendor_id:            $vendor_id,
				currency:             $currency,
				gross_minor:          $gross,
				commission_minor:     $commission,
				shipping_share_minor: 0,
				tax_share_minor:      0,
				fee_share_minor:      $fee_share,
				net_minor:            $net,
				meta:                 [
					'source'     => 'legacy_split_calculator',
					'fee_pct'    => $v['platform_fee_percent'] ?? null,
					'item_count' => is_array( $v['items'] ?? null ) ? count( $v['items'] ) : 0,
				],
			);
		}

		return apply_filters( 'nkzmp/v1/allocation/calculate', $out, $order );
	}

	/**
	 * Self-contained kalkulace (zatím neimplementováno).
	 *
	 * @throws \RuntimeException Vždy v 0.7.0-dev.
	 */
	public static function calculate( \WC_Order $order, array $settings = [] ): array {
		throw new \RuntimeException( 'NKZMP\\Allocation\\Calculator::calculate() není zatím implementováno – použij from_legacy_calc() s výstupem Split_Calculator.' );
	}
}
