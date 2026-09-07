<?php
/**
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard\Views;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Support\Money;

defined( 'ABSPATH' ) || exit;

final class PayoutsView {

	public static function render( array $vendor ): void {
		global $wpdb;

		$vendor_id = (int) $vendor['id'];
		$currency  = (string) ( $vendor['currency'] ?: get_woocommerce_currency() ?: 'CZK' );
		$ledger    = LedgerSchema::table_name();

		$entries = $wpdb->get_results( $wpdb->prepare(
			"SELECT id, type, amount_minor, currency, order_id, source_ref, occurred_at
			 FROM {$ledger}
			 WHERE vendor_id = %d
			 ORDER BY id DESC
			 LIMIT 50",
			$vendor_id
		), ARRAY_A );

		$balance = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(amount_minor), 0)
			 FROM {$ledger}
			 WHERE vendor_id = %d
			   AND currency = %s
			   AND id NOT IN (SELECT reverses_id FROM {$ledger} WHERE reverses_id IS NOT NULL)",
			$vendor_id,
			strtoupper( $currency )
		) );

		$total_paid = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ABS(amount_minor)), 0) FROM {$ledger} WHERE vendor_id = %d AND type = 'payout'", // phpcs:ignore
			$vendor_id
		) );

		?>
		<div class="nkzmp-vd nkzmp-vd-payouts">

			<header class="nkzmp-vd-section-head">
				<h1><?php esc_html_e( 'Moje výplaty', 'nkz-mp-vendor-dashboard' ); ?></h1>
				<p class="nkzmp-vd-meta"><?php esc_html_e( 'Všechny transfery, které ti poslala platforma.', 'nkz-mp-vendor-dashboard' ); ?></p>
			</header>

			<section class="nkzmp-vd-stats">
				<div class="nkzmp-vd-stat">
					<div class="nkzmp-vd-stat-label"><?php esc_html_e( 'Vyplaceno celkem', 'nkz-mp-vendor-dashboard' ); ?></div>
					<div class="nkzmp-vd-stat-value"><?php echo esc_html( Money::from_minor_display( $total_paid, $currency ) ); ?></div>
				</div>
				<div class="nkzmp-vd-stat">
					<div class="nkzmp-vd-stat-label"><?php esc_html_e( 'Aktuální zůstatek', 'nkz-mp-vendor-dashboard' ); ?></div>
					<div class="nkzmp-vd-stat-value"><?php echo esc_html( Money::from_minor_display( $balance, $currency ) ); ?></div>
					<div class="nkzmp-vd-stat-hint"><?php esc_html_e( 'na Stripe účtu', 'nkz-mp-vendor-dashboard' ); ?></div>
				</div>
			</section>

			<?php if ( empty( $entries ) ) : ?>
				<p class="nkzmp-vd-empty-msg"><?php esc_html_e( 'Zatím žádné transfery. Až ti zákazníci nakoupí, transfery se zobrazí tady.', 'nkz-mp-vendor-dashboard' ); ?></p>
			<?php else : ?>
				<table class="nkzmp-vd-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Kdy', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Typ', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th><?php esc_html_e( 'Objednávka', 'nkz-mp-vendor-dashboard' ); ?></th>
							<th class="col-num"><?php esc_html_e( 'Částka', 'nkz-mp-vendor-dashboard' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $e ) :
							$amount = (int) $e['amount_minor'];
							$is_credit = $amount > 0;
							?>
							<tr>
								<td><?php echo esc_html( gmdate( 'd. n. Y H:i', (int) $e['occurred_at'] ) ); ?></td>
								<td><span class="nkzmp-vd-pill nkzmp-vd-pill--type-<?php echo esc_attr( $e['type'] ); ?>"><?php echo esc_html( self::type_label( (string) $e['type'] ) ); ?></span></td>
								<td><?php echo $e['order_id'] ? '#' . esc_html( (string) $e['order_id'] ) : '—'; ?></td>
								<td class="col-num <?php echo $is_credit ? 'is-credit' : 'is-debit'; ?>">
									<?php echo esc_html( ( $amount > 0 ? '+' : '' ) . Money::from_minor_display( $amount, (string) $e['currency'] ) ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

		</div>
		<?php
	}

	private static function type_label( string $type ): string {
		return match ( $type ) {
			'order_credit'         => __( 'Připsání', 'nkz-mp-vendor-dashboard' ),
			'payout'               => __( 'Výplata', 'nkz-mp-vendor-dashboard' ),
			'platform_commission'  => __( 'Provize', 'nkz-mp-vendor-dashboard' ),
			'reversal'             => __( 'Vrácení', 'nkz-mp-vendor-dashboard' ),
			'refund_debit'         => __( 'Refund', 'nkz-mp-vendor-dashboard' ),
			'chargeback'           => __( 'Chargeback', 'nkz-mp-vendor-dashboard' ),
			'manual_adjustment'    => __( 'Korekce', 'nkz-mp-vendor-dashboard' ),
			default                => $type,
		};
	}
}
