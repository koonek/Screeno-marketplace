<?php
/**
 * WC shipping method – per-vendor paušál.
 *
 * Sečte paušály všech vendorů, kteří mají v košíku alespoň 1 produkt
 * vyžadující dopravu. Rate je jedna položka s breakdown v meta (WC zobrazí
 * pod cenou dopravy).
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \WC_Shipping_Method::class ) ) {
	return;
}

final class Method extends \WC_Shipping_Method {

	public function __construct( $instance_id = 0 ) {
		$this->id                 = 'nkzmp_vendor_shipping';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'NKZ Marketplace – per-vendor doprava', 'nkz-mp-shipping' );
		$this->method_description = __( 'Sečte paušální dopravu od každého prodejce v košíku. Digital produkty dopravu nevyžadují.', 'nkz-mp-shipping' );
		$this->supports           = [ 'shipping-zones', 'instance-settings', 'settings' ];

		$this->init();
	}

	public function init(): void {
		$this->init_form_fields();
		$this->init_settings();

		$this->title   = $this->get_option( 'title', __( 'Doprava od prodejců', 'nkz-mp-shipping' ) );
		$this->enabled = $this->get_option( 'enabled', 'yes' );

		add_action( 'woocommerce_update_options_shipping_' . $this->id, [ $this, 'process_admin_options' ] );
	}

	public function init_form_fields(): void {
		$this->instance_form_fields = [
			'title' => [
				'title'       => __( 'Název', 'nkz-mp-shipping' ),
				'type'        => 'text',
				'default'     => __( 'Doprava od prodejců', 'nkz-mp-shipping' ),
				'description' => __( 'Co uvidí zákazník v košíku.', 'nkz-mp-shipping' ),
			],
		];
		$this->form_fields = $this->instance_form_fields;
	}

	/**
	 * @param array $package
	 */
	public function calculate_shipping( $package = [] ): void {
		$contents = $package['contents'] ?? [];
		if ( empty( $contents ) ) {
			return;
		}

		// Seskup podle vendora — kdo má aspoň 1 produkt co vyžaduje dopravu.
		$vendors_needing = [];
		foreach ( $contents as $item ) {
			$product = $item['data'] ?? null;
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			if ( ! Rate::product_requires_shipping( $product ) ) {
				continue;
			}
			// U variací vezmi parent pro vendor meta.
			$pid       = $product->get_parent_id() ?: $product->get_id();
			$vendor_id = Rate::product_vendor_id( $pid );
			if ( $vendor_id <= 0 ) {
				$vendor_id = Rate::product_vendor_id( $product->get_id() );
			}
			if ( $vendor_id <= 0 ) {
				// Produkt bez vendora — platforma. Použij default jednou.
				$vendor_id = 0;
			}
			$vendors_needing[ $vendor_id ] = true;
		}

		if ( empty( $vendors_needing ) ) {
			return; // jen digital → žádná doprava
		}

		$total     = 0.0;
		$breakdown = [];
		foreach ( array_keys( $vendors_needing ) as $vendor_id ) {
			$flat = $vendor_id > 0 ? Rate::vendor_flat( $vendor_id ) : Rate::default_flat();
			if ( $flat <= 0 ) {
				continue;
			}
			$total += $flat;
			$name   = $vendor_id > 0 ? get_the_title( $vendor_id ) : __( 'Platforma', 'nkz-mp-shipping' );
			$breakdown[] = sprintf( '%s: %s', $name ?: ( '#' . $vendor_id ), wc_price( $flat ) );
		}

		if ( $total <= 0 ) {
			// Vše zdarma — přidej rate 0 ať checkout nezamrzne.
			$total = 0.0;
		}

		$meta = [];
		if ( count( $breakdown ) > 1 ) {
			$meta[ __( 'Rozpis', 'nkz-mp-shipping' ) ] = implode( ' · ', $breakdown );
		}

		$this->add_rate( [
			'id'        => $this->get_rate_id(),
			'label'     => $this->title,
			'cost'      => $total,
			'meta_data' => $meta,
			'package'   => $package,
		] );
	}
}
