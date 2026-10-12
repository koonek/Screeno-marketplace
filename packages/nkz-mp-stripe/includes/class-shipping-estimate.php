<?php
/**
 * Termín odeslání předobjednávek Screenuj podle balíčku prodejce.
 *
 *  - Rozjezd: tisk podle dohody – datum tisku v kartě prodejce. Do dne před tiskem běží
 *    předobjednávky (odpočet u produktu), pak je stopka (produkt nejde koupit, dokud se
 *    nezadá další termín). Odeslání = datum tisku + N pracovních dnů (výchozí 10).
 *  - Růst, Naplno: týdenní dávka – uzávěrka (výchozí pondělí 12:00) a odeslání (výchozí pátek)
 *    téhož týdne. Objednávka po uzávěrce jde do dávky dalšího týdne.
 *  - Pauzy (svátky, Vánoce) platí pro všechno: v pauze se neodesílá a pracovní dny se nepočítají.
 *
 * Termín se zobrazí u produktu, v košíku a uloží se k položce objednávky (vidět i v e-mailu).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Shipping_Estimate {

	public const ITEM_META = 'Odešleme do';

	private static ?Shipping_Estimate $instance = null;
	public static function instance(): Shipping_Estimate { return self::$instance ??= new self(); }

	public function init(): void {
		add_action( 'woocommerce_single_product_summary', [ $this, 'render_product_notice' ], 25 );
		add_shortcode( 'screeno_odeslani', [ $this, 'shortcode' ] );
		add_filter( 'woocommerce_is_purchasable', [ $this, 'filter_purchasable' ], 10, 2 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'cart_item_data' ], 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_order_item' ], 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * Výpočet (čistý – bez WP, kvůli testům).
	 * ------------------------------------------------------------------- */

	/**
	 * @param string             $package    rozjezd|rust|naplno
	 * @param string             $print_date Y-m-d (jen Rozjezd), '' = nezadáno
	 * @param \DateTimeImmutable $now        aktuální čas v časové zóně webu
	 * @param array              $cfg        weekly_cutoff_day (1=po … 7=ne), weekly_cutoff_time "H:i",
	 *                                       weekly_ship_day (1–7), rozjezd_business_days, pauses [[from,to] Y-m-d]
	 * @return array{open:bool, ship:?\DateTimeImmutable, order_until:?\DateTimeImmutable}|null  null = termín neřešíme
	 */
	public static function compute( string $package, string $print_date, \DateTimeImmutable $now, array $cfg ): ?array {
		$pauses = $cfg['pauses'] ?? [];

		if ( 'rozjezd' === $package ) {
			$print = \DateTimeImmutable::createFromFormat( '!Y-m-d', $print_date, $now->getTimezone() );
			if ( ! $print ) {
				return null; // termín tisku zatím není domluvený → nic neukazujeme ani neblokujeme
			}
			if ( $now >= $print ) {
				return [ 'open' => false, 'ship' => null, 'order_until' => null ];
			}
			return [
				'open'        => true,
				'ship'        => self::add_business_days( $print, max( 0, (int) $cfg['rozjezd_business_days'] ), $pauses ),
				'order_until' => $print->modify( '-1 day' ),
			];
		}

		if ( in_array( $package, [ 'rust', 'naplno' ], true ) ) {
			$cutoff_day = min( 7, max( 1, (int) $cfg['weekly_cutoff_day'] ) );
			$ship_day   = min( 7, max( 1, (int) $cfg['weekly_ship_day'] ) );
			[ $h, $m ]  = array_map( 'intval', explode( ':', (string) $cfg['weekly_cutoff_time'] ) + [ 0, 0 ] );

			// Nejbližší uzávěrka, která ještě nenastala.
			$cutoff = $now->setTime( $h, $m )->modify( '+' . ( ( $cutoff_day - (int) $now->format( 'N' ) + 7 ) % 7 ) . ' days' );
			if ( $cutoff < $now ) {
				$cutoff = $cutoff->modify( '+7 days' );
			}
			$ship = $cutoff->setTime( 0, 0 )->modify( '+' . ( ( $ship_day - $cutoff_day + 7 ) % 7 ) . ' days' );
			for ( $i = 0; $i < 60 && self::in_pause( $ship, $pauses ); $i++ ) {
				$ship = $ship->modify( '+7 days' );
			}
			return [ 'open' => true, 'ship' => $ship, 'order_until' => null ];
		}

		return null;
	}

	/** Přičte pracovní dny (bez víkendů a dnů v pauze). */
	public static function add_business_days( \DateTimeImmutable $from, int $days, array $pauses ): \DateTimeImmutable {
		$d = $from->setTime( 0, 0 );
		while ( $days > 0 ) {
			$d = $d->modify( '+1 day' );
			if ( (int) $d->format( 'N' ) <= 5 && ! self::in_pause( $d, $pauses ) ) {
				$days--;
			}
		}
		return $d;
	}

	public static function in_pause( \DateTimeImmutable $day, array $pauses ): bool {
		$ymd = $day->format( 'Y-m-d' );
		foreach ( $pauses as [ $from, $to ] ) {
			if ( $ymd >= $from && $ymd <= $to ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Řádky "17.12.2026 - 4.1.2027" (nebo jen jedno datum) → [[Y-m-d, Y-m-d], …]. Neplatné řádky se přeskočí.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public static function parse_pauses( string $raw ): array {
		$out = [];
		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			if ( ! preg_match_all( '/(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4})/', $line, $m, PREG_SET_ORDER ) ) {
				continue;
			}
			$dates = array_map( static fn( $x ) => sprintf( '%04d-%02d-%02d', $x[3], $x[2], $x[1] ), $m );
			$from  = $dates[0];
			$to    = $dates[1] ?? $dates[0];
			$out[] = $from <= $to ? [ $from, $to ] : [ $to, $from ];
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Napojení na WooCommerce.
	 * ------------------------------------------------------------------- */

	public static function config(): array {
		$s = Plugin::settings();
		return [
			'weekly_cutoff_day'     => (int) $s['delivery_weekly_cutoff_day'],
			'weekly_cutoff_time'    => (string) $s['delivery_weekly_cutoff_time'],
			'weekly_ship_day'       => (int) $s['delivery_weekly_ship_day'],
			'rozjezd_business_days' => (int) $s['delivery_rozjezd_business_days'],
			'pauses'                => self::parse_pauses( (string) $s['delivery_pauses'] ),
		];
	}

	/** Termín pro produkt (variace se berou přes rodiče). null = produkt Screenuj neřeší. */
	public static function for_product( int $product_id ): ?array {
		$parent    = (int) wp_get_post_parent_id( $product_id );
		$vendor_id = (int) get_post_meta( $parent ?: $product_id, '_nkv_vendor_id', true );
		if ( $vendor_id <= 0 ) {
			return null;
		}
		$package = Vendors::package_of( $vendor_id );
		if ( '' === $package ) {
			return null;
		}
		$print_date = (string) get_post_meta( $vendor_id, '_nkv_print_date', true );
		return self::compute( $package, $print_date, new \DateTimeImmutable( 'now', wp_timezone() ), self::config() );
	}

	/** 1 den, 2–4 dny, 5 dní (čeština má tři tvary, `_n()` umí jen dva). */
	public static function days_cs( int $n ): string {
		return $n . ' ' . ( 1 === $n ? 'den' : ( $n >= 2 && $n <= 4 ? 'dny' : 'dní' ) );
	}

	public static function format_date( \DateTimeImmutable $d ): string {
		return $d->format( 'j. n.' );
	}

	public function filter_purchasable( bool $purchasable, $product ): bool {
		if ( ! $purchasable || ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $purchasable;
		}
		$estimate = self::for_product( (int) $product->get_id() );
		return null === $estimate ? $purchasable : $estimate['open'];
	}

	public function notice_html( int $product_id ): string {
		$e = self::for_product( $product_id );
		if ( null === $e ) {
			return '';
		}
		if ( ! $e['open'] ) {
			$text = esc_html__( 'Předobjednávky jsou uzavřené, termín dalšího tisku brzy oznámíme.', 'nkz-woo-stripe-vendor-split' );
		} else {
			$text = esc_html( sprintf( __( 'Odešleme do %s', 'nkz-woo-stripe-vendor-split' ), self::format_date( $e['ship'] ) ) );
			if ( $e['order_until'] ) {
				$today = new \DateTimeImmutable( 'today', wp_timezone() );
				$left  = (int) $today->diff( $e['order_until'] )->days;
				$text .= '<br>' . esc_html(
					0 === $left
						? sprintf( __( 'Předobjednávky končí dnes (%s)', 'nkz-woo-stripe-vendor-split' ), self::format_date( $e['order_until'] ) )
						: sprintf( __( 'Předobjednávky končí %1$s · zbývá %2$s', 'nkz-woo-stripe-vendor-split' ), self::format_date( $e['order_until'] ), self::days_cs( $left ) )
				);
			}
		}
		return '<p class="nkv-ship-estimate">' . $text . '</p>';
	}

	public function render_product_notice(): void {
		echo $this->notice_html( (int) get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in notice_html
	}

	/** [screeno_odeslani] – pro Elementor šablony produktu, kde se standardní hook nevolá. */
	public function shortcode( $atts = [] ): string {
		$atts = shortcode_atts( [ 'id' => 0 ], (array) $atts );
		return $this->notice_html( (int) $atts['id'] ?: (int) get_the_ID() );
	}

	public function cart_item_data( array $data, array $cart_item ): array {
		$e = self::for_product( (int) ( $cart_item['variation_id'] ?: $cart_item['product_id'] ) );
		if ( $e && $e['open'] ) {
			$data[] = [ 'key' => __( self::ITEM_META, 'nkz-woo-stripe-vendor-split' ), 'value' => self::format_date( $e['ship'] ) ];
		}
		return $data;
	}

	/** Termín se k položce objednávky uloží natrvalo – pozdější změna nastavení ho už nemění. */
	public function save_order_item( $item, $cart_item_key, array $values ): void {
		$e = self::for_product( (int) ( $values['variation_id'] ?: $values['product_id'] ) );
		if ( $e && $e['open'] ) {
			$item->add_meta_data( __( self::ITEM_META, 'nkz-woo-stripe-vendor-split' ), $e['ship']->format( 'j. n. Y' ), true );
		}
	}
}
