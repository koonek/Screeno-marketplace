<?php
/**
 * SEO – schema.org Organization markup + canonical URL.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Seo {

	private static ?Seo $instance = null;

	public static function instance(): Seo {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_head', [ $this, 'render_meta' ], 5 );
	}

	public function render_meta(): void {
		$slug = (string) get_query_var( 'nkzmp_vendor_slug' );
		if ( $slug === '' ) {
			return;
		}
		$vendor = VendorPage::instance()->current();
		if ( ! $vendor ) {
			return;
		}

		$slug_base = Settings::get()['single_slug'];
		$url       = home_url( '/' . $slug_base . '/' . $slug );

		$schema = [
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $vendor['name'],
			'url'      => $url,
		];
		if ( ! empty( $vendor['website'] ) ) {
			$schema['sameAs'] = [ $vendor['website'] ];
		}
		if ( ! empty( $vendor['bio'] ) ) {
			$schema['description'] = wp_strip_all_tags( (string) $vendor['bio'] );
		}
		$thumb = get_post_thumbnail_id( (int) $vendor['id'] );
		if ( $thumb ) {
			$src = wp_get_attachment_image_src( $thumb, 'large' );
			if ( $src ) {
				$schema['logo'] = $src[0];
			}
		}

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}
}
