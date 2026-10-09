<?php
/**
 * Numbering – číselné řady dokladů.
 *
 * Každý dodavatel (Art of život, každý prodejce) má vlastní řadu, zvlášť
 * pro faktury a pro dobropisy, a řada se čísluje od začátku každý rok:
 *
 *   AOZ-2026-00001        doklad Art of život
 *   AOZ-D-2026-00001      dobropis Art of život
 *   P3613-2026-00001      doklad prodejce #3613
 *
 * Číslo se přiděluje pod zámkem v databázi a čte se přímo z ní, ne
 * z cache. Na webu běží persistentní object cache – dvě souběžné
 * objednávky by jinak mohly dostat stejné číslo, a duplicitní číslo
 * dokladu je chyba, kterou už nejde vzít zpět.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Numbering {

	/**
	 * @param string $issuer 'platform' nebo ID prodejce.
	 * @param string $type   'invoice' | 'credit'.
	 */
	public static function next( string $issuer, string $type, ?int $at = null ): string {
		$year   = wp_date( 'Y', $at ?? time() );
		$series = self::series( $issuer, $type );
		$seq    = self::increment( 'nkzmp_inv_seq_' . md5( $series . '|' . $year ) );
		return $series . '-' . $year . '-' . str_pad( (string) $seq, 5, '0', STR_PAD_LEFT );
	}

	/** Prefix řady bez roku a pořadí, např. „AOZ", „AOZ-D", „P3613". */
	public static function series( string $issuer, string $type ): string {
		$s = Settings::get();
		if ( $issuer === 'platform' ) {
			$base = rtrim( (string) $s['prefix_platform'], '-' ) ?: 'AOZ';
		} else {
			$base = rtrim( str_replace( '{vendor}', $issuer, (string) $s['prefix_vendor'] ), '-' ) ?: ( 'P' . $issuer );
		}
		if ( $type === 'credit' ) {
			$base .= '-' . ( trim( (string) $s['prefix_credit'], '-' ) ?: 'D' );
		}
		return $base;
	}

	/** Atomické +1 nad hodnotou v options. */
	private static function increment( string $option ): int {
		global $wpdb;
		$lock = 'nkzmp_inv_seq';
		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $lock ) );
		try {
			$current = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				$option
			) );
			$next = $current + 1;
			$wpdb->query( $wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')
				 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
				$option,
				(string) $next
			) );
			wp_cache_delete( $option, 'options' );
			return $next;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}
}
