<?php
/**
 * SearchBoost – chytřejší vyhledávání produktů.
 *
 *  - hledá i podle značky (prodejce) a kategorie: „eyra" najde všechny
 *    produkty značky Eyra, „náušnice" i produkty v kategorii Náušnice,
 *  - bez diakritiky i s ní,
 *  - překlep: když nic nenajde, zkusí nejbližší slovo z názvů produktů,
 *    značek a kategorií („nausnice" → „náušnice", „nahrdelnk" →
 *    „náhrdelník") a ukáže „Zobrazujeme výsledky pro …".
 *
 * Výstup je seznam ID produktů (post__in) – filtry a řazení zůstávají.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class SearchBoost {

	private const DICT = 'nkzmp_search_dict';

	/** Opravený výraz z posledního hledání (pro hlášku). */
	public static string $corrected = '';
	public static string $original  = '';

	public static function fold( string $s ): string {
		$s = function_exists( 'remove_accents' ) ? remove_accents( $s ) : $s;
		return trim( mb_strtolower( $s ) );
	}

	/**
	 * ID produktů pro hledaný výraz (text ∪ značka ∪ kategorie, s opravou
	 * překlepu). Prázdné pole = nic nenalezeno.
	 *
	 * @return int[]
	 */
	public static function ids( string $q ): array {
		$q = trim( $q );
		self::$corrected = '';
		self::$original  = $q;
		if ( $q === '' ) {
			return [];
		}
		$ids = self::match( $q );
		if ( ! $ids ) {
			$fixed = self::correct( $q );
			if ( $fixed !== '' && self::fold( $fixed ) !== self::fold( $q ) ) {
				$ids = self::match( $fixed );
				if ( $ids ) {
					self::$corrected = $fixed;
				}
			}
		}
		return $ids;
	}

	/** @return int[] */
	private static function match( string $q ): array {
		$ids = get_posts( [
			'post_type'        => 'product',
			'post_status'      => 'publish',
			's'                => $q,
			'fields'           => 'ids',
			'posts_per_page'   => -1,
			'no_found_rows'    => true,
			'suppress_filters' => false,
			'nkzmp_search_raw' => true, // ať se na tenhle dotaz nepověsí znovu SearchBoost
		] );
		$fq = self::fold( $q );
		if ( mb_strlen( $fq ) >= 3 ) {
			// Značky.
			$vendor_ids = [];
			foreach ( ShopFilters::product_vendors() as $vid => $v ) {
				$fn = self::fold( (string) ( is_array( $v ) ? $v['name'] : $v ) );
				if ( $fn !== '' && ( str_contains( $fn, $fq ) || ( mb_strlen( $fn ) >= 3 && str_contains( $fq, $fn ) ) ) ) {
					$vendor_ids[] = (int) $vid;
				}
			}
			if ( $vendor_ids ) {
				$ids = array_merge( $ids, get_posts( [
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery
						'relation' => 'OR',
						[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_ids, 'compare' => 'IN' ],
						[ 'key' => '_nkv_vendor_id', 'value' => $vendor_ids, 'compare' => 'IN' ],
					],
				] ) );
			}
			// Kategorie (vč. podkategorií).
			$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
			$tids  = [];
			if ( ! is_wp_error( $terms ) ) {
				foreach ( (array) $terms as $t ) {
					$fn = self::fold( (string) $t->name );
					if ( str_contains( $fn, $fq ) || ( mb_strlen( $fn ) >= 4 && str_contains( $fq, $fn ) ) ) {
						$tids[] = (int) $t->term_id;
					}
				}
			}
			if ( $tids ) {
				$ids = array_merge( $ids, get_posts( [
					'post_type'      => 'product',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'terms' => $tids, 'include_children' => true ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
				] ) );
			}
		}
		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	/** Slovník slov z názvů produktů, značek a kategorií (cache 1 h). */
	private static function dictionary(): array {
		$cached = get_transient( self::DICT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		global $wpdb;
		$texts = (array) $wpdb->get_col( "SELECT post_title FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		foreach ( ShopFilters::product_vendors() as $v ) {
			$texts[] = (string) ( is_array( $v ) ? $v['name'] : $v );
		}
		$terms = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true, 'fields' => 'names' ] );
		if ( ! is_wp_error( $terms ) ) {
			$texts = array_merge( $texts, (array) $terms );
		}
		$dict = [];
		foreach ( $texts as $t ) {
			foreach ( preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( (string) $t ) ) as $w ) {
				if ( mb_strlen( $w ) >= 3 && ! is_numeric( $w ) ) {
					$dict[ self::fold( $w ) ] = $w; // složené → původní (s diakritikou)
				}
			}
		}
		set_transient( self::DICT, $dict, HOUR_IN_SECONDS );
		return $dict;
	}

	/** Opraví překlepy slovo po slově; '' když není co opravit. */
	public static function correct( string $q ): string {
		$dict = self::dictionary();
		if ( ! $dict ) {
			return '';
		}
		$words   = preg_split( '/\s+/u', trim( $q ) );
		$changed = false;
		foreach ( $words as &$w ) {
			$fw = self::fold( $w );
			if ( mb_strlen( $fw ) < 3 || isset( $dict[ $fw ] ) ) {
				continue;
			}
			$max  = mb_strlen( $fw ) <= 4 ? 1 : 2;
			$best = null;
			$bd   = PHP_INT_MAX;
			foreach ( $dict as $fk => $orig ) {
				if ( abs( strlen( $fk ) - strlen( $fw ) ) > $max ) {
					continue;
				}
				// Předpona („nahrdel" → „náhrdelník") se počítá jako shoda.
				$d = str_starts_with( $fk, $fw ) && strlen( $fw ) >= 4 ? 0 : levenshtein( $fw, $fk );
				if ( $d < $bd || ( $d === $bd && $best !== null && strlen( $fk ) < strlen( self::fold( $best ) ) ) ) {
					$bd   = $d;
					$best = $orig;
				}
			}
			if ( $best !== null && $bd <= $max ) {
				$w       = $best;
				$changed = true;
			}
		}
		unset( $w );
		return $changed ? implode( ' ', $words ) : '';
	}

	public static function forget(): void {
		delete_transient( self::DICT );
	}

	/** Hláška „Zobrazujeme výsledky pro …" nad výsledky. */
	public static function note(): void {
		if ( self::$corrected === '' ) {
			return;
		}
		printf(
			'<p class="nkzmp-search-note" style="margin:0 0 16px;padding:10px 14px;border-radius:12px;background:#f2f6ff;color:#1f2937;">%s</p>',
			wp_kses(
				sprintf(
					/* translators: 1: opravený výraz, 2: původní výraz */
					__( 'Zobrazujeme výsledky pro <strong>„%1$s“</strong> – pro „%2$s“ jsme nic nenašli.', 'nkz-mp-storefront' ),
					esc_html( self::$corrected ),
					esc_html( self::$original )
				),
				[ 'strong' => [] ]
			)
		);
	}
}
