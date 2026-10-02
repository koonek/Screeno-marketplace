<?php
/**
 * HeicUploads – ošetření fotek z iPhonu (.heic / .heif).
 *
 * Problém: iPhone fotí defaultně do HEIC. Prohlížeče kromě Safari ho neumí
 * zobrazit → v katalogu je rozbitá ikona místo fotky (a nepomůže ani to, že
 * soubor na serveru existuje).
 *
 * Řešení, v tomto pořadí:
 *  1. Když server umí HEIC (Imagick s HEIC delegátem), převedeme na JPEG.
 *  2. Když neumí, upload odmítneme se srozumitelnou hláškou a návodem –
 *     lepší než pustit dál soubor, který se nikomu nezobrazí.
 *
 * Běží na `wp_handle_upload_prefilter`, tj. pro všechny uploady (i admin) –
 * problém je stejný všude. Vypnutí: `nkzmp/v1/uploads/handle_heic` → false.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class HeicUploads {

	private static ?HeicUploads $instance = null;

	public static function instance(): HeicUploads {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'maybe_convert' ] );
	}

	/** Umí tenhle server číst HEIC? */
	public static function server_supports_heic(): bool {
		if ( ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			$formats = \Imagick::queryFormats( 'HEI*' );
			return ! empty( $formats );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	private static function is_heic( array $file ): bool {
		$name = strtolower( (string) ( $file['name'] ?? '' ) );
		$type = strtolower( (string) ( $file['type'] ?? '' ) );
		return str_ends_with( $name, '.heic' )
			|| str_ends_with( $name, '.heif' )
			|| str_contains( $type, 'heic' )
			|| str_contains( $type, 'heif' );
	}

	/**
	 * @param array $file $_FILES položka.
	 * @return array
	 */
	public function maybe_convert( $file ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) ) {
			return $file;
		}
		if ( ! apply_filters( 'nkzmp/v1/uploads/handle_heic', true ) ) {
			return $file;
		}
		if ( ! self::is_heic( $file ) ) {
			return $file;
		}

		if ( self::server_supports_heic() ) {
			$converted = self::convert_to_jpeg( $file );
			if ( $converted !== null ) {
				return $converted;
			}
		}

		// Převod není možný → radši odmítnout než pustit nezobrazitelnou fotku.
		$file['error'] = __(
			'Fotka je ve formátu HEIC (typické pro iPhone) a většina prohlížečů ji nezobrazí. Ulož ji prosím jako JPEG. Na iPhonu: Nastavení → Fotoaparát → Formáty → „Nejkompatibilnější“, nebo fotku otevři ve Fotkách, dej Sdílet → Kopírovat fotku a vlož ji – uloží se jako JPEG.',
			'nkz-mp-vendor-dashboard'
		);
		return $file;
	}

	/**
	 * Převede nahraný HEIC na JPEG (in-place v tmp souboru).
	 *
	 * @return array|null Upravená položka, nebo null když se převod nepovedl.
	 */
	private static function convert_to_jpeg( array $file ): ?array {
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp === '' || ! file_exists( $tmp ) ) {
			return null;
		}
		try {
			$img = new \Imagick( $tmp );
			$img->setImageFormat( 'jpeg' );
			$img->setImageCompressionQuality( (int) apply_filters( 'nkzmp/v1/uploads/heic_quality', 88 ) );
			// EXIF orientace – jinak by fotky z mobilu byly otočené.
			if ( method_exists( $img, 'autoOrient' ) ) {
				$img->autoOrient();
			}
			$img->stripImage();
			$ok = $img->writeImage( $tmp );
			$img->clear();
			$img->destroy();
			if ( ! $ok ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] HEIC → JPEG převod selhal: ' . $e->getMessage() );
			return null;
		}

		$name = (string) ( $file['name'] ?? 'photo' );
		$base = preg_replace( '/\.(heic|heif)$/i', '', $name );
		$file['name'] = ( $base !== '' ? $base : 'photo' ) . '.jpg';
		$file['type'] = 'image/jpeg';
		if ( isset( $file['size'] ) ) {
			$file['size'] = (int) filesize( $tmp );
		}
		return $file;
	}
}
