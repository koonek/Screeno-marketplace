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
		$on_vendor = self::is_vendor_page();
		$on_wc     = function_exists( 'is_woocommerce' ) && (
			is_shop() || is_product_taxonomy()
			|| is_product() || is_cart() || is_checkout() || is_account_page()
			|| self::is_order_received()
		);

		if ( ! $on_vendor && ! $on_wc ) {
			return;
		}

		self::ensure_storefront_css();
		if ( $on_wc && apply_filters( 'nkzmp/v1/storefront/style_wc', true ) ) {
			self::ensure_wc_css();
		}
	}

	/** Vynutí storefront CSS – idempotentní. Volá se i mimo WC stránky (shortcode). */
	public static function ensure_storefront_css(): void {
		if ( wp_style_is( 'nkz-mp-storefront', 'enqueued' ) ) {
			return;
		}
		wp_enqueue_style(
			'nkz-mp-storefront',
			NKZMP_STOREFRONT_URL . 'assets/storefront.css',
			[],
			NKZMP_STOREFRONT_VERSION
		);
		$inline = self::tokens_css();
		$font   = self::font_face_css();
		if ( $font !== '' ) {
			$inline = $font . $inline;
		}
		if ( $inline !== '' ) {
			wp_add_inline_style( 'nkz-mp-storefront', $inline );
		}
	}

	/**
	 * @font-face pro brand font na WC/storefront stránkách.
	 *
	 * Proč: Elementor aplikuje brand font (Fabio XM) jen na svůj obsah.
	 * WooCommerce a plugin-renderované stránky (produkt, košík, vendor)
	 * font nedostanou → fallback komolí české háčky (č š ž ř ě).
	 * Načteme stejný font pod vlastním názvem a aplikujeme ho tady.
	 *
	 * URL přes content_url() → přežije migraci domény. Cesta i celý blok
	 * je filtrovatelný. Prázdná URL = vypnuto.
	 */
	private static function font_face_css(): string {
		$base = content_url( '/uploads/2026/03/' );
		// STATIC font (ne variable). Variable Fabio-XM-Variable.ttf ma rozbite
		// ceske hacky (c s z r e). Static FabioXM-Regular.woff2 renderuje
		// spravne (pouziva ho i Elementor).
		$woff2  = (string) apply_filters( 'nkzmp/v1/storefront/font_woff2', $base . 'FabioXM-Regular.woff2' );
		$woff   = (string) apply_filters( 'nkzmp/v1/storefront/font_woff', $base . 'FabioXM-Regular.woff' );
		$family = (string) apply_filters( 'nkzmp/v1/storefront/font_family', 'Fabio XM AOZ' );
		if ( ( $woff2 === '' && $woff === '' ) || $family === '' ) {
			return '';
		}

		$stack = "'" . $family . "', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif";

		$srcs = [];
		if ( $woff2 !== '' ) {
			$srcs[] = "url('" . esc_url( $woff2 ) . "') format('woff2')";
		}
		if ( $woff !== '' ) {
			$srcs[] = "url('" . esc_url( $woff ) . "') format('woff')";
		}
		$src = implode( ',', $srcs );

		$css  = "@font-face{font-family:'" . $family . "';src:" . $src . ";font-weight:400 700;font-display:swap;}";
		$css .= 'body.woocommerce,body.woocommerce-page,'
			. '.nkzmp-single-vendor,.nkzmp-vendor-header,.nkzmp-vendor-card,'
			. '.nkzmp-latest-products,.nkzmp-product-categories,'
			. '.nkzmp-steps,.nkzmp-rm,.nkzmp-filters,'
			. '.woocommerce-page .product,.woocommerce div.product,'
			. '.woocommerce-cart,.woocommerce-checkout,.woocommerce-account'
			. '{font-family:' . $stack . ';}';

		return (string) apply_filters( 'nkzmp/v1/storefront/font_face_css', $css, $url, $family );
	}

	/** Vynutí WC styling – idempotentní. */
	public static function ensure_wc_css(): void {
		if ( wp_style_is( 'nkz-mp-storefront-wc', 'enqueued' ) ) {
			return;
		}
		self::ensure_storefront_css();
		wp_enqueue_style(
			'nkz-mp-storefront-wc',
			NKZMP_STOREFRONT_URL . 'assets/wc-frontend.css',
			[ 'nkz-mp-storefront' ],
			NKZMP_STOREFRONT_VERSION
		);
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

	/**
	 * Vendor page detekce robustně:
	 *  - query vars (po flush permalinks – standardní cesta)
	 *  - is_singular pro vendor post type (Dokan/náš)
	 *  - URL path fallback (pre-flush nebo když rewrite hooky padly)
	 */
	private static function is_vendor_page(): bool {
		if ( get_query_var( 'nkzmp_vendor_slug' ) || get_query_var( 'nkzmp_vendor_archive' ) ) {
			return true;
		}
		if ( function_exists( 'is_singular' ) && is_singular( [ 'nkzmp_vendor', 'nkv_vendor' ] ) ) {
			return true;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
		if ( is_string( $path ) && preg_match( '#/vendor(/|s/?$|/?$)#', (string) $path ) ) {
			return true;
		}
		return false;
	}
}
