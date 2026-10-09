<?php
/**
 * WithdrawalRefund – vrácení peněz u odstoupení jedním krokem.
 *
 * Ruční cesta byla křehká: refundace v WooCommerce musela jít po
 * položkách (jinak nevznikne dobropis), u objednávky placené poukazem
 * šlo na kartu vrátit jen část a zbytek admin musel řešit zvlášť, a když
 * už prodejce výplatu dostal, nikdo mu ji automaticky nestáhl.
 *
 * Tady admin u odstoupení klikne „Vrátit peníze", zkontroluje rozpočet
 * (zboží, poštovné, servisní poplatek) a potvrdí:
 *  - vytvoří se refundace po položkách → dobropis vznikne sám,
 *  - na kartu se vrátí, co bylo zaplaceno kartou; část zaplacená
 *    poukazem se vrátí jako nový poukaz (stejný prostředek, jakým
 *    zákazník platil),
 *  - pokud už prodejce výplatu dostal, stáhne se mu podíl za vrácené
 *    zboží (podle podmínek jdou náklady vrácení za prodejcem),
 *  - odstoupení se označí jako vyřízené.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class WithdrawalRefund {

	public const PAGE = 'nkzmp-withdrawal-refund';

	private static ?WithdrawalRefund $instance = null;

	public static function instance(): WithdrawalRefund {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_page' ] );
		add_action( 'admin_post_nkzmp_withdrawal_refund', [ $this, 'handle' ] );
	}

	/** Skrytá admin stránka s potvrzením (bez položky v menu). */
	public function register_page(): void {
		add_submenu_page( '', __( 'Vrácení peněz – odstoupení', 'nkz-mp-storefront' ), '', 'manage_woocommerce', self::PAGE, [ $this, 'render' ] );
	}

	public static function url( \WC_Order $order, int $vendor_id ): string {
		return add_query_arg(
			[ 'page' => self::PAGE, 'order_id' => $order->get_id(), 'vendor_id' => $vendor_id ],
			admin_url( 'admin.php' )
		);
	}

	/* ============================================================ výpočet */

	/**
	 * Rozpočet vrácení pro odstoupení u prodejce.
	 *
	 * @return array{items:array,items_total:float,shipping_item:int,shipping_max:float,shipping_default:float,fee_item:int,fee_amount:float,fee_default:bool,vendor_all:bool,order_all:bool}
	 */
	public static function plan( \WC_Order $order, int $vendor_id ): array {
		$record = Withdrawal::record( $order, $vendor_id ) ?? [];
		$chosen = (array) ( $record['items'] ?? [] );
		$items  = [];
		$sum    = 0.0;

		foreach ( $chosen as $item_id => $qty ) {
			$item = $order->get_item( (int) $item_id );
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$item_qty = max( 1, (int) $item->get_quantity() );
			$qty      = min( (int) $qty, $item_qty - abs( (int) $order->get_qty_refunded_for_item( (int) $item_id ) ) );
			if ( $qty <= 0 ) {
				continue;
			}
			$ratio = $qty / $item_qty;
			$net   = round( (float) $item->get_total() * $ratio, 2 );
			$taxes = [];
			foreach ( (array) ( $item->get_taxes()['total'] ?? [] ) as $rate_id => $tax ) {
				$taxes[ $rate_id ] = round( (float) $tax * $ratio, 2 );
			}
			$gross    = $net + array_sum( $taxes );
			$items[]  = [ 'id' => (int) $item_id, 'name' => $item->get_name(), 'qty' => $qty, 'net' => $net, 'taxes' => $taxes, 'gross' => $gross ];
			$sum     += $gross;
		}

		// Vrací zákazník všechno, co od prodejce měl (a od kterého jde odstoupit)?
		$vendor_all = true;
		$order_all  = true;
		$withdrawn  = [];
		foreach ( (array) $order->get_meta( Withdrawal::META ) as $vid => $rec ) {
			foreach ( (array) ( $rec['items'] ?? [] ) as $iid => $q ) {
				$withdrawn[ (int) $iid ] = (int) $q;
			}
		}
		foreach ( $order->get_items( 'line_item' ) as $iid => $it ) {
			if ( ! $it instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$full = ( $withdrawn[ (int) $iid ] ?? 0 ) >= (int) $it->get_quantity();
			if ( ! $full ) {
				$order_all = false;
				if ( self::item_vendor( $it ) === $vendor_id ) {
					$vendor_all = false;
				}
			}
		}

		// Poštovné: při odstoupení od celé zásilky se vrací i běžná doprava.
		[ $ship_item, $ship_max ] = self::shipping_line( $order );
		$ship_default = $vendor_all ? min( $ship_max, self::vendor_shipping_share( $order, $vendor_id, $ship_max ) ) : 0.0;

		// Servisní poplatek – je za celou objednávku, takže ho navrhujeme
		// vrátit jen když se odstupuje od celé objednávky.
		[ $fee_item, $fee_amount ] = self::fee_line( $order );

		return [
			'items'            => $items,
			'items_total'      => round( $sum, 2 ),
			'shipping_item'    => $ship_item,
			'shipping_max'     => $ship_max,
			'shipping_default' => round( $ship_default, 2 ),
			'fee_item'         => $fee_item,
			'fee_amount'       => $fee_amount,
			'fee_default'      => $order_all && $fee_amount > 0,
			'vendor_all'       => $vendor_all,
			'order_all'        => $order_all,
		];
	}

	private static function item_vendor( \WC_Order_Item_Product $item ): int {
		$pid = (int) $item->get_product_id();
		$v   = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		return $v > 0 ? $v : (int) get_post_meta( $pid, '_nkv_vendor_id', true );
	}

	/** Zásilkový řádek (ne „doprava dohodou") a kolik z něj ještě nebylo vráceno. */
	private static function shipping_line( \WC_Order $order ): array {
		foreach ( $order->get_items( 'shipping' ) as $id => $ship ) {
			$total = (float) $ship->get_total() + (float) $ship->get_total_tax();
			if ( $total <= 0 ) {
				continue;
			}
			$refunded = abs( (float) $order->get_total_refunded_for_item( (int) $id, 'shipping' ) );
			return [ (int) $id, max( 0.0, round( $total - $refunded, 2 ) ) ];
		}
		return [ 0, 0.0 ];
	}

	/** Podíl prodejce na poštovném (každý prodejce posílá svůj balík). */
	private static function vendor_shipping_share( \WC_Order $order, int $vendor_id, float $max ): float {
		foreach ( $order->get_items( 'shipping' ) as $ship ) {
			$json = (string) $ship->get_meta( '_nkzmp_vendor_breakdown' );
			$map  = $json !== '' ? json_decode( $json, true ) : null;
			if ( is_array( $map ) && isset( $map[ (string) $vendor_id ] ) ) {
				return (float) $map[ (string) $vendor_id ];
			}
		}
		// Jen jeden prodejce posílá → celé poštovné je jeho.
		$vendors = [];
		foreach ( $order->get_items( 'line_item' ) as $it ) {
			if ( $it instanceof \WC_Order_Item_Product && ( ! $it->get_product() || $it->get_product()->needs_shipping() ) ) {
				$vendors[ self::item_vendor( $it ) ] = true;
			}
		}
		if ( count( $vendors ) <= 1 ) {
			return $max;
		}
		// Víc prodejců bez rozpisu → odhad podle aktuální sazby prodejce.
		if ( class_exists( \NKZMP\Shipping\Rate::class ) ) {
			$products = [];
			foreach ( $order->get_items( 'line_item' ) as $it ) {
				if ( $it instanceof \WC_Order_Item_Product && self::item_vendor( $it ) === $vendor_id && $it->get_product() ) {
					$products[] = $it->get_product();
				}
			}
			$pkg = [ 'destination' => [ 'country' => (string) $order->get_shipping_country() ] ];
			return min( $max, \NKZMP\Shipping\Rate::vendor_cost_for_package( $vendor_id, $products, $pkg ) );
		}
		return 0.0;
	}

	private static function fee_line( \WC_Order $order ): array {
		$label = (string) apply_filters( 'nkzmp/v1/platform_fee/label', __( 'Servisní poplatek', 'nkz-mp-platform-fee' ) );
		foreach ( $order->get_items( 'fee' ) as $id => $fee ) {
			if ( $fee->get_name() !== $label ) {
				continue;
			}
			$total    = (float) $fee->get_total() + (float) $fee->get_total_tax();
			$refunded = abs( (float) $order->get_total_refunded_for_item( (int) $id, 'fee' ) );
			return [ (int) $id, max( 0.0, round( $total - $refunded, 2 ) ) ];
		}
		return [ 0, 0.0 ];
	}

	/** Kolik ještě jde vrátit na kartu (zaplaceno kartou minus už vrácené). */
	public static function card_refundable( \WC_Order $order ): float {
		return max( 0.0, round( (float) $order->get_total() - (float) $order->get_total_refunded(), 2 ) );
	}

	/* ============================================================ stránka */

	public function render(): void {
		$order_id  = absint( $_GET['order_id'] ?? 0 );
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		$order     = wc_get_order( $order_id );
		$record    = $order instanceof \WC_Order ? Withdrawal::record( $order, $vendor_id ) : null;

		echo '<div class="wrap"><h1>' . esc_html__( 'Vrácení peněz – odstoupení od smlouvy', 'nkz-mp-storefront' ) . '</h1>';
		if ( ! $order || ! $record ) {
			echo '<p>' . esc_html__( 'Odstoupení nenalezeno.', 'nkz-mp-storefront' ) . '</p></div>';
			return;
		}
		if ( ( $record['status'] ?? 'open' ) === 'resolved' ) {
			echo '<p>' . esc_html__( 'Toto odstoupení je už vyřízené.', 'nkz-mp-storefront' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p></div>', esc_url( $order->get_edit_order_url() ), esc_html__( 'Zpět na objednávku', 'nkz-mp-storefront' ) );
			return;
		}

		$plan = self::plan( $order, $vendor_id );
		$card = self::card_refundable( $order );

		printf(
			'<p>%s</p>',
			esc_html( sprintf(
				/* translators: 1: číslo objednávky, 2: prodejce, 3: datum */
				__( 'Objednávka #%1$s · prodejce %2$s · odstoupení %3$s', 'nkz-mp-storefront' ),
				$order->get_order_number(),
				get_the_title( $vendor_id ) ?: ( '#' . $vendor_id ),
				wp_date( 'j. n. Y H:i', (int) $record['at'] )
			) )
		);

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="max-width:720px;">';
		echo '<input type="hidden" name="action" value="nkzmp_withdrawal_refund" />';
		echo '<input type="hidden" name="order_id" value="' . (int) $order_id . '" />';
		echo '<input type="hidden" name="vendor_id" value="' . (int) $vendor_id . '" />';
		wp_nonce_field( 'nkzmp_withdrawal_refund_' . $order_id . '_' . $vendor_id );

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Položka', 'nkz-mp-storefront' ) . '</th><th>' . esc_html__( 'Ks', 'nkz-mp-storefront' ) . '</th><th style="text-align:right">' . esc_html__( 'Vrátit', 'nkz-mp-storefront' ) . '</th></tr></thead><tbody>';
		foreach ( $plan['items'] as $it ) {
			printf( '<tr><td>%s</td><td>%d</td><td style="text-align:right">%s</td></tr>', esc_html( $it['name'] ), (int) $it['qty'], wp_kses_post( wc_price( $it['gross'] ) ) );
		}
		if ( $plan['shipping_item'] ) {
			printf(
				'<tr><td>%s<br><span class="description">%s</span></td><td></td><td style="text-align:right"><input type="number" step="0.01" min="0" max="%s" name="shipping" value="%s" style="width:110px" data-nkzmp-part /> Kč</td></tr>',
				esc_html__( 'Poštovné', 'nkz-mp-storefront' ),
				esc_html( $plan['vendor_all']
					? __( 'Zákazník vrací celou zásilku od prodejce – běžné poštovné se ze zákona vrací.', 'nkz-mp-storefront' )
					: __( 'Zákazník vrací jen část zásilky – poštovné se nevrací.', 'nkz-mp-storefront' ) ),
				esc_attr( (string) $plan['shipping_max'] ),
				esc_attr( (string) $plan['shipping_default'] )
			);
		}
		if ( $plan['fee_item'] && $plan['fee_amount'] > 0 ) {
			printf(
				'<tr><td><label><input type="checkbox" name="fee" value="1" %s data-nkzmp-fee="%s" /> %s</label><br><span class="description">%s</span></td><td></td><td style="text-align:right">%s</td></tr>',
				checked( $plan['fee_default'], true, false ),
				esc_attr( (string) $plan['fee_amount'] ),
				esc_html__( 'Servisní poplatek', 'nkz-mp-storefront' ),
				esc_html__( 'Navrženo při odstoupení od celé objednávky. Jestli se vrací vždy, má potvrdit právník.', 'nkz-mp-storefront' ),
				wp_kses_post( wc_price( $plan['fee_amount'] ) )
			);
		}
		printf(
			'</tbody><tfoot><tr><th colspan="2">%s</th><th style="text-align:right" data-nkzmp-total data-items="%s">%s</th></tr></tfoot></table>',
			esc_html__( 'Celkem k vrácení', 'nkz-mp-storefront' ),
			esc_attr( (string) $plan['items_total'] ),
			esc_html( number_format( $plan['items_total'] + $plan['shipping_default'] + ( $plan['fee_default'] ? $plan['fee_amount'] : 0 ), 2, ',', ' ' ) . ' Kč' )
		);

		echo '<p>' . esc_html( sprintf(
			/* translators: %s: částka */
			__( 'Na kartu lze vrátit nejvýš %s (kolik bylo zaplaceno kartou a ještě nevráceno). Případný zbytek dostane zákazník jako nový dárkový poukaz – zaplatil ho poukazem, takže se vrací stejnou cestou.', 'nkz-mp-storefront' ),
			wp_strip_all_tags( wc_price( $card ) )
		) ) . '</p>';
		echo '<p><label><input type="checkbox" name="restock" value="1" checked /> ' . esc_html__( 'Vrátit zboží na sklad', 'nkz-mp-storefront' ) . '</label></p>';
		echo '<p class="description">' . esc_html__( 'Pokud už prodejce výplatu za tuto objednávku dostal, stáhne se mu podíl za vrácené zboží. Dobropis se vystaví a pošle zákazníkovi automaticky.', 'nkz-mp-storefront' ) . '</p>';
		submit_button( __( 'Vrátit peníze', 'nkz-mp-storefront' ), 'primary', 'submit', true, [ 'onclick' => "return confirm('" . esc_js( __( 'Opravdu vrátit peníze? Tohle nejde vzít zpět.', 'nkz-mp-storefront' ) ) . "');" ] );
		echo '</form>';
		?>
		<script>
		(function () {
			var total = document.querySelector('[data-nkzmp-total]');
			if (!total) { return; }
			function upd() {
				var t = parseFloat(total.dataset.items) || 0;
				var s = document.querySelector('[data-nkzmp-part]'); if (s) { t += parseFloat(s.value) || 0; }
				var f = document.querySelector('[data-nkzmp-fee]'); if (f && f.checked) { t += parseFloat(f.dataset.nkzmpFee) || 0; }
				total.textContent = t.toFixed(2).replace('.', ',') + ' Kč';
			}
			document.addEventListener('input', upd); document.addEventListener('change', upd);
		})();
		</script>
		<?php
		echo '</div>';
	}

	/* ============================================================ provedení */

	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-storefront' ) );
		}
		$order_id  = absint( $_POST['order_id'] ?? 0 );
		$vendor_id = absint( $_POST['vendor_id'] ?? 0 );
		check_admin_referer( 'nkzmp_withdrawal_refund_' . $order_id . '_' . $vendor_id );

		$order  = wc_get_order( $order_id );
		$record = $order instanceof \WC_Order ? Withdrawal::record( $order, $vendor_id ) : null;
		if ( ! $order || ! $record || ( $record['status'] ?? 'open' ) === 'resolved' ) {
			wp_safe_redirect( $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url() );
			exit;
		}

		// Položky počítáme znovu na serveru – z formuláře bereme jen volby.
		$plan     = self::plan( $order, $vendor_id );
		$shipping = $plan['shipping_item'] ? min( $plan['shipping_max'], max( 0.0, (float) ( $_POST['shipping'] ?? 0 ) ) ) : 0.0;
		$with_fee = ! empty( $_POST['fee'] ) && $plan['fee_item'] && $plan['fee_amount'] > 0;
		$restock  = ! empty( $_POST['restock'] );

		$lines = [];
		foreach ( $plan['items'] as $it ) {
			$lines[ $it['id'] ] = [ 'qty' => $it['qty'], 'refund_total' => $it['net'], 'refund_tax' => $it['taxes'] ];
		}
		if ( $shipping > 0 ) {
			$lines[ $plan['shipping_item'] ] = self::proportional( $order->get_item( $plan['shipping_item'] ), $shipping );
		}
		if ( $with_fee ) {
			$lines[ $plan['fee_item'] ] = self::proportional( $order->get_item( $plan['fee_item'] ), $plan['fee_amount'] );
		}

		$total = round( $plan['items_total'] + $shipping + ( $with_fee ? $plan['fee_amount'] : 0 ), 2 );
		if ( $total <= 0 ) {
			wp_safe_redirect( $order->get_edit_order_url() );
			exit;
		}
		$card    = min( $total, self::card_refundable( $order ) );
		$voucher = round( $total - $card, 2 );

		$refund = wc_create_refund( [
			'amount'         => $card,
			'reason'         => __( 'Odstoupení od smlouvy', 'nkz-mp-storefront' ),
			'order_id'       => $order_id,
			'line_items'     => $lines,
			'refund_payment' => $card > 0,
			'restock_items'  => $restock,
		] );
		if ( is_wp_error( $refund ) ) {
			wp_die( esc_html( sprintf( __( 'Vrácení peněz se nepovedlo: %s', 'nkz-mp-storefront' ), $refund->get_error_message() ) ), '', [ 'back_link' => true ] );
		}

		$order = wc_get_order( $order_id ); // čerstvá po refundaci

		// Část zaplacená poukazem → nový poukaz.
		$credit_code = '';
		if ( $voucher > 0 && class_exists( Voucher::class ) ) {
			$credit_code = Voucher::issue_credit( $order, $voucher );
		}

		// Prodejce už dostal výplatu → stáhnout mu podíl za vrácené zboží.
		$reversal_note = self::reverse_vendor( $order, $refund, $vendor_id );

		$all = $order->get_meta( Withdrawal::META );
		if ( is_array( $all ) && isset( $all[ $vendor_id ] ) ) {
			$all[ $vendor_id ]['status']      = 'resolved';
			$all[ $vendor_id ]['resolved_at'] = time();
			$all[ $vendor_id ]['refund_id']   = $refund->get_id();
			$all[ $vendor_id ]['refunded']    = [ 'card' => $card, 'voucher' => $voucher, 'voucher_code' => $credit_code ];
			$order->update_meta_data( Withdrawal::META, $all );
		}
		$order->add_order_note( sprintf(
			/* translators: 1: částka kartou, 2: částka poukazem, 3: poznámka */
			__( 'Odstoupení vyřízeno: vráceno na kartu %1$s%2$s.%3$s', 'nkz-mp-storefront' ),
			wp_strip_all_tags( wc_price( $card ) ),
			$voucher > 0 ? sprintf( __( ', poukazem %1$s (%2$s)', 'nkz-mp-storefront' ), wp_strip_all_tags( wc_price( $voucher ) ), $credit_code ) : '',
			$reversal_note !== '' ? ' ' . $reversal_note : ''
		) );
		$order->save();

		$open = get_option( Withdrawal::OPEN_OPTION, [] );
		if ( is_array( $open ) ) {
			unset( $open[ $order_id . ':' . $vendor_id ] );
			update_option( Withdrawal::OPEN_OPTION, $open, false );
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/** Refundace řádku dopravy/poplatku o danou částku (vč. poměrné daně). */
	private static function proportional( $item, float $gross ): array {
		$total = (float) $item->get_total();
		$tax   = (float) $item->get_total_tax();
		$all   = $total + $tax;
		$ratio = $all > 0 ? $gross / $all : 0;
		$taxes = [];
		foreach ( (array) ( $item->get_taxes()['total'] ?? [] ) as $rate_id => $t ) {
			$taxes[ $rate_id ] = round( (float) $t * $ratio, 2 );
		}
		return [ 'qty' => 0, 'refund_total' => round( $total * $ratio, 2 ), 'refund_tax' => $taxes ];
	}

	/**
	 * Stáhne prodejci podíl za vrácené zboží, pokud už výplatu dostal.
	 * Vrací poznámku do objednávky.
	 */
	private static function reverse_vendor( \WC_Order $order, $refund, int $vendor_id ): string {
		if ( ! class_exists( \NKVSVS\Refund_Service::class ) || ! class_exists( \NKVSVS\Transfer_Service::class ) || ! $refund instanceof \WC_Order_Refund ) {
			return '';
		}
		$done = false;
		foreach ( \NKVSVS\Transfer_Service::instance()->get_transfer_records( $order ) as $rec ) {
			if ( (int) $rec['vendor_id'] === $vendor_id && $rec['status'] === 'completed' && ! empty( $rec['transfer_id'] ) ) {
				$done = true;
			}
		}
		if ( ! $done ) {
			return __( 'Prodejce výplatu ještě nedostal – zůstává pozastavená.', 'nkz-mp-storefront' );
		}
		$suggest = \NKVSVS\Refund_Service::suggested_reversal_minor( $order, $refund );
		$amount  = (int) ( $suggest[ $vendor_id ] ?? 0 );
		if ( $amount <= 0 ) {
			return '';
		}
		try {
			\NKVSVS\Refund_Service::instance()->reverse( $order, $vendor_id, $amount, 'withdrawal_refund_' . $refund->get_id() );
			return sprintf( __( 'Prodejci stažen podíl %s z výplaty.', 'nkz-mp-storefront' ), wp_strip_all_tags( wc_price( $amount / 100 ) ) );
		} catch ( \Throwable $e ) {
			return sprintf( __( 'POZOR: stažení podílu od prodejce selhalo (%s) – je potřeba ho vymáhat ručně.', 'nkz-mp-storefront' ), $e->getMessage() );
		}
	}
}
