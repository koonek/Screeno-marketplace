<?php
/**
 * Stripe webhook receiver.
 *
 * Currently handles only `account.updated` — keeps connected-account status in sync
 * without forcing the admin to click "Obnovit stav".
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Webhook_Controller {

	private static ?Webhook_Controller $instance = null;
	public static function instance(): Webhook_Controller { return self::$instance ??= new self(); }

	public const ROUTE_NS   = 'nkv-svs/v1';
	public const ROUTE_PATH = '/webhook';
	public const OPTION_SECRET = 'nkv_svs_webhook_secret';

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public static function endpoint_url(): string {
		return rest_url( self::ROUTE_NS . self::ROUTE_PATH );
	}

	public function register_route(): void {
		register_rest_route(
			self::ROUTE_NS,
			self::ROUTE_PATH,
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function handle( \WP_REST_Request $request ) {
		$secret = (string) get_option( self::OPTION_SECRET, '' );
		if ( '' === $secret ) {
			return new \WP_REST_Response( [ 'error' => 'webhook_secret_not_configured' ], 503 );
		}

		$payload   = $request->get_body();
		$signature = $request->get_header( 'stripe-signature' );

		if ( ! self::verify_signature( $payload, (string) $signature, $secret ) ) {
			Logger::warning( 'Webhook signature verification failed' );
			return new \WP_REST_Response( [ 'error' => 'invalid_signature' ], 400 );
		}

		$event = json_decode( $payload, true );
		if ( ! is_array( $event ) || empty( $event['type'] ) ) {
			return new \WP_REST_Response( [ 'error' => 'invalid_payload' ], 400 );
		}

		try {
			switch ( $event['type'] ) {
				case 'account.updated':
					$this->on_account_updated( $event );
					break;
				default:
					// Ignore unknown events — Stripe will not retry on 200.
					break;
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Webhook handler failed', [ 'type' => $event['type'], 'err' => $e->getMessage() ] );
			return new \WP_REST_Response( [ 'error' => 'handler_failure' ], 500 );
		}

		return new \WP_REST_Response( [ 'ok' => true ], 200 );
	}

	private function on_account_updated( array $event ): void {
		$account_id = (string) ( $event['data']['object']['id'] ?? '' );
		if ( '' === $account_id ) {
			return;
		}
		$posts = get_posts(
			[
				'post_type'      => Vendors::POST_TYPE,
				'meta_key'       => '_nkv_stripe_account_id',
				'meta_value'     => $account_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'post_status'    => 'any',
			]
		);
		if ( empty( $posts ) ) {
			Logger::info( 'Webhook account.updated for unknown vendor', [ 'account' => $account_id ] );
			return;
		}
		Onboarding_Controller::instance()->sync_account_status( (int) $posts[0], $account_id );
	}

	/**
	 * Verify the Stripe-Signature header. Format: `t=<ts>,v1=<sig>[,v1=<sig>...]`.
	 * Uses 5-minute tolerance window.
	 */
	public static function verify_signature( string $payload, string $header, string $secret, int $tolerance = 300 ): bool {
		if ( '' === $header || '' === $secret ) {
			return false;
		}
		$timestamp = null;
		$sigs      = [];
		foreach ( explode( ',', $header ) as $part ) {
			$kv = explode( '=', $part, 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			if ( 't' === $kv[0] ) {
				$timestamp = (int) $kv[1];
			} elseif ( 'v1' === $kv[0] ) {
				$sigs[] = $kv[1];
			}
		}
		if ( ! $timestamp || empty( $sigs ) ) {
			return false;
		}
		if ( abs( time() - $timestamp ) > $tolerance ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
		foreach ( $sigs as $s ) {
			if ( hash_equals( $expected, $s ) ) {
				return true;
			}
		}
		return false;
	}
}
