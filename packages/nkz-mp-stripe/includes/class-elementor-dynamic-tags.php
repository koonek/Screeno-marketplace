<?php
/**
 * Elementor Dynamic Tags for public vendor fields.
 *
 * The vendor public fields live under protected meta keys (`_nkv_vendor_*`)
 * which Elementor's generic "Custom Field" tag refuses to list. These dedicated
 * tags expose the PUBLIC ones in the dynamic content picker (group "Prodejce")
 * so a Theme Builder template for the vendor CPT — or a product — can render
 * vendor data. Sensitive meta (email, fees, Stripe IDs, internal note) is never
 * registered here.
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
		add_action( 'elementor/dynamic_tags/register_tags', [ $this, 'register' ] );
	}

	/**
	 * Works with both the new (`register`) and legacy (`register_tag`) manager APIs.
	 *
	 * @param object $manager Elementor dynamic tags manager.
	 */
	public function register( $manager ): void {
		if ( ! is_object( $manager ) ) {
			return;
		}

		if ( method_exists( $manager, 'register_group' ) ) {
			$manager->register_group(
				self::GROUP,
				[ 'title' => __( 'Prodejce', 'nkz-woo-stripe-vendor-split' ) ]
			);
		}

		$tags = [
			Elementor_Vendor_Field_Tag::class,
			Elementor_Vendor_Website_Tag::class,
			Elementor_Vendor_Link_Tag::class,
			Elementor_Vendor_Logo_Tag::class,
		];

		foreach ( $tags as $class ) {
			try {
				if ( method_exists( $manager, 'register' ) ) {
					$manager->register( new $class() );
				} elseif ( method_exists( $manager, 'register_tag' ) ) {
					$manager->register_tag( $class );
				}
			} catch ( \Throwable $e ) {
				error_log( '[nkz-vendor] Elementor tag registration failed: ' . $class . ' — ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
	}
}
