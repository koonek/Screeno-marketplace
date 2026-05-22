<?php
/**
 * Shortcode + block pro registration form.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Shortcode {

	public const SLUG = 'nkzmp_vendor_registration';

	private static ?Shortcode $instance = null;

	public static function instance(): Shortcode {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( self::SLUG, [ $this, 'render' ] );
	}

	public function render( $atts = [] ): string {
		$flash = isset( $_GET['nkzmp_reg'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_reg'] ) ) : '';

		ob_start();

		if ( 'ok' === $flash ) {
			echo '<div class="nkzmp-reg-success">';
			echo '<p>' . esc_html( Settings::get()['success_message'] ) . '</p>';
			echo '</div>';
			return (string) ob_get_clean();
		}

		$error_msg = isset( $_GET['nkzmp_err'] ) ? sanitize_text_field( wp_unslash( $_GET['nkzmp_err'] ) ) : '';

		$file = NKZMP_REGISTRATION_DIR . 'templates/form.php';
		if ( is_readable( $file ) ) {
			$terms_url = Settings::get()['terms_url'];
			include $file;
		}
		return (string) ob_get_clean();
	}
}
