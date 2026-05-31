<?php
/**
 * VocativeService – 5. pád (vokativ) jmen přes sklonovani-jmen.cz API.
 *
 * API kontrakt:
 *   GET https://www.sklonovani-jmen.cz/api?klic=KEY&pad=5&jmeno=Jan%20Novák
 *   → text/plain: "pane Nováku" / "paní Adelaido"
 *   Číselné kódy ("8", …) = chyba (typicky firemní jméno mimo 5. pád).
 *   Pro firmy v 5. pádu vrací "vážení".
 *
 * Návratová hodnota služby: jen jméno bez prefixu "pane/paní"
 * (např. "Nováku") – šablony oslovují "Ahoj {name_vocative}".
 *
 * Vrstvy fallbacku:
 *   1) post meta `_nkzmp_vendor_name_vocative` (perzistentní per vendor)
 *   2) filter `nkzmp/v1/vocative` (custom override / jiná knihovna)
 *   3) transient cache podle md5(jméno)
 *   4) API call → cache → meta
 *   5) Fallback = původní jméno (1. pád), nikdy nepadáme.
 *
 * @package NKZMP\Services
 */

namespace NKZMP\Services;

defined( 'ABSPATH' ) || exit;

final class VocativeService {

	private const ENDPOINT     = 'https://www.sklonovani-jmen.cz/api';
	private const CACHE_PREFIX = 'nkzmp_voc_';
	private const CACHE_TTL    = MONTH_IN_SECONDS;
	private const META_KEY     = '_nkzmp_vendor_name_vocative';
	private const TIMEOUT      = 3;

	/**
	 * Vrátí jméno v 5. pádu (bez prefixu pane/paní). Při jakékoli chybě
	 * vrátí původní jméno – mail se odešle, jen bez personalizace pádem.
	 */
	public static function get( string $name, ?int $vendor_id = null ): string {
		$name = trim( $name );
		if ( $name === '' ) {
			return '';
		}

		if ( $vendor_id ) {
			$meta = (string) get_post_meta( $vendor_id, self::META_KEY, true );
			if ( $meta !== '' ) {
				return $meta;
			}
		}

		$override = apply_filters( 'nkzmp/v1/vocative', null, $name, $vendor_id );
		if ( is_string( $override ) && $override !== '' ) {
			return $override;
		}

		$cache_key = self::CACHE_PREFIX . md5( mb_strtolower( $name, 'UTF-8' ) );
		$cached    = get_transient( $cache_key );
		if ( is_string( $cached ) && $cached !== '' ) {
			if ( $vendor_id ) {
				update_post_meta( $vendor_id, self::META_KEY, $cached );
			}
			return $cached;
		}

		$key = self::api_key();
		if ( $key === '' ) {
			return $name;
		}

		$result = self::call_api( $key, $name );
		if ( $result === null ) {
			return $name;
		}

		set_transient( $cache_key, $result, self::CACHE_TTL );
		if ( $vendor_id ) {
			update_post_meta( $vendor_id, self::META_KEY, $result );
		}
		return $result;
	}

	/** Smaže cachovaný vokativ pro vendora (volat po změně jména). */
	public static function forget( int $vendor_id ): void {
		delete_post_meta( $vendor_id, self::META_KEY );
	}

	private static function api_key(): string {
		if ( ! class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			return '';
		}
		$opts = get_option( \NKZMP\Admin\EmailSettings::OPTION, [] );
		$key  = is_array( $opts ) ? (string) ( $opts['sklonovani_jmen_api_key'] ?? '' ) : '';
		return trim( $key );
	}

	private static function call_api( string $key, string $name ): ?string {
		$url = add_query_arg(
			[
				'klic'  => $key,
				'pad'   => 5,
				'jmeno' => $name,
			],
			self::ENDPOINT
		);

		$resp = wp_remote_get(
			$url,
			[
				'timeout'    => self::TIMEOUT,
				'user-agent' => 'NKZ-Marketplace/1.0',
			]
		);
		if ( is_wp_error( $resp ) ) {
			return null;
		}
		if ( (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			return null;
		}
		$body = trim( (string) wp_remote_retrieve_body( $resp ) );
		if ( $body === '' || ctype_digit( $body ) ) {
			return null;
		}
		if ( mb_strtolower( $body, 'UTF-8' ) === 'vážení' ) {
			return null;
		}
		// Odstraň prefix "pane "/"paní " – placeholder dosazujeme do šablony,
		// kde si oslovení píše admin sám ("Ahoj {name_vocative}").
		$stripped = preg_replace( '/^(pane|paní)\s+/iu', '', $body );
		$stripped = trim( (string) $stripped );
		return $stripped !== '' ? $stripped : null;
	}
}
