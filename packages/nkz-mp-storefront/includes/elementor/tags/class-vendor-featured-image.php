<?php
/**
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

use NKZMP\Storefront\Elementor\ElementorIntegration;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Data_Tag::class ) ) {
	return;
}

final class VendorFeaturedImage extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_group() {
		return 'nkzmp_vendor';
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::IMAGE_CATEGORY ];
	}

	public function get_name() {
		return 'nkzmp_vendor_featured_image';
	}

	public function get_title() {
		return __( 'Vendor: Hlavní obrázek', 'nkz-mp-storefront' );
	}

	public function get_value( array $options = [] ) {
		$vendor_id = ElementorIntegration::current_vendor_id();
		if ( $vendor_id <= 0 ) {
			return [];
		}
		$attachment_id = (int) get_post_thumbnail_id( $vendor_id );
		if ( $attachment_id <= 0 ) {
			return [];
		}
		return [
			'id'  => $attachment_id,
			'url' => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
		];
	}
}
