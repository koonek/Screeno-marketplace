<?php
/**
 * VendorRateAdmin – meta box na vendor CPT edit screen pro paušál dopravy.
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class VendorRateAdmin {

	private static ?VendorRateAdmin $instance = null;

	public static function instance(): VendorRateAdmin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'add_box' ] );
		add_action( 'save_post', [ $this, 'save' ], 10, 2 );
	}

	public function add_box(): void {
		foreach ( [ 'nkv_vendor', 'nkzmp_vendor' ] as $cpt ) {
			if ( ! post_type_exists( $cpt ) ) {
				continue;
			}
			add_meta_box(
				'nkzmp_shipping_rate',
				__( 'Doprava – paušál', 'nkz-mp-shipping' ),
				[ $this, 'render' ],
				$cpt,
				'side'
			);
		}
	}

	public function render( \WP_Post $post ): void {
		wp_nonce_field( 'nkzmp_shipping_rate_save', 'nkzmp_shipping_rate_nonce' );
		$rate    = get_post_meta( $post->ID, NKZMP_SHIPPING_VENDOR_RATE_META, true );
		$default = Rate::default_flat();
		echo '<p><label for="nkzmp_shipping_flat">' . esc_html__( 'Paušál za dopravu (Kč)', 'nkz-mp-shipping' ) . '</label></p>';
		echo '<input type="number" min="0" step="1" id="nkzmp_shipping_flat" name="nkzmp_shipping_flat" value="' . esc_attr( (string) $rate ) . '" placeholder="' . esc_attr( sprintf( __( 'výchozí %s Kč', 'nkz-mp-shipping' ), $default ) ) . '" style="width:100%;" />';
		echo '<p class="description">' . esc_html__( 'Účtuje se jednou za objednávku, pokud má tento prodejce v košíku fyzický produkt. Prázdné = použít výchozí.', 'nkz-mp-shipping' ) . '</p>';
	}

	public function save( int $post_id, \WP_Post $post ): void {
		if ( ! in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			return;
		}
		if ( ! isset( $_POST['nkzmp_shipping_rate_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nkzmp_shipping_rate_nonce'] ) ), 'nkzmp_shipping_rate_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$raw = isset( $_POST['nkzmp_shipping_flat'] ) ? trim( (string) wp_unslash( $_POST['nkzmp_shipping_flat'] ) ) : '';
		if ( $raw === '' ) {
			delete_post_meta( $post_id, NKZMP_SHIPPING_VENDOR_RATE_META );
		} else {
			update_post_meta( $post_id, NKZMP_SHIPPING_VENDOR_RATE_META, (float) $raw );
		}
	}
}
