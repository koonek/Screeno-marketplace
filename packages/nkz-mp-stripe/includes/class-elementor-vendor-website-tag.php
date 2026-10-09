<?php
/**
 * Elementor Dynamic Tag: vendor website URL (public).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Website_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'nkv-vendor-website';
	}

	public function get_title() {
		return __( 'Prodejce: Web', 'nkz-woo-stripe-vendor-split' );
	}

	public function get_group() {
		return Elementor_Dynamic_Tags::GROUP;
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
	}

	public function get_value( array $options = [] ) {
		$vendor_id = Vendors::resolve_vendor_id();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( $vendor_id ) ) {
			return '';
		}
		return esc_url( (string) get_post_meta( $vendor_id, '_nkv_vendor_website', true ) );
	}
}
