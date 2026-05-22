<?php
/**
 * Money math – minor units, deterministic rounding, largest-remainder allocation.
 *
 * Port z legacy `nkvsvs_*` procedurálních helperů. Žádné WP/WC závislosti,
 * pure PHP. Currency je ISO kód (CZK, EUR, USD, ...). Minor units = haléře/centy.
 *
 * @package NKZMP
 */

namespace NKZMP\Support;

defined( 'ABSPATH' ) || exit;

final class Money {

	/**
	 * Currencies bez desetinných míst (per Stripe specs). Minor factor = 1.
	 *
	 * @return string[]
	 */
	public static function zero_decimal_currencies(): array {
		return [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ];
	}

	public static function minor_factor( string $currency ): int {
		return in_array( strtoupper( $currency ), self::zero_decimal_currencies(), true ) ? 1 : 100;
	}

	/**
	 * Float major → integer minor. Deterministic half-up rounding.
	 */
	public static function to_minor( float $amount, string $currency ): int {
		$factor = self::minor_factor( $currency );
		return (int) round( $amount * $factor, 0, PHP_ROUND_HALF_UP );
	}

	/**
	 * Minor → display string. **Jen pro UI, ne pro výpočty.**
	 */
	public static function from_minor_display( int $minor, string $currency ): string {
		$factor = self::minor_factor( $currency );
		$value  = $minor / $factor;
		$nf     = function_exists( 'number_format_i18n' )
			? number_format_i18n( $value, 1 === $factor ? 0 : 2 )
			: number_format( $value, 1 === $factor ? 0 : 2 );
		return $nf . ' ' . strtoupper( $currency );
	}

	/**
	 * Largest-remainder alokace integer amount podle vah.
	 * Součet vrácených hodnot je vždy přesně $total.
	 *
	 * @param int                $total   Celkové množství v minor units.
	 * @param array<string,int|float> $weights Mapa key => nezáporná váha.
	 * @return array<string,int>
	 */
	public static function allocate_largest_remainder( int $total, array $weights ): array {
		$sum_w = array_sum( $weights );
		if ( $sum_w <= 0 || 0 === $total ) {
			return array_map( static fn() => 0, $weights );
		}

		$floor_vals = [];
		$remainders = [];
		foreach ( $weights as $k => $w ) {
			$e                  = $total * $w / $sum_w;
			$floor_vals[ $k ]   = (int) floor( $e );
			$remainders[ $k ]   = $e - floor( $e );
		}

		$assigned = array_sum( $floor_vals );
		$leftover = $total - $assigned;

		// Stable tie-break by key order: php arsort with SORT_NUMERIC preserves insertion order on ties.
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
}
