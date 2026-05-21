<?php
/**
 * Hourly fallback sync of vendor account statuses.
 *
 * The Stripe webhook (`account.updated`) is the primary source of truth, but webhooks
 * can fail (Stripe outage, server downtime, signature secret rotated etc.). This cron
 * job reconciles state by polling Stripe for every vendor with a connected account.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Cron_Sync {

	private static ?Cron_Sync $instance = null;
	public static function instance(): Cron_Sync { return self::$instance ??= new self(); }

	public const HOOK = 'nkv_svs_cron_sync_vendors';

	public function init(): void {
		add_action( self::HOOK, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'ensure_scheduled' ] );
		register_deactivation_hook( NKVSVS_PLUGIN_FILE, [ __CLASS__, 'unschedule' ] );
	}

	public function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK );
		}
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Iterate all vendors with a Stripe account and refresh their status.
	 * Errors are logged but don't stop the loop.
	 */
	public function run(): void {
		$client = new Stripe_Client();
		if ( ! $client->is_ready() ) {
			Logger::warning( 'Cron sync skipped — Stripe key not configured' );
			return;
		}
		$vendor_ids = get_posts(
			[
				'post_type'      => Vendors::POST_TYPE,
				'meta_key'       => '_nkv_stripe_account_id',
				'meta_compare'   => 'EXISTS',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			]
		);

		$onboarding = Onboarding_Controller::instance();
		$updated    = 0;
		$failed     = 0;

		foreach ( $vendor_ids as $vendor_id ) {
			$account_id = (string) get_post_meta( $vendor_id, '_nkv_stripe_account_id', true );
			if ( '' === $account_id ) {
				continue;
			}
			$err = $onboarding->sync_account_status( (int) $vendor_id, $account_id );
			if ( null === $err ) {
				$updated++;
			} else {
				$failed++;
			}
		}

		Logger::info( 'Cron vendor sync complete', [
			'updated' => $updated,
			'failed'  => $failed,
			'total'   => count( $vendor_ids ),
		] );
	}
}
