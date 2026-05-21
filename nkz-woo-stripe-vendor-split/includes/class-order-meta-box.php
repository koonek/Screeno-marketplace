<?php
/**
 * Order edit screen meta box + AJAX actions.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Order_Meta_Box {

	private static ?Order_Meta_Box $instance = null;
	public static function instance(): Order_Meta_Box { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'add_meta_boxes', [ $this, 'register' ] );
		add_action( 'admin_post_nkv_svs_action', [ $this, 'handle_action' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue( string $hook ): void {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ], true ) ) {
			return;
		}
		wp_enqueue_style( 'nkv-svs-admin', NKVSVS_PLUGIN_URL . 'assets/admin.css', [], NKVSVS_VERSION );
	}

	public function register(): void {
		$screens = [ 'shop_order' ];
		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )
			&& wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled() ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( $screens as $screen ) {
			add_meta_box( 'nkv_svs_box', __( 'Rozdělení plateb Stripe', 'nkz-woo-stripe-vendor-split' ), [ $this, 'render' ], $screen, 'normal', 'default' );
		}
	}

	public function render( $post_or_order ): void {
		$order = ( $post_or_order instanceof \WC_Order ) ? $post_or_order : wc_get_order( $post_or_order->ID );
		if ( ! $order instanceof \WC_Order ) {
			echo '<p>—</p>';
			return;
		}

		$status   = (string) ( $order->get_meta( '_nkv_split_status' ) ?: 'none' );
		$calc_raw = $order->get_meta( '_nkv_split_calculation' );
		$calc     = is_string( $calc_raw ) ? json_decode( $calc_raw, true ) : ( is_array( $calc_raw ) ? $calc_raw : null );
		$records  = Transfer_Service::instance()->get_transfer_records( $order );
		$currency = $order->get_currency();

		echo '<p><strong>' . esc_html__( 'Stav', 'nkz-woo-stripe-vendor-split' ) . ':</strong> ';
		printf( '<span class="nkv-svs-status nkv-svs-status-%s">%s</span></p>', esc_attr( $status ), esc_html( $status ) );

		if ( $calc && ! empty( $calc['vendors'] ) ) {
			echo '<table class="widefat striped"><thead><tr>';
			echo '<th>' . esc_html__( 'Prodejce', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '<th>' . esc_html__( 'Základ', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '<th>' . esc_html__( 'Provize', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '<th>' . esc_html__( 'Stripe fee (vendor)', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '<th>' . esc_html__( 'Pro prodejce', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '<th>' . esc_html__( 'Transfer', 'nkz-woo-stripe-vendor-split' ) . '</th>';
			echo '</tr></thead><tbody>';
			foreach ( $calc['vendors'] as $v ) {
				$rec = $this->find_record( $records, (int) $v['vendor_id'] );
				// stripe_fee_share_minor lives on the record (post-fetch), fall back to 0 in calc snapshot.
				$stripe_fee_minor = (int) ( $rec['stripe_fee_share_minor'] ?? $v['stripe_fee_share_minor'] ?? 0 );
				echo '<tr>';
				echo '<td>' . esc_html( $v['vendor_name'] ) . '</td>';
				echo '<td>' . esc_html( nkvsvs_from_minor_display( (int) $v['base_minor'], $currency ) ) . '</td>';
				echo '<td>' . esc_html( nkvsvs_from_minor_display( (int) $v['platform_fee_minor'], $currency ) ) . '</td>';
				echo '<td>' . esc_html( $stripe_fee_minor > 0 ? nkvsvs_from_minor_display( $stripe_fee_minor, $currency ) : '—' ) . '</td>';
				$vendor_amt_minor = $rec ? (int) $rec['amount_minor'] : (int) $v['transfer_amount_minor'];
				echo '<td>' . esc_html( nkvsvs_from_minor_display( $vendor_amt_minor, $currency ) ) . '</td>';
				echo '<td>';
				if ( $rec ) {
					printf( '<span class="nkv-svs-status nkv-svs-status-%s">%s</span>', esc_attr( $rec['status'] ), esc_html( $rec['status'] ) );
					if ( ! empty( $rec['transfer_id'] ) ) {
						echo '<br/><code>' . esc_html( $rec['transfer_id'] ) . '</code>';
					}
					if ( ! empty( $rec['error'] ) ) {
						echo '<br/><small>' . esc_html( $rec['error'] ) . '</small>';
					}
				} else {
					echo '—';
				}
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'Žádný výpočet zatím neexistuje.', 'nkz-woo-stripe-vendor-split' ) . '</p>';
		}

		// Actions form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;">';
		echo '<input type="hidden" name="action" value="nkv_svs_action" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $order->get_id() ) . '" />';
		wp_nonce_field( 'nkv_svs_action_' . $order->get_id(), 'nkv_svs_nonce' );

		echo '<p>';
		submit_button( __( 'Přepočítat', 'nkz-woo-stripe-vendor-split' ), 'secondary small', 'nkv_action_recalculate', false );
		echo ' ';
		submit_button( __( 'Vytvořit transfery', 'nkz-woo-stripe-vendor-split' ), 'primary small', 'nkv_action_run', false );
		echo ' ';
		submit_button( __( 'Opakovat neúspěšné', 'nkz-woo-stripe-vendor-split' ), 'secondary small', 'nkv_action_retry', false );
		echo ' ';
		submit_button( __( 'Označit jako vyřešené', 'nkz-woo-stripe-vendor-split' ), 'secondary small', 'nkv_action_resolve', false );
		echo '</p>';
		echo '</form>';
	}

	public function handle_action(): void {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'nkv_svs_action_' . $order_id, 'nkv_svs_nonce' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'nkz-woo-stripe-vendor-split' ) );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'nkz-woo-stripe-vendor-split' ) );
		}

		$ts = Transfer_Service::instance();

		if ( isset( $_POST['nkv_action_recalculate'] ) ) {
			$calc = Split_Calculator::calculate( $order );
			$order->update_meta_data( '_nkv_split_calculation', wp_json_encode( $calc ) );
			$order->save();
			$order->add_order_note( __( 'NKV: Split přepočten ručně.', 'nkz-woo-stripe-vendor-split' ) );
		} elseif ( isset( $_POST['nkv_action_run'] ) ) {
			$ts->maybe_create_transfers( $order_id );
		} elseif ( isset( $_POST['nkv_action_retry'] ) ) {
			// Reset failed records so they can be retried (new run uses same idempotency key — Stripe returns cached if completed).
			$records = $ts->get_transfer_records( $order );
			$records = array_filter( $records, static fn( $r ) => 'failed' !== $r['status'] );
			$ts->save_transfer_records( $order, array_values( $records ) );
			$ts->maybe_create_transfers( $order_id );
		} elseif ( isset( $_POST['nkv_action_resolve'] ) ) {
			$order->update_meta_data( '_nkv_split_status', 'manual' );
			$order->add_order_note( __( 'NKV: Označeno jako ručně vyřešeno.', 'nkz-woo-stripe-vendor-split' ) );
			$order->save();
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	private function find_record( array $records, int $vendor_id ): ?array {
		foreach ( $records as $r ) {
			if ( (int) $r['vendor_id'] === $vendor_id ) {
				return $r;
			}
		}
		return null;
	}
}
