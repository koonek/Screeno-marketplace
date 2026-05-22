<?php
/**
 * Admin tools page – akce, které admin spouští ručně (migrace, status změny).
 *
 * Umístění: WooCommerce → NKZ Marketplace Tools.
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Audit\Recorder as AuditRecorder;
use NKZMP\Support\Capabilities;
use NKZMP\Vendor\MetaMigrator;
use NKZMP\Vendor\Status;
use NKZMP\Vendor\StatusService;

defined( 'ABSPATH' ) || exit;

final class ToolsPage {

	private const NONCE_ACTION = 'nkzmp_tools_action';

	private static ?ToolsPage $instance = null;

	public static function instance(): ToolsPage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_nkzmp_migrate_vendors', [ $this, 'handle_migrate' ] );
		add_action( 'admin_post_nkzmp_vendor_status', [ $this, 'handle_status' ] );
		add_action( 'admin_post_nkzmp_run_reconcile', [ $this, 'handle_reconcile' ] );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'NKZ Marketplace Tools', 'nkz-marketplace' ),
			__( 'NKZ Marketplace Tools', 'nkz-marketplace' ),
			Capabilities::MANAGE_VENDORS,
			'nkz-marketplace-tools',
			[ $this, 'render' ]
		);
	}

	public function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$flash = isset( $_GET['nkzmp_flash'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_flash'] ) ) : '';
		$msg   = isset( $_GET['nkzmp_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_msg'] ) ) : '';

		echo '<div class="wrap">';
		echo '<h1>NKZ Marketplace – Tools</h1>';

		if ( $flash === 'ok' ) {
			echo '<div class="notice notice-success"><p>' . esc_html( $msg ?: __( 'Hotovo.', 'nkz-marketplace' ) ) . '</p></div>';
		} elseif ( $flash === 'err' ) {
			echo '<div class="notice notice-error"><p>' . esc_html( $msg ?: __( 'Chyba.', 'nkz-marketplace' ) ) . '</p></div>';
		}

		$this->render_migration_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_status_section();
		echo '<hr style="margin:24px 0;">';
		$this->render_reconcile_section();
		echo '</div>';
	}

	private function render_reconcile_section(): void {
		echo '<h2>' . esc_html__( 'Reconciliation', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Porovná ledger PAYOUT řádky se Stripe transfery ve zvoleném časovém okně. Drift se zaloguje do auditu.', 'nkz-marketplace' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_run_reconcile" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Okno (since)', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><select name="since"><option value="24h">24h</option><option value="7d" selected>7d</option><option value="30d">30d</option><option value="90d">90d</option></select></td></tr>';
		echo '</tbody></table>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Spustit reconcile', 'nkz-marketplace' ) . '</button>';
		echo '</form>';

		$last = get_transient( 'nkzmp_last_reconcile_result' );
		if ( $last && is_array( $last ) ) {
			echo '<h3 style="margin-top:18px;">' . esc_html__( 'Poslední běh', 'nkz-marketplace' ) . '</h3>';
			foreach ( $last as $name => $r ) {
				echo '<table class="widefat striped" style="max-width:700px;margin-bottom:8px;"><tbody>';
				echo '<tr><th>Adapter</th><td><code>' . esc_html( $name ) . '</code></td></tr>';
				if ( isset( $r['error'] ) ) {
					echo '<tr><th>Error</th><td style="color:#dc3232;">' . esc_html( (string) $r['error'] ) . '</td></tr>';
				} else {
					echo '<tr><th>Window</th><td>' . esc_html( gmdate( 'Y-m-d H:i', (int) $r['from_ts'] ) ) . ' → ' . esc_html( gmdate( 'Y-m-d H:i', (int) $r['to_ts'] ) ) . ' UTC</td></tr>';
					echo '<tr><th>Source</th><td>' . (int) $r['source_count'] . '</td></tr>';
					echo '<tr><th>Ledger</th><td>' . (int) $r['ledger_count'] . '</td></tr>';
					echo '<tr><th>Matched</th><td><strong style="color:' . ( $r['matched_count'] > 0 ? '#46b450' : '#777' ) . ';">' . (int) $r['matched_count'] . '</strong></td></tr>';
					echo '<tr><th>Drift</th><td><strong style="color:' . ( $r['drift_count'] > 0 ? '#dc3232' : '#46b450' ) . ';">' . (int) $r['drift_count'] . '</strong></td></tr>';
				}
				echo '</tbody></table>';
				if ( ! empty( $r['drift'] ) ) {
					echo '<details><summary>' . esc_html__( 'Drift detail', 'nkz-marketplace' ) . '</summary><pre style="background:#f6f7f7;padding:8px;overflow:auto;">' . esc_html( wp_json_encode( $r['drift'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ) . '</pre></details>';
				}
			}
		}
	}

	public function handle_reconcile(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}

		$since = isset( $_POST['since'] ) ? sanitize_text_field( wp_unslash( $_POST['since'] ) ) : '7d';
		$secs  = match ( $since ) {
			'24h' => DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
			'90d' => 90 * DAY_IN_SECONDS,
			default => 7 * DAY_IN_SECONDS,
		};

		$to_ts   = time();
		$from_ts = $to_ts - $secs;
		$drivers = \NKZMP\Reconciliation\Service::drivers();
		if ( ! $drivers ) {
			$this->redirect( 'err', __( 'Žádné reconciliation drivery (chybí Stripe adapter ≥ 0.6.6?).', 'nkz-marketplace' ) );
		}

		$service = new \NKZMP\Reconciliation\Service();
		$out     = [];
		$totals  = [ 'matched' => 0, 'drift' => 0 ];
		foreach ( $drivers as $name => $driver ) {
			try {
				$report             = $service->run( $driver, $from_ts, $to_ts );
				$out[ $name ]       = $report->to_array();
				$totals['matched'] += $report->matched_count;
				$totals['drift']   += count( $report->drift );
			} catch ( \Throwable $e ) {
				$out[ $name ] = [ 'error' => $e->getMessage() ];
			}
		}
		set_transient( 'nkzmp_last_reconcile_result', $out, 6 * HOUR_IN_SECONDS );

		$msg = sprintf( __( 'Reconcile dokončen: %d matched, %d drift.', 'nkz-marketplace' ), $totals['matched'], $totals['drift'] );
		$this->redirect( $totals['drift'] > 0 ? 'err' : 'ok', $msg );
	}

	private function render_migration_section(): void {
		$summary = MetaMigrator::migrate_all( true, 0 ); // dry run preview

		echo '<h2>' . esc_html__( 'Meta migrace _nkv_* → _nkzmp_*', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Idempotentní. Legacy klíče zůstávají na místě pro rollback.', 'nkz-marketplace' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Dry-run preview:', 'nkz-marketplace' ) . '</strong></p>';
		echo '<ul>';
		echo '<li>' . esc_html( sprintf( __( 'Vendoři: %d', 'nkz-marketplace' ), $summary['processed'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Klíče k překopírování: %d', 'nkz-marketplace' ), $summary['copied'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Klíče už existují (skip): %d', 'nkz-marketplace' ), $summary['skipped_exists'] ) ) . '</li>';
		echo '<li>' . esc_html( sprintf( __( 'Legacy klíče prázdné (skip): %d', 'nkz-marketplace' ), $summary['skipped_empty'] ) ) . '</li>';
		echo '</ul>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_migrate_vendors" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<button type="submit" class="button button-primary"' . ( $summary['copied'] > 0 ? '' : ' disabled' ) . '>'
			. esc_html__( 'Spustit migraci (write)', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	private function render_status_section(): void {
		echo '<h2>' . esc_html__( 'Změna statusu vendora', 'nkz-marketplace' ) . '</h2>';
		echo '<p>' . esc_html__( 'Provede validovaný přechod statusu. Změna se zaznamená do auditu.', 'nkz-marketplace' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="nkzmp_vendor_status" />';
		wp_nonce_field( self::NONCE_ACTION );
		echo '<table class="form-table"><tbody>';
		echo '<tr><th><label>' . esc_html__( 'Vendor ID', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><input type="number" name="vendor_id" required min="1" /></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Nový status', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><select name="status">';
		foreach ( Status::cases() as $s ) {
			echo '<option value="' . esc_attr( $s->value ) . '">' . esc_html( $s->value ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th><label>' . esc_html__( 'Reason (volitelné)', 'nkz-marketplace' ) . '</label></th>';
		echo '<td><input type="text" name="reason" size="60" /></td></tr>';
		echo '</tbody></table>';
		echo '<button type="submit" class="button button-primary">' . esc_html__( 'Provést přechod', 'nkz-marketplace' ) . '</button>';
		echo '</form>';
	}

	public function handle_migrate(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$summary = MetaMigrator::migrate_all( false, 0 );

		( new AuditRecorder() )->record(
			action:      'vendor.meta_migrated',
			entity_type: 'system',
			entity_id:   0,
			summary:     sprintf( 'Meta migration: %d vendors, %d keys copied', $summary['processed'], $summary['copied'] ),
			payload:     $summary,
		);

		$msg = sprintf( __( 'Migrace: %d vendorů, %d klíčů zkopírováno.', 'nkz-marketplace' ), $summary['processed'], $summary['copied'] );
		$this->redirect( 'ok', $msg );
	}

	public function handle_status(): void {
		check_admin_referer( self::NONCE_ACTION );
		if ( ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			wp_die( esc_html__( 'Nedostatečná oprávnění.', 'nkz-marketplace' ) );
		}
		$vendor_id = isset( $_POST['vendor_id'] ) ? (int) $_POST['vendor_id'] : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$reason    = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';

		$target = Status::tryFrom( $status );
		if ( $vendor_id <= 0 || ! $target ) {
			$this->redirect( 'err', __( 'Vendor ID nebo status je špatně.', 'nkz-marketplace' ) );
		}

		try {
			( new StatusService() )->transition( $vendor_id, $target, [ 'reason' => $reason ] );
			$this->redirect( 'ok', sprintf( __( 'Vendor #%d je teď %s.', 'nkz-marketplace' ), $vendor_id, $target->value ) );
		} catch ( \Throwable $e ) {
			$this->redirect( 'err', $e->getMessage() );
		}
	}

	private function redirect( string $flash, string $msg ): void {
		wp_safe_redirect( add_query_arg(
			[ 'page' => 'nkz-marketplace-tools', 'nkzmp_flash' => $flash, 'nkzmp_msg' => rawurlencode( $msg ) ],
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
