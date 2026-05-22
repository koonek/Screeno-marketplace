<?php
/**
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront\Elementor\Tags;

use NKZMP\Storefront\Settings;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( \Elementor\Core\DynamicTags\Tag::class ) ) {
	return;
}

final class VendorProfileUrl extends VendorTagBase {

	public function get_name() {
		return 'nkzmp_vendor_profile_url';
	}

	public function get_title() {
		return __( 'Vendor: Profil URL', 'nkz-mp-storefront' );
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::URL_CATEGORY ];
	}

	public function render() {
		$vendor = $this->resolve_vendor();
		if ( ! $vendor ) {
			return;
		}
		$slug_base = Settings::get()['single_slug'];
		$post      = get_post( (int) $vendor['id'] );
		if ( ! $post ) {
			return;
		}
		echo esc_url( home_url( '/' . $slug_base . '/' . $post->post_name ) );
	}
}
