<?php
/**
 * Bázová REST třída – sdílené permission helpery a chybové odpovědi.
 *
 * @package NKZMP
 */

namespace NKZMP\Rest;

defined( 'ABSPATH' ) || exit;

abstract class ControllerBase {

	public const NAMESPACE = 'nkzmp/v1';

	abstract public function register_routes(): void;

	protected function error_forbidden( string $message = 'Forbidden' ): \WP_Error {
		return new \WP_Error( 'nkzmp_forbidden', $message, [ 'status' => 403 ] );
	}

	protected function error_not_found( string $message = 'Not found' ): \WP_Error {
		return new \WP_Error( 'nkzmp_not_found', $message, [ 'status' => 404 ] );
	}

	protected function error_bad_request( string $message = 'Bad request' ): \WP_Error {
		return new \WP_Error( 'nkzmp_bad_request', $message, [ 'status' => 400 ] );
	}

	protected function current_user_id(): int {
		return (int) get_current_user_id();
	}
}
