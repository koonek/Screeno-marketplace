<?php
/**
 * Failed_Transfers – hromadný přehled neúspěšných výplat + auto-retry.
 *
 * Proč: transfer prodejci umí selhat z důvodu, který se sám napraví
 * (typicky „insufficient funds" – platforma měla zapnuté automatické payouty
 * a Stripe balance byl prázdný). Bez tohohle by admin musel chybu hledat po
 * jednotlivých objednávkách a každou ručně opakovat.
 *
 * Dvě části:
 *  1. Admin přehled všech failed transferů na jednom místě (NKZ Dashboard).
 *  2. Cron auto-retry pro chyby, které mají smysl opakovat (nedostatek
 *     prostředků / rate limit / dočasný výpadek API). Trvalé chyby
 *     (chybějící capability, neexistující účet) se neopakují – ty chtějí zásah.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Failed_Transfers {

	public const CRON_HOOK   = 'nkv_svs_retry_failed_transfers';
	private const ATTEMPT_META = '_nkv_auto_retry_count';
	private const MAX_ATTEMPTS = 5;

	private static ?Failed_Transfers $instance = null;

	public static function instance(): Failed_Transfers {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( self::CRON_HOOK, [ $this, 'retry_all' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
		add_action( 'nkzmp/v1/admin/dashboard/after', [ $this, 'render_overview' ] );
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * Je chyba dočasná (má smysl ji opakovat)?
	 * Pozn.: „insufficient funds" je dočasná jen ve smyslu „opakuj později" –
	 * admin musí přepnout Stripe na manual payouts, jinak bude selhávat dál.
	 */
	public static function is_retryable( string $error ): bool {
		$e          = strtolower( $error );
		$retryable  = [
			'insufficient funds',
			'rate limit',
			'lock_timeout',
			'api_connection',
			'try again',
			'temporarily unavailable',
		];
		foreach ( $retryable as $needle ) {
			if ( str_contains( $e, $needle ) ) {
				return true;
			}
		}
		return (bool) apply_filters( 'nkv/v1/transfers/is_retryable', false, $error );
	}

	/**
	 * Objednávky s aspoň jedním failed transferem.
	 *
	 * @return array<int,array{order:\WC_Order,records:array}>
	 */
	public static function collect( int $limit = 200 ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$orders = wc_get_orders( [
			'limit'   => $limit,
			'status'  => [ 'processing', 'completed', 'on-hold' ],
			'orderby' => 'date',
			'order'   => 'DESC',
		] );
		$ts  = new Transfer_Service();
		$out = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$failed = array_values( array_filter(
				$ts->get_transfer_records( $order ),
				static fn( $r ) => 'failed' === ( $r['status'] ?? '' )
			) );
			if ( ! empty( $failed ) ) {
				$out[] = [ 'order' => $order, 'records' => $failed ];
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Auto-retry (cron).
	 * ------------------------------------------------------------------- */

	public function retry_all(): void {
		foreach ( self::collect() as $row ) {
			$order = $row['order'];
			foreach ( $row['records'] as $r ) {
				$vendor_id = (int) ( $r['vendor_id'] ?? 0 );
				$error     = (string) ( $r['error'] ?? '' );
				if ( $vendor_id <= 0 || ! self::is_retryable( $error ) ) {
					continue;
				}
				$key      = self::ATTEMPT_META . '_' . $vendor_id;
				$attempts = (int) $order->get_meta( $key );
				if ( $attempts >= self::MAX_ATTEMPTS ) {
					continue; // dál to nemá smysl, čeká na admina
				}
				$order->update_meta_data( $key, $attempts + 1 );
				$order->save();
				self::retry_one( $order, $vendor_id );
			}
		}
	}

	/** Zopakuje transfer pro jednoho prodejce (stejná logika jako tlačítko v objednávce). */
	public static function retry_one( \WC_Order $order, int $vendor_id ): void {
		$ts = new Transfer_Service();
		$ts->bump_retry_attempt( $order, $vendor_id );
		$records = array_values( array_filter(
			$ts->get_transfer_records( $order ),
			static fn( $r ) => ! ( 'failed' === ( $r['status'] ?? '' ) && (int) ( $r['vendor_id'] ?? 0 ) === $vendor_id )
		) );
		$ts->save_transfer_records( $order, $records );
		$ts->maybe_create_transfers( $order->get_id(), false, $vendor_id );
	}

	/* ---------------------------------------------------------------------
	 * Admin přehled.
	 * ------------------------------------------------------------------- */

	public function render_overview(): void {
		$rows = self::collect();

		echo '<div class="nkzmp-admin-card" style="margin-top:24px;padding:20px;background:#fff;border:1px solid #e2e4e7;border-radius:8px;">';
		echo '<h2 style="margin-top:0;">' . esc_html__( '💸 Neúspěšné výplaty prodejcům', 'nkz-woo-stripe-vendor-split' ) . '</h2>';

		if ( empty( $rows ) ) {
			echo '<p style="color:#46b450;">' . esc_html__( 'Žádné neúspěšné transfery. Všichni prodejci dostali své peníze. 👍', 'nkz-woo-stripe-vendor-split' ) . '</p>';
			echo '</div>';
			return;
		}

		// Nejčastější příčina – ukážeme rovnou návod, ať admin nehledá.
		$has_funds_issue = false;
		foreach ( $rows as $row ) {
			foreach ( $row['records'] as $r ) {
				if ( str_contains( strtolower( (string) ( $r['error'] ?? '' ) ), 'insufficient funds' ) ) {
					$has_funds_issue = true;
					break 2;
				}
			}
		}
		if ( $has_funds_issue ) {
			echo '<div class="notice notice-warning inline" style="margin:0 0 14px;"><p><strong>'
				. esc_html__( 'Nedostatek prostředků na Stripe balanci.', 'nkz-woo-stripe-vendor-split' ) . '</strong><br>'
				. esc_html__( 'Platforma má nejspíš zapnuté automatické výplaty – peníze odchází na banku dřív, než z nich stihneme zaplatit prodejce. Přepni ve Stripe výplaty na Manual (Settings → Payouts) a pak dej Opakovat. Marži si vybereš ručně.', 'nkz-woo-stripe-vendor-split' )
				. '</p></div>';
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'Objednávka', 'nkz-woo-stripe-vendor-split' ) . '</th>';
		echo '<th>' . esc_html__( 'Prodejce', 'nkz-woo-stripe-vendor-split' ) . '</th>';
		echo '<th>' . esc_html__( 'Částka', 'nkz-woo-stripe-vendor-split' ) . '</th>';
		echo '<th>' . esc_html__( 'Chyba', 'nkz-woo-stripe-vendor-split' ) . '</th>';
		echo '</tr></thead><tbody>';

		$total = 0.0;
		foreach ( $rows as $row ) {
			$order = $row['order'];
			foreach ( $row['records'] as $r ) {
				$vendor_id = (int) ( $r['vendor_id'] ?? 0 );
				$vendor    = $vendor_id > 0 ? Vendor_Repository::get( $vendor_id ) : null;
				$vname     = $vendor ? (string) $vendor['name'] : ( '#' . $vendor_id );
				$minor     = (int) ( $r['amount_minor'] ?? 0 );
				$currency  = (string) ( $r['currency'] ?? $order->get_currency() );
				$amount    = $minor / 100;
				$total    += $amount;
				printf(
					'<tr><td><a href="%s">#%s</a></td><td>%s</td><td>%s</td><td style="color:#b00020;font-size:12px;">%s</td></tr>',
					esc_url( $order->get_edit_order_url() ),
					esc_html( (string) $order->get_order_number() ),
					esc_html( $vname ),
					esc_html( number_format_i18n( $amount, 2 ) . ' ' . $currency ),
					esc_html( mb_substr( (string) ( $r['error'] ?? '' ), 0, 160 ) )
				);
			}
		}
		echo '</tbody></table>';
		printf(
			'<p style="margin-top:12px;"><strong>%s</strong> %s</p>',
			esc_html__( 'Nevyplaceno celkem:', 'nkz-woo-stripe-vendor-split' ),
			esc_html( number_format_i18n( $total, 2 ) )
		);
		echo '<p class="description">' . esc_html__( 'Dočasné chyby (nedostatek prostředků, rate limit) se každou hodinu zkusí samy, max 5×. Ostatní vyžadují zásah – otevři objednávku a použij „Opakovat neúspěšné".', 'nkz-woo-stripe-vendor-split' ) . '</p>';
		echo '</div>';
	}
}
