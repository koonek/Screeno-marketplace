<?php
/**
 * AccountChrome – AOZ branding nad WooCommerce My Account.
 *
 * Vkládá brand header před navigaci, body class, vizuální separator
 * před vendor sekcemi v menu. Zbytek dělá CSS.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class AccountChrome {

	private static ?AccountChrome $instance = null;

	public static function instance(): AccountChrome {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'body_class', [ $this, 'body_class' ] );
		add_action( 'woocommerce_before_account_navigation', [ $this, 'render_brand_header' ] );
		add_filter( 'woocommerce_account_menu_item_classes', [ $this, 'menu_item_classes' ], 10, 2 );
	}

	public function body_class( array $classes ): array {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			$classes[] = 'nkzmp-account';
			if ( VendorContext::user_is_vendor() ) {
				$classes[] = 'nkzmp-account--vendor';
			}
		}
		return $classes;
	}

	public function render_brand_header(): void {
		if ( ! VendorContext::user_is_vendor() ) {
			return;
		}
		$vendor = VendorContext::current_vendor();
		if ( ! $vendor ) {
			return;
		}
		?>
		<div class="nkzmp-acc-brand">
			<span class="nkzmp-acc-brand-kicker"><?php esc_html_e( 'Prodejce', 'nkz-mp-vendor-dashboard' ); ?></span>
			<div class="nkzmp-acc-brand-name"><?php echo esc_html( (string) $vendor['name'] ); ?></div>
		</div>
		<?php
	}

	/**
	 * Přidá vendor-section třídu k menu položkám aby je CSS dokázal stylovat
	 * jako vlastní sekci s headerem.
	 *
	 * @param array  $classes
	 * @param string $endpoint
	 */
	public function menu_item_classes( $classes, $endpoint ) {
		if ( in_array( $endpoint, [ 'vendor', 'vendor-products', 'vendor-payouts' ], true ) ) {
			$classes[] = 'nkzmp-acc-nav-vendor';
			if ( $endpoint === 'vendor' ) {
				$classes[] = 'nkzmp-acc-nav-vendor-first';
			}
		}
		return $classes;
	}
}
