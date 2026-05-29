<?php
/**
 * Frontend CSS pro storefront + WC stránky (single product, košík, pokladna,
 * thank-you). Tokeny se inlinují přes `nkzmp/v1/storefront/tokens` filter,
 * aby Screeno mohlo přebít branding bez editace kódu / CSS.
 *
 * Vypnutí WC stylingu: add_filter( 'nkzmp/v1/storefront/style_wc', '__return_false' );
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private static ?Assets $instance = null;

	public static function instance(): Assets {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		$on_vendor = (bool) get_query_var( 'nkzmp_vendor_slug' ) || (bool) get_query_var( 'nkzmp_vendor_archive' );
		$on_wc     = function_exists( 'is_woocommerce' ) && (
			is_product() || is_cart() || is_checkout() || is_account_page() || self::is_order_received()
		);

		if ( ! $on_vendor && ! $on_wc ) {
			return;
		}

		wp_enqueue_style(
			'nkz-mp-storefront',
			NKZMP_STOREFRONT_URL . 'assets/storefront.css',
			[],
			NKZMP_STOREFRONT_VERSION
		);

		// Token override jako inline <style> – přebíjí :root defaulty v storefront.css.
		$inline = self::tokens_css();
		if ( $inline !== '' ) {
			wp_add_inline_style( 'nkz-mp-storefront', $inline );
		}

		// WC frontend styling jen na WC stránkách + pokud není explicitně vypnut.
		if ( $on_wc && apply_filters( 'nkzmp/v1/storefront/style_wc', true ) ) {
			wp_enqueue_style(
				'nkz-mp-storefront-wc',
				NKZMP_STOREFRONT_URL . 'assets/wc-frontend.css',
				[ 'nkz-mp-storefront' ],
				NKZMP_STOREFRONT_VERSION
			);
		}
	}

	/**
	 * Inline CSS proměnné přebíjející defaulty v storefront.css :root.
	 * Pro Screeno: filtr vrátí jiné hodnoty, žádná editace kódu nutná.
	 */
	private static function tokens_css(): string {
		$tokens = (array) apply_filters( 'nkzmp/v1/storefront/tokens', [] );
		if ( empty( $tokens ) ) {
			return '';
		}
		$map = [
			'accent'      => '--nkzmp-color-accent',
			'accent_hover' => '--nkzmp-color-accent-hover',
			'accent_soft' => '--nkzmp-color-accent-soft',
			'bg'          => '--nkzmp-color-bg',
			'bg_warm'     => '--nkzmp-color-bg-warm',
			'surface'     => '--nkzmp-color-surface',
			'text'        => '--nkzmp-color-text',
			'text_muted'  => '--nkzmp-color-text-muted',
			'border'      => '--nkzmp-color-border',
			'border_soft' => '--nkzmp-color-border-soft',
			'radius'      => '--nkzmp-radius',
			'radius_soft' => '--nkzmp-radius-soft',
			'shadow'      => '--nkzmp-shadow',
			'font'        => '--nkzmp-font-family',
		];
		$css = '';
		foreach ( $map as $key => $var ) {
			if ( isset( $tokens[ $key ] ) && $tokens[ $key ] !== '' ) {
				$css .= sprintf( '%s:%s;', $var, esc_attr( (string) $tokens[ $key ] ) );
			}
		}
		return $css !== '' ? ':root{' . $css . '}' : '';
	}

	private static function is_order_received(): bool {
		return function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' );
	}
}
