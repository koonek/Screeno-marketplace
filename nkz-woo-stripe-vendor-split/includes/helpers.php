<?php
/**
 * Pure helpers for money math.
 *
 * @package NKVSVS
 */

defined( 'ABSPATH' ) || exit;

/**
 * Currencies with zero decimal minor units per Stripe docs.
 */
function nkvsvs_zero_decimal_currencies(): array {
	return [ 'BIF','CLP','DJF','GNF','JPY','KMF','KRW','MGA','PYG','RWF','UGX','VND','VUV','XAF','XOF','XPF' ];
}

/**
 * Minor units factor for a given currency.
 */
function nkvsvs_minor_factor( string $currency ): int {
	return in_array( strtoupper( $currency ), nkvsvs_zero_decimal_currencies(), true ) ? 1 : 100;
}

/**
 * Convert a float amount to Stripe minor units. Deterministic half-up rounding.
 */
function nkvsvs_to_minor( float $amount, string $currency ): int {
	$factor = nkvsvs_minor_factor( $currency );
	// (string) cast + round half-up via PHP_ROUND_HALF_UP avoids float drift surprises.
	return (int) round( $amount * $factor, 0, PHP_ROUND_HALF_UP );
}

/**
 * Format minor units to a human string (for admin UI, not for math).
 */
function nkvsvs_from_minor_display( int $minor, string $currency ): string {
	$factor = nkvsvs_minor_factor( $currency );
	$value  = $minor / $factor;
	return number_format_i18n( $value, 1 === $factor ? 0 : 2 ) . ' ' . strtoupper( $currency );
}

/**
 * Allocate an integer amount across weights using largest-remainder method.
 * Returns array with same keys as $weights, summing exactly to $total.
 *
 * @param int   $total   Total minor units to allocate.
 * @param array $weights Map of key => non-negative weight (int or float).
 */
function nkvsvs_allocate_largest_remainder( int $total, array $weights ): array {
	$sum_w = array_sum( $weights );
	if ( $sum_w <= 0 || 0 === $total ) {
		return array_map( static fn() => 0, $weights );
	}
	$exact      = [];
	$floor_vals = [];
	$remainders = [];
	foreach ( $weights as $k => $w ) {
		$e             = $total * $w / $sum_w;
		$exact[ $k ]   = $e;
		$floor_vals[ $k ] = (int) floor( $e );
		$remainders[ $k ] = $e - floor( $e );
	}
	$assigned = array_sum( $floor_vals );
	$leftover = $total - $assigned;
	// Distribute leftover by largest remainder, ties broken by key order (stable).
	arsort( $remainders, SORT_NUMERIC );
	foreach ( array_keys( $remainders ) as $k ) {
		if ( $leftover <= 0 ) {
			break;
		}
		$floor_vals[ $k ]++;
		$leftover--;
	}
	return $floor_vals;
}
