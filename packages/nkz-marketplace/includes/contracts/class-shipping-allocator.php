<?php
/**
 * Shipping allocator contract.
 *
 * Implementuje balíček, který odpovídá na otázku „kolik z dopravy patří
 * komu" pro danou objednávku. Default v core: všechno platformě (Screeno
 * chování). AOZ instaluje nkz-mp-shipping, který vrací per-vendor split.
 *
 * @package NKZMP
 */

namespace NKZMP\Contracts;

use WC_Order;

defined( 'ABSPATH' ) || exit;

interface ShippingAllocator {

	/**
	 * Vrátí mapu vendor_id => amount_minor (částka v doplňkové měně objednávky).
	 * Vendor_id 0 (resp. null klíč) reprezentuje platformu.
	 *
	 * @param WC_Order $order
	 * @return array<int,int>
	 */
	public function allocate( WC_Order $order ): array;
}
