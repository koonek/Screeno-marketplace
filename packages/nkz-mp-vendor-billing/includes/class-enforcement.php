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
	private array $vendor_status_cache = [];

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
		if ( $this->is_suspended_product( $product ) ) {
			return false;
		}
		return $purchasable;
	}

	public function availability( $availability, $product ) {
		if ( $this->is_suspended_product( $product ) ) {
			$availability['availability'] = __( 'Dočasně nedostupné', 'nkz-mp-vendor-billing' );
			$availability['class']        = 'out-of-stock';
		}
		return $availability;
	}

	public function badge(): void {
		global $product;
		if ( $product instanceof \WC_Product && $this->is_suspended_product( $product ) ) {
			echo '<p class="nkzmp-billing-unavailable" style="display:inline-block;padding:6px 12px;background:rgba(0,0,0,0.06);font-size:13px;color:#666;">'
				. esc_html__( 'Tento prodejce je dočasně nedostupný.', 'nkz-mp-vendor-billing' )
				. '</p>';
		}
	}

	private function is_suspended_product( $product ): bool {
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
		if ( ! isset( $this->vendor_status_cache[ $vid ] ) ) {
			$raw = (string) get_post_meta( $vid, '_nkzmp_vendor_status', true );
			if ( $raw === '' ) {
				$raw = (string) get_post_meta( $vid, '_nkv_vendor_status', true );
			}
			$this->vendor_status_cache[ $vid ] = $raw;
		}
		return $this->vendor_status_cache[ $vid ] === Status::SUSPENDED->value;
	}
}
