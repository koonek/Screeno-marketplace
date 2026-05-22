<?php
/**
 * Payment adapter contract.
 *
 * Implementuje balíček, který umí vytvořit/refundovat platbu a rozdělit ji
 * mezi platformu a vendory. Core sám nezná žádné PSP – orchestruje přes
 * tento kontrakt.
 *
 * @package NKZMP
 */

namespace NKZMP\Contracts;

use NKZMP\Allocation\Allocation;
use WC_Order;

defined( 'ABSPATH' ) || exit;

interface PaymentAdapter {

	/**
	 * Identifier adaptéru, např. "stripe".
	 */
	public function id(): string;

	/**
	 * Provede platby splitu (např. Stripe Connect transfery) podle alokací.
	 *
	 * @param WC_Order      $order
	 * @param Allocation[]  $allocations
	 * @return AdapterResult
	 */
	public function settle( WC_Order $order, array $allocations ): AdapterResult;

	/**
	 * Vrátí splitnuté prostředky při refundu objednávky.
	 *
	 * @param WC_Order $order
	 * @param int      $refund_id
	 * @return AdapterResult
	 */
	public function reverse_on_refund( WC_Order $order, int $refund_id ): AdapterResult;
}
