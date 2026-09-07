<?php
/**
 * Signature – ověření Stripe webhook podpisu (bez stripe-php SDK).
 *
 * Stripe posílá header `Stripe-Signature: t=<ts>,v1=<sig>[,v1=...]`. Signed
 * payload = `<ts>.<raw_body>`, HMAC-SHA256 se signing secretem (whsec_...).
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class Signature {

	/** Tolerance časového razítka (replay ochrana). */
	private const TOLERANCE = 300; // 5 minut

	/**
	 * @return bool True pokud podpis sedí.
	 */
	public static function verify( string $payload, string $sig_header, string $secret ): bool {
		if ( $secret === '' || $sig_header === '' ) {
			return false;
		}

		$timestamp = null;
		$sigs      = [];
		foreach ( explode( ',', $sig_header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( count( $kv ) !== 2 ) {
				continue;
			}
			[ $k, $v ] = $kv;
			if ( $k === 't' ) {
				$timestamp = $v;
			} elseif ( $k === 'v1' ) {
				$sigs[] = $v;
			}
		}

		if ( $timestamp === null || empty( $sigs ) ) {
			return false;
		}

		// Replay ochrana.
		if ( abs( time() - (int) $timestamp ) > self::TOLERANCE ) {
			return false;
		}

		$signed   = $timestamp . '.' . $payload;
		$expected = hash_hmac( 'sha256', $signed, $secret );

		foreach ( $sigs as $sig ) {
			if ( hash_equals( $expected, $sig ) ) {
				return true;
			}
		}
		return false;
	}
}
