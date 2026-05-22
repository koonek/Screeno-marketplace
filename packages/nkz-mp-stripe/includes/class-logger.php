<?php
/**
 * WC logger wrapper.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private const SOURCE = 'nkz-stripe-vendor-split';

	private static ?\WC_Logger_Interface $logger = null;

	private static function wc(): \WC_Logger_Interface {
		return self::$logger ??= wc_get_logger();
	}

	public static function info( string $message, array $context = [] ): void {
		self::wc()->info( self::format( $message, $context ), [ 'source' => self::SOURCE ] );
	}

	public static function error( string $message, array $context = [] ): void {
		self::wc()->error( self::format( $message, $context ), [ 'source' => self::SOURCE ] );
	}

	public static function debug( string $message, array $context = [] ): void {
		$s = Plugin::settings();
		if ( 'yes' !== ( $s['debug_logging'] ?? 'no' ) ) {
			return;
		}
		self::wc()->debug( self::format( $message, $context ), [ 'source' => self::SOURCE ] );
	}

	private static function format( string $message, array $context ): string {
		if ( empty( $context ) ) {
			return $message;
		}
		// Redact anything that looks like a secret.
		array_walk_recursive(
			$context,
			static function ( &$v, $k ) {
				if ( is_string( $k ) && preg_match( '/secret|api_key|password/i', $k ) ) {
					$v = '***';
				}
			}
		);
		return $message . ' ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
}
