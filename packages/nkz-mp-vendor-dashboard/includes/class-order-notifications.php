<?php
/**
 * OrderNotifications – e-mail vendorovi o nové objednávce s jeho produkty.
 *
 * Spustí se při přechodu objednávky na `processing` (platba přijata). Pro
 * každého vendora v objednávce pošle e-mail s JEHO položkami + výdejnou
 * (pokud Zásilkovna) + odkazem na vendor objednávky. Idempotentní per
 * vendor přes order meta flag.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class OrderNotifications {

	private static ?OrderNotifications $instance = null;

	public static function instance(): OrderNotifications {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'woocommerce_order_status_processing', [ $this, 'notify' ], 20 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'notify' ], 20 );
	}

	public function notify( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Seskup položky podle vendora.
		$by_vendor = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var \WC_Order_Item_Product $item */
			$pid = $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid <= 0 ) {
				continue;
			}
			$by_vendor[ $vid ][] = $item;
		}
		if ( empty( $by_vendor ) ) {
			return;
		}

		$pickup = (string) $order->get_meta( '_nkzmp_packeta_point_name' );

		foreach ( $by_vendor as $vendor_id => $items ) {
			// Idempotence – každý vendor max 1× per objednávka.
			$flag = '_nkzmp_vendor_notified_' . $vendor_id;
			if ( $order->get_meta( $flag ) ) {
				continue;
			}

			$vendor = ( new \NKZMP\Vendor\Repository() )->find( (int) $vendor_id );
			if ( ! $vendor || empty( $vendor['email'] ) ) {
				continue;
			}

			$lines    = [];
			$subtotal = 0.0;
			foreach ( $items as $item ) {
				$t         = (float) $item->get_total();
				$subtotal += $t;
				$lines[]   = sprintf( '  %d× %s — %s', (int) $item->get_quantity(), $item->get_name(), wp_strip_all_tags( wc_price( $t, [ 'currency' => $order->get_currency() ] ) ) );
			}

			$site = (string) get_bloginfo( 'name' );
			$vars = [
				'name'            => (string) $vendor['name'],
				'order_number'    => (string) $order->get_order_number(),
				'order_date'      => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) ) : '',
				'items'           => implode( "\n", $lines ) . ( $pickup !== '' ? "\n\nDoručení na výdejní místo: " . $pickup : '' ),
				'subtotal'        => wp_strip_all_tags( wc_price( $subtotal, [ 'currency' => $order->get_currency() ] ) ),
				'order_admin_url' => wc_get_account_endpoint_url( 'vendor-orders' ),
				'site_name'       => $site,
			];

			$subject = $body = '';
			if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
				$subject = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_order_vendor_subject' ), $vars );
				$body    = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_order_vendor_body' ), $vars );
			}
			if ( $subject === '' ) {
				$subject = sprintf( __( 'Nová objednávka #%s — %s', 'nkz-mp-vendor-dashboard' ), $vars['order_number'], $site );
			}
			if ( $body === '' ) {
				$body = sprintf( "Ahoj %s,\n\nmáš novou objednávku #%s.\n\n%s\n\nCelkem: %s\n\n%s",
					$vars['name'], $vars['order_number'], $vars['items'], $vars['subtotal'], $vars['order_admin_url'] );
			}

			$this->send( (string) $vendor['email'], $subject, $body );
			$order->update_meta_data( $flag, time() );
		}
		$order->save();
	}

	private function send( string $to, string $subject, string $body ): void {
		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			return;
		}
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
