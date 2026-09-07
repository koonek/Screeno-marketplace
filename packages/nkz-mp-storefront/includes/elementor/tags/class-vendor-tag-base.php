<?php
/**
 * Bázová třída pro vendor dynamic tags.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

use NKZMP\Storefront\Elementor\ElementorIntegration;
use NKZMP\Vendor\Repository as VendorRepository;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
	return;
}

abstract class VendorTagBase extends \Elementor\Core\DynamicTags\Tag {

	public function get_group() {
		return 'nkzmp_vendor';
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
	}

	protected function resolve_vendor(): ?array {
		$vendor_id = ElementorIntegration::current_vendor_id();
		if ( $vendor_id <= 0 ) {
			return null;
		}
		return ( new VendorRepository() )->find( $vendor_id );
	}
}
