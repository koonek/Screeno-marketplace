<?php
/**
 * Elementor Dynamic Tag: link to the vendor profile (public).
 *
 * Works on a vendor post (links to itself) and on a product (links to the
 * product's vendor). Lets a product template show a "Zobrazit prodejce" button.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Link_Tag extends \Elementor\Core\DynamicTags\Data_Tag {

	public function get_name(): string {
		return 'nkv-vendor-link';
	}

	public function get_title(): string {
		return __( 'Prodejce: Odkaz na profil', 'nkz-woo-stripe-vendor-split' );
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
		$vendor_id = Vendors::resolve_vendor_id();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( $vendor_id ) ) {
			return '';
		}
		return (string) get_permalink( $vendor_id );
	}
}
