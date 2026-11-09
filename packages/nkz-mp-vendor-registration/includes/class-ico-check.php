<?php
/**
 * IcoCheck – kontrola IČO prodejce.
 *
 *  - Kontrolní číslice: poslední číslice IČO je dopočítaná z ostatních
 *    (vážený součet mod 11, stejně u CZ i SK). Překlep nebo vymyšlené
 *    číslo se pozná hned, bez internetu.
 *  - ARES: na pozadí ověří, že subjekt existuje, a uloží výsledek
 *    („ověřeno v ARES" / „v ARES nenalezeno"). Jen pro informaci správce –
 *    neblokuje (ARES občas nejede, slovenská IČO v něm nejsou).
 *
 * Ověřuje se při každé změně IČO (registrace, profil, administrace)
 * a jednorázově u stávajících prodejců.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class IcoCheck {

	public const META     = '_nkzmp_ico_ares';
	private const EVENT   = 'nkzmp_ico_verify';
	private const BACKFILL = 'nkzmp_ico_backfill_done';

	private static ?IcoCheck $instance = null;

	public static function instance(): IcoCheck {
		return self::$instance ??= new self();
	}

	public function init(): void {
		foreach ( [ 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ] as $hook ) {
			add_action( $hook, [ $this, 'on_meta' ], 10, 3 );
		}
		add_action( self::EVENT, [ $this, 'verify' ] );
		add_action( 'admin_init', [ $this, 'maybe_backfill' ] );
	}

	/** Platné IČO (6–8 číslic, doplní se nulami na 8, sedí kontrolní číslice)? */
	public static function valid( string $ico ): bool {
		$d = preg_replace( '/\D/', '', $ico );
		if ( strlen( (string) $d ) < 6 || strlen( (string) $d ) > 8 ) {
			return false;
		}
		$d   = str_pad( (string) $d, 8, '0', STR_PAD_LEFT );
		$sum = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			$sum += (int) $d[ $i ] * ( 8 - $i );
		}
		return ( 11 - $sum % 11 ) % 10 === (int) $d[7];
	}

	private static function current_ico( int $vendor_id ): string {
		return trim( (string) ( get_post_meta( $vendor_id, '_nkv_vendor_ico', true ) ?: get_post_meta( $vendor_id, '_nkzmp_vendor_ico', true ) ) );
	}

	/**
	 * Uložený výsledek pro aktuální IČO, nebo null (neověřeno / IČO se změnilo).
	 *
	 * @return array{state:string,ico:string,name?:string,active?:bool,at:int}|null
	 */
	public static function state( int $vendor_id ): ?array {
		$ico = self::current_ico( $vendor_id );
		if ( $ico === '' ) {
			return null;
		}
		$s = get_post_meta( $vendor_id, self::META, true );
		return is_array( $s ) && ( $s['ico'] ?? '' ) === $ico ? $s : null;
	}

	/** Krátký popis stavu pro administraci (HTML-safe text) – '' když IČO není. */
	public static function label( int $vendor_id ): string {
		if ( self::current_ico( $vendor_id ) === '' ) {
			return '';
		}
		$s = self::state( $vendor_id );
		if ( ! $s ) {
			self::schedule( $vendor_id );
			return esc_html__( 'ověřuje se v ARES…', 'nkz-mp-vendor-registration' );
		}
		switch ( $s['state'] ) {
			case 'invalid':
				return esc_html__( '✗ neplatné IČO (nesedí kontrolní číslice)', 'nkz-mp-vendor-registration' );
			case 'ok':
				return esc_html( '✓ ' . __( 'ověřeno v ARES', 'nkz-mp-vendor-registration' ) . ( ! empty( $s['name'] ) ? ': ' . $s['name'] : '' ) )
					. ( isset( $s['active'] ) && ! $s['active'] ? ' ' . esc_html__( '(subjekt zanikl)', 'nkz-mp-vendor-registration' ) : '' );
			case 'not_found':
				return esc_html__( '⚠ v ARES nenalezeno – zkontroluj s prodejcem', 'nkz-mp-vendor-registration' );
			case 'foreign':
				return esc_html__( 'zahraniční prodejce – ARES se neověřuje (kontrolní číslice sedí)', 'nkz-mp-vendor-registration' );
			default:
				return esc_html__( 'ARES zatím neodpověděl – zkusí se znovu', 'nkz-mp-vendor-registration' );
		}
	}

	/* ------------------------------------------------------------ ověření */

	public function on_meta( $meta_id, $object_id, $meta_key ): void {
		if ( $meta_key !== '_nkv_vendor_ico' && $meta_key !== '_nkzmp_vendor_ico' ) {
			return;
		}
		$type = get_post_type( (int) $object_id );
		if ( $type === 'nkv_vendor' || $type === 'nkzmp_vendor' ) {
			self::schedule( (int) $object_id );
		}
	}

	private static function schedule( int $vendor_id, int $delay = 5 ): void {
		if ( ! wp_next_scheduled( self::EVENT, [ $vendor_id ] ) ) {
			wp_schedule_single_event( time() + $delay, self::EVENT, [ $vendor_id ] );
		}
	}

	public function verify( $vendor_id ): void {
		$vendor_id = (int) $vendor_id;
		$ico       = self::current_ico( $vendor_id );
		if ( $ico === '' ) {
			delete_post_meta( $vendor_id, self::META );
			return;
		}
		$state = [ 'ico' => $ico, 'at' => time() ];
		if ( ! self::valid( $ico ) ) {
			$state['state'] = 'invalid';
		} elseif ( strtoupper( (string) get_post_meta( $vendor_id, '_nkv_stripe_country', true ) ) === 'SK' ) {
			$state['state'] = 'foreign';
		} else {
			$r = AresLookup::fetch( $ico );
			if ( is_wp_error( $r ) ) {
				$state['state'] = 'unknown';
				self::schedule( $vendor_id, HOUR_IN_SECONDS ); // ARES nejede – zkusit později
			} elseif ( empty( $r['found'] ) ) {
				$state['state'] = 'not_found';
			} else {
				$state['state']  = 'ok';
				$state['name']   = (string) ( $r['name'] ?? '' );
				$state['active'] = ! empty( $r['active'] );
			}
		}
		update_post_meta( $vendor_id, self::META, $state );
	}

	/** Jednorázově ověřit stávající prodejce (rozloženo, ať ARES nezahltíme). */
	public function maybe_backfill(): void {
		if ( get_option( self::BACKFILL ) ) {
			return;
		}
		update_option( self::BACKFILL, time(), false );
		$ids = get_posts( [ 'post_type' => [ 'nkv_vendor', 'nkzmp_vendor' ], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		$n   = 0;
		foreach ( (array) $ids as $id ) {
			if ( self::current_ico( (int) $id ) !== '' && ! self::state( (int) $id ) ) {
				self::schedule( (int) $id, 30 + 3 * $n++ );
			}
		}
	}
}
