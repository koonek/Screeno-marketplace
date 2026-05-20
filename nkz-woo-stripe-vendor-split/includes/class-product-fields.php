<?php
/**
 * Product data fields: vendor assignment, fee override, split enable.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Product_Fields {

	private static ?Product_Fields $instance = null;
	public static function instance(): Product_Fields { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'render_fields' ] );
		add_action( 'woocommerce_process_product_meta', [ $this, 'save' ] );
	}

	public function render_fields(): void {
		global $post;
		echo '<div class="options_group">';

		woocommerce_wp_select(
			[
				'id'      => '_nkv_vendor_id',
				'label'   => __( 'Prodejce (Stripe split)', 'nkz-woo-stripe-vendor-split' ),
				'options' => array_map( 'strval', Vendors::dropdown_options() ),
				'value'   => (string) get_post_meta( $post->ID, '_nkv_vendor_id', true ),
			]
		);

		woocommerce_wp_checkbox(
			[
				'id'          => '_nkv_vendor_split_enabled',
				'label'       => __( 'Aktivovat rozdělení', 'nkz-woo-stripe-vendor-split' ),
				'description' => __( 'Vytvořit Stripe transfer pro tohoto prodejce po zaplacení.', 'nkz-woo-stripe-vendor-split' ),
				'value'       => get_post_meta( $post->ID, '_nkv_vendor_split_enabled', true ) ?: 'yes',
			]
		);

		woocommerce_wp_text_input(
			[
				'id'                => '_nkv_platform_fee_percent_override',
				'label'             => __( 'Provize platformy — procento (%)', 'nkz-woo-stripe-vendor-split' ),
				'desc_tip'          => true,
				'description'       => __( 'Ponechte prázdné pro default prodejce. Ignoruje se, pokud je vyplněná fixní částka níže.', 'nkz-woo-stripe-vendor-split' ),
				'type'              => 'number',
				'custom_attributes' => [ 'step' => '0.01', 'min' => '0', 'max' => '100' ],
				'value'             => get_post_meta( $post->ID, '_nkv_platform_fee_percent_override', true ),
			]
		);

		woocommerce_wp_text_input(
			[
				'id'                => '_nkv_platform_fee_fixed_override',
				'label'             => __( 'Provize platformy — fixní částka (Kč)', 'nkz-woo-stripe-vendor-split' ),
				'desc_tip'          => true,
				'description'       => __( 'Pokud je vyplněno, ignoruje se procento výše. Použij pro fee dle materiálu/typu produktu.', 'nkz-woo-stripe-vendor-split' ),
				'type'              => 'number',
				'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
				'value'             => get_post_meta( $post->ID, '_nkv_platform_fee_fixed_override', true ),
			]
		);

		echo '</div>';
	}

	public function save( int $product_id ): void {
		if ( ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$vendor_id = isset( $_POST['_nkv_vendor_id'] ) ? (int) $_POST['_nkv_vendor_id'] : 0;
		update_post_meta( $product_id, '_nkv_vendor_id', $vendor_id );

		$enabled = isset( $_POST['_nkv_vendor_split_enabled'] ) ? 'yes' : 'no';
		update_post_meta( $product_id, '_nkv_vendor_split_enabled', $enabled );

		$override = $_POST['_nkv_platform_fee_percent_override'] ?? '';
		if ( '' === $override ) {
			delete_post_meta( $product_id, '_nkv_platform_fee_percent_override' );
		} else {
			update_post_meta( $product_id, '_nkv_platform_fee_percent_override', (float) $override );
		}

		$fixed = $_POST['_nkv_platform_fee_fixed_override'] ?? '';
		if ( '' === $fixed ) {
			delete_post_meta( $product_id, '_nkv_platform_fee_fixed_override' );
		} else {
			update_post_meta( $product_id, '_nkv_platform_fee_fixed_override', (float) $fixed );
		}
	}
}
