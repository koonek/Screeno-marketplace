<?php
/**
 * LedgerEntry value object.
 *
 * Append-only: jediný způsob „mazání" je nový řádek typu REVERSAL s vyplněným
 * reverses_id. Idempotency_key zajišťuje, že webhook retry nezapíše duplikát.
 *
 * Konvence znamének:
 *  - kladná částka = připsání na účet vendora (nebo platformy, pokud vendor_id=0)
 *  - záporná částka = odepsání
 *
 * @package NKZMP
 */

namespace NKZMP\Ledger;

defined( 'ABSPATH' ) || exit;

final class Entry {

	public function __construct(
		public readonly ?int $id,
		public readonly int $vendor_id,
		public readonly EntryType $type,
		public readonly int $amount_minor,
		public readonly string $currency,
		public readonly ?int $order_id,
		public readonly ?string $source_adapter,
		public readonly ?string $source_ref,
		public readonly string $idempotency_key,
		public readonly ?int $reverses_id,
		public readonly int $occurred_at,
		public readonly int $recorded_at,
		public readonly array $meta = [],
	) {}

	public function to_array(): array {
		return [
			'id'              => $this->id,
			'vendor_id'       => $this->vendor_id,
			'type'            => $this->type->value,
			'amount_minor'    => $this->amount_minor,
			'currency'        => $this->currency,
			'order_id'        => $this->order_id,
			'source_adapter'  => $this->source_adapter,
			'source_ref'      => $this->source_ref,
			'idempotency_key' => $this->idempotency_key,
			'reverses_id'     => $this->reverses_id,
			'occurred_at'     => $this->occurred_at,
			'recorded_at'     => $this->recorded_at,
			'meta'            => $this->meta,
		];
	}
}
