<?php
/**
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
	return;
}

final class VendorWebsite extends VendorTagBase {

	public function get_name() {
		return 'nkzmp_vendor_website';
	}

	public function get_title() {
		return __( 'Vendor: Web URL', 'nkz-mp-storefront' );
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
	}

	public function render() {
		$vendor = $this->resolve_vendor();
		echo $vendor ? esc_url( (string) $vendor['website'] ) : '';
	}
}
