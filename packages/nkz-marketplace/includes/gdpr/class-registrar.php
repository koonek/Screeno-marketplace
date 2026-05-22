<?php
/**
 * GDPR registrar – připojí exporter + eraser do WP Tools → Erase/Export.
 *
 * @package NKZMP
 */

namespace NKZMP\Gdpr;

defined( 'ABSPATH' ) || exit;

final class Registrar {

	private static ?Registrar $instance = null;

	public static function instance(): Registrar {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', [ Exporter::class, 'register' ] );
		add_filter( 'wp_privacy_personal_data_erasers', [ Eraser::class, 'register' ] );
	}
}
