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
		if ( ! $post || ! has_shortcode( (string) $post->post_content, Shortcode::SLUG ) ) {
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
