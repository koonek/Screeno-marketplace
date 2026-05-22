<?php
/**
 * Pure split calculation logic.
 *
 * All money math runs in minor units (integer). No float arithmetic on amounts.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Split_Calculator {

	/**
	 * Build a deterministic split calculation array for an order.
	 *
	 * @return array Calculation snapshot (see README data model).
	 */
	public static function calculate( \WC_Order $order ): array {
		$settings = Plugin::settings();
		$currency = $order->get_currency();
		$factor   = nkvsvs_minor_factor( $currency );

		$include_tax       = 'yes' === $settings['split_includes_tax'];
		$include_shipping  = 'yes' === $settings['split_includes_shipping'];
		$deduct_coupons    = 'yes' === $settings['deduct_coupons_proportionally'];
		$min_transfer      = nkvsvs_to_minor( (float) $settings['minimum_transfer_amount'], $currency );
		$default_fee_pct   = (float) $settings['default_fee_percent'];

		// 1) Group line items by vendor.
		$by_vendor = []; // vendor_id => [ items => [], base_subtotal_minor => int (pre-discount), base_total_minor => int, tax_minor => int ]
		$total_items_subtotal_minor = 0;

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			/** @var \WC_Order_Item_Product $item */
			$product_id   = $item->get_product_id();
			$variation_id = (int) $item->get_variation_id(); // 0 if not a variation
			$vendor_id    = (int) get_post_meta( $product_id, '_nkv_vendor_id', true );
			$enabled      = get_post_meta( $product_id, '_nkv_vendor_split_enabled', true );
			if ( $vendor_id <= 0 || 'no' === $enabled ) {
				continue;
			}

			$subtotal_minor = nkvsvs_to_minor( (float) $item->get_subtotal(), $currency );           // pre-discount net
			$total_minor    = nkvsvs_to_minor( (float) $item->get_total(), $currency );              // post-discount net
			$tax_minor      = nkvsvs_to_minor( (float) $item->get_total_tax(), $currency );

			$total_items_subtotal_minor += $subtotal_minor;

			$by_vendor[ $vendor_id ] ??= [
				'items'                  => [],
				'subtotal_minor'         => 0,
				'total_minor'            => 0,
				'tax_minor'              => 0,
			];
			$by_vendor[ $vendor_id ]['items'][] = [
				'order_item_id'       => $item_id,
				'product_id'          => $product_id,
				'variation_id'        => $variation_id,
				'qty'                 => (float) $item->get_quantity(),
				'line_subtotal_minor' => $subtotal_minor,
				'line_total_minor'    => $total_minor,
				'line_tax_minor'      => $tax_minor,
			];
			$by_vendor[ $vendor_id ]['subtotal_minor'] += $subtotal_minor;
			$by_vendor[ $vendor_id ]['total_minor']    += $total_minor;
			$by_vendor[ $vendor_id ]['tax_minor']      += $tax_minor;
		}

		// 2) Compute base per vendor.
		$vendor_splits = [];
		foreach ( $by_vendor as $vendor_id => $agg ) {
			$vendor = Vendor_Repository::get( $vendor_id );
			if ( ! $vendor ) {
				continue;
			}

			// Base = either post-discount net OR pre-discount net (if coupons not deducted from vendor).
			$net_base = $deduct_coupons ? $agg['total_minor'] : $agg['subtotal_minor'];
			$base     = $net_base + ( $include_tax ? $agg['tax_minor'] : 0 );

			// Per-product fixed fee OVERRIDES percent for that item. If item has fixed override,
			// it contributes a fixed amount to platform fee; otherwise it contributes via percent.
			$fixed_fee_minor    = 0;
			$percent_base_minor = 0; // base portion covered by percent (items without fixed override)
			$fee_pct_override   = null;

			foreach ( $agg['items'] as $it ) {
				// Variation fee override beats parent product override.
				$variation_id = (int) ( $it['variation_id'] ?? 0 );
				$fixed_ov = '';
				$pct_ov   = '';
				if ( $variation_id > 0 ) {
					$fixed_ov = get_post_meta( $variation_id, '_nkv_platform_fee_fixed_override', true );
					$pct_ov   = get_post_meta( $variation_id, '_nkv_platform_fee_percent_override', true );
				}
				if ( '' === $fixed_ov || null === $fixed_ov ) {
					$fixed_ov = get_post_meta( $it['product_id'], '_nkv_platform_fee_fixed_override', true );
				}
				if ( '' === $pct_ov || null === $pct_ov ) {
					$pct_ov = get_post_meta( $it['product_id'], '_nkv_platform_fee_percent_override', true );
				}
				$line_base = (int) ( $deduct_coupons ? $it['line_total_minor'] : $it['line_subtotal_minor'] )
					+ ( $include_tax ? (int) $it['line_tax_minor'] : 0 );

				if ( '' !== $fixed_ov && null !== $fixed_ov && (float) $fixed_ov > 0 ) {
					// Fixed is per-unit × qty.
					$fixed_fee_minor += nkvsvs_to_minor( (float) $fixed_ov * (float) $it['qty'], $currency );
					continue;
				}
				$percent_base_minor += $line_base;
				if ( null === $fee_pct_override && '' !== $pct_ov && null !== $pct_ov ) {
					$fee_pct_override = (float) $pct_ov;
				}
			}

			$fee_pct = $fee_pct_override ?? ( $vendor['fee_percent'] > 0 ? $vendor['fee_percent'] : $default_fee_pct );
			$fee_pct = (float) apply_filters( 'nkv_svs_filter_platform_fee_percent', $fee_pct, $vendor, $agg );

			// Floor the percent portion — platform keeps the rounding crumb.
			$platform_fee_minor  = (int) floor( $percent_base_minor * $fee_pct / 100 );
			$platform_fee_minor += $fixed_fee_minor;
			$platform_fee_minor += (int) $vendor['fee_fixed']; // vendor-level fixed surcharge
			$platform_fee_minor  = min( $platform_fee_minor, $base );

			$transfer_amount = $base - $platform_fee_minor;
			$transfer_amount = (int) apply_filters( 'nkv_svs_filter_transfer_amount_minor', $transfer_amount, $vendor, $order );

			$skip_reason = null;
			$payable     = Vendor_Repository::is_payable( $vendor );
			if ( ! $payable ) {
				$skip_reason = 'vendor_not_payable';
			} elseif ( 'yes' === $settings['require_currency_match'] && $vendor['currency'] && strcasecmp( $vendor['currency'], $currency ) !== 0 ) {
				$skip_reason = 'currency_mismatch';
			} elseif ( $transfer_amount <= 0 ) {
				$skip_reason = 'zero_amount';
			} elseif ( $transfer_amount < $min_transfer ) {
				$skip_reason = 'below_minimum';
			}

			$vendor_splits[] = [
				'vendor_id'            => $vendor_id,
				'vendor_name'          => $vendor['name'],
				'stripe_account_id'    => $vendor['stripe_account_id'],
				'items'                => $agg['items'],
				'base_minor'           => $base,
				'platform_fee_percent' => $fee_pct,
				'platform_fee_minor'   => $platform_fee_minor,
				'stripe_fee_share_minor' => 0, // populated later if deduction enabled and fee known
				'transfer_amount_minor'  => $transfer_amount,
				'below_minimum'        => 'below_minimum' === $skip_reason,
				'reason_skipped'       => $skip_reason,
			];
		}

		$calc = [
			'calculated_at'      => time(),
			'currency'           => $currency,
			'minor_unit_factor'  => $factor,
			'order_totals'       => [
				'items_total_minor'    => nkvsvs_to_minor( (float) $order->get_subtotal(), $currency ),
				'shipping_total_minor' => nkvsvs_to_minor( (float) $order->get_shipping_total(), $currency ),
				'tax_total_minor'      => nkvsvs_to_minor( (float) $order->get_total_tax(), $currency ),
				'discount_total_minor' => nkvsvs_to_minor( (float) $order->get_total_discount(), $currency ),
				'grand_total_minor'    => nkvsvs_to_minor( (float) $order->get_total(), $currency ),
			],
			'settings_snapshot'  => [
				'split_includes_tax'              => $include_tax,
				'split_includes_shipping'         => $include_shipping,
				'deduct_coupons_proportionally'   => $deduct_coupons,
				'deduct_stripe_fee_from_vendor'   => 'yes' === $settings['deduct_stripe_fee_from_vendor'],
				'minimum_transfer_amount_minor'   => $min_transfer,
				'default_fee_percent'             => $default_fee_pct,
			],
			'vendors'            => $vendor_splits,
		];

		return apply_filters( 'nkv_svs_filter_calculation', $calc, $order );
	}
}
