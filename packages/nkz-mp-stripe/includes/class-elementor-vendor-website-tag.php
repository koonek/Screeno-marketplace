<?php
/**
 * Elementor Dynamic Tag: vendor website URL (public).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Website_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'nkv-vendor-website';
	}

	public function get_title(): string {
		return __( 'Prodejce: Web', 'nkz-woo-stripe-vendor-split' );
	}

	public function get_group(): string {
		return Elementor_Dynamic_Tags::GROUP;
	}

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [
			\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
		];
	}

	/**
	 * @param array<string,mixed> $options
	 */
	public function get_value( array $options = [] ): string {
		$vendor_id = get_the_ID();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( (int) $vendor_id ) ) {
			return '';
		}
		return esc_url( (string) get_post_meta( $vendor_id, '_nkv_vendor_website', true ) );
	}
}
