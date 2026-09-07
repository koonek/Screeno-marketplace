<?php
/**
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
	return;
}

final class VendorBio extends VendorTagBase {

	public function get_name() {
		return 'nkzmp_vendor_bio';
	}

	public function get_title() {
		return __( 'Vendor: Bio / popis', 'nkz-mp-storefront' );
	}

	public function render() {
		$vendor = $this->resolve_vendor();
		if ( ! $vendor ) {
			return;
		}
		echo wp_kses_post( wpautop( (string) $vendor['bio'] ) );
	}
}
