<?php
/**
 * Elementor Dynamic Tags for public vendor fields.
 *
 * The vendor public fields live under protected meta keys (`_nkv_vendor_bio`,
 * `_nkv_vendor_website`) which Elementor's generic "Custom Field" tag refuses to
 * list. These dedicated tags expose them in the Dynamic widget picker so a Single
 * (Theme Builder) template for the `nkv_vendor` CPT can render the public profile.
 *
 * Only public fields are exposed here — sensitive meta (email, IČO, fees, Stripe
 * IDs, internal note) is intentionally never registered as a dynamic tag.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Elementor_Dynamic_Tags {

	private static ?Elementor_Dynamic_Tags $instance = null;
	public static function instance(): Elementor_Dynamic_Tags { return self::$instance ??= new self(); }

	public const GROUP = 'nkv-vendor';

	public function init(): void {
		// Elementor 3.5+ registration API.
		add_action( 'elementor/dynamic_tags/register', [ $this, 'register' ] );
		// Back-compat for Elementor < 3.5.
		add_action( 'elementor/dynamic_tags/register_tags', [ $this, 'register_legacy' ] );
	}

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $manager
	 */
	public function register( $manager ): void {
		$manager->register_group(
			self::GROUP,
			[ 'title' => __( 'Prodejce', 'nkz-woo-stripe-vendor-split' ) ]
		);
		$manager->register( new Elementor_Vendor_Field_Tag() );
		$manager->register( new Elementor_Vendor_Website_Tag() );
		$manager->register( new Elementor_Vendor_Link_Tag() );
		$manager->register( new Elementor_Vendor_Logo_Tag() );
	}

	/**
	 * @param \Elementor\Core\DynamicTags\Manager $manager
	 */
	public function register_legacy( $manager ): void {
		$manager->register_group(
			self::GROUP,
			[ 'title' => __( 'Prodejce', 'nkz-woo-stripe-vendor-split' ) ]
		);
		$manager->register_tag( Elementor_Vendor_Field_Tag::class );
		$manager->register_tag( Elementor_Vendor_Website_Tag::class );
		$manager->register_tag( Elementor_Vendor_Link_Tag::class );
		$manager->register_tag( Elementor_Vendor_Logo_Tag::class );
	}
}
