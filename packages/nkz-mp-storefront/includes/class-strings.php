<?php
/**
 * Strings – český fallback překlad pro WooCommerce řetězce, které zůstávají
 * anglicky (např. „Proceed to Checkout", „Billing Details", „Your Order").
 *
 * Locale-gated: aplikuje se jen když je web v češtině (cs / cs_CZ), aby jiný
 * projekt (Screeno v jiném jazyce) nebyl ovlivněn. Override jde vypnout
 * filtrem `nkzmp/v1/storefront/cs_fallback` → false.
 *
 * Není to náhrada za .po/.mo (to je Fáze 2 i18n) – jen praktická záplata,
 * aby pokladna/košík nebyly půl-anglické, dokud WC překlad nedoběhne.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Strings {

	private static ?Strings $instance = null;

	public static function instance(): Strings {
		return self::$instance ??= new self();
	}

	/** @var array<string,string> */
	private array $map = [];

	public function init(): void {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		if ( strpos( (string) $locale, 'cs' ) !== 0 ) {
			return; // jen čeština
		}
		if ( ! apply_filters( 'nkzmp/v1/storefront/cs_fallback', true ) ) {
			return;
		}
		$this->map = [
			// Košík
			'Cart Totals'            => 'Celkem v košíku',
			'Update cart'            => 'Aktualizovat košík',
			'Update Cart'            => 'Aktualizovat košík',
			'Apply coupon'           => 'Použít kupón',
			'Coupon:'                => 'Kupón:',
			'Coupon code'            => 'Kód kupónu',
			'Proceed to checkout'    => 'Pokračovat k pokladně',
			'Proceed to Checkout'    => 'Pokračovat k pokladně',
			'Product'                => 'Produkt',
			'Price'                  => 'Cena',
			'Quantity'               => 'Množství',
			'Subtotal'               => 'Mezisoučet',
			'Remove this item'       => 'Odebrat položku',
			// Pokladna
			'Billing details'        => 'Fakturační údaje',
			'Billing Details'        => 'Fakturační údaje',
			'Ship to a different address?' => 'Doručit na jinou adresu?',
			'Additional information' => 'Doplňující informace',
			'Your order'             => 'Vaše objednávka',
			'Your Order'             => 'Vaše objednávka',
			'First name'             => 'Jméno',
			'First Name'             => 'Jméno',
			'Last name'              => 'Příjmení',
			'Last Name'              => 'Příjmení',
			'Company name'           => 'Název firmy',
			'Country / Region'       => 'Země / Region',
			'Street address'         => 'Ulice a č.p.',
			'Town / City'            => 'Město',
			'Postcode / ZIP'         => 'PSČ',
			'Phone'                  => 'Telefon',
			'Email address'          => 'E-mailová adresa',
			'Order notes'            => 'Poznámka k objednávce',
			'Place order'            => 'Objednat',
			'Have a coupon?'         => 'Máš kupón?',
			'Click here to enter your code' => 'Klikni sem pro zadání kódu',
			// Thank you
			'Order received'         => 'Objednávka přijata',
			'Thank you. Your order has been received.' => 'Děkujeme. Tvoji objednávku jsme přijali.',
			'Order number:'          => 'Číslo objednávky:',
			'Date:'                  => 'Datum:',
			'Total:'                 => 'Celkem:',
			'Payment method:'        => 'Způsob platby:',
			'Order details'          => 'Detail objednávky',
		];

		add_filter( 'gettext', [ $this, 'translate' ], 20, 3 );
		add_filter( 'gettext_with_context', [ $this, 'translate_ctx' ], 20, 4 );
	}

	/**
	 * @param string $translated
	 * @param string $text
	 * @param string $domain
	 */
	public function translate( $translated, $text, $domain ): string {
		// Záměrně napříč doménami – některá témata renderují WC řetězce přes
		// vlastní textdomain (např. „Cart Totals", „Proceed to Checkout" s
		// velkými písmeny), takže omezení na 'woocommerce' je nechytí.
		// Bezpečné: překládáme jen přesnou shodu z tight mapy a jen když
		// nebyl řetězec už přeložen (tj. zůstal anglický originál).
		if ( $translated === $text && isset( $this->map[ $text ] ) ) {
			return $this->map[ $text ];
		}
		return $translated;
	}

	/**
	 * @param string $translated
	 * @param string $text
	 * @param string $context
	 * @param string $domain
	 */
	public function translate_ctx( $translated, $text, $context, $domain ): string {
		return $this->translate( $translated, $text, $domain );
	}
}
