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
		$result    = self::query_orders( $vendor_id );
		$orders    = $result['orders'];

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

					<?php if ( $result['pages'] > 1 ) : ?>
						<nav class="nkzmp-vd-pagination" style="display:flex;gap:16px;align-items:center;margin-top:24px;">
							<?php if ( $result['page'] > 1 ) : ?>
								<a class="nkzmp-vd-cancel" href="<?php echo esc_url( add_query_arg( 'vorders_page', $result['page'] - 1 ) ); ?>">← <?php esc_html_e( 'Novější', 'nkz-mp-vendor-dashboard' ); ?></a>
							<?php endif; ?>
							<span style="font-size:13px;color:rgba(0,0,0,.55);"><?php echo esc_html( sprintf( __( 'Strana %1$d z %2$d', 'nkz-mp-vendor-dashboard' ), $result['page'], $result['pages'] ) ); ?></span>
							<?php if ( $result['page'] < $result['pages'] ) : ?>
								<a class="nkzmp-vd-cancel" href="<?php echo esc_url( add_query_arg( 'vorders_page', $result['page'] + 1 ) ); ?>"><?php esc_html_e( 'Starší', 'nkz-mp-vendor-dashboard' ); ?> →</a>
							<?php endif; ?>
						</nav>
					<?php endif; ?>
			<?php endif; ?>

		</div>
		<?php
	}

	private const PER_PAGE = 20;

	/**
	 * Stránkovaný seznam objednávek vendora. Primárně přes index
	 * (_nkzmp_order_vendor) s reálnou paginací; fallback sken posledních
	 * objednávek + lazy backfill indexu.
	 *
	 * @return array{orders:array,page:int,pages:int}
	 */
	private static function query_orders( int $vendor_id ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return [ 'orders' => [], 'page' => 1, 'pages' => 1 ];
		}

		$page    = isset( $_GET['vorders_page'] ) ? max( 1, (int) $_GET['vorders_page'] ) : 1;
		$statuses = [ 'wc-processing', 'wc-completed', 'wc-on-hold', 'wc-refunded' ];

		// 1) Indexovaná, stránkovaná query.
		if ( class_exists( OrderVendorIndex::class ) ) {
			$res = wc_get_orders( [
				'limit'      => self::PER_PAGE,
				'paged'      => $page,
				'paginate'   => true,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'status'     => $statuses,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query' => [ [ 'key' => OrderVendorIndex::META, 'value' => $vendor_id ] ],
			] );
			if ( is_object( $res ) && (int) $res->total > 0 ) {
				$rows = [];
				foreach ( $res->orders as $order ) {
					if ( $order instanceof \WC_Order ) {
						$row = self::build_row( $order, $vendor_id );
						if ( $row !== null ) {
							$rows[] = $row;
						}
					}
				}
				return [ 'orders' => $rows, 'page' => $page, 'pages' => max( 1, (int) $res->max_num_pages ) ];
			}
		}

		// 2) Fallback: sken posledních objednávek + lazy backfill indexu.
		$orders = wc_get_orders( [
			'limit'   => 80,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => $statuses,
		] );

		$out = [];
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$row = self::build_row( $order, $vendor_id );
			if ( $row === null ) {
				continue;
			}
			// Lazy backfill indexu pro příští stránkování.
			if ( class_exists( OrderVendorIndex::class ) ) {
				OrderVendorIndex::index( $order );
			}
			$out[] = $row;
		}
		return [ 'orders' => $out, 'page' => 1, 'pages' => 1 ];
	}

	/**
	 * Postaví řádek objednávky pro vendora, nebo null když v ní nemá položku.
	 *
	 * @return array{number:string,date:string,status:string,status_label:string,items:array,vendor_total:string,packeta:?array}|null
	 */
	private static function build_row( \WC_Order $order, int $vendor_id ): ?array {
		$lines    = [];
		$subtotal = 0.0;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid         = $item->get_product_id();
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
			return null;
		}
		return [
			'number'       => $order->get_order_number(),
			'date'         => $order->get_date_created() ? $order->get_date_created()->date_i18n( 'j. n. Y' ) : '',
			'status'       => $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'items'        => $lines,
			'vendor_total' => wc_price( $subtotal, [ 'currency' => $order->get_currency() ] ),
			'packeta'      => self::packeta_action( $order, $vendor_id ),
		];
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
		// Pokud je objednávka uzavřená a štítek neexistuje, akci „Vytvořit"
		// skryjeme (vrátíme null) – uzavřené objednávky se už neexpedují.
		// Filtr stejný jako v admin order display.
		if ( $packet === null
			&& $order->has_status( [ 'completed', 'cancelled', 'refunded' ] )
			&& apply_filters( 'nkzmp/v1/packeta/hide_create_on_closed', true, $order ) ) {
			return null;
		}
		return [
			'url'        => \NKZMP\Packeta\LabelController::label_url( $order->get_id(), $vendor_id ),
			'cancel_url' => \NKZMP\Packeta\LabelController::cancel_url( $order->get_id(), $vendor_id ),
			'barcode'    => $packet !== null ? (string) ( $packet['barcode'] ?? '' ) : '',
		];
	}
}
