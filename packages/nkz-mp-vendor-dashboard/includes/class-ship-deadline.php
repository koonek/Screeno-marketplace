<?php
/**
 * ShipDeadline – „čas na odeslání" u objednávek.
 *
 * Jedna služba pro tři věci:
 *  1. E-mail ZÁKAZNÍKOVI při zaplacení: „prodejce zabalí a odešle do <datum>".
 *  2. CRON připomínky PRODEJCI: den před koncem lhůty + při prošvihnutí.
 *  3. Admin PŘEHLED prošvihnutých objednávek (kdo nestíhá expedovat).
 *
 * Lhůta běží od zaplacení objednávky (fallback vytvoření) a končí vytvořením
 * Packeta štítku (= odesláno). Vše čistě informativní – žádná automatická akce
 * s penězi. Detekce odeslání čte jen order meta (žádné API volání v cronu).
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ShipDeadline {

	public const CRON_HOOK      = 'nkzmp_ship_deadline_scan';
	private const CUSTOMER_FLAG = '_nkzmp_ship_customer_notified';
	private const REMIND_FLAG   = '_nkzmp_ship_remind_';   // + vendor_id (den předem)
	private const OVERDUE_FLAG  = '_nkzmp_ship_overdue_';  // + vendor_id (po termínu)

	private static ?ShipDeadline $instance = null;

	public static function instance(): ShipDeadline {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// 1) E-mail zákazníkovi po zaplacení (za OrderNotifications, prio 25).
		add_action( 'woocommerce_order_status_processing', [ $this, 'notify_customer' ], 25 );

		// 2) Cron připomínky prodejci.
		add_action( self::CRON_HOOK, [ $this, 'scan' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', self::CRON_HOOK );
		}

		// 3) Admin přehled prošvihnutých pod hlavním dashboardem.
		add_action( 'nkzmp/v1/admin/dashboard/after', [ $this, 'render_admin_overview' ] );
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Sdílená logika lhůty (používá i OrdersView).
	 * ------------------------------------------------------------------- */

	/** Lhůta na odeslání ve dnech (filtrovatelné). */
	public static function days( ?\WC_Order $order = null ): int {
		return max( 0, (int) apply_filters( 'nkzmp/v1/dashboard/ship_deadline_days', 3, $order ) );
	}

	/** Čas zaplacení (fallback vytvoření), nebo 0. */
	public static function paid_ts( \WC_Order $order ): int {
		$d = $order->get_date_paid() ?: $order->get_date_created();
		return $d ? (int) $d->getTimestamp() : 0;
	}

	/** Deadline timestamp = zaplaceno + N dní. */
	public static function deadline_ts( \WC_Order $order ): int {
		$paid = self::paid_ts( $order );
		return $paid > 0 ? $paid + self::days( $order ) * DAY_IN_SECONDS : 0;
	}

	/** Distinct vendor ID v objednávce. */
	public static function order_vendor_ids( \WC_Order $order ): array {
		$ids = [];
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid = $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid > 0 ) {
				$ids[ $vid ] = true;
			}
		}
		return array_keys( $ids );
	}

	/** Má daný prodejce v objednávce fyzické (odesílatelné) zboží? */
	public static function vendor_needs_shipping( \WC_Order $order, int $vendor_id ): bool {
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid = $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid !== $vendor_id ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && $product->needs_shipping() ) {
				return true;
			}
		}
		return false;
	}

	/** Odeslal už prodejce svou část? (Packeta štítek s barcode.) */
	public static function is_vendor_dispatched( \WC_Order $order, int $vendor_id ): bool {
		if ( ! class_exists( \NKZMP\Packeta\LabelService::class ) ) {
			return false;
		}
		$packet = \NKZMP\Packeta\LabelService::instance()->get_packet( $order, $vendor_id );
		return is_array( $packet ) && ! empty( $packet['barcode'] );
	}

	/**
	 * Otevřené, fyzické, ještě neodeslané „prodejce v objednávce".
	 *
	 * @return array<int,array{order:\WC_Order,vendor_id:int,deadline:int}>
	 */
	public static function collect_pending( int $limit = 200 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$orders = wc_get_orders( [
			'limit'   => $limit,
			'status'  => [ 'processing', 'on-hold' ],
			'orderby' => 'date',
			'order'   => 'DESC',
		] );
		$rows = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$deadline = self::deadline_ts( $order );
			if ( $deadline <= 0 ) {
				continue;
			}
			foreach ( self::order_vendor_ids( $order ) as $vid ) {
				if ( ! self::vendor_needs_shipping( $order, $vid ) ) {
					continue;
				}
				if ( self::is_vendor_dispatched( $order, $vid ) ) {
					continue;
				}
				$rows[] = [ 'order' => $order, 'vendor_id' => $vid, 'deadline' => $deadline ];
			}
		}
		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * 1) E-mail zákazníkovi.
	 * ------------------------------------------------------------------- */

	public function notify_customer( int $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		if ( $order->get_meta( self::CUSTOMER_FLAG ) ) {
			return; // už posláno
		}
		$email = $order->get_billing_email();
		if ( ! is_email( $email ) ) {
			return;
		}
		// Jen když má objednávka vůbec nějakého prodejce s fyzickým zbožím.
		$has_physical = false;
		foreach ( self::order_vendor_ids( $order ) as $vid ) {
			if ( self::vendor_needs_shipping( $order, $vid ) ) {
				$has_physical = true;
				break;
			}
		}
		if ( ! $has_physical ) {
			return;
		}

		$deadline = self::deadline_ts( $order );
		if ( $deadline <= 0 ) {
			return;
		}
		$site          = (string) get_bloginfo( 'name' );
		$deadline_str  = wp_date( 'j. n. Y', $deadline );
		$customer_name = trim( (string) $order->get_billing_first_name() );

		$subject = (string) apply_filters(
			'nkzmp/v1/ship/customer_subject',
			sprintf( __( 'Tvoje objednávka #%1$s je u prodejce — %2$s', 'nkz-mp-vendor-dashboard' ), $order->get_order_number(), $site ),
			$order
		);
		$body = (string) apply_filters(
			'nkzmp/v1/ship/customer_body',
			sprintf(
				/* translators: 1: jméno, 2: číslo, 3: datum, 4: název webu */
				__( "Ahoj %1\$s,\n\ntvoji objednávku #%2\$s jsme předali prodejci. Zabalí ji a předá k odeslání nejpozději do %3\$s. Jakmile ji odešle, dáme ti vědět.\n\nDíky, že nakupuješ na %4\$s.", 'nkz-mp-vendor-dashboard' ),
				$customer_name !== '' ? $customer_name : __( 'ahoj', 'nkz-mp-vendor-dashboard' ),
				$order->get_order_number(),
				$deadline_str,
				$site
			),
			$order,
			$deadline
		);

		$this->send( $email, $subject, $body );
		$order->update_meta_data( self::CUSTOMER_FLAG, time() );
		$order->save();
	}

	/* ---------------------------------------------------------------------
	 * 2) Cron připomínky prodejci.
	 * ------------------------------------------------------------------- */

	public function scan(): void {
		$now  = time();
		$rows = self::collect_pending();
		foreach ( $rows as $row ) {
			$order    = $row['order'];
			$vid      = (int) $row['vendor_id'];
			$deadline = (int) $row['deadline'];

			if ( $now >= $deadline ) {
				$flag = self::OVERDUE_FLAG . $vid;
				if ( ! $order->get_meta( $flag ) ) {
					$this->email_vendor( $order, $vid, 'overdue', $deadline );
					$order->update_meta_data( $flag, time() );
					$order->save();
				}
			} elseif ( $now >= $deadline - DAY_IN_SECONDS ) {
				$flag = self::REMIND_FLAG . $vid;
				if ( ! $order->get_meta( $flag ) ) {
					$this->email_vendor( $order, $vid, 'soon', $deadline );
					$order->update_meta_data( $flag, time() );
					$order->save();
				}
			}
		}
	}

	private function email_vendor( \WC_Order $order, int $vendor_id, string $mode, int $deadline ): void {
		$vendor = ( new \NKZMP\Vendor\Repository() )->find( $vendor_id );
		if ( ! $vendor || empty( $vendor['email'] ) ) {
			return;
		}
		$site         = (string) get_bloginfo( 'name' );
		$vendor_name  = (string) $vendor['name'];
		$deadline_str = wp_date( 'j. n. Y H:i', $deadline );
		$orders_url   = wc_get_account_endpoint_url( 'vendor-orders' );
		$num          = $order->get_order_number();

		if ( $mode === 'overdue' ) {
			$subject = sprintf( __( '⚠️ Objednávka #%1$s po termínu odeslání — %2$s', 'nkz-mp-vendor-dashboard' ), $num, $site );
			$body    = sprintf(
				/* translators: 1: jméno, 2: číslo, 3: datum, 4: odkaz */
				__( "Ahoj %1\$s,\n\nobjednávka #%2\$s měla být odeslána do %3\$s, ale zatím pro ni nemáme štítek Zásilkovny. Odešli ji prosím co nejdřív, ať kupující nečeká a výplata se ti nezdrží.\n\nObjednávky: %4\$s", 'nkz-mp-vendor-dashboard' ),
				$vendor_name, $num, $deadline_str, $orders_url
			);
		} else {
			$subject = sprintf( __( '⏳ Připomínka: odešli objednávku #%1$s — %2$s', 'nkz-mp-vendor-dashboard' ), $num, $site );
			$body    = sprintf(
				/* translators: 1: jméno, 2: číslo, 3: datum, 4: odkaz */
				__( "Ahoj %1\$s,\n\npřipomínáme objednávku #%2\$s — lhůta na odeslání končí %3\$s. Vytvoř prosím štítek Zásilkovny a předej zásilku včas.\n\nObjednávky: %4\$s", 'nkz-mp-vendor-dashboard' ),
				$vendor_name, $num, $deadline_str, $orders_url
			);
		}
		$subject = (string) apply_filters( 'nkzmp/v1/ship/vendor_subject', $subject, $mode, $order, $vendor_id );
		$body    = (string) apply_filters( 'nkzmp/v1/ship/vendor_body', $body, $mode, $order, $vendor_id );

		$this->send( (string) $vendor['email'], $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * 3) Admin přehled prošvihnutých.
	 * ------------------------------------------------------------------- */

	public function render_admin_overview(): void {
		$now  = time();
		$rows = array_filter( self::collect_pending(), static fn( $r ) => $now >= (int) $r['deadline'] );
		usort( $rows, static fn( $a, $b ) => (int) $a['deadline'] <=> (int) $b['deadline'] );

		echo '<div class="nkzmp-admin-card" style="margin-top:24px;padding:20px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( '⚠️ Po termínu odeslání', 'nkz-mp-vendor-dashboard' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p style="color:#46b450;">' . esc_html__( 'Nic nečeká po termínu. Všichni prodejci stíhají expedovat. 👍', 'nkz-mp-vendor-dashboard' ) . '</p>';
			echo '</div>';
			return;
		}

		echo '<p style="color:rgba(0,0,0,.6);">' . esc_html__( 'Objednávky, které měly být už odeslané (chybí štítek Zásilkovny). Řazeno od nejstaršího termínu.', 'nkz-mp-vendor-dashboard' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Objednávka', 'nkz-mp-vendor-dashboard' ) . '</th>';
		echo '<th>' . esc_html__( 'Prodejce', 'nkz-mp-vendor-dashboard' ) . '</th>';
		echo '<th>' . esc_html__( 'Termín byl', 'nkz-mp-vendor-dashboard' ) . '</th>';
		echo '<th>' . esc_html__( 'Po termínu', 'nkz-mp-vendor-dashboard' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$order    = $r['order'];
			$vid      = (int) $r['vendor_id'];
			$deadline = (int) $r['deadline'];
			$vendor   = ( new \NKZMP\Vendor\Repository() )->find( $vid );
			$vname    = $vendor ? (string) $vendor['name'] : ( '#' . $vid );
			printf(
				'<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td style="color:#b00020;font-weight:600;">%s</td></tr>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( (string) $order->get_order_number() ),
				esc_html( $vname ),
				esc_html( wp_date( 'j. n. Y H:i', $deadline ) ),
				esc_html( human_time_diff( $deadline, $now ) )
			);
		}
		echo '</tbody></table>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------- */

	private function send( string $to, string $subject, string $body ): void {
		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			return;
		}
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
