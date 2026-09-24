<?php
/**
 * Rewrite rules pro vendor URLs.
 *
 *  /vendors            → archive
 *  /vendors/page/2     → archive paginated
 *  /vendor/<slug>      → single vendor page
 *  /vendor/<slug>/page/2 → single page paginated (product grid)
 *
 * Query vars: nkzmp_vendor_slug, nkzmp_vendor_archive.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Rewrite {

	private static ?Rewrite $instance = null;

	public static function instance(): Rewrite {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ self::class, 'register_rules' ] );
		add_filter( 'query_vars', [ $this, 'register_query_vars' ] );
		add_filter( 'post_type_link', [ $this, 'filter_vendor_permalink' ], 10, 2 );
	}

	/**
	 * Přepiš permalink vendor CPT na /vendor/<slug>, aby admin "View" a
	 * frontend odkazy vedly na storefront URL místo ?nkv_vendor=… query.
	 *
	 * @param string  $url
	 * @param \WP_Post $post
	 */
	public function filter_vendor_permalink( string $url, \WP_Post $post ): string {
		if ( ! in_array( $post->post_type, [ 'nkzmp_vendor', 'nkv_vendor' ], true ) ) {
			return $url;
		}
		$s = Settings::get();
		if ( 'yes' !== $s['enable_single'] ) {
			return $url;
		}
		return home_url( '/' . $s['single_slug'] . '/' . $post->post_name );
	}

	public static function register_rules(): void {
		$s = Settings::get();
		if ( 'yes' === $s['enable_archive'] ) {
			add_rewrite_rule( '^' . preg_quote( $s['archive_slug'], '/' ) . '/?$', 'index.php?nkzmp_vendor_archive=1', 'top' );
			add_rewrite_rule( '^' . preg_quote( $s['archive_slug'], '/' ) . '/page/([0-9]+)/?$', 'index.php?nkzmp_vendor_archive=1&paged=$matches[1]', 'top' );
		}
		if ( 'yes' === $s['enable_single'] ) {
			add_rewrite_rule( '^' . preg_quote( $s['single_slug'], '/' ) . '/([^/]+)/?$', 'index.php?nkzmp_vendor_slug=$matches[1]', 'top' );
			add_rewrite_rule( '^' . preg_quote( $s['single_slug'], '/' ) . '/([^/]+)/page/([0-9]+)/?$', 'index.php?nkzmp_vendor_slug=$matches[1]&paged=$matches[2]', 'top' );
		}
	}

	public function register_query_vars( array $vars ): array {
		$vars[] = 'nkzmp_vendor_slug';
		$vars[] = 'nkzmp_vendor_archive';
		return $vars;
	}
}
