<?php
/**
 * Elementor Dynamic Tag: vendor public field (text).
 *
 * One tag with a field selector covering every PUBLIC vendor field, so the whole
 * set shows up under the "Prodejce" group in Elementor's dynamic content picker.
 *
 * Whitelist only — sensitive meta (email, fees, Stripe IDs, internal note) is not
 * selectable here, so it can never leak through this tag.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Field_Tag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name(): string {
		return 'nkv-vendor-field';
	}

	public function get_title(): string {
		return __( 'Prodejce: Údaj', 'nkz-woo-stripe-vendor-split' );
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

	/**
	 * Public fields selectable in this tag. Maps the Elementor select value to a
	 * vendor meta key (or the special `title` = post title). Anything not listed
	 * here is intentionally not exposable.
	 *
	 * @return array<string,string>
	 */
	public static function fields(): array {
		return [
			'title'    => __( 'Jméno prodejce', 'nkz-woo-stripe-vendor-split' ),
			'bio'      => __( 'Bio / popisek', 'nkz-woo-stripe-vendor-split' ),
			'ico'      => __( 'IČO / DIČ', 'nkz-woo-stripe-vendor-split' ),
			'currency' => __( 'Měna', 'nkz-woo-stripe-vendor-split' ),
		];
	}

	protected function register_controls(): void {
		$this->add_control(
			'vendor_field',
			[
				'label'   => __( 'Pole prodejce', 'nkz-woo-stripe-vendor-split' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => self::fields(),
				'default' => 'bio',
			]
		);
	}

	public function render(): void {
		$vendor_id = (int) get_the_ID();
		if ( ! $vendor_id || ! Vendors::is_public_vendor( $vendor_id ) ) {
			return;
		}

		$field = (string) $this->get_settings( 'vendor_field' );
		if ( ! array_key_exists( $field, self::fields() ) ) {
			return;
		}

		if ( 'title' === $field ) {
			echo esc_html( get_the_title( $vendor_id ) );
			return;
		}

		$value = (string) get_post_meta( $vendor_id, '_nkv_vendor_' . $field, true );
		if ( '' === $value ) {
			return;
		}

		// Bio keeps line breaks; short scalar fields stay plain text.
		if ( 'bio' === $field ) {
			echo wp_kses_post( wpautop( $value ) );
		} else {
			echo esc_html( $value );
		}
	}
}
