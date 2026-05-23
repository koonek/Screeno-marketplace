<?php
/**
 * Enforcement – produkty suspended prodejce nejsou koupitelné.
 *
 * Dle plánu: produkty zůstávají v katalogu viditelné, ale „add to cart" je
 * disabled + badge „dočasně nedostupné".
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class Enforcement {

	private static ?Enforcement $instance = null;
	private array $vendor_block_cache = [];

	public static function instance(): Enforcement {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		add_filter( 'woocommerce_is_purchasable', [ $this, 'is_purchasable' ], 10, 2 );
		add_filter( 'woocommerce_get_availability', [ $this, 'availability' ], 10, 2 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'badge' ], 11 );
	}

	public function is_purchasable( $purchasable, $product ) {
		if ( $this->is_blocked_product( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	public function availability( $availability, $product ) {
		if ( $this->is_blocked_product( $product ) ) {
			$availability['availability'] = __( 'Dočasně nedostupné', 'nkz-mp-vendor-billing' );
			$availability['class']        = 'out-of-stock';
		}
		return $availability;
	}

	public function badge(): void {
		global $product;
		if ( $product instanceof \WC_Product && $this->is_blocked_product( $product ) ) {
			echo '<p class="nkzmp-billing-unavailable" style="display:inline-block;padding:6px 12px;background:rgba(0,0,0,0.06);font-size:13px;color:#666;">'
				. esc_html__( 'Tento prodejce je dočasně nedostupný.', 'nkz-mp-vendor-billing' )
				. '</p>';
		}
	}

	/**
	 * Produkt nelze koupit, když:
	 *  - vendor je suspended, NEBO
	 *  - vendor nemá aktivní předplatné (billing status mimo active / past_due).
	 *
	 * past_due = platba selhala, ale jsme v grace → necháme prodávat dokud
	 * grace neuplyne (pak webhook vendora suspendne).
	 *
	 * Produkty bez vendora (platforma) se neblokují.
	 */
	private function is_blocked_product( $product ): bool {
		if ( ! $product instanceof \WC_Product ) {
			return false;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		if ( $vid <= 0 ) {
			return false;
		}

		if ( isset( $this->vendor_block_cache[ $vid ] ) ) {
			return $this->vendor_block_cache[ $vid ];
		}

		$vendor_status = (string) get_post_meta( $vid, '_nkzmp_vendor_status', true );
		if ( $vendor_status === '' ) {
			$vendor_status = (string) get_post_meta( $vid, '_nkv_vendor_status', true );
		}
		$billing_status = (string) get_post_meta( $vid, NKZMP_BILLING_STATUS_META, true );

		$blocked = false;
		if ( $vendor_status === Status::SUSPENDED->value ) {
			$blocked = true;
		} elseif ( ! in_array( $billing_status, [ 'active', 'past_due' ], true ) ) {
			$blocked = true; // žádné aktivní předplatné → nemůže prodávat
		}

		$blocked = (bool) apply_filters( 'nkzmp/v1/billing/product_blocked', $blocked, $vid, $product );

		$this->vendor_block_cache[ $vid ] = $blocked;
		return $blocked;
	}
}
