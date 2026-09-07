<?php
/**
 * Single vendor page – nastavuje query jako native singular CPT, takže
 * Elementor Theme Builder, theme single-nkv_vendor.php, nebo plugin fallback
 * (v tom pořadí) může chytit rendering.
 *
 * Plugin fallback se aktivuje JEN když WP nemá lepší template – tj. index.php
 * nebo 404.php. Tím se nepřebíjí Elementor Theme Builder Single template.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

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
		add_action( 'pre_get_posts', [ $this, 'intercept_query' ] );
		add_filter( 'template_include', [ $this, 'fallback_template' ], 99 );
		add_filter( 'pre_get_document_title', [ $this, 'maybe_title' ] );
		// Vypneme legacy plugin block_single_vendor() — naše routing tu URL ovládá.
		add_action( 'wp', [ $this, 'unhook_legacy_404' ], 5 );
	}

	public function intercept_query( \WP_Query $q ): void {
		if ( is_admin() || ! $q->is_main_query() ) {
			return;
		}
		$slug = (string) $q->get( 'nkzmp_vendor_slug' );
		if ( $slug === '' ) {
			return;
		}

		$q->set( 'post_type', [ 'nkzmp_vendor', 'nkv_vendor' ] );
		$q->set( 'name', $slug );
		$q->set( 'posts_per_page', 1 );

		// Označit jako singular, ať Theme Builder / template hierarchy běží správně.
		$q->is_404                 = false;
		$q->is_home                = false;
		$q->is_singular            = true;
		$q->is_single              = true;
		$q->is_archive             = false;
		// post_type-specific flag, aby template hierarchy hledala single-nkv_vendor.php.
		$q->is_singular_nkv_vendor = true;
	}

	public function unhook_legacy_404(): void {
		if ( ! get_query_var( 'nkzmp_vendor_slug' ) ) {
			return;
		}
		if ( class_exists( \NKVSVS\Vendors::class ) ) {
			remove_action( 'template_redirect', [ \NKVSVS\Vendors::instance(), 'block_single_vendor' ] );
		}
	}

	public function fallback_template( string $template ): string {
		$slug = (string) get_query_var( 'nkzmp_vendor_slug' );
		if ( $slug === '' ) {
			return $template;
		}

		// Resolve vendor + active status check.
		$vendor = $this->find_active_vendor_by_slug( $slug );
		if ( ! $vendor ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			return get_query_template( '404' );
		}
		$this->current = $vendor;

		// Pokud theme nebo Elementor zvolily konkrétní template (single-nkv_vendor.php,
		// Elementor Theme Builder Single, atd.), nech to být.
		$basename = basename( $template );
		$generic  = in_array( $basename, [ 'index.php', '404.php', '' ], true );
		if ( ! $generic ) {
			return $template;
		}

		// Plugin fallback.
		$fallback = Templates::locate( 'single-vendor.php' );
		return $fallback ?: $template;
	}

	public function maybe_title( $title ) {
		if ( $this->current ) {
			return $this->current['name'] . ' – ' . get_bloginfo( 'name' );
		}
		return $title;
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
		$raw_status = (string) $vendor['status'];
		if ( $raw_status === '' ) {
			return $vendor; // migrační období – status meta nemusí být vyplněn
		}
		$status = Status::tryFrom( $raw_status );
		return $status === Status::ACTIVE ? $vendor : null;
	}

	/**
	 * Helper pro plugin fallback template – vrátí produkty vendora.
	 */
	public function fetch_products( int $vendor_id, int $paged = 1 ): array {
		$per_page = (int) Settings::get()['per_page'];
		$query    = new \WP_Query( [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'meta_query'     => [
				'relation' => 'OR',
				[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_id, 'compare' => '=' ],
				[ 'key' => '_nkv_vendor_id',   'value' => $vendor_id, 'compare' => '=' ],
			],
		] );
		return [
			'items'    => $query->posts,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
			'paged'    => $paged,
			'per_page' => $per_page,
		];
	}
}
