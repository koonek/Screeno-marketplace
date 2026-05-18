<?php
/**
 * Stripe API client wrapper.
 *
 * Uses stripe/stripe-php if available (bundled via Composer), else falls back to wp_remote_post.
 * Always passes per-request API key — never touches \Stripe::setApiKey() globally.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Stripe_Client {

	private string $api_key;

	public function __construct( ?string $api_key = null ) {
		$this->api_key = $api_key ?? Plugin::secret_key();
	}

	public function is_ready(): bool {
		return '' !== $this->api_key;
	}

	/**
	 * Retrieve a PaymentIntent (used to resolve latest charge id).
	 *
	 * @return array|null Decoded object or null on failure.
	 */
	public function retrieve_payment_intent( string $pi_id ): ?array {
		return $this->request( 'GET', "payment_intents/{$pi_id}", [] );
	}

	/**
	 * Create a Stripe Transfer.
	 *
	 * @param array  $params         destination, amount, currency, transfer_group, source_transaction?, metadata
	 * @param string $idempotency_key
	 */
	public function create_transfer( array $params, string $idempotency_key ): array {
		$res = $this->request( 'POST', 'transfers', $params, [ 'Idempotency-Key' => $idempotency_key ] );
		if ( null === $res ) {
			throw new \RuntimeException( 'Stripe transfer failed: empty response' );
		}
		if ( isset( $res['error'] ) ) {
			throw new \RuntimeException( 'Stripe transfer error: ' . ( $res['error']['message'] ?? 'unknown' ) );
		}
		return $res;
	}

	/**
	 * Reverse a Stripe Transfer.
	 */
	public function reverse_transfer( string $transfer_id, array $params, string $idempotency_key ): array {
		$res = $this->request( 'POST', "transfers/{$transfer_id}/reversals", $params, [ 'Idempotency-Key' => $idempotency_key ] );
		if ( null === $res ) {
			throw new \RuntimeException( 'Stripe reversal failed: empty response' );
		}
		if ( isset( $res['error'] ) ) {
			throw new \RuntimeException( 'Stripe reversal error: ' . ( $res['error']['message'] ?? 'unknown' ) );
		}
		return $res;
	}

	/**
	 * Low-level request via wp_remote_*. Keeps zero dependency footprint by default.
	 * If stripe-php is loaded by another plugin, we still use HTTP — to avoid coupling to its version.
	 *
	 * @return array|null Decoded body, or null on transport failure (response['error'] set on API error).
	 */
	private function request( string $method, string $path, array $params, array $headers = [] ): ?array {
		$url  = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => array_merge(
				[
					'Authorization'  => 'Bearer ' . $this->api_key,
					'Stripe-Version' => '2024-06-20',
				],
				$headers
			),
		];

		if ( 'GET' === $method ) {
			if ( ! empty( $params ) ) {
				$url = add_query_arg( $params, $url );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body'] = self::build_form( $params );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			Logger::error( 'Stripe transport error', [ 'path' => $path, 'msg' => $response->get_error_message() ] );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		if ( $code >= 400 ) {
			Logger::error( 'Stripe API error', [ 'path' => $path, 'code' => $code, 'body' => $json ?: $body ] );
			return is_array( $json ) ? $json : [ 'error' => [ 'message' => $body ] ];
		}
		return is_array( $json ) ? $json : null;
	}

	/**
	 * Stripe expects nested params as form-encoded (metadata[foo]=bar). http_build_query handles this.
	 */
	private static function build_form( array $params ): string {
		return http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}
}
