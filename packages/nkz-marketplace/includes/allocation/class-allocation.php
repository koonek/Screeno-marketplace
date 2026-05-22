<?php
/**
 * Allocation – výsledek alokace pro jednoho vendora v rámci jedné objednávky.
 *
 * Všechny částky v minor units (haléře, centy) integer-math. Currency je
 * shodná s objednávkou.
 *
 * net = gross - commission + shipping_share - fee_share
 *
 * (Konkrétní rovnice si může adapter upravit přes filter
 * `nkzmp/v1/allocation/calculate`, pokud potřebuje jiný model.)
 *
 * @package NKZMP
 */

namespace NKZMP\Allocation;

defined( 'ABSPATH' ) || exit;

final class Allocation {

	public function __construct(
		public readonly int $vendor_id,
		public readonly string $currency,
		public readonly int $gross_minor,
		public readonly int $commission_minor,
		public readonly int $shipping_share_minor,
		public readonly int $tax_share_minor,
		public readonly int $fee_share_minor,
		public readonly int $net_minor,
		public readonly array $meta = [],
	) {}

	public function to_array(): array {
		return [
			'vendor_id'            => $this->vendor_id,
			'currency'             => $this->currency,
			'gross_minor'          => $this->gross_minor,
			'commission_minor'     => $this->commission_minor,
			'shipping_share_minor' => $this->shipping_share_minor,
			'tax_share_minor'      => $this->tax_share_minor,
			'fee_share_minor'      => $this->fee_share_minor,
			'net_minor'            => $this->net_minor,
			'meta'                 => $this->meta,
		];
	}
}
