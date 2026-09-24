<?php
/**
 * Elementor Dynamic Tags pro vendor data + Loop Grid Query.
 *
 * Tags se objeví v Elementor editoru pod skupinou "NKZ Vendor". Designér
 * je drag-and-drop přiřadí do widgetů (Title, Text, Image, Button…).
 *
 * Tagy:
 *  - vendor_name           (text)
 *  - vendor_bio            (text)
 *  - vendor_website        (url)
 *  - vendor_email          (text)
 *  - vendor_profile_url    (url – /vendor/<slug>)
 *  - vendor_featured_image (image)
 *
 * Loop Grid Query ID `nkzmp_vendor_products` filtruje produkty current vendora
 * (na single vendor page) podle _nkzmp_vendor_id / _nkv_vendor_id meta.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor;

defined( 'ABSPATH' ) || exit;

final class ElementorIntegration {

	private static ?ElementorIntegration $instance = null;

	public static function instance(): ElementorIntegration {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register_tags' ] );
		add_action( 'elementor/query/nkzmp_vendor_products', [ $this, 'filter_vendor_products_query' ] );
		// Belt-and-suspenders: Elementor / Elementor Pro používají různé filtry
		// pro plnění dropdownu Source v Loop Grid / Posts widgetech v různých
		// verzích. Hookujeme všechny známé.
		add_filter( 'elementor/utils/get_public_post_types', [ $this, 'expose_vendor_post_type' ] );
		add_filter( 'elementor_pro/loop_builder/loop_widget/source_post_types', [ $this, 'expose_vendor_post_type' ] );
	}

	/**
	 * @param array<string,string|object> $post_types
	 * @return array<string,string|object>
	 */
	public function expose_vendor_post_type( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}
		foreach ( [ 'nkzmp_vendor', 'nkv_vendor' ] as $pt ) {
			if ( isset( $post_types[ $pt ] ) ) {
				continue;
			}
			$obj = get_post_type_object( $pt );
			if ( ! $obj ) {
				continue;
			}
			// První item v poli napoví formát ostatních (label string vs WP_Post_Type).
			$first = reset( $post_types );
			$post_types[ $pt ] = is_object( $first ) ? $obj : (string) $obj->label;
		}
		return $post_types;
	}

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $manager
	 */
	public function register_tags( $manager ): void {
		if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
			return;
		}

		// Skupina musí existovat i bez Elementor Pro pro free Elementor users.
		$manager->register_group(
			'nkzmp_vendor',
			[ 'title' => __( 'NKZ Vendor', 'nkz-mp-storefront' ) ]
		);

		// Načti všechny tag třídy a registruj.
		$tags = [
			Tags\VendorName::class,
			Tags\VendorBio::class,
			Tags\VendorWebsite::class,
			Tags\VendorEmail::class,
			Tags\VendorProfileUrl::class,
			Tags\VendorFeaturedImage::class,
		];
		foreach ( $tags as $tag_class ) {
			if ( class_exists( $tag_class ) ) {
				$manager->register( new $tag_class() );
			}
		}
	}

	/**
	 * Loop Grid Query callback: filter produkty podle current vendora.
	 *
	 * Použití v Elementoru:
	 *  1. Vlož Loop Grid widget
	 *  2. Source: Posts (Products)
	 *  3. Query → Query ID: `nkzmp_vendor_products`
	 *
	 * @param \WP_Query $query
	 */
	public function filter_vendor_products_query( \WP_Query $query ): void {
		$vendor_id = self::current_vendor_id();
		if ( $vendor_id <= 0 ) {
			$query->set( 'post__in', [ 0 ] ); // nic
			return;
		}
		$query->set( 'meta_query', [
			'relation' => 'OR',
			[ 'key' => '_nkzmp_vendor_id', 'value' => $vendor_id, 'compare' => '=' ],
			[ 'key' => '_nkv_vendor_id',   'value' => $vendor_id, 'compare' => '=' ],
		] );
	}

	/**
	 * Vrátí ID vendora v aktuálním kontextu (single vendor page).
	 */
	public static function current_vendor_id(): int {
		global $post;
		if ( $post instanceof \WP_Post && in_array( $post->post_type, [ 'nkv_vendor', 'nkzmp_vendor' ], true ) ) {
			return (int) $post->ID;
		}
		return 0;
	}
}
