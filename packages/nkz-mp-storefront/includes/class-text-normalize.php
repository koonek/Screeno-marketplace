<?php
/**
 * TextNormalize – normalizuje výstupní text na Unicode NFC (složený tvar).
 *
 * Proč: část obsahu (produkty importované/kopírované z Macu) je uložená
 * v NFD (rozložené) formě – „č" jako „c" + kombinující háček U+030C. Font
 * pak kombinující značku vykreslí špatně (č → ć/ċ, ž → ż, ř s plovoucím
 * háčkem). Elementor obsah psaný přímo je NFC a renderuje se dobře.
 *
 * Normalizace na NFC sjednotí „c+háček" → „č" (jeden znak), který font
 * vykreslí správně. Idempotentní – NFC text nechá beze změny.
 *
 * Vyžaduje PHP intl (Normalizer). Bez něj se filtry neregistrují (no-op).
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/normalize_nfc', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class TextNormalize {

	private static ?TextNormalize $instance = null;

	public static function instance(): TextNormalize {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/normalize_nfc', true ) ) {
			return;
		}

		add_filter( 'the_title', [ $this, 'nfc' ], 5 );
		add_filter( 'the_content', [ $this, 'nfc' ], 5 );
		add_filter( 'the_excerpt', [ $this, 'nfc' ], 5 );
		add_filter( 'woocommerce_short_description', [ $this, 'nfc' ], 5 );
		add_filter( 'woocommerce_currency_symbol', [ $this, 'nfc' ], 5 );
		add_filter( 'woocommerce_product_get_name', [ $this, 'nfc' ], 5 );
		add_filter( 'woocommerce_product_get_description', [ $this, 'nfc' ], 5 );
		add_filter( 'woocommerce_product_get_short_description', [ $this, 'nfc' ], 5 );
		// Vendor jméno + bio (naše storefront data).
		add_filter( 'nkzmp/v1/storefront/vendor_name', [ $this, 'nfc' ], 5 );
		add_filter( 'nkzmp/v1/storefront/vendor_bio', [ $this, 'nfc' ], 5 );
	}

	/**
	 * @param mixed $text
	 * @return mixed
	 */
	public function nfc( $text ) {
		if ( ! is_string( $text ) || $text === '' ) {
			return $text;
		}
		return self::to_nfc( $text );
	}

	/**
	 * NFC normalizace. Preferuje intl Normalizer (kompletní), jinak fallback
	 * na ruční mapu kombinujících značek (bez závislosti) – pokrývá CZ/SK/PL.
	 */
	public static function to_nfc( string $text ): string {
		if ( $text === '' ) {
			return $text;
		}
		// Rychlý skip: žádné kombinující značky (U+0300–U+036F = \xCC\x80–\xCD\xAF).
		if ( strpos( $text, "\xCC" ) === false && strpos( $text, "\xCD" ) === false ) {
			return $text;
		}
		if ( class_exists( \Normalizer::class ) ) {
			$out = \Normalizer::normalize( $text, \Normalizer::FORM_C );
			if ( is_string( $out ) ) {
				return $out;
			}
		}
		return strtr( $text, self::map() );
	}

	/** @var array<string,string>|null */
	private static ?array $map = null;

	/** Base písmeno + kombinující značka → složený znak (CZ/SK/PL sada). */
	private static function map(): array {
		if ( self::$map !== null ) {
			return self::$map;
		}
		$caron = "\u{030C}";  // ̌  háček
		$acute = "\u{0301}";  // ́  čárka
		$ring  = "\u{030A}";  // ̊  kroužek
		$diaer = "\u{0308}";  // ̈  přehláska
		$circ  = "\u{0302}";  // ̂  vokáň

		$m = [];
		$add = static function ( array &$m, string $mark, array $pairs ): void {
			foreach ( $pairs as $base => $composed ) {
				$m[ $base . $mark ] = $composed;
			}
		};

		$add( $m, $caron, [
			'c' => 'č', 'C' => 'Č', 'd' => 'ď', 'D' => 'Ď', 'e' => 'ě', 'E' => 'Ě',
			'l' => 'ľ', 'L' => 'Ľ', 'n' => 'ň', 'N' => 'Ň', 'r' => 'ř', 'R' => 'Ř',
			's' => 'š', 'S' => 'Š', 't' => 'ť', 'T' => 'Ť', 'z' => 'ž', 'Z' => 'Ž',
		] );
		$add( $m, $acute, [
			'a' => 'á', 'A' => 'Á', 'c' => 'ć', 'C' => 'Ć', 'e' => 'é', 'E' => 'É',
			'i' => 'í', 'I' => 'Í', 'l' => 'ĺ', 'L' => 'Ĺ', 'n' => 'ń', 'N' => 'Ń',
			'o' => 'ó', 'O' => 'Ó', 'r' => 'ŕ', 'R' => 'Ŕ', 's' => 'ś', 'S' => 'Ś',
			'u' => 'ú', 'U' => 'Ú', 'y' => 'ý', 'Y' => 'Ý', 'z' => 'ź', 'Z' => 'Ź',
		] );
		$add( $m, $ring, [ 'a' => 'å', 'A' => 'Å', 'u' => 'ů', 'U' => 'Ů' ] );
		$add( $m, $diaer, [
			'a' => 'ä', 'A' => 'Ä', 'e' => 'ë', 'E' => 'Ë', 'i' => 'ï', 'I' => 'Ï',
			'o' => 'ö', 'O' => 'Ö', 'u' => 'ü', 'U' => 'Ü', 'y' => 'ÿ',
		] );
		$add( $m, $circ, [
			'a' => 'â', 'A' => 'Â', 'e' => 'ê', 'E' => 'Ê', 'i' => 'î', 'I' => 'Î',
			'o' => 'ô', 'O' => 'Ô', 'u' => 'û', 'U' => 'Û',
		] );

		self::$map = $m;
		return $m;
	}
}
