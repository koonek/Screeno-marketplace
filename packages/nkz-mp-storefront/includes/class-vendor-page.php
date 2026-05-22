<?php
/**
 * Single vendor page handler – `/vendor/<slug>`.
 *
 * Vendor je `nkv_vendor` nebo `nkzmp_vendor` CPT s `post_name` = slug.
 * Pouze `active` vendoři se zobrazují (status z core MetaKeys::STATUS).
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

use NKZMP\Vendor\MetaKeys as VendorMeta;
use NKZMP\Vendor\Repository as VendorRepository;
use NKZMP\Vendor\Status;

defined( 'ABSPATH' ) || exit;

final class VendorPage {

	private static ?VendorPage $instance = null;

	public static function instance(): VendorPage {
		return self::$instance ??= new self();
	}

	private ?array $current = null;

	public function init(): void {
		add_action( 'template_redirect', [ $this, 'maybe_render' ] );
		add_action( 'pre_get_posts', [ $this, 'intercept_query' ] );
	}

	public function intercept_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$slug = (string) $q->get( 'nkzmp_vendor_slug' );
		if ( $slug === '' ) {
			return;
		}
		// Nahraď query za singular pohled, ať WP nehledá page/post.
		$q->set( 'post_type', [ 'nkzmp_vendor', 'nkv_vendor' ] );
		$q->set( 'name', $slug );
		$q->set( 'posts_per_page', 1 );
		$q->is_404      = false;
		$q->is_home     = false;
		$q->is_singular = false;
	}

	public function maybe_render(): void {
		$slug = (string) get_query_var( 'nkzmp_vendor_slug' );
		if ( $slug === '' ) {
			return;
		}

		$vendor = $this->find_active_vendor_by_slug( $slug );
		if ( ! $vendor ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return;
		}

		$this->current = $vendor;
		add_filter( 'pre_get_document_title', fn() => $vendor['name'] . ' – ' . get_bloginfo( 'name' ) );

		status_header( 200 );

		get_header();
		Templates::render( 'single-vendor.php', [
			'vendor'   => $vendor,
			'products' => $this->fetch_products( (int) $vendor['id'], (int) ( get_query_var( 'paged' ) ?: 1 ) ),
		] );
		get_footer();
		exit;
	}

	public function current(): ?array {
		return $this->current;
	}

	private function find_active_vendor_by_slug( string $slug ): ?array {
		$post = get_page_by_path( $slug, OBJECT, [ 'nkzmp_vendor', 'nkv_vendor' ] );
		if ( ! $post instanceof \WP_Post ) {
			return null;
		}
		$vendor = ( new VendorRepository() )->find( $post->ID );
		if ( ! $vendor ) {
			return null;
		}
		$status = $vendor['status'] ? Status::tryFrom( (string) $vendor['status'] ) : null;
		if ( $status !== Status::ACTIVE ) {
			// Pokud status meta není vyplněn, povolíme view (migrační období).
			if ( $vendor['status'] !== '' ) {
				return null;
			}
		}
		return $vendor;
	}

	private function fetch_products( int $vendor_id, int $paged ): array {
		$per_page = (int) Settings::get()['per_page'];
		$args     = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_id, 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_id',   'value' => $vendor_id, 'compare' => '=' ],
			],
		];
		$query = new \WP_Query( $args );
		return [
			'items'       => $query->posts,
			'total'       => (int) $query->found_posts,
			'pages'       => (int) $query->max_num_pages,
			'paged'       => $paged,
			'per_page'    => $per_page,
		];
	}
}
