<?php
/**
 * Reconciliation report.
 *
 * Driftové kategorie:
 *  - missing_in_ledger: záznam je v PSP, chybí v ledgeru (nezaúčtováno)
 *  - missing_in_source: záznam je v ledgeru, chybí v PSP (možná manual / fake)
 *  - amount_mismatch:   stejný source_ref, jiná částka
 *  - currency_mismatch: stejný source_ref, jiná měna
 *
 * Driver i ledger jsou matchovány podle (source_adapter, source_ref).
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

defined( 'ABSPATH' ) || exit;

final class Report {

	/** @var array<int, array{kind:string, source_ref:string, detail:array}> */
	public array $drift = [];

	public function __construct(
		public readonly string $adapter,
		public readonly int $from_ts,
		public readonly int $to_ts,
		public int $source_count = 0,
		public int $ledger_count = 0,
		public int $matched_count = 0,
	) {}

	public function add_drift( string $kind, string $source_ref, array $detail ): void {
		$this->drift[] = [ 'kind' => $kind, 'source_ref' => $source_ref, 'detail' => $detail ];
	}

	public function has_drift(): bool {
		return count( $this->drift ) > 0;
	}

	public function to_array(): array {
		return [
			'adapter'       => $this->adapter,
			'from_ts'       => $this->from_ts,
			'to_ts'         => $this->to_ts,
			'source_count'  => $this->source_count,
			'ledger_count'  => $this->ledger_count,
			'matched_count' => $this->matched_count,
			'drift_count'   => count( $this->drift ),
			'drift'         => $this->drift,
		];
	}
}
