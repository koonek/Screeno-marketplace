<?php
/**
 * Redirect – vendor role z wp-admin na /muj-ucet/vendor.
 *
 * Předchází tomu, že vendor uvidí WP admin dashboard, který má cizí data.
 * Filter `nkzmp/v1/dashboard/redirect_vendor_admin` umožní vypnout.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class Redirect {

	private static ?Redirect $instance = null;

	public static function instance(): Redirect {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_init', [ $this, 'maybe_redirect' ] );
		add_filter( 'show_admin_bar', [ $this, 'hide_admin_bar_for_vendors' ] );
	}

	public function maybe_redirect(): void {
		if ( wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		// CRITICAL: nesmíme redirectovat z admin-post.php (kde běží naše
		// formulářové akce typu nkzmp_vd_product_submit) ani z admin-ajax.php.
		// Když by tu redirect proběhl před admin_post_* hookem, form data se
		// zahodí a vendor skončí na dashboardu místo zpracování submitu.
		$pagenow = $GLOBALS['pagenow'] ?? '';
		if ( in_array( $pagenow, [ 'admin-post.php', 'admin-ajax.php', 'async-upload.php' ], true ) ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( ! $user || in_array( 'administrator', (array) $user->roles, true ) ) {
			return;
		}
		if ( ! VendorContext::user_is_vendor() ) {
			return;
		}
		if ( ! apply_filters( 'nkzmp/v1/dashboard/redirect_vendor_admin', true ) ) {
			return;
		}
		$account_url = wc_get_account_endpoint_url( 'vendor' );
		if ( $account_url ) {
			wp_safe_redirect( $account_url );
			exit;
		}
	}

	public function hide_admin_bar_for_vendors( $show ) {
		if ( is_user_logged_in() && VendorContext::user_is_vendor() ) {
			$user = wp_get_current_user();
			if ( ! in_array( 'administrator', (array) $user->roles, true ) && ! in_array( 'shop_manager', (array) $user->roles, true ) ) {
				return false;
			}
		}
		return $show;
	}
}
