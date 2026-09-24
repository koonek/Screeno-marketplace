<?php
/**
 * ImageOrientation – narovnání fotek z mobilu hned při nahrání.
 *
 * Telefony fotku fyzicky neotáčejí. Uloží ji naležato tak, jak ji nasnímal
 * senzor, a přidají do EXIFu značku „orientation", která říká, o kolik se
 * má při zobrazení pootočit. Kdo značku přečte, vidí fotku správně.
 *
 * Problém nastane, když ji přečtou DVA. WordPress při nahrání pixely otočí,
 * ale EXIF značku v souboru nechá – a prohlížeč (dnes všechny, výchozí
 * `image-orientation: from-image`) ji pak otočí ještě jednou. Výsledek:
 * fotka naležato. U čtvercových fotek to nikdo nepozná, proto to prodejci
 * hlásí jako „když není fotka čtvercová, přetočí se".
 *
 * Řešení: při nahrání otočíme pixely sami a EXIF značku odstraníme. Po nás
 * už soubor nikoho nemá čím zmást – nezáleží na tom, jestli by WordPress
 * na daném serveru otáčel, nebo ne.
 *
 * Přepisujeme jen fotky, které značku opravdu mají, aby se ostatní
 * zbytečně znovu nekomprimovaly. Barevný profil (ICC) zachováváme, jinak
 * by fotky po převodu zbledly.
 *
 * Vypnutí: `nkzmp/v1/uploads/fix_orientation` → false.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ImageOrientation {

	private static ?ImageOrientation $instance = null;

	public static function instance(): ImageOrientation {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Priorita 20 = až po HeicUploads (10), aby HEIC byl v tu chvíli
		// už převedený na JPEG.
		add_filter( 'wp_handle_upload_prefilter', [ $this, 'normalize' ], 20 );
	}

	/**
	 * @param array $file $_FILES položka.
	 * @return array
	 */
	public function normalize( $file ) {
		if ( ! is_array( $file ) || ! empty( $file['error'] ) ) {
			return $file;
		}
		if ( ! apply_filters( 'nkzmp/v1/uploads/fix_orientation', true ) ) {
			return $file;
		}

		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( $tmp === '' || ! is_readable( $tmp ) ) {
			return $file;
		}

		// EXIF orientaci nosí jen JPEG a TIFF. PNG/WebP nemá smysl otevírat.
		$type = strtolower( (string) ( $file['type'] ?? '' ) );
		$name = strtolower( (string) ( $file['name'] ?? '' ) );
		$is_jpeg = str_contains( $type, 'jpeg' ) || str_contains( $type, 'jpg' )
			|| str_ends_with( $name, '.jpg' ) || str_ends_with( $name, '.jpeg' )
			|| str_contains( $type, 'tiff' ) || str_ends_with( $name, '.tif' ) || str_ends_with( $name, '.tiff' );
		if ( ! $is_jpeg ) {
			return $file;
		}

		$orientation = self::read_orientation( $tmp );
		if ( $orientation <= 1 ) {
			return $file; // 0 = nezjištěno, 1 = už narovnáno → nesaháme
		}

		$done = self::rotate_imagick( $tmp ) || self::rotate_gd( $tmp, $orientation );
		if ( $done && isset( $file['size'] ) ) {
			clearstatcache( true, $tmp );
			$file['size'] = (int) filesize( $tmp );
		}

		return $file;
	}

	/**
	 * Zjistí EXIF orientaci. Vrací 0, když se ji nepodařilo přečíst.
	 */
	public static function read_orientation( string $path ): int {
		if ( function_exists( 'exif_read_data' ) ) {
			try {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- poškozený EXIF nesmí shodit upload.
				$exif = @exif_read_data( $path );
				if ( is_array( $exif ) && ! empty( $exif['Orientation'] ) ) {
					return (int) $exif['Orientation'];
				}
				return 1;
			} catch ( \Throwable $e ) {
				return 0;
			}
		}

		if ( class_exists( '\Imagick' ) ) {
			try {
				$img = new \Imagick( $path );
				$o   = (int) $img->getImageOrientation();
				$img->clear();
				$img->destroy();
				// Imagick vrací 0 = UNDEFINED; to je pro nás „nezjištěno".
				return $o;
			} catch ( \Throwable $e ) {
				return 0;
			}
		}

		return 0;
	}

	/** Otočení přes Imagick (preferované – umí zachovat ICC profil). */
	private static function rotate_imagick( string $path ): bool {
		if ( ! class_exists( '\Imagick' ) ) {
			return false;
		}
		try {
			$img = new \Imagick( $path );

			if ( ! method_exists( $img, 'autoOrient' ) ) {
				$img->clear();
				$img->destroy();
				return false;
			}

			// Barevný profil si schováme – stripImage() by ho jinak zahodil
			// a fotky by po uložení zbledly.
			$profiles = [];
			try {
				$profiles = $img->getImageProfiles( 'icc', true );
			} catch ( \Throwable $e ) {
				$profiles = [];
			}

			$img->autoOrient();
			$img->stripImage();
			if ( ! empty( $profiles['icc'] ) ) {
				$img->profileImage( 'icc', $profiles['icc'] );
			}
			// Pojistka: po stripImage() by značka být neměla, ale ať je jisté,
			// že v souboru nezůstane nic, co by prohlížeč znovu otočil.
			$img->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );

			if ( strtolower( $img->getImageFormat() ) === 'jpeg' ) {
				$img->setImageCompressionQuality( (int) apply_filters( 'nkzmp/v1/uploads/orientation_quality', 92 ) );
			}

			$ok = $img->writeImage( $path );
			$img->clear();
			$img->destroy();

			return (bool) $ok;
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] Narovnání fotky (Imagick) selhalo: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Záloha přes GD, když Imagick na serveru není.
	 *
	 * GD o EXIFu neví, takže otočení musíme spočítat z hodnoty sami. GD taky
	 * EXIF nezapisuje, takže se značka zahodí sama – což je přesně to, co
	 * potřebujeme.
	 */
	private static function rotate_gd( string $path, int $orientation ): bool {
		if ( ! function_exists( 'imagecreatefromjpeg' ) || ! function_exists( 'imagerotate' ) ) {
			return false;
		}
		// Úhel otočení a případné zrcadlení pro jednotlivé EXIF hodnoty.
		$map = [
			2 => [ 0, true ],
			3 => [ 180, false ],
			4 => [ 180, true ],
			5 => [ -90, true ],
			6 => [ -90, false ],
			7 => [ 90, true ],
			8 => [ 90, false ],
		];
		if ( ! isset( $map[ $orientation ] ) ) {
			return false;
		}
		[ $angle, $flip ] = $map[ $orientation ];

		try {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- poškozený JPEG nesmí shodit upload.
			$src = @imagecreatefromjpeg( $path );
			if ( ! $src ) {
				return false;
			}
			if ( 0 !== $angle ) {
				$rotated = imagerotate( $src, $angle, 0 );
				if ( $rotated ) {
					imagedestroy( $src );
					$src = $rotated;
				}
			}
			if ( $flip && function_exists( 'imageflip' ) ) {
				imageflip( $src, IMG_FLIP_HORIZONTAL );
			}
			$ok = imagejpeg( $src, $path, (int) apply_filters( 'nkzmp/v1/uploads/orientation_quality', 92 ) );
			imagedestroy( $src );

			return (bool) $ok;
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] Narovnání fotky (GD) selhalo: ' . $e->getMessage() );
			return false;
		}
	}
}
