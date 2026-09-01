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
	 * Retrieve a Charge, optionally expanding balance_transaction (for fee lookup).
	 *
	 * @return array|null
	 */
	public function retrieve_charge( string $charge_id, array $expand = [] ): ?array {
		$params = [];
		foreach ( $expand as $i => $path ) {
			$params[ 'expand[' . $i . ']' ] = $path;
		}
		return $this->request( 'GET', "charges/{$charge_id}", $params );
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
	 * Create an Express connected account.
	 *
	 * @param array $params country, email, capabilities[], business_type?, metadata
	 */
	public function create_account( array $params, string $idempotency_key ): array {
		$res = $this->request( 'POST', 'accounts', $params, [ 'Idempotency-Key' => $idempotency_key ] );
		if ( null === $res || isset( $res['error'] ) ) {
			throw new \RuntimeException( 'Stripe account create failed: ' . ( $res['error']['message'] ?? 'transport' ) );
		}
		return $res;
	}

	/**
	 * Retrieve a connected account (for status sync).
	 */
	public function retrieve_account( string $account_id ): ?array {
		return $this->request( 'GET', "accounts/{$account_id}", [] );
	}

	/**
	 * Update connected account – např. re-request capability `transfers`
	 * u účtů založených bez ní.
	 *
	 * @param array $params např. ['capabilities' => ['transfers' => ['requested' => 'true']]]
	 */
	public function update_account( string $account_id, array $params ): ?array {
		return $this->request( 'POST', "accounts/{$account_id}", $params );
	}

	/**
	 * Create an Account Link for hosted onboarding.
	 *
	 * @param array $params account, refresh_url, return_url, type (account_onboarding|account_update)
	 */
	public function create_account_link( array $params ): array {
		$res = $this->request( 'POST', 'account_links', $params );
		if ( null === $res || isset( $res['error'] ) ) {
			throw new \RuntimeException( 'Stripe account_link failed: ' . ( $res['error']['message'] ?? 'transport' ) );
		}
		return $res;
	}

	/**
	 * Create an Express Dashboard login link.
	 */
	public function create_login_link( string $account_id ): array {
		$res = $this->request( 'POST', "accounts/{$account_id}/login_links", [] );
		if ( null === $res || isset( $res['error'] ) ) {
			throw new \RuntimeException( 'Stripe login_link failed: ' . ( $res['error']['message'] ?? 'transport' ) );
		}
		return $res;
	}

	/**
	 * List Stripe transfers in a time window. Auto-paginated.
	 *
	 * @return array<int, array<string,mixed>> List of transfer objects.
	 */
	public function list_transfers( int $created_gte, int $created_lte, int $limit_per_page = 100 ): array {
		$all          = [];
		$starting_after = null;
		do {
			$params = [
				'limit'          => $limit_per_page,
				'created[gte]'   => $created_gte,
				'created[lte]'   => $created_lte,
			];
			if ( $starting_after !== null ) {
				$params['starting_after'] = $starting_after;
			}
			$res = $this->request( 'GET', 'transfers', $params );
			if ( ! is_array( $res ) || ! isset( $res['data'] ) || ! is_array( $res['data'] ) ) {
				break;
			}
			foreach ( $res['data'] as $tr ) {
				$all[] = $tr;
			}
			$has_more = ! empty( $res['has_more'] );
			$last     = end( $res['data'] );
			$starting_after = $last && isset( $last['id'] ) ? (string) $last['id'] : null;
		} while ( $has_more && $starting_after );
		return $all;
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
