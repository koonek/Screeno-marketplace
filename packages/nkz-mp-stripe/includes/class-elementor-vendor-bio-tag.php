<?php
/**
 * Elementor Dynamic Tag: vendor bio (public).
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Bio_Tag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name(): string {
		return 'nkv-vendor-bio';
	}

	public function get_title(): string {
		return __( 'Prodejce: Bio', 'nkz-woo-stripe-vendor-split' );
	}

	public function get_group(): string {
		return Elementor_Dynamic_Tags::GROUP;
	}

	/**
	 * @return string[]
	 */
	public function get_categories(): array {
		return [
			\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
		];
	}

	public function render(): void {
		$vendor_id = get_the_ID();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( (int) $vendor_id ) ) {
			return;
		}
		$bio = (string) get_post_meta( $vendor_id, '_nkv_vendor_bio', true );
		if ( '' === $bio ) {
			return;
		}
		echo wp_kses_post( wpautop( $bio ) );
	}
}
