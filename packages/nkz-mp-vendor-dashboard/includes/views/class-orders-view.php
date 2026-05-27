<?php
/**
 * OrdersView – objednávky obsahující produkty tohoto vendora.
 *
 * Scan posledních 80 objednávek, filtr na ty s vendor produkty. Pro každou
 * zobrazí jen vendorovy položky + jejich mezisoučet (ne cizí položky).
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

defined( 'ABSPATH' ) || exit;

final class OrdersView {

	public static function render( array $vendor ): void {
		$vendor_id = (int) $vendor['id'];
		$orders    = self::vendor_orders( $vendor_id, 80 );

		?>
		<div class="nkzmp-vd nkzmp-vd-orders">

			<header class="nkzmp-vd-section-head">
				<h1><?php esc_html_e( 'Moje objednávky', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta"><?php esc_html_e( 'Objednávky, které obsahují tvoje produkty. Vidíš jen své položky.', 'nkz-mp-vendor-dashboard' ); ?></p>
			</header>

			<?php if ( isset( $_GET['nkzmp_packeta_err'] ) ) : ?>
				<div class="nkzmp-vd-form-error"><strong><?php esc_html_e( 'Štítek Zásilkovny:', 'nkz-mp-vendor-dashboard' ); ?></strong> <?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nkzmp_packeta_err'] ) ) ); ?></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['nkzmp_packeta_msg'] ) ) : ?>
				<div class="nkzmp-vd-flash nkzmp-vd-flash--success"><div class="icon">✓</div><div><strong><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['nkzmp_packeta_msg'] ) ) ); ?></strong></div></div>
			<?php endif; ?>

			<?php if ( empty( $orders ) ) : ?>
				<div class="nkzmp-vd-products-empty">
					<div class="nkzmp-vd-products-empty-art">○</div>
					<h2><?php esc_html_e( 'Zatím žádné objednávky', 'nkz-mp-vendor-dashboard' ); ?></h2>
					<p><?php esc_html_e( 'Až si někdo koupí tvůj produkt, objednávka se objeví tady.', 'nkz-mp-vendor-dashboard' ); ?></p>
				</div>
			<?php else : ?>
				<div class="nkzmp-vd-order-list">
					<?php foreach ( $orders as $o ) : ?>
						<article class="nkzmp-vd-order">
							<header class="nkzmp-vd-order-head">
								<div>
									<span class="nkzmp-vd-order-num">#<?php echo esc_html( (string) $o['number'] ); ?></span>
									<span class="nkzmp-vd-order-date"><?php echo esc_html( $o['date'] ); ?></span>
								</div>
								<span class="nkzmp-vd-order-status nkzmp-vd-ostatus--<?php echo esc_attr( $o['status'] ); ?>"><?php echo esc_html( $o['status_label'] ); ?></span>
							</header>

							<ul class="nkzmp-vd-order-items">
								<?php foreach ( $o['items'] as $line ) : ?>
									<li>
										<span class="nkzmp-vd-oi-qty"><?php echo (int) $line['qty']; ?>×</span>
										<span class="nkzmp-vd-oi-name"><?php echo esc_html( $line['name'] ); ?></span>
										<span class="nkzmp-vd-oi-total"><?php echo wp_kses_post( $line['total'] ); ?></span>
									</li>
								<?php endforeach; ?>
							</ul>

							<footer class="nkzmp-vd-order-foot">
								<span><?php esc_html_e( 'Tvoje položky celkem', 'nkz-mp-vendor-dashboard' ); ?></span>
								<strong><?php echo wp_kses_post( $o['vendor_total'] ); ?></strong>
							</footer>

							<?php if ( ! empty( $o['packeta'] ) ) : ?>
								<div class="nkzmp-vd-order-packeta" style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(0,0,0,.1);">
									<?php if ( ! empty( $o['packeta']['barcode'] ) ) : ?>
										<span style="font-size:13px;color:rgba(0,0,0,.55);"><?php esc_html_e( 'Zásilka Zásilkovny:', 'nkz-mp-vendor-dashboard' ); ?> <code><?php echo esc_html( (string) $o['packeta']['barcode'] ); ?></code></span>
										<a class="nkzmp-vd-cancel" href="<?php echo esc_url( $o['packeta']['url'] ); ?>"><?php esc_html_e( 'Stáhnout štítek (PDF)', 'nkz-mp-vendor-dashboard' ); ?> →</a>
										<a class="nkzmp-vd-cancel" href="<?php echo esc_url( $o['packeta']['cancel_url'] ); ?>" style="color:#b00020;" onclick="return confirm('<?php echo esc_js( __( 'Opravdu zrušit tuto zásilku?', 'nkz-mp-vendor-dashboard' ) ); ?>');"><?php esc_html_e( 'Zrušit zásilku', 'nkz-mp-vendor-dashboard' ); ?></a>
									<?php else : ?>
										<a class="nkzmp-vd-cancel" href="<?php echo esc_url( $o['packeta']['url'] ); ?>"><?php esc_html_e( 'Vytvořit štítek Zásilkovny (PDF)', 'nkz-mp-vendor-dashboard' ); ?> →</a>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * @return array<int, array{number:string,date:string,status:string,status_label:string,items:array,vendor_total:string}>
	 */
	private static function vendor_orders( int $vendor_id, int $scan ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [];
		}
		$orders = wc_get_orders( [
			'limit'   => $scan,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => [ 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ],
		] );

		$out = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$lines   = [];
			$subtotal = 0.0;
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				/** @var \WC_Order_Item_Product $item */
				$pid     = $item->get_product_id();
				$item_vendor = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
				if ( $item_vendor <= 0 ) {
					$item_vendor = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
				}
				if ( $item_vendor !== $vendor_id ) {
					continue;
				}
				$line_total = (float) $item->get_total();
				$subtotal  += $line_total;
				$lines[]    = [
					'qty'   => (float) $item->get_quantity(),
					'name'  => $item->get_name(),
					'total' => wc_price( $line_total, [ 'currency' => $order->get_currency() ] ),
				];
			}
			if ( empty( $lines ) ) {
				continue;
			}
			$out[] = [
				'number'       => $order->get_order_number(),
				'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j. n. Y' ) : '',
				'status'       => $order->get_status(),
				'status_label' => wc_get_order_status_name( $order->get_status() ),
				'items'        => $lines,
				'vendor_total' => wc_price( $subtotal, [ 'currency' => $order->get_currency() ] ),
				'packeta'      => self::packeta_action( $order, $vendor_id ),
			];
		}
		return $out;
	}

	/**
	 * Data pro tlačítko štítku Zásilkovny, nebo null (modul neaktivní /
	 * objednávka nemá výdejní místo / nevyplněné API heslo).
	 *
	 * @return array{url:string,barcode:string}|null
	 */
	private static function packeta_action( \WC_Order $order, int $vendor_id ): ?array {
		if ( ! class_exists( \NKZMP\Packeta\LabelService::class ) || ! \NKZMP\Packeta\Settings::api_configured() ) {
			return null;
		}
		if ( (string) $order->get_meta( '_nkzmp_packeta_point_id' ) === '' ) {
			return null;
		}
		$packet = \NKZMP\Packeta\LabelService::instance()->get_packet( $order, $vendor_id );
		return [
			'url'        => \NKZMP\Packeta\LabelController::label_url( $order->get_id(), $vendor_id ),
			'cancel_url' => \NKZMP\Packeta\LabelController::cancel_url( $order->get_id(), $vendor_id ),
			'barcode'    => $packet !== null ? (string) ( $packet['barcode'] ?? '' ) : '',
		];
	}
}
