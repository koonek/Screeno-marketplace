<?php
/**
 * DriftNotifier – e-mail adminovi když reconciliation najde drift.
 *
 * Hooká nkzmp/v1/reconciliation/completed (fire-uje Service::run). Pokud
 * report má drift, pošle adminovi souhrn. Dedupe přes transient (12h), aby
 * cron + manuální běh nespamovaly.
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

defined( 'ABSPATH' ) || exit;

final class DriftNotifier {

	private static ?DriftNotifier $instance = null;

	public static function instance(): DriftNotifier {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/reconciliation/completed', [ $this, 'maybe_notify' ] );
	}

	public function maybe_notify( Report $report ): void {
		if ( ! $report->has_drift() ) {
			return;
		}

		// Dedupe – pošli max 1× za 12h pro daný adapter.
		$key = 'nkzmp_drift_notified_' . md5( $report->adapter );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 12 * HOUR_IN_SECONDS );

		$to = (string) apply_filters( 'nkzmp/v1/reconciliation/notify_email', get_option( 'admin_email' ) );
		if ( ! is_email( $to ) ) {
			return;
		}

		$site      = (string) get_bloginfo( 'name' );
		$count     = count( $report->drift );
		$tools_url = admin_url( 'admin.php?page=' . ( defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'nkz-marketplace' ) . '-tools' );

		$lines = [];
		foreach ( array_slice( $report->drift, 0, 20 ) as $d ) {
			$lines[] = sprintf(
				'  [%s] %s — %s',
				$d['kind'],
				$d['source_ref'],
				wp_json_encode( $d['detail'] )
			);
		}

		$subject = sprintf( '[%s] Reconciliation drift: %d', $site, $count );
		$body    = sprintf(
			"Reconciliation našla %d nesrovnalostí mezi ledgerem a PSP (%s).\n\n" .
			"Okno: %s – %s\n" .
			"Source: %d, Ledger: %d, Matched: %d, Drift: %d\n\n" .
			"%s\n\n" .
			"Zkontroluj v Tools (reconcile / backfill):\n%s\n",
			$count,
			$report->adapter,
			gmdate( 'Y-m-d H:i', $report->from_ts ),
			gmdate( 'Y-m-d H:i', $report->to_ts ),
			$report->source_count,
			$report->ledger_count,
			$report->matched_count,
			$count,
			implode( "\n", $lines ),
			$tools_url
		);

		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
