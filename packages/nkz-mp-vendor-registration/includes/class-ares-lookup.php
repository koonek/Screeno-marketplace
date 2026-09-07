<?php
/**
 * ARES lookup endpoint — autocomplete jména firmy z IČO.
 *
 * REST: GET /wp-json/nkzmp-registration/v1/ares/<ico>
 * Cache 24h v transient.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class AresLookup {

	private static ?AresLookup $instance = null;

	public static function instance(): AresLookup {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register_route' ] );
	}

	public function register_route(): void {
		register_rest_route(
			'nkzmp-registration/v1',
			'/ares/(?P<ico>[0-9]{6,10})',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'handle' ],
				'permission_callback' => '__return_true',
				'args'                => [
					'ico' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);
	}

	public function handle( \WP_REST_Request $req ) {
		$ico = preg_replace( '/[^0-9]/', '', (string) $req['ico'] );
		if ( strlen( $ico ) < 6 || strlen( $ico ) > 10 ) {
			return new \WP_Error( 'invalid_ico', 'Invalid IČO format', [ 'status' => 400 ] );
		}
		$ico = str_pad( $ico, 8, '0', STR_PAD_LEFT );

		$cache_key = 'nkzmp_ares_' . $ico;
		$cached    = get_transient( $cache_key );
		if ( $cached !== false ) {
			return rest_ensure_response( $cached );
		}

		$url      = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/' . $ico;
		$response = wp_remote_get( $url, [
			'timeout' => 8,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ares_unreachable', 'ARES nedostupný', [ 'status' => 502 ] );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code === 404 ) {
			$data = [ 'found' => false ];
			set_transient( $cache_key, $data, HOUR_IN_SECONDS );
			return rest_ensure_response( $data );
		}
		if ( $code >= 400 ) {
			return new \WP_Error( 'ares_error', 'ARES vrátil chybu', [ 'status' => 502 ] );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			return new \WP_Error( 'ares_parse', 'ARES odpověď nelze přečíst', [ 'status' => 502 ] );
		}

		$data = [
			'found'   => true,
			'ico'     => $ico,
			'name'    => (string) ( $body['obchodniJmeno'] ?? '' ),
			'address' => $this->format_address( $body['sidlo'] ?? [] ),
			'active'  => empty( $body['datumZaniku'] ),
		];

		set_transient( $cache_key, $data, DAY_IN_SECONDS );
		return rest_ensure_response( $data );
	}

	private function format_address( $sidlo ): string {
		if ( ! is_array( $sidlo ) ) {
			return '';
		}
		$parts = array_filter( [
			(string) ( $sidlo['nazevUlice'] ?? '' ) . ' ' . trim( ( $sidlo['cisloDomovni'] ?? '' ) . '/' . ( $sidlo['cisloOrientacni'] ?? '' ), '/' ),
			(string) ( $sidlo['psc'] ?? '' ) . ' ' . (string) ( $sidlo['nazevObce'] ?? '' ),
		] );
		return trim( implode( ', ', array_map( 'trim', $parts ) ) );
	}
}
