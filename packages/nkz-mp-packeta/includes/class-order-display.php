<?php
/**
 * OrderDisplay – zobrazí vybranou výdejnu v admin objednávce, na thank-you,
 * v e-mailech a ve vendor objednávkách.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class OrderDisplay {

	private static ?OrderDisplay $instance = null;

	public static function instance(): OrderDisplay {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Admin order detail.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_box' ] );
		// Thank you + e-maily + my account order.
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'frontend_block' ] );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_block' ], 10, 1 );
	}

	public function admin_box( \WC_Order $order ): void {
		$id   = (string) $order->get_meta( NKZMP_PACKETA_POINT_ID_META );
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $id === '' ) {
			return;
		}
		echo '<p><strong>' . esc_html__( 'Zásilkovna – výdejní místo', 'nkz-mp-packeta' ) . ':</strong><br>';
		echo esc_html( $name ) . ' <code>' . esc_html( $id ) . '</code></p>';
	}

	public function frontend_block( \WC_Order $order ): void {
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $name === '' ) {
			return;
		}
		echo '<section class="nkzmp-packeta-order">';
		echo '<h2>' . esc_html__( 'Výdejní místo Zásilkovny', 'nkz-mp-packeta' ) . '</h2>';
		echo '<p>' . esc_html( $name ) . '</p>';
		echo '</section>';
	}

	public function email_block( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$name = (string) $order->get_meta( NKZMP_PACKETA_POINT_NAME_META );
		if ( $name === '' ) {
			return;
		}
		echo '<p style="margin:16px 0;"><strong>' . esc_html__( 'Výdejní místo Zásilkovny:', 'nkz-mp-packeta' ) . '</strong><br>' . esc_html( $name ) . '</p>';
	}
}
