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
		// Forms musí být MIMO #post (WC admin obal order edit). Nested form by
		// rozbil outer #post → 'Aktualizovat' / 'Dokončeno' v Order Actions
		// pak nereaguje na klik. Renderujeme je v admin_footer mimo #post DOM.
		add_action( 'admin_footer', [ $this, 'render_footer_forms' ] );
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

		// Tlačítka MIMO <form> – odkazují na externí form přes atribut form=.
		// Form vyrenderujeme v admin_footer mimo #post (jinak by nested form
		// rozbil outer 'Aktualizovat / Dokončeno' button).
		$form_id = 'nkv-svs-actions-' . $order->get_id();
		$this->footer_form_id   = $form_id;
		$this->footer_order_id  = $order->get_id();
		echo '<p style="margin-top:10px;">';
		printf( '<button type="submit" form="%s" name="nkv_action_recalculate" class="button button-secondary button-small">%s</button> ', esc_attr( $form_id ), esc_html__( 'Přepočítat', 'nkz-woo-stripe-vendor-split' ) );
		printf( '<button type="submit" form="%s" name="nkv_action_run" class="button button-primary button-small">%s</button> ', esc_attr( $form_id ), esc_html__( 'Vytvořit transfery', 'nkz-woo-stripe-vendor-split' ) );
		printf( '<button type="submit" form="%s" name="nkv_action_retry" class="button button-secondary button-small">%s</button> ', esc_attr( $form_id ), esc_html__( 'Opakovat neúspěšné', 'nkz-woo-stripe-vendor-split' ) );
		printf( '<button type="submit" form="%s" name="nkv_action_resolve" class="button button-secondary button-small">%s</button>', esc_attr( $form_id ), esc_html__( 'Označit jako vyřešené', 'nkz-woo-stripe-vendor-split' ) );
		echo '</p>';

		// Partial reversal panel — také mimo #post (renderuje vlastní formy do footeru).
		$this->render_partial_reversal( $order, $records );
	}

	/** ID akčního formu pro tlačítka (renderovaného v admin_footer). */
	private string $footer_form_id  = '';
	private int $footer_order_id = 0;
	/** @var array<int,array{vendor_id:int,remaining:int,suggested:int,currency:string,form_id:string}> */
	private array $footer_reversal_forms = [];

	public function render_footer_forms(): void {
		if ( $this->footer_form_id === '' || $this->footer_order_id <= 0 ) {
			return;
		}
		$action = esc_url( admin_url( 'admin-post.php' ) );
		echo '<form id="' . esc_attr( $this->footer_form_id ) . '" method="post" action="' . $action . '" style="display:none;">';
		echo '<input type="hidden" name="action" value="nkv_svs_action" />';
		echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $this->footer_order_id ) . '" />';
		wp_nonce_field( 'nkv_svs_action_' . $this->footer_order_id, 'nkv_svs_nonce' );
		echo '</form>';

		foreach ( $this->footer_reversal_forms as $f ) {
			echo '<form id="' . esc_attr( $f['form_id'] ) . '" method="post" action="' . $action . '" style="display:none;">';
			echo '<input type="hidden" name="action" value="nkv_svs_action" />';
			echo '<input type="hidden" name="order_id" value="' . esc_attr( (string) $this->footer_order_id ) . '" />';
			echo '<input type="hidden" name="reverse_vendor_id" value="' . esc_attr( (string) $f['vendor_id'] ) . '" />';
			wp_nonce_field( 'nkv_svs_action_' . $this->footer_order_id, 'nkv_svs_nonce' );
			echo '</form>';
		}
	}

	private function render_partial_reversal( \WC_Order $order, array $records ): void {
		$reversible = array_filter( $records, static fn( $r ) => 'completed' === $r['status'] && ! empty( $r['transfer_id'] ) );
		if ( empty( $reversible ) ) {
			return;
		}
		echo '<hr><h4 style="margin:14px 0 6px;">' . esc_html__( 'Ruční reversal (vrátit peníze z prodejce)', 'nkz-woo-stripe-vendor-split' ) . '</h4>';
		echo '<p style="color:#50575e;font-size:12px;margin:0 0 10px;">' . esc_html__( 'Použij při částečném refundu objednávky — proporční hodnota je předvyplněná podle naposled vytvořeného refundu.', 'nkz-woo-stripe-vendor-split' ) . '</p>';

		// Compute suggestions from the most recent refund (if any).
		$suggestions = [];
		$refunds = $order->get_refunds();
		if ( ! empty( $refunds ) ) {
			$latest_refund = $refunds[0]; // get_refunds() is sorted DESC by date.
			$suggestions   = Refund_Service::suggested_reversal_minor( $order, $latest_refund );
		}

		foreach ( $reversible as $rec ) {
			$vendor_id = (int) $rec['vendor_id'];
			$vendor    = Vendor_Repository::get( $vendor_id );
			$vname     = $vendor['name'] ?? '#' . $vendor_id;
			$remaining = (int) $rec['amount_minor'] - Refund_Service::reversed_amount_minor( $rec );
			$suggested = (int) ( $suggestions[ $vendor_id ] ?? 0 );
			$default   = $suggested > 0 ? $suggested : $remaining;
			$currency  = $order->get_currency();

			$form_id = 'nkv-svs-reversal-' . $order->get_id() . '-' . $vendor_id;
			$this->footer_reversal_forms[] = [
				'vendor_id' => $vendor_id,
				'remaining' => $remaining,
				'suggested' => $default,
				'currency'  => $currency,
				'form_id'   => $form_id,
			];

			echo '<div style="display:flex;gap:6px;align-items:center;margin-bottom:6px;flex-wrap:wrap;">';
			printf( '<strong style="min-width:120px;">%s</strong>', esc_html( $vname ) );
			printf(
				'<span style="color:#50575e;font-size:12px;">%s: %s</span>',
				esc_html__( 'lze vrátit', 'nkz-woo-stripe-vendor-split' ),
				esc_html( nkvsvs_from_minor_display( $remaining, $currency ) )
			);
			printf(
				'<input type="number" form="%s" name="reverse_amount" step="0.01" min="0.01" max="%s" value="%s" style="width:110px;" />',
				esc_attr( $form_id ),
				esc_attr( (string) ( $remaining / nkvsvs_minor_factor( $currency ) ) ),
				esc_attr( (string) ( $default / nkvsvs_minor_factor( $currency ) ) )
			);
			echo '<span style="color:#50575e;">' . esc_html( $currency ) . '</span>';
			printf(
				'<button type="submit" form="%s" name="nkv_action_reverse" class="button button-secondary button-small">%s</button>',
				esc_attr( $form_id ),
				esc_html__( 'Vrátit částku', 'nkz-woo-stripe-vendor-split' )
			);
			echo '</div>';
		}
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
			// Reset failed records + BUMP idempotency attempt counter pro daného
			// vendora – jinak Stripe vrátí cached failure ze starého pokusu
			// (idempotency cache 24h) a retry by byl no-op pro mezitím
			// napravené chyby (capability, klíče …).
			$records = $ts->get_transfer_records( $order );
			foreach ( $records as $r ) {
				if ( 'failed' === ( $r['status'] ?? '' ) && ! empty( $r['vendor_id'] ) ) {
					$ts->bump_retry_attempt( $order, (int) $r['vendor_id'] );
				}
			}
			$records = array_filter( $records, static fn( $r ) => 'failed' !== $r['status'] );
			$ts->save_transfer_records( $order, array_values( $records ) );
			$ts->maybe_create_transfers( $order_id );
		} elseif ( isset( $_POST['nkv_action_resolve'] ) ) {
			$order->update_meta_data( '_nkv_split_status', 'manual' );
			$order->add_order_note( __( 'NKV: Označeno jako ručně vyřešeno.', 'nkz-woo-stripe-vendor-split' ) );
			$order->save();
		} elseif ( isset( $_POST['nkv_action_reverse'] ) ) {
			$vendor_id = (int) ( $_POST['reverse_vendor_id'] ?? 0 );
			$amount    = (float) ( $_POST['reverse_amount'] ?? 0 );
			$currency  = $order->get_currency();
			$minor     = nkvsvs_to_minor( $amount, $currency );
			if ( $vendor_id > 0 && $minor > 0 ) {
				try {
					Refund_Service::instance()->reverse(
						$order,
						$vendor_id,
						$minor,
						'manual_partial_' . current_time( 'U' )
					);
				} catch ( \Throwable $e ) {
					$order->add_order_note( sprintf( __( 'NKV: Ruční reversal selhal: %s', 'nkz-woo-stripe-vendor-split' ), $e->getMessage() ) );
				}
			}
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
