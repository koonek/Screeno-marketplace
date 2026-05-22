<?php
/**
 * AdapterResult – jednoduchý value object pro výsledek adaptérové operace.
 *
 * @package NKZMP
 */

namespace NKZMP\Contracts;

defined( 'ABSPATH' ) || exit;

final class AdapterResult {

	private function __construct(
		public readonly bool $ok,
		public readonly array $data = [],
		public readonly ?string $error_code = null,
		public readonly ?string $error_message = null,
	) {}

	public static function success( array $data = [] ): self {
		return new self( true, $data );
	}

	public static function failure( string $code, string $message, array $data = [] ): self {
		return new self( false, $data, $code, $message );
	}
}
