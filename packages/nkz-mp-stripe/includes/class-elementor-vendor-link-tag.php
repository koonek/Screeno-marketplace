<?php
/**
 * Elementor Dynamic Tag: link to the vendor profile (public).
 *
 * Works on a vendor post (links to itself) and on a product (links to the
 * product's vendor).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Link_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name() {
		return 'nkv-vendor-link';
	}

	public function get_title() {
		return __( 'Prodejce: Odkaz na profil', 'nkz-woo-stripe-vendor-split' );
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
		return (string) get_permalink( $vendor_id );
	}
}
