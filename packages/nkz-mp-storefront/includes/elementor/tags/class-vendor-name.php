<?php
/**
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
	return;
}

final class VendorName extends VendorTagBase {

	public function get_name() {
		return 'nkzmp_vendor_name';
	}

	public function get_title() {
		return __( 'Vendor: Název', 'nkz-mp-storefront' );
	}

	public function render() {
		$vendor = $this->resolve_vendor();
		echo $vendor ? esc_html( (string) $vendor['name'] ) : '';
	}
}
