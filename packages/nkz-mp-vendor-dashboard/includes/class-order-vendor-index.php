<?php
/**
 * OrderVendorIndex – index objednávka → vendor pro stránkování.
 *
 * Ukládá na objednávku meta `_nkzmp_order_vendor` (jeden řádek per vendor
 * v objednávce). Díky tomu může OrdersView dělat reálnou stránkovanou
 * query přes wc_get_orders místo skenu posledních N objednávek.
 *
 * Index se plní pro nové objednávky (status change) a lazy při zobrazení.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class OrderVendorIndex {

	public const META = '_nkzmp_order_vendor';

	private static ?OrderVendorIndex $instance = null;

	public static function instance(): OrderVendorIndex {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_id' ], 20 );
		add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_changed' ], 20, 4 );
	}

	public function on_order_id( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::index( $order );
		}
	}

	public function on_status_changed( int $order_id, string $from, string $to, $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::index( $order );
		}
	}

	/** Distinct vendor ID v objednávce (z line items). */
	public static function vendor_ids( \WC_Order $order ): array {
		$ids = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid = $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid > 0 ) {
				$ids[ $vid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/** Zapíše/aktualizuje index na objednávce (idempotentní – přepíše). */
	public static function index( \WC_Order $order ): void {
		$ids = self::vendor_ids( $order );
		if ( empty( $ids ) ) {
			return;
		}
		$order->delete_meta_data( self::META );
		foreach ( $ids as $vid ) {
			$order->add_meta_data( self::META, (int) $vid, false );
		}
		$order->save();
	}
}
