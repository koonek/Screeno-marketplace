<?php
/**
 * Frontend CSS.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Assets {

	private static ?Assets $instance = null;

	public static function instance(): Assets {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
	}

	public function enqueue(): void {
		global $post;
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$content = (string) $post->post_content;
		if ( ! has_shortcode( $content, Shortcode::SLUG ) && ! has_shortcode( $content, StatusPage::SHORTCODE ) ) {
			return;
		}
		wp_enqueue_style(
			'nkz-mp-vendor-registration',
			NKZMP_REGISTRATION_URL . 'assets/registration.css',
			[],
			NKZMP_REGISTRATION_VERSION
		);
	}
}
