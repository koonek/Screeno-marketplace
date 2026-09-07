<?php
/**
 * StripeApi – minimální Stripe REST helper pro Billing.
 *
 * Bere secret key ze Stripe adapteru (\NKVSVS\Plugin::secret_key). Žádná
 * závislost na stripe-php SDK – čisté wp_remote_*.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class StripeApi {

	private string $key;

	public function __construct( ?string $key = null ) {
		if ( $key !== null ) {
			$this->key = $key;
		} elseif ( class_exists( \NKVSVS\Plugin::class ) ) {
			$this->key = (string) \NKVSVS\Plugin::secret_key();
		} else {
			$this->key = '';
		}
	}

	public function is_ready(): bool {
		return $this->key !== '';
	}

	public function create_customer( string $email, string $name, array $metadata = [] ): ?array {
		return $this->post( 'customers', [
			'email'    => $email,
			'name'     => $name,
			'metadata' => $metadata,
		] );
	}

	/**
	 * Najde nebo vytvoří recurring Price pro danou částku/měnu. Cache v option.
	 */
	public function ensure_price( int $amount_major, string $currency, string $product_name ): ?string {
		$currency = strtolower( $currency );
		$cache_key = sprintf( 'nkzmp_billing_price_%d_%s', $amount_major, $currency );
		$cached    = get_option( $cache_key );
		if ( $cached ) {
			return (string) $cached;
		}

		$res = $this->post( 'prices', [
			'unit_amount'  => $amount_major * 100,
			'currency'     => $currency,
			'recurring'    => [ 'interval' => 'month' ],
			'product_data' => [ 'name' => $product_name ],
		] );
		if ( ! $res || empty( $res['id'] ) ) {
			return null;
		}
		update_option( $cache_key, $res['id'], false );
		return (string) $res['id'];
	}

	/**
	 * Checkout Session v subscription módu. Vrátí URL.
	 */
	public function create_subscription_checkout( string $customer_id, string $price_id, string $success_url, string $cancel_url, array $metadata = [] ): ?array {
		return $this->post( 'checkout/sessions', [
			'mode'                 => 'subscription',
			'customer'             => $customer_id,
			'line_items'           => [ [ 'price' => $price_id, 'quantity' => 1 ] ],
			'success_url'          => $success_url,
			'cancel_url'           => $cancel_url,
			'subscription_data'    => [ 'metadata' => $metadata ],
			'metadata'             => $metadata,
		] );
	}

	/**
	 * Billing portal session (správa platby / zrušení).
	 */
	public function create_portal_session( string $customer_id, string $return_url ): ?array {
		return $this->post( 'billing_portal/sessions', [
			'customer'   => $customer_id,
			'return_url' => $return_url,
		] );
	}

	public function get_subscription( string $subscription_id ): ?array {
		return $this->get( 'subscriptions/' . $subscription_id );
	}

	public function get_checkout_session( string $session_id ): ?array {
		return $this->get( 'checkout/sessions/' . $session_id );
	}

	/**
	 * Nejnovější subscription zákazníka (jakýkoli stav). Pro self-heal, když
	 * jsme subscription ID neuložili (webhook nedorazil + prodejce se nevrátil),
	 * ale customer ID z checkoutu máme.
	 */
	public function get_latest_customer_subscription( string $customer_id ): ?array {
		$res = $this->request( 'GET', 'subscriptions', [
			'customer' => $customer_id,
			'status'   => 'all',
			'limit'    => 1,
		] );
		if ( ! is_array( $res ) || empty( $res['data'][0] ) ) {
			return null;
		}
		return (array) $res['data'][0];
	}

	private function get( string $path ): ?array {
		return $this->request( 'GET', $path, [] );
	}

	private function post( string $path, array $params ): ?array {
		return $this->request( 'POST', $path, $params );
	}

	private function request( string $method, string $path, array $params ): ?array {
		if ( ! $this->is_ready() ) {
			return null;
		}
		$url  = 'https://api.stripe.com/v1/' . ltrim( $path, '/' );
		$args = [
			'method'  => $method,
			'timeout' => 30,
			'headers' => [
				'Authorization'  => 'Bearer ' . $this->key,
				'Stripe-Version' => '2024-06-20',
			],
		];
		if ( 'GET' === $method ) {
			if ( $params ) {
				$url = add_query_arg( $params, $url );
			}
		} else {
			$args['headers']['Content-Type'] = 'application/x-www-form-urlencoded';
			$args['body'] = http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			error_log( '[NKZMP Billing] Stripe transport error: ' . $response->get_error_message() );
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			error_log( '[NKZMP Billing] Stripe API error ' . $code . ': ' . wp_json_encode( $body ) );
			return is_array( $body ) ? $body : null;
		}
		return is_array( $body ) ? $body : null;
	}
}
