<?php
/**
 * CacheFlush – pročistí cache webu po změnách od prodejce.
 *
 * Proč: když prodejce nahraje novou fotku nebo upraví produkt/profil, cache
 * plugin (nebo CDN) dál servíruje starou verzi stránky – prodejce i zákazník
 * pak vidí původní obrázek a myslí si, že se nahrání nepovedlo.
 *
 * Voláme purge u nejběžnějších cache pluginů. Každý blok je podmíněný, takže
 * když plugin není, nic se neděje. Vypnutí: `nkzmp/v1/cache/auto_purge` → false.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class CacheFlush {

	private static bool $done = false;

	/**
	 * Pročistí cache. Idempotentní v rámci requestu – i když se zavolá víckrát
	 * (uložení produktu + fotky), purge proběhne jen jednou.
	 */
	public static function purge(): void {
		if ( self::$done ) {
			return;
		}
		if ( ! apply_filters( 'nkzmp/v1/cache/auto_purge', true ) ) {
			return;
		}
		self::$done = true;

		// WordPress objektová cache + WC transienty.
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( class_exists( \WC_Cache_Helper::class ) ) {
			\WC_Cache_Helper::get_transient_version( 'product', true );
		}

		// WP Rocket
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		// LiteSpeed Cache – kromě stránek pročistíme i sloučené/minifikované
		// CSS+JS a kritické CSS. Bez toho se po updatu pluginu servírují staré
		// styly (LiteSpeed si drží vlastní kopii) a změny vzhledu se neprojeví.
		if ( has_action( 'litespeed_purge_all' ) || defined( 'LSCWP_V' ) ) {
			do_action( 'litespeed_purge_all' );
			do_action( 'litespeed_purge_all_cssjs' );
			do_action( 'litespeed_purge_all_ccss' );
			do_action( 'litespeed_purge_all_object' );
		}
		// W3 Total Cache
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		// WP Super Cache
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		// WP Fastest Cache
		if ( isset( $GLOBALS['wp_fastest_cache'] ) && method_exists( $GLOBALS['wp_fastest_cache'], 'deleteCache' ) ) {
			$GLOBALS['wp_fastest_cache']->deleteCache( true );
		}
		// Cache Enabler
		if ( has_action( 'cache_enabler_clear_complete_cache' ) ) {
			do_action( 'cache_enabler_clear_complete_cache' );
		}
		// SiteGround Optimizer
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		// Breeze (Cloudways)
		if ( has_action( 'breeze_clear_all_cache' ) ) {
			do_action( 'breeze_clear_all_cache' );
		}
		// Autoptimize (minifikované CSS/JS)
		if ( class_exists( '\autoptimizeCache' ) && method_exists( '\autoptimizeCache', 'clearall' ) ) {
			\autoptimizeCache::clearall();
		}
		// Cloudflare (oficiální plugin)
		if ( has_action( 'cloudflare_purge_everything' ) ) {
			do_action( 'cloudflare_purge_everything' );
		}

		/**
		 * Pro cache řešení, která tu nejsou (vlastní CDN apod.).
		 */
		do_action( 'nkzmp/v1/cache/purged' );
	}
}
