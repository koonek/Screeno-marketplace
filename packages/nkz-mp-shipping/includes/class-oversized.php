<?php
/**
 * Oversized – doprava dohodou pro nadrozměrné zboží (obrazy apod.).
 *
 * Zásilkovna nebere zásilky přes své rozměry. Než klient vybere dopravce
 * pro velké kusy, prodejce se se zákazníkem domluví individuálně:
 *
 *  - u produktu je příznak „nadrozměr",
 *  - košík se rozdělí na dva balíky – běžné zboží jde Zásilkovnou,
 *    nadrozměrné dostane jedinou volbu „Doprava dohodou" za 0 Kč,
 *  - cenu a způsob dopravy si prodejce se zákazníkem domluví sám
 *    a doprava se platí mimo platformu.
 *
 * Rozdělení na dva balíky je záměrné: kdo si koupí obraz a misku, nechce
 * kvůli obrazu domlouvat dopravu i té misky.
 *
 * @package NKZMP\Shipping
 */

namespace NKZMP\Shipping;

defined( 'ABSPATH' ) || exit;

final class Oversized {

	public const META = '_nkzmp_oversized';

	/** ID rate pro „Doprava dohodou". */
	public const RATE_ID = 'nkzmp_by_arrangement';

	private static ?Oversized $instance = null;

	public static function instance(): Oversized {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'woocommerce_cart_shipping_packages', [ $this, 'split_packages' ], 20 );
		// Po přepisu ceny Zásilkovny (100), ať máme poslední slovo.
		add_filter( 'woocommerce_package_rates', [ $this, 'package_rates' ], 110, 2 );
		add_action( 'woocommerce_after_shipping_rate', [ $this, 'rate_note' ], 10, 2 );

		add_action( 'woocommerce_product_options_shipping', [ $this, 'admin_field' ], 20 );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_admin_field' ] );
	}

	/** Je produkt (nebo jeho rodič u varianty) nadrozměrný? */
	public static function is_oversized( \WC_Product $product ): bool {
		$pid = $product->get_parent_id() ?: $product->get_id();
		$on  = get_post_meta( $pid, self::META, true ) === 'yes';
		return (bool) apply_filters( 'nkzmp/v1/shipping/is_oversized', $on, $product );
	}

	/* ------------------------------------------------------------- košík */

	/**
	 * Oddělí nadrozměrné položky do vlastního balíku.
	 *
	 * @param array $packages
	 * @return array
	 */
	public function split_packages( $packages ): array {
		if ( ! is_array( $packages ) || ! $packages ) {
			return (array) $packages;
		}
		$out = [];
		foreach ( $packages as $package ) {
			$normal = [];
			$big    = [];
			foreach ( (array) ( $package['contents'] ?? [] ) as $key => $item ) {
				$product = $item['data'] ?? null;
				if ( $product instanceof \WC_Product && self::is_oversized( $product ) ) {
					$big[ $key ] = $item;
				} else {
					$normal[ $key ] = $item;
				}
			}
			if ( ! $big ) {
				$out[] = $package;
				continue;
			}
			if ( $normal ) {
				$out[] = self::package_with( $package, $normal, false );
			}
			$out[] = self::package_with( $package, $big, true );
		}
		return $out;
	}

	/** Kopie balíku s jiným obsahem a přepočtenými součty. */
	private static function package_with( array $package, array $contents, bool $oversized ): array {
		$package['contents']      = $contents;
		$package['contents_cost'] = array_sum( array_map(
			static fn( $i ) => (float) ( $i['line_total'] ?? 0 ),
			$contents
		) );
		$package['nkzmp_oversized'] = $oversized;
		return $package;
	}

	/**
	 * Nadrozměrný balík: jediná volba „Doprava dohodou".
	 * Běžný balík: „dohodou" nikdy.
	 *
	 * @param array<string,\WC_Shipping_Rate> $rates
	 * @param array                           $package
	 * @return array<string,\WC_Shipping_Rate>
	 */
	public function package_rates( $rates, $package ): array {
		$rates = is_array( $rates ) ? $rates : [];
		if ( empty( $package['nkzmp_oversized'] ) ) {
			unset( $rates[ self::RATE_ID ] );
			return $rates;
		}
		$rate = new \WC_Shipping_Rate(
			self::RATE_ID,
			(string) apply_filters( 'nkzmp/v1/shipping/arrangement_label', __( 'Doprava dohodou s prodejcem', 'nkz-mp-shipping' ) ),
			0,
			[],
			self::RATE_ID
		);
		return [ self::RATE_ID => $rate ];
	}

	/**
	 * Vysvětlivka pod volbou v košíku – zákazník musí vědět, že doprava
	 * není v ceně a že ho prodejce kontaktuje.
	 *
	 * @param \WC_Shipping_Rate $rate
	 * @param int|string        $index
	 */
	public function rate_note( $rate, $index = 0 ): void {
		if ( ! $rate instanceof \WC_Shipping_Rate || $rate->get_id() !== self::RATE_ID ) {
			return;
		}
		echo '<p class="nkzmp-arrangement-note" style="margin:6px 0 0;font-size:13px;color:#6b7280;">'
			. esc_html( (string) apply_filters(
				'nkzmp/v1/shipping/arrangement_note',
				__( 'Tohle zboží je nadrozměrné a Zásilkovna ho nepřepraví. Po objednávce vás prodejce kontaktuje a domluví s vámi způsob a cenu dopravy – tu platíte přímo prodejci.', 'nkz-mp-shipping' )
			) )
			. '</p>';
	}

	/* -------------------------------------------------------------- admin */

	public function admin_field(): void {
		if ( ! function_exists( 'woocommerce_wp_checkbox' ) ) {
			return;
		}
		woocommerce_wp_checkbox( [
			'id'          => self::META,
			'label'       => __( 'Nadrozměrné zboží', 'nkz-mp-shipping' ),
			'description' => __( 'Nevejde se do Zásilkovny (obrazy, velké kusy). V košíku se nabídne „Doprava dohodou s prodejcem" a prodejce se se zákazníkem domluví sám.', 'nkz-mp-shipping' ),
		] );
	}

	/**
	 * @param \WC_Product $product
	 */
	public function save_admin_field( $product ): void {
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC ověřuje nonce.
		$product->update_meta_data( self::META, isset( $_POST[ self::META ] ) ? 'yes' : 'no' );
	}
}
