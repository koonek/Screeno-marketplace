<?php
/**
 * Elementor Dynamic Tag: vendor public field (text).
 *
 * One tag with a field selector covering every PUBLIC vendor field. Resolves the
 * vendor from the current context (vendor post, or a product via `_nkv_vendor_id`).
 * Whitelist only — sensitive meta is not selectable.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Vendor_Field_Tag extends \Elementor\Core\DynamicTags\Tag {

	public function get_name() {
		return 'nkv-vendor-field';
	}

	public function get_title() {
		return __( 'Prodejce: Údaj', 'nkz-woo-stripe-vendor-split' );
	}

	public function get_group() {
		return Elementor_Dynamic_Tags::GROUP;
	}

	public function get_categories() {
		return [ \Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY ];
	}

	/**
	 * Public fields selectable here → vendor meta key (or `title` = post title).
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

	protected function register_controls() {
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

	public function render() {
		$vendor_id = Vendors::resolve_vendor_id();
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

		if ( 'bio' === $field ) {
			echo wp_kses_post( wpautop( $value ) );
		} else {
			echo esc_html( $value );
		}
	}
}
