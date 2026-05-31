<?php
/**
 * Elementor Dynamic Tag: vendor logo (featured image of the vendor post).
 *
 * On a product the native "Featured Image" tag returns the product image, so
 * this tag resolves the product's vendor and returns the vendor's thumbnail.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Logo_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'nkv-vendor-logo';
	}

	public function get_title(): string {
		return __( 'Prodejce: Logo', 'nkz-woo-stripe-vendor-split' );
	}

	public function get_group(): string {
		return Elementor_Dynamic_Tags::GROUP;
	}

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [
			\Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY,
		];
	}

	/**
	 * @param array<string,mixed> $options
	 * @return array{id:int,url:string}
	 */
	public function get_value( array $options = [] ): array {
		$empty = [
			'id'  => 0,
			'url' => \Elementor\Utils::get_placeholder_image_src(),
		];

		$vendor_id = Vendors::resolve_vendor_id();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( $vendor_id ) ) {
			return $empty;
		}

		$thumb_id = (int) get_post_thumbnail_id( $vendor_id );
		if ( ! $thumb_id ) {
			return $empty;
		}

		return [
			'id'  => $thumb_id,
			'url' => (string) wp_get_attachment_image_url( $thumb_id, 'full' ),
		];
	}
}
