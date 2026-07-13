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
		if ( ! class_exists( \Normalizer::class ) ) {
			return; // intl není → nic neděláme
		}
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
		// Rychlý skip: čistě ASCII nebo už NFC → beze změny.
		if ( ! preg_match( '/[\x80-\xFF]/', $text ) ) {
			return $text;
		}
		if ( \Normalizer::isNormalized( $text, \Normalizer::FORM_C ) ) {
			return $text;
		}
		$out = \Normalizer::normalize( $text, \Normalizer::FORM_C );
		return is_string( $out ) ? $out : $text;
	}
}
