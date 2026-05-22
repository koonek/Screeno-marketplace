<?php
/**
 * Archive `/vendors` – list všech active vendorů.
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
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
	}

	public function maybe_render(): void {
		if ( ! get_query_var( 'nkzmp_vendor_archive' ) ) {
			return;
		}

		$paged = (int) ( get_query_var( 'paged' ) ?: 1 );

		add_filter( 'pre_get_document_title', fn() => __( 'Prodejci', 'nkz-mp-storefront' ) . ' – ' . get_bloginfo( 'name' ) );
		status_header( 200 );

		get_header();
		Templates::render( 'archive-vendor.php', [
			'vendors' => $this->fetch_vendors( $paged ),
		] );
		get_footer();
		exit;
	}

	private function fetch_vendors( int $paged ): array {
		$per_page = 24;

		// Najdi všechny vendory; preferuj active. Pokud status meta není
		// vyplněn (legacy data), vendor je zahrnut.
		$args = [
			'post_type'      => [ 'nkzmp_vendor', 'nkv_vendor' ],
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => VendorMeta::STATUS, 'value' => 'active', 'compare' => '=' ],
				[ 'key' => VendorMeta::STATUS, 'compare' => 'NOT EXISTS' ],
				[ 'key' => '_nkv_vendor_status', 'value' => 'active', 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_status', 'compare' => 'NOT EXISTS' ],
			],
		];

		$query = new \WP_Query( $args );
		return [
			'items'    => $query->posts,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'paged'    => $paged,
			'per_page' => $per_page,
		];
	}
}
