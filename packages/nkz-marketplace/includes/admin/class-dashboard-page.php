<?php
/**
 * Admin Dashboard – první stránka top-level menu.
 *
 * Moderní layout v AOZ duchu: bílá / černá / accent #0060FF, generous
 * whitespace, žádné radius, jasná typografická hierarchie.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Audit\Schema as AuditSchema;
use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Payout\Schema as PayoutSchema;
use NKZMP\Support\Capabilities;
use NKZMP\Support\Money;
use NKZMP\Vendor\MetaKeys as VendorMeta;
use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class DashboardPage {

	public static function render_static(): void {
		( new self() )->render();
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$stats  = $this->stats();
		$audit  = $this->recent_audit( 8 );
		$health = $this->health();

		?>
		<div class="wrap nkzmp-dash">

			<header class="nkzmp-dash-head">
				<div>
					<span class="nkzmp-dash-kicker"><?php esc_html_e( 'Marketplace', 'nkz-marketplace' ); ?></span>
					<h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
				</div>
				<div class="nkzmp-dash-meta">
					<span><?php echo esc_html( gmdate( 'd. n. Y' ) ); ?></span>
					<span class="dot">·</span>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-marketplace-status' ) ); ?>"><?php esc_html_e( 'Detaily', 'nkz-marketplace' ); ?> →</a>
				</div>
			</header>

			<!-- Stats -->
			<section class="nkzmp-dash-stats">
				<?php foreach ( $stats as $stat ) : ?>
					<a class="nkzmp-dash-stat <?php echo esc_attr( $stat['accent'] ? 'is-accent' : '' ); ?>" href="<?php echo esc_url( $stat['href'] ); ?>">
						<div class="nkzmp-dash-stat-label"><?php echo esc_html( $stat['label'] ); ?></div>
						<div class="nkzmp-dash-stat-value"><?php echo esc_html( $stat['value'] ); ?></div>
						<?php if ( ! empty( $stat['hint'] ) ) : ?>
							<div class="nkzmp-dash-stat-hint"><?php echo esc_html( $stat['hint'] ); ?></div>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</section>

			<div class="nkzmp-dash-cols">

				<section class="nkzmp-dash-block">
					<header class="nkzmp-dash-block-head">
						<h2><?php esc_html_e( 'Aktivita', 'nkz-marketplace' ); ?></h2>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-marketplace-status' ) ); ?>"><?php esc_html_e( 'Vše', 'nkz-marketplace' ); ?> →</a>
					</header>

					<?php if ( empty( $audit ) ) : ?>
						<p class="nkzmp-dash-empty"><?php esc_html_e( 'Zatím se nic nestalo. Audit log se zaplní s první akcí.', 'nkz-marketplace' ); ?></p>
					<?php else : ?>
						<ul class="nkzmp-dash-activity">
							<?php foreach ( $audit as $event ) : ?>
								<li>
									<div class="nkzmp-act-time" title="<?php echo esc_attr( gmdate( 'Y-m-d H:i:s', $event->occurred_at ) ); ?>">
										<?php echo esc_html( human_time_diff( $event->occurred_at, time() ) ); ?>
									</div>
									<div class="nkzmp-act-body">
										<div class="nkzmp-act-action"><?php echo esc_html( $this->prettify_action( $event->action ) ); ?></div>
										<div class="nkzmp-act-summary"><?php echo esc_html( (string) $event->summary ); ?></div>
									</div>
									<div class="nkzmp-act-actor"><?php echo esc_html( (string) ( $event->actor_label ?: '—' ) ); ?></div>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</section>

				<aside class="nkzmp-dash-side">

					<section class="nkzmp-dash-block">
						<header class="nkzmp-dash-block-head">
							<h2><?php esc_html_e( 'Stav systému', 'nkz-marketplace' ); ?></h2>
						</header>
						<ul class="nkzmp-dash-health">
							<?php foreach ( $health as $check ) : ?>
								<li class="is-<?php echo esc_attr( $check['state'] ); ?>">
									<span class="nkzmp-health-dot"></span>
									<span class="nkzmp-health-label"><?php echo esc_html( $check['label'] ); ?></span>
									<?php if ( ! empty( $check['detail'] ) ) : ?>
										<span class="nkzmp-health-detail"><?php echo esc_html( $check['detail'] ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>

					<section class="nkzmp-dash-block">
						<header class="nkzmp-dash-block-head">
							<h2><?php esc_html_e( 'Rychlé akce', 'nkz-marketplace' ); ?></h2>
						</header>
						<div class="nkzmp-dash-actions">
							<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=nkv_vendor' ) ); ?>">
								<span><?php esc_html_e( 'Vendoři', 'nkz-marketplace' ); ?></span>
								<small><?php esc_html_e( 'spravovat', 'nkz-marketplace' ); ?></small>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-marketplace-tools' ) ); ?>">
								<span><?php esc_html_e( 'Tools', 'nkz-marketplace' ); ?></span>
								<small><?php esc_html_e( 'migrace, reconcile', 'nkz-marketplace' ); ?></small>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-mp-storefront' ) ); ?>">
								<span><?php esc_html_e( 'Storefront', 'nkz-marketplace' ); ?></span>
								<small><?php esc_html_e( 'vendor stránky', 'nkz-marketplace' ); ?></small>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=nkz-mp-vendor-registration' ) ); ?>">
								<span><?php esc_html_e( 'Registrace', 'nkz-marketplace' ); ?></span>
								<small><?php esc_html_e( 'formulář, e-maily', 'nkz-marketplace' ); ?></small>
							</a>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=nkv_stripe_split' ) ); ?>">
								<span><?php esc_html_e( 'Stripe Connect', 'nkz-marketplace' ); ?></span>
								<small><?php esc_html_e( 'API klíče, provize', 'nkz-marketplace' ); ?></small>
							</a>
						</div>
					</section>

				</aside>

			</div>
		</div>

		<style>
			/* ── NKZ Dashboard — moderní AOZ-inspired admin styling ─── */
			#wpwrap { background:#fafafa; }
			.nkzmp-dash {
				max-width: 1280px;
				margin: 0;
				padding: 8px 0 64px;
				color: #0a0a0a;
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, sans-serif;
			}
			.nkzmp-dash .wp-heading-inline { display:none; }

			.nkzmp-dash-head {
				display: flex;
				justify-content: space-between;
				align-items: flex-end;
				padding: 16px 0 32px;
				margin-bottom: 32px;
				border-bottom: 1px solid #0a0a0a;
			}
			.nkzmp-dash-kicker {
				display: block;
				font-size: 11px;
				font-weight: 600;
				letter-spacing: 0.14em;
				text-transform: uppercase;
				color: #0060FF;
				margin-bottom: 8px;
			}
			.nkzmp-dash-head h1 {
				margin: 0;
				font-size: clamp(28px, 4vw, 40px);
				font-weight: 400;
				line-height: 1.05;
				letter-spacing: -0.02em;
				color: #0a0a0a;
			}
			.nkzmp-dash-meta {
				font-size: 13px;
				color: rgba(10,10,10,0.55);
			}
			.nkzmp-dash-meta a { color: #0060FF; text-decoration: none; border-bottom: 1px solid #0060FF; }
			.nkzmp-dash-meta .dot { margin: 0 8px; }

			/* Stat cards */
			.nkzmp-dash-stats {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
				gap: 1px;
				background: #0a0a0a;
				border: 1px solid #0a0a0a;
				margin-bottom: 32px;
			}
			.nkzmp-dash-stat {
				display: block;
				padding: 24px 28px;
				background: #fff;
				text-decoration: none;
				color: #0a0a0a;
				transition: background 0.15s ease;
			}
			.nkzmp-dash-stat:hover { background: #f5f5f5; }
			.nkzmp-dash-stat.is-accent { background: #0060FF; color: #fff; }
			.nkzmp-dash-stat.is-accent:hover { background: #0050d6; color: #fff; }
			.nkzmp-dash-stat-label {
				font-size: 11px;
				font-weight: 600;
				letter-spacing: 0.12em;
				text-transform: uppercase;
				opacity: 0.65;
				margin-bottom: 12px;
			}
			.nkzmp-dash-stat-value {
				font-size: 40px;
				font-weight: 400;
				line-height: 1;
				letter-spacing: -0.025em;
				font-variant-numeric: tabular-nums;
			}
			.nkzmp-dash-stat-hint {
				font-size: 12px;
				opacity: 0.65;
				margin-top: 8px;
			}

			/* Two-column layout */
			.nkzmp-dash-cols {
				display: grid;
				grid-template-columns: 2fr 1fr;
				gap: 32px;
			}
			@media (max-width: 960px) {
				.nkzmp-dash-cols { grid-template-columns: 1fr; }
			}
			.nkzmp-dash-side { display: flex; flex-direction: column; gap: 32px; }

			/* Blocks */
			.nkzmp-dash-block {
				background: #fff;
				border: 1px solid #0a0a0a;
				padding: 24px 28px;
			}
			.nkzmp-dash-block-head {
				display: flex;
				justify-content: space-between;
				align-items: baseline;
				margin-bottom: 20px;
				padding-bottom: 16px;
				border-bottom: 1px solid rgba(10,10,10,0.1);
			}
			.nkzmp-dash-block-head h2 {
				margin: 0;
				font-size: 16px;
				font-weight: 500;
				letter-spacing: -0.005em;
				color: #0a0a0a;
			}
			.nkzmp-dash-block-head a {
				font-size: 13px;
				color: #0060FF;
				text-decoration: none;
				border-bottom: 1px solid transparent;
			}
			.nkzmp-dash-block-head a:hover { border-bottom-color: #0060FF; }

			.nkzmp-dash-empty {
				font-style: italic;
				color: rgba(10,10,10,0.5);
				margin: 16px 0 0;
				font-size: 14px;
			}

			/* Activity list */
			.nkzmp-dash-activity {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.nkzmp-dash-activity li {
				display: grid;
				grid-template-columns: 80px 1fr auto;
				gap: 16px;
				padding: 14px 0;
				border-bottom: 1px solid rgba(10,10,10,0.06);
				align-items: baseline;
			}
			.nkzmp-dash-activity li:last-child { border-bottom: none; }
			.nkzmp-act-time {
				font-size: 12px;
				color: rgba(10,10,10,0.55);
				font-variant-numeric: tabular-nums;
			}
			.nkzmp-act-action {
				font-size: 13px;
				font-weight: 500;
				color: #0060FF;
				font-family: 'SF Mono', Menlo, monospace;
			}
			.nkzmp-act-summary {
				font-size: 14px;
				color: #0a0a0a;
				margin-top: 2px;
				line-height: 1.4;
			}
			.nkzmp-act-actor {
				font-size: 11px;
				font-weight: 500;
				letter-spacing: 0.04em;
				text-transform: uppercase;
				color: rgba(10,10,10,0.45);
			}

			/* Health */
			.nkzmp-dash-health {
				list-style: none;
				margin: 0;
				padding: 0;
			}
			.nkzmp-dash-health li {
				display: grid;
				grid-template-columns: 8px 1fr auto;
				gap: 12px;
				padding: 10px 0;
				align-items: center;
				font-size: 13px;
				border-bottom: 1px solid rgba(10,10,10,0.06);
			}
			.nkzmp-dash-health li:last-child { border-bottom: none; }
			.nkzmp-health-dot {
				width: 8px;
				height: 8px;
				border-radius: 50%;
				background: rgba(10,10,10,0.2);
			}
			.nkzmp-dash-health li.is-ok .nkzmp-health-dot { background: #00a32a; box-shadow: 0 0 0 3px rgba(0,163,42,0.15); }
			.nkzmp-dash-health li.is-warn .nkzmp-health-dot { background: #dba617; box-shadow: 0 0 0 3px rgba(219,166,23,0.15); }
			.nkzmp-dash-health li.is-fail .nkzmp-health-dot { background: #d63638; box-shadow: 0 0 0 3px rgba(214,54,56,0.15); }
			.nkzmp-health-label { color: #0a0a0a; }
			.nkzmp-health-detail { font-size: 12px; color: rgba(10,10,10,0.55); }

			/* Quick actions */
			.nkzmp-dash-actions {
				display: grid;
				grid-template-columns: 1fr 1fr;
				gap: 1px;
				background: rgba(10,10,10,0.1);
				margin: -1px;
			}
			.nkzmp-dash-actions a {
				display: block;
				padding: 16px;
				background: #fff;
				text-decoration: none;
				color: #0a0a0a;
				transition: background 0.15s ease;
			}
			.nkzmp-dash-actions a:hover {
				background: #0a0a0a;
				color: #fff;
			}
			.nkzmp-dash-actions a:hover small { color: rgba(255,255,255,0.65); }
			.nkzmp-dash-actions span {
				display: block;
				font-size: 14px;
				font-weight: 500;
				line-height: 1.2;
			}
			.nkzmp-dash-actions small {
				display: block;
				font-size: 11px;
				color: rgba(10,10,10,0.5);
				margin-top: 4px;
				letter-spacing: 0.02em;
			}
		</style>
		<?php
	}

	/**
	 * @return array<int, array{label:string, value:string, hint:string, href:string, accent:bool}>
	 */
	private function stats(): array {
		global $wpdb;

		$vendor_types  = [ 'nkv_vendor', 'nkzmp_vendor' ];
		$total_vendors = 0;
		foreach ( $vendor_types as $type ) {
			if ( ! post_type_exists( $type ) ) {
				continue;
			}
			$counts         = wp_count_posts( $type );
			$total_vendors += (int) ( $counts->publish ?? 0 );
		}

		$pending = 0;
		$active  = 0;
		foreach ( [ VendorMeta::STATUS, '_nkv_vendor_status' ] as $key ) {
			$pending += (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				$key,
				Status::PENDING->value
			) );
			$active += (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s",
				$key,
				Status::ACTIVE->value
			) );
		}
		$pending = min( $pending, $total_vendors );
		$active  = min( $active, $total_vendors );

		$ledger_table = LedgerSchema::table_name();
		$since        = time() - 30 * DAY_IN_SECONDS;
		$volume_minor = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(ABS(amount_minor)), 0) FROM {$ledger_table} WHERE type = 'payout' AND occurred_at >= %d", // phpcs:ignore
			$since
		) );
		$volume = $volume_minor > 0 ? Money::from_minor_display( $volume_minor, 'CZK' ) : '0 Kč';

		$pending_link = $pending > 0
			? admin_url( 'edit.php?post_type=nkv_vendor&meta_key=_nkv_vendor_status&meta_value=pending' )
			: admin_url( 'admin.php?page=nkz-marketplace-tools' );

		return [
			[
				'label'  => __( 'Vendoři', 'nkz-marketplace' ),
				'value'  => (string) $total_vendors,
				'hint'   => '',
				'href'   => admin_url( 'edit.php?post_type=nkv_vendor' ),
				'accent' => false,
			],
			[
				'label'  => __( 'Čeká na schválení', 'nkz-marketplace' ),
				'value'  => (string) $pending,
				'hint'   => $pending > 0 ? __( 'Projdi v Tools', 'nkz-marketplace' ) : __( 'Vše vyřízeno', 'nkz-marketplace' ),
				'href'   => $pending_link,
				'accent' => $pending > 0,
			],
			[
				'label'  => __( 'Aktivní prodejci', 'nkz-marketplace' ),
				'value'  => (string) $active,
				'hint'   => '',
				'href'   => admin_url( 'edit.php?post_type=nkv_vendor' ),
				'accent' => false,
			],
			[
				'label'  => __( 'Transfery (30 dní)', 'nkz-marketplace' ),
				'value'  => $volume,
				'hint'   => __( 'rozdáno vendorům', 'nkz-marketplace' ),
				'href'   => admin_url( 'admin.php?page=nkz-marketplace-status' ),
				'accent' => false,
			],
		];
	}

	/**
	 * @return \NKZMP\Audit\Event[]
	 */
	private function recent_audit( int $limit ): array {
		if ( ! class_exists( AuditRecorder::class ) ) {
			return [];
		}
		return ( new AuditRecorder() )->query( [ 'limit' => $limit ] );
	}

	private function prettify_action( string $action ): string {
		$map = [
			'payout.transition'             => __( 'Výplata', 'nkz-marketplace' ),
			'ledger.manual_adjustment'      => __( 'Manuální korekce', 'nkz-marketplace' ),
			'ledger.backfill'               => __( 'Backfill', 'nkz-marketplace' ),
			'vendor.status_changed'         => __( 'Změna statusu', 'nkz-marketplace' ),
			'vendor.registration_submitted' => __( 'Nová přihláška', 'nkz-marketplace' ),
			'vendor.meta_migrated'          => __( 'Meta migrace', 'nkz-marketplace' ),
			'role.vendor_granted'           => __( 'Role přidělena', 'nkz-marketplace' ),
			'role.vendor_revoked'           => __( 'Role odebrána', 'nkz-marketplace' ),
			'reconcile.drift_detected'      => __( 'Reconcile drift', 'nkz-marketplace' ),
			'gdpr.erasure'                  => __( 'GDPR výmaz', 'nkz-marketplace' ),
		];
		return $map[ $action ] ?? $action;
	}

	/**
	 * @return array<int, array{label:string, state:string, detail:string}>
	 */
	private function health(): array {
		global $wpdb;

		$rows = [];

		$ledger_ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', LedgerSchema::table_name() ) ) === LedgerSchema::table_name();
		$rows[]    = [
			'label'  => __( 'Ledger', 'nkz-marketplace' ),
			'state'  => $ledger_ok ? 'ok' : 'fail',
			'detail' => '',
		];

		$payouts_ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', PayoutSchema::table_name() ) ) === PayoutSchema::table_name();
		$rows[]     = [
			'label'  => __( 'Payouts', 'nkz-marketplace' ),
			'state'  => $payouts_ok ? 'ok' : 'fail',
			'detail' => '',
		];

		$audit_ok = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', AuditSchema::table_name() ) ) === AuditSchema::table_name();
		$rows[]   = [
			'label'  => __( 'Audit log', 'nkz-marketplace' ),
			'state'  => $audit_ok ? 'ok' : 'fail',
			'detail' => '',
		];

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$adapter_loaded = is_plugin_active( 'nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php' )
			|| class_exists( \NKVSVS\Plugin::class );
		$rows[] = [
			'label'  => __( 'Stripe adapter', 'nkz-marketplace' ),
			'state'  => $adapter_loaded ? 'ok' : 'fail',
			'detail' => '',
		];

		$reconcile_next = wp_next_scheduled( \NKZMP\Reconciliation\Cron::EVENT );
		$rows[]         = [
			'label'  => __( 'Reconcile cron', 'nkz-marketplace' ),
			'state'  => $reconcile_next ? 'ok' : 'warn',
			'detail' => $reconcile_next
				? sprintf( __( 'za %s', 'nkz-marketplace' ), human_time_diff( time(), $reconcile_next ) )
				: __( 'naplánuj', 'nkz-marketplace' ),
		];

		return $rows;
	}
}
