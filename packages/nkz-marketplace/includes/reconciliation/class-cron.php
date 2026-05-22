<?php
/**
 * Reconciliation cron – denní spuštění pro každý registrovaný driver.
 *
 * Event:  nkzmp_reconcile_daily
 * Default: 03:00 site time
 * Window:  posledních 26 hodin (overlap 2h kvůli late-arriving PSP eventům)
 *
 * Driver-level chyba se logguje, ale neshazuje běh ostatních driverů.
 *
 * @package NKZMP
 */

namespace NKZMP\Reconciliation;

defined( 'ABSPATH' ) || exit;

final class Cron {

	public const EVENT = 'nkzmp_reconcile_daily';

	private static ?Cron $instance = null;

	public static function instance(): Cron {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( self::EVENT, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	public function maybe_schedule(): void {
		if ( wp_next_scheduled( self::EVENT ) ) {
			return;
		}
		// Naplánuj na nejbližší 03:00 site time.
		$now    = current_time( 'timestamp' );
		$target = strtotime( '03:00 tomorrow', $now );
		if ( $target === false ) {
			$target = $now + DAY_IN_SECONDS;
		}
		wp_schedule_event( $target, 'daily', self::EVENT );
	}

	public function run(): void {
		$drivers = Service::drivers();
		if ( ! $drivers ) {
			return;
		}
		$to_ts   = time();
		$from_ts = $to_ts - ( 26 * HOUR_IN_SECONDS );
		$service = new Service();

		foreach ( $drivers as $name => $driver ) {
			try {
				$report = $service->run( $driver, $from_ts, $to_ts );
				if ( $report->has_drift() ) {
					error_log( sprintf( '[NKZMP] reconcile %s: %d drift entries', $name, count( $report->drift ) ) );
				}
			} catch ( \Throwable $e ) {
				error_log( sprintf( '[NKZMP] reconcile driver %s failed: %s', $name, $e->getMessage() ) );
			}
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::EVENT );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EVENT );
		}
	}
}
