<?php
/**
 * GraceCron – fallback suspend neplatících vendorů.
 *
 * Stripe normálně pošle invoice.payment_failed při každém retry → webhook
 * suspendne po grace. Pokud ale webhook vypadne / není nastavený, neplatící
 * vendor by prodával dál. Denní cron projde `past_due` vendory a po uplynutí
 * grace je suspendne.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

use NKZMP\Vendor\Status;
use NKZMP\Vendor\StatusService;

defined( 'ABSPATH' ) || exit;

final class GraceCron {

	public const EVENT = 'nkzmp_billing_grace_check';

	private static ?GraceCron $instance = null;

	public static function instance(): GraceCron {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( self::EVENT, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'maybe_schedule' ] );
	}

	public function maybe_schedule(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::EVENT );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::EVENT );
		}
	}

	public function run(): void {
		if ( ! Settings::is_enabled() ) {
			return;
		}
		$grace = (int) Settings::get()['grace_days'] * DAY_IN_SECONDS;
		if ( $grace <= 0 ) {
			$grace = 0;
		}

		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = %s AND meta_value = 'past_due'",
			NKZMP_BILLING_STATUS_META
		) );
		if ( ! $rows ) {
			return;
		}

		$now = time();
		foreach ( $rows as $row ) {
			$vendor_id = (int) $row->post_id;
			$failed_at = (int) get_post_meta( $vendor_id, '_nkzmp_billing_failed_at', true );
			if ( $failed_at <= 0 ) {
				// Nemáme čas selhání → nastav teď a počkej na příští běh.
				update_post_meta( $vendor_id, '_nkzmp_billing_failed_at', $now );
				continue;
			}
			if ( ( $now - $failed_at ) < $grace ) {
				continue; // ještě v grace
			}
			$this->suspend( $vendor_id );
		}
	}

	private function suspend( int $vendor_id ): void {
		$status = ( new \NKZMP\Vendor\Repository() )->status( $vendor_id );
		if ( $status !== Status::ACTIVE ) {
			return;
		}
		try {
			( new StatusService() )->transition( $vendor_id, Status::SUSPENDED, [ 'source' => 'billing_grace_cron' ] );
		} catch ( \Throwable $e ) {
			update_post_meta( $vendor_id, '_nkzmp_vendor_status', 'suspended' );
		}
		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      'billing.suspended_grace_cron',
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     'Suspended – grace period uplynula bez platby',
				actor_label: 'billing_cron',
			);
		}
	}
}
