<?php
/**
 * AdminOverview – billing přehled v admin Dashboardu.
 *
 * Hooká se na nkzmp/v1/admin/dashboard/after (core DashboardPage). Zobrazí:
 *  - stat karty: aktivní / bez předplatného / po splatnosti / MRR
 *  - tabulku vendorů podle billing stavu
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class AdminOverview {

	private static ?AdminOverview $instance = null;

	public static function instance(): AdminOverview {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/admin/dashboard/after', [ $this, 'render' ] );
	}

	public function render(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		$data = $this->collect();
		$cur  = (string) Settings::get()['currency'];

		?>
		<section class="nkzmp-dash-block" style="margin-top:32px;">
			<header class="nkzmp-dash-block-head">
				<h2><?php esc_html_e( 'Předplatné prodejců', 'nkz-mp-vendor-billing' ); ?></h2>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-mp-vendor-billing' ) ); ?>"><?php esc_html_e( 'Nastavení', 'nkz-mp-vendor-billing' ); ?> →</a>
			</header>

			<div class="nkzmp-bill-stats">
				<div class="nkzmp-bill-stat is-ok">
					<span class="n"><?php echo (int) $data['active']; ?></span>
					<span class="l"><?php esc_html_e( 'Platí', 'nkz-mp-vendor-billing' ); ?></span>
				</div>
				<div class="nkzmp-bill-stat is-warn">
					<span class="n"><?php echo (int) $data['none']; ?></span>
					<span class="l"><?php esc_html_e( 'Nezaplatili', 'nkz-mp-vendor-billing' ); ?></span>
				</div>
				<div class="nkzmp-bill-stat is-err">
					<span class="n"><?php echo (int) $data['past_due']; ?></span>
					<span class="l"><?php esc_html_e( 'Po splatnosti', 'nkz-mp-vendor-billing' ); ?></span>
				</div>
				<div class="nkzmp-bill-stat is-muted">
					<span class="n"><?php echo (int) $data['canceled']; ?></span>
					<span class="l"><?php esc_html_e( 'Zrušeno', 'nkz-mp-vendor-billing' ); ?></span>
				</div>
				<div class="nkzmp-bill-stat is-accent">
					<span class="n"><?php echo esc_html( number_format_i18n( $data['mrr'] ) . ' ' . $cur ); ?></span>
					<span class="l"><?php esc_html_e( 'MRR (měsíčně)', 'nkz-mp-vendor-billing' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $data['rows'] ) ) : ?>
				<table class="widefat striped" style="margin-top:16px;">
					<thead><tr>
						<th><?php esc_html_e( 'Prodejce', 'nkz-mp-vendor-billing' ); ?></th>
						<th><?php esc_html_e( 'Vendor stav', 'nkz-mp-vendor-billing' ); ?></th>
						<th><?php esc_html_e( 'Předplatné', 'nkz-mp-vendor-billing' ); ?></th>
						<th><?php esc_html_e( 'Částka', 'nkz-mp-vendor-billing' ); ?></th>
					</tr></thead>
					<tbody>
						<?php foreach ( $data['rows'] as $r ) : ?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $r['id'] ) ); ?>"><?php echo esc_html( $r['name'] ); ?></a></td>
								<td><code><?php echo esc_html( $r['vendor_status'] ?: '—' ); ?></code></td>
								<td><span class="nkzmp-bill-pill nkzmp-bill-pill--<?php echo esc_attr( $r['billing'] ); ?>"><?php echo esc_html( $r['billing_label'] ); ?></span></td>
								<td><?php echo esc_html( $r['amount'] . ' ' . $cur ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</section>

		<style>
			.nkzmp-bill-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:1px; background:#0a0a0a; border:1px solid #0a0a0a; }
			.nkzmp-bill-stat { background:#fff; padding:18px 20px; display:flex; flex-direction:column; gap:6px; }
			.nkzmp-bill-stat .n { font-size:28px; font-weight:400; line-height:1; letter-spacing:-0.02em; font-variant-numeric:tabular-nums; }
			.nkzmp-bill-stat .l { font-size:11px; font-weight:600; letter-spacing:0.1em; text-transform:uppercase; color:#646970; }
			.nkzmp-bill-stat.is-ok .n { color:#00a32a; }
			.nkzmp-bill-stat.is-warn .n { color:#dba617; }
			.nkzmp-bill-stat.is-err .n { color:#d63638; }
			.nkzmp-bill-stat.is-accent { background:#0060FF; }
			.nkzmp-bill-stat.is-accent .n, .nkzmp-bill-stat.is-accent .l { color:#fff; }
			.nkzmp-bill-pill { display:inline-block; padding:2px 10px; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
			.nkzmp-bill-pill--active { background:#00a32a; color:#fff; }
			.nkzmp-bill-pill--past_due { background:#d63638; color:#fff; }
			.nkzmp-bill-pill--none { background:#dba617; color:#fff; }
			.nkzmp-bill-pill--canceled { background:rgba(0,0,0,0.12); }
		</style>
		<?php
	}

	private function collect(): array {
		global $wpdb;

		// Vendoři kteří jsou ve stavu kde billing dává smysl (active / suspended /
		// approved_awaiting_kyc). Pro jednoduchost vezmeme všechny published vendory.
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ('nkv_vendor','nkzmp_vendor') AND post_status='publish'
			 LIMIT 500"
		);

		$active = $past_due = $none = $canceled = 0;
		$mrr    = 0;
		$rows   = [];

		foreach ( $ids ?: [] as $id ) {
			$id = (int) $id;
			$vendor_status = (string) get_post_meta( $id, '_nkzmp_vendor_status', true );
			if ( $vendor_status === '' ) {
				$vendor_status = (string) get_post_meta( $id, '_nkv_vendor_status', true );
			}
			// Billing dává smysl jen pro vendory co prošli approval (ne pending/rejected).
			if ( in_array( $vendor_status, [ Status::PENDING->value, Status::REJECTED->value, '' ], true ) ) {
				continue;
			}

			$billing = (string) get_post_meta( $id, NKZMP_BILLING_STATUS_META, true ) ?: 'none';
			$amount  = Settings::amount_for_vendor( $id );

			switch ( $billing ) {
				case 'active':   $active++;   $mrr += $amount; break;
				case 'past_due': $past_due++; break;
				case 'canceled': $canceled++; break;
				default:         $none++; $billing = 'none'; break;
			}

			$rows[] = [
				'id'            => $id,
				'name'          => get_the_title( $id ) ?: ( '#' . $id ),
				'vendor_status' => $vendor_status,
				'billing'       => $billing,
				'billing_label' => $this->billing_label( $billing ),
				'amount'        => $amount,
			];
		}

		// Seřaď: nezaplatili a po splatnosti nahoru (vyžadují pozornost).
		usort( $rows, static function ( $a, $b ) {
			$order = [ 'past_due' => 0, 'none' => 1, 'active' => 2, 'canceled' => 3 ];
			return ( $order[ $a['billing'] ] ?? 9 ) <=> ( $order[ $b['billing'] ] ?? 9 );
		} );

		return [
			'active'   => $active,
			'past_due' => $past_due,
			'none'     => $none,
			'canceled' => $canceled,
			'mrr'      => $mrr,
			'rows'     => $rows,
		];
	}

	private function billing_label( string $b ): string {
		return match ( $b ) {
			'active'   => __( 'Platí', 'nkz-mp-vendor-billing' ),
			'past_due' => __( 'Po splatnosti', 'nkz-mp-vendor-billing' ),
			'canceled' => __( 'Zrušeno', 'nkz-mp-vendor-billing' ),
			default    => __( 'Nezaplatil', 'nkz-mp-vendor-billing' ),
		};
	}
}
