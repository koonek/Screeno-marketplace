<?php
/**
 * CartGrouping – Flér-style seskupení košíku/pokladny po prodejcích.
 *
 * Vizuální vrstva (nemění checkout logiku ani výpočet dopravy):
 *  - na každý cart/review řádek přidá třídu `nkzmp-vrow nkzmp-vrow-<id>`
 *  - do JS localizuje mapu { vendorId: name } pro prodejce v košíku
 *  - JS vloží hlavičku „Balíček od <name>" před první řádek skupiny
 *    a per-vendor mezisoučet za poslední; znovu se spustí na WC eventech
 *    (updated_cart_totals / updated_checkout).
 *
 * Vypnutí: add_filter( 'nkzmp/v1/storefront/cart_grouping', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class CartGrouping {

	private static ?CartGrouping $instance = null;

	public static function instance(): CartGrouping {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/cart_grouping', true ) ) {
			return;
		}
		add_filter( 'woocommerce_cart_item_class', [ $this, 'row_class' ], 10, 3 );
		add_filter( 'woocommerce_cart_item_name', [ $this, 'tag_name' ], 10, 3 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ], 20 );
	}

	/**
	 * Přidá vendor třídu na <tr> řádku košíku (funguje i v review-order tabulce).
	 *
	 * @param string $class
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 */
	public function row_class( $class, $cart_item, $cart_item_key ): string {
		$vid = self::item_vendor_id( $cart_item );
		return trim( $class . ' nkzmp-vrow nkzmp-vrow-' . $vid );
	}

	/**
	 * Do jména produktu přidá neviditelný marker s vendor ID – review-order
	 * tabulka v pokladně nepoužívá woocommerce_cart_item_class, takže potřebujeme
	 * fallback čitelný z DOM i tam.
	 *
	 * @param string $name
	 * @param array  $cart_item
	 * @param string $cart_item_key
	 */
	public function tag_name( $name, $cart_item, $cart_item_key ): string {
		$vid = self::item_vendor_id( $cart_item );
		return '<span class="nkzmp-vtag" data-vendor="' . esc_attr( (string) $vid ) . '" style="display:none"></span>' . $name;
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_cart' ) || ( ! is_cart() && ! is_checkout() ) ) {
			return;
		}
		if ( ! WC()->cart ) {
			return;
		}

		// Mapa vendorId → název pro hlavičky balíčků.
		$names = [ '0' => (string) apply_filters( 'nkzmp/v1/storefront/platform_label', __( 'Obchod', 'nkz-mp-storefront' ) ) ];
		foreach ( WC()->cart->get_cart() as $item ) {
			$vid = self::item_vendor_id( $item );
			if ( ! isset( $names[ (string) $vid ] ) && $vid > 0 ) {
				$title = get_the_title( $vid );
				$names[ (string) $vid ] = $title ?: ( '#' . $vid );
			}
		}

		// Per-vendor poštovné: NEPOČÍTÁME samostatně, čteme z právě vybrané
		// WC shipping rate (Method ukládá breakdown jako JSON do meta_data).
		// Tím je zaručeno že per-vendor čísla v UI vždy sednou s celkovým
		// WC totalem (WC rate je single source of truth). Když customer
		// vybere jinou metodu (Free shipping / flat) bez breakdownu, řádek
		// '+ Poštovné' v kartách prostě nezobrazíme – nezbývá kde brát čísla.
		$shipping = self::shipping_breakdown_from_active_rate();

		wp_register_script( 'nkz-mp-cart-grouping', NKZMP_STOREFRONT_URL . 'assets/cart-grouping.js', [], NKZMP_STOREFRONT_VERSION, true );
		wp_localize_script( 'nkz-mp-cart-grouping', 'nkzmpCartGroups', [
			'names'    => $names,
			'shipping' => $shipping,
			'i18n'     => [
				'package'  => __( 'Balíček od %s', 'nkz-mp-storefront' ),
				'subtotal' => __( 'Mezisoučet prodejce', 'nkz-mp-storefront' ),
				'shipping' => __( '+ Poštovné', 'nkz-mp-storefront' ),
			],
		] );
		wp_enqueue_script( 'nkz-mp-cart-grouping' );
	}

	/**
	 * Vrátí mapu vendorId → formátovaná cena z aktuálně vybrané shipping rate.
	 * Hledá v `WC()->cart->calculate_shipping()` packages → chosen rate s
	 * naším `_nkzmp_vendor_breakdown` meta. Když není nic vybráno nebo není
	 * to naše Method, vrátí prázdné pole (UI neukáže '+ Poštovné').
	 *
	 * @return array<string,string>
	 */
	private static function shipping_breakdown_from_active_rate(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [];
		}
		$packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
		$chosen   = (array) ( WC()->session ? WC()->session->get( 'chosen_shipping_methods', [] ) : [] );

		foreach ( $packages as $i => $package ) {
			$rates    = $package['rates'] ?? [];
			$chosen_id = $chosen[ $i ] ?? '';
			$rate     = $chosen_id !== '' && isset( $rates[ $chosen_id ] )
				? $rates[ $chosen_id ]
				: ( ! empty( $rates ) ? reset( $rates ) : null );
			if ( ! $rate instanceof \WC_Shipping_Rate ) {
				continue;
			}
			$meta = $rate->get_meta_data();
			if ( empty( $meta['_nkzmp_vendor_breakdown'] ) ) {
				continue;
			}
			$decoded = json_decode( (string) $meta['_nkzmp_vendor_breakdown'], true );
			if ( ! is_array( $decoded ) ) {
				continue;
			}
			$out = [];
			foreach ( $decoded as $vid => $cost ) {
				$cost = (float) $cost;
				if ( $cost > 0 ) {
					$out[ (string) $vid ] = trim( html_entity_decode( wp_strip_all_tags( wc_price( $cost ) ), ENT_QUOTES, 'UTF-8' ) );
				} else {
					$out[ (string) $vid ] = (string) __( 'zdarma', 'nkz-mp-storefront' );
				}
			}
			return $out;
		}
		return [];
	}

	/**
	 * Vendor ID z cart item (přes produkt meta, variace → parent).
	 *
	 * @param array $cart_item
	 */
	private static function item_vendor_id( $cart_item ): int {
		$product = $cart_item['data'] ?? null;
		if ( ! $product instanceof \WC_Product ) {
			return 0;
		}
		$pid = $product->get_parent_id() ?: $product->get_id();
		$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $vid <= 0 ) {
			$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		if ( $vid <= 0 && $product->get_id() !== $pid ) {
			$vid = (int) get_post_meta( $product->get_id(), '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $product->get_id(), '_nkv_vendor_id', true );
			}
		}
		return max( 0, $vid );
	}
}
