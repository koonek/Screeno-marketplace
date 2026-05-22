<?php
/**
 * Payout adapter contract.
 *
 * Implementuje balíček, který umí vyplatit prostředky vendorovi (bankou,
 * Stripe payoutem, atd.). Core volá tento kontrakt z payout state machine.
 *
 * @package NKZMP
 */

namespace NKZMP\Contracts;

defined( 'ABSPATH' ) || exit;

interface PayoutAdapter {

	public function id(): string;

	/**
	 * Spustí výplatu vendorovi. Vrátí adapter-specific reference (např. Stripe payout id).
	 *
	 * @param int    $vendor_id
	 * @param int    $amount_minor
	 * @param string $currency
	 * @param array  $context Idempotency key, ledger entry ids atd.
	 * @return AdapterResult
	 */
	public function pay( int $vendor_id, int $amount_minor, string $currency, array $context ): AdapterResult;
}
