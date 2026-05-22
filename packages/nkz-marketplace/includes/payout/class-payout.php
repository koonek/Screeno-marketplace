<?php
/**
 * Payout value object.
 *
 * Reprezentuje jednu plánovanou / probíhající / dokončenou výplatu vendorovi.
 *
 * @package NKZMP
 */

namespace NKZMP\Payout;

defined( 'ABSPATH' ) || exit;

final class Payout {

	public function __construct(
		public readonly ?int $id,
		public readonly int $vendor_id,
		public readonly int $amount_minor,
		public readonly string $currency,
		public readonly State $state,
		public readonly ?string $adapter,
		public readonly ?string $adapter_ref,
		public readonly string $idempotency_key,
		public readonly int $created_at,
		public readonly int $updated_at,
		public readonly ?int $scheduled_for,
		public readonly ?int $completed_at,
		public readonly array $meta = [],
	) {}
}
