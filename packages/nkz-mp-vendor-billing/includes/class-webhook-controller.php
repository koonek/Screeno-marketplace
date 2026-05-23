<?php
/**
 * WebhookController – Stripe Billing webhooks.
 *
 * REST: POST /wp-json/nkzmp/v1/billing/webhook
 *
 * Eventy:
 *  - checkout.session.completed (mode=subscription) → billing active +
 *    vendor active (pokud byl pending/awaiting)
 *  - invoice.paid                                   → billing active, reactivate
 *  - invoice.payment_failed                         → past_due; po grace → suspend
 *  - customer.subscription.deleted                  → canceled → suspend
 *
 * Vendor se najde podle metadata.vendor_id nebo podle customer ID v meta.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

use NKZMP\Vendor\Status;
use NKZMP\Vendor\StatusService;

defined( 'ABSPATH' ) || exit;

final class WebhookController {

	private static ?WebhookController $instance = null;

	public static function instance(): WebhookController {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_rest_route( 'nkzmp/v1', '/billing/webhook', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
		] );
	}

	public function handle( \WP_REST_Request $req ) {
		$payload = $req->get_body();

		// Signature verification. Pokud je v Settings signing secret, podpis se
		// VYŽADUJE (jinak 400). Bez secretu projde (dev/staging bez webhook secretu).
		$secret = (string) Settings::get()['webhook_secret'];
		if ( $secret !== '' ) {
			$sig = (string) $req->get_header( 'stripe_signature' );
			if ( ! Signature::verify( $payload, $sig, $secret ) ) {
				error_log( '[NKZMP Billing] webhook signature mismatch' );
				return new \WP_REST_Response( [ 'ok' => false, 'error' => 'invalid signature' ], 400 );
			}
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'ok' => false ], 400 );
		}

		$type   = (string) $event['type'];
		$object = $event['data']['object'] ?? [];

		switch ( $type ) {
			case 'checkout.session.completed':
				if ( ( $object['mode'] ?? '' ) === 'subscription' ) {
					$this->on_subscription_started( $object );
				}
				break;
			case 'invoice.paid':
				$this->on_invoice_paid( $object );
				break;
			case 'invoice.payment_failed':
				$this->on_invoice_failed( $object );
				break;
			case 'customer.subscription.deleted':
				$this->on_subscription_deleted( $object );
				break;
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	private function on_subscription_started( array $session ): void {
		$vendor_id = $this->resolve_vendor( $session );
		if ( $vendor_id <= 0 ) {
			return;
		}
		if ( ! empty( $session['subscription'] ) ) {
			update_post_meta( $vendor_id, NKZMP_BILLING_SUBSCRIPTION_META, (string) $session['subscription'] );
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, 'active' );
		delete_post_meta( $vendor_id, '_nkzmp_billing_failed_at' );
		$this->maybe_reactivate( $vendor_id );
		$this->audit( 'billing.subscription_started', $vendor_id, 'Subscription active' );
	}

	private function on_invoice_paid( array $invoice ): void {
		$vendor_id = $this->resolve_vendor( $invoice );
		if ( $vendor_id <= 0 ) {
			return;
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, 'active' );
		delete_post_meta( $vendor_id, '_nkzmp_billing_failed_at' );
		$this->maybe_reactivate( $vendor_id );
		$this->audit( 'billing.invoice_paid', $vendor_id, 'Invoice paid' );
	}

	private function on_invoice_failed( array $invoice ): void {
		$vendor_id = $this->resolve_vendor( $invoice );
		if ( $vendor_id <= 0 ) {
			return;
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, 'past_due' );
		if ( ! get_post_meta( $vendor_id, '_nkzmp_billing_failed_at', true ) ) {
			update_post_meta( $vendor_id, '_nkzmp_billing_failed_at', time() );
		}
		$this->audit( 'billing.payment_failed', $vendor_id, 'Payment failed → past_due' );

		// Grace period: pokud uplynula, suspend hned. Jinak nech cron/další fail.
		$grace = (int) Settings::get()['grace_days'] * DAY_IN_SECONDS;
		$failed_at = (int) get_post_meta( $vendor_id, '_nkzmp_billing_failed_at', true );
		if ( $grace <= 0 || ( time() - $failed_at ) >= $grace ) {
			$this->suspend( $vendor_id, 'billing_grace_elapsed' );
		}
	}

	private function on_subscription_deleted( array $subscription ): void {
		$vendor_id = $this->resolve_vendor( $subscription );
		if ( $vendor_id <= 0 ) {
			return;
		}
		update_post_meta( $vendor_id, NKZMP_BILLING_STATUS_META, 'canceled' );
		$this->suspend( $vendor_id, 'billing_subscription_canceled' );
		$this->audit( 'billing.subscription_deleted', $vendor_id, 'Subscription canceled → suspend' );
	}

	private function maybe_reactivate( int $vendor_id ): void {
		$status = ( new \NKZMP\Vendor\Repository() )->status( $vendor_id );
		if ( $status === Status::SUSPENDED ) {
			try {
				( new StatusService() )->transition( $vendor_id, Status::ACTIVE, [ 'source' => 'billing_reactivate' ] );
			} catch ( \Throwable $e ) {
				// Fallback: nastav meta přímo.
				update_post_meta( $vendor_id, '_nkzmp_vendor_status', 'active' );
			}
		}
	}

	private function suspend( int $vendor_id, string $reason ): void {
		$status = ( new \NKZMP\Vendor\Repository() )->status( $vendor_id );
		if ( $status === Status::ACTIVE ) {
			try {
				( new StatusService() )->transition( $vendor_id, Status::SUSPENDED, [ 'source' => $reason ] );
			} catch ( \Throwable $e ) {
				update_post_meta( $vendor_id, '_nkzmp_vendor_status', 'suspended' );
			}
		}
	}

	/**
	 * Najde vendor_id z metadata nebo podle customer ID.
	 */
	private function resolve_vendor( array $object ): int {
		// 1) metadata.vendor_id (na session / subscription).
		$meta_vid = (int) ( $object['metadata']['vendor_id'] ?? 0 );
		if ( $meta_vid > 0 ) {
			return $meta_vid;
		}
		// 2) customer ID → meta lookup.
		$customer = (string) ( $object['customer'] ?? '' );
		if ( $customer !== '' ) {
			global $wpdb;
			$vid = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
				NKZMP_BILLING_CUSTOMER_META,
				$customer
			) );
			if ( $vid > 0 ) {
				return $vid;
			}
		}
		return 0;
	}

	private function audit( string $action, int $vendor_id, string $summary ): void {
		if ( class_exists( \NKZMP\Audit\Recorder::class ) ) {
			( new \NKZMP\Audit\Recorder() )->record(
				action:      $action,
				entity_type: 'vendor',
				entity_id:   $vendor_id,
				summary:     $summary,
				actor_label: 'stripe_billing',
			);
		}
	}
}
