<?php
/**
 * Archive `/vendors` – stejný hybrid pattern jako VendorPage.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

use NKZMP\Vendor\MetaKeys as VendorMeta;

defined( 'ABSPATH' ) || exit;

final class ArchivePage {

	private static ?ArchivePage $instance = null;

	public static function instance(): ArchivePage {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'pre_get_posts', [ $this, 'intercept_query' ] );
		add_filter( 'template_include', [ $this, 'fallback_template' ], 99 );
		add_filter( 'pre_get_document_title', [ $this, 'maybe_title' ] );
	}

	public function intercept_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		if ( ! $q->get( 'nkzmp_vendor_archive' ) ) {
			return;
		}

		$q->set( 'post_type', [ 'nkzmp_vendor', 'nkv_vendor' ] );
		$q->set( 'posts_per_page', 24 );
		$q->set( 'orderby', 'title' );
		$q->set( 'order', 'ASC' );
		$q->set( 'meta_query', [
			'relation' => 'OR',
			[ 'key' => VendorMeta::STATUS, 'value' => 'active', 'compare' => '=' ],
			[ 'key' => VendorMeta::STATUS, 'compare' => 'NOT EXISTS' ],
			[ 'key' => '_nkv_vendor_status', 'value' => 'active', 'compare' => '=' ],
			[ 'key' => '_nkv_vendor_status', 'compare' => 'NOT EXISTS' ],
		] );

		$q->is_404               = false;
		$q->is_home              = false;
		$q->is_archive           = true;
		$q->is_post_type_archive = true;
	}

	public function fallback_template( string $template ): string {
		if ( ! get_query_var( 'nkzmp_vendor_archive' ) ) {
			return $template;
		}

		$basename = basename( $template );
		$generic  = in_array( $basename, [ 'index.php', '404.php', '' ], true );
		if ( ! $generic ) {
			return $template;
		}

		$fallback = Templates::locate( 'archive-vendor.php' );
		return $fallback ?: $template;
	}

	public function maybe_title( $title ) {
		if ( get_query_var( 'nkzmp_vendor_archive' ) ) {
			return __( 'Prodejci', 'nkz-mp-storefront' ) . ' – ' . get_bloginfo( 'name' );
		}
		return $title;
	}
}
