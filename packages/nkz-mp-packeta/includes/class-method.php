<?php
/**
 * WC shipping method – Zásilkovna výdejní místo.
 *
 * Cena = per-vendor paušál (reuse NKZMP\Shipping\Rate). Výběr konkrétní
 * výdejny řeší CheckoutWidget; tato metoda jen určuje cenu + že jde o
 * pickup-point doručení.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WC_Shipping_Method::class ) ) {
	return;
}

final class Method extends \WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'nkzmp_packeta';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Zásilkovna – výdejní místo', 'nkz-mp-packeta' );
		$this->method_description = __( 'Doručení na výdejní místo Zásilkovny. Cena = per-vendor paušál.', 'nkz-mp-packeta' );
		$this->supports           = [ 'shipping-zones', 'instance-settings', 'settings' ];
		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();
		$this->title   = $this->get_option( 'title', __( 'Zásilkovna – výdejní místo', 'nkz-mp-packeta' ) );
		$this->enabled = $this->get_option( 'enabled', 'yes' );
		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title' => [
				'title'   => __( 'Název', 'nkz-mp-packeta' ),
				'type'    => 'text',
				'default' => __( 'Zásilkovna – výdejní místo', 'nkz-mp-packeta' ),
			],
		];
		$this->form_fields = $this->instance_form_fields;
	}

	public function calculate_shipping( $package = [] ): void {
		$contents = $package['contents'] ?? [];
		if ( empty( $contents ) ) {
			return;
		}

		$total = $this->vendor_sum( $contents );

		$this->add_rate( [
			'id'    => $this->get_rate_id(),
			'label' => $this->title,
			'cost'  => $total,
			'package' => $package,
		] );
	}

	/**
	 * Součet per-vendor paušálů pro vendory s fyzickým produktem v košíku.
	 * Reuse NKZMP\Shipping\Rate pokud je shipping modul aktivní.
	 */
	private function vendor_sum( array $contents ): float {
		$shipping_rate_cls = '\\NKZMP\\Shipping\\Rate';
		$has_shipping_mod  = class_exists( $shipping_rate_cls );

		$vendors = [];
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( $has_shipping_mod && ! $shipping_rate_cls::product_requires_shipping( $product ) ) {
				continue;
			}
			if ( ! $has_shipping_mod && ( $product->is_virtual() || $product->is_downloadable() ) ) {
				continue;
			}
			$pid = $product->get_parent_id() ?: $product->get_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			$vendors[ $vid ] = true;
		}

		if ( empty( $vendors ) ) {
			return 0.0;
		}

		$total = 0.0;
		foreach ( array_keys( $vendors ) as $vid ) {
			if ( $has_shipping_mod ) {
				$total += $vid > 0 ? $shipping_rate_cls::vendor_flat( $vid ) : $shipping_rate_cls::default_flat();
			} else {
				$total += (float) Settings::get()['default_price'];
			}
		}
		return $total;
	}
}
