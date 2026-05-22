<?php
/**
 * Normalizovaný záznam ze zdroje pravdy (PSP) pro reconciliation.
 *
 * Driver vrací pole těchto objektů za dané časové okno. Service je porovná
 * s ledger entries podle (source_adapter, source_ref).
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

defined( 'ABSPATH' ) || exit;

final class SourceRecord {

	public function __construct(
		public readonly string $source_adapter,
		public readonly string $source_ref,
		public readonly int $amount_minor,
		public readonly string $currency,
		public readonly int $occurred_at,
		public readonly string $type,
		public readonly ?int $vendor_id = null,
		public readonly array $meta = [],
	) {}
}
