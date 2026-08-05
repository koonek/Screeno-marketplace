<?php
/**
 * LoginBranding – obrandování nativní wp-login.php obrazovky (login, reset hesla).
 *
 * Záměrně NENÍ hardcoded na AOZ. Všechny brand-specifické hodnoty jdou přes
 * filtry s rozumnými defaulty, takže jiný projekt (Screeno) je přebije bez
 * editace kódu:
 *
 *   add_filter( 'nkzmp/v1/login/tokens', function ( array $t ) {
 *       $t['accent']  = '#FF3D00';
 *       $t['logo']    = 'https://…/screeno-logo.svg';
 *       $t['bg']      = '#0e0e0e';
 *       return $t;
 *   } );
 *
 * Vypnutí celé funkce: add_filter( 'nkzmp/v1/login/enabled', '__return_false' );
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class LoginBranding {

	private static ?LoginBranding $instance = null;

	public static function instance(): LoginBranding {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/login/enabled', true ) ) {
			return;
		}
		add_action( 'login_enqueue_scripts', [ $this, 'styles' ] );
		add_filter( 'login_headerurl', [ $this, 'header_url' ] );
		add_filter( 'login_headertext', [ $this, 'header_text' ] );
	}

	/**
	 * Brand tokeny. Defaulty jsou neutrální/AOZ-laděné, ale plně filterovatelné.
	 *
	 * @return array<string,string>
	 */
	private function tokens(): array {
		$defaults = [
			'accent'      => '#0060FF',
			'accent_ink'  => '#0048cc',
			'bg'          => '#faf8f4',   // teplý krémový podklad
			'surface'     => '#ffffff',
			'text'        => '#111111',
			'border'      => 'rgba(17,17,17,0.10)',
			'radius'      => '10px',
			'font'        => "'Fabio XM AOZ','Fabio XM','Inter',system-ui,-apple-system,'Segoe UI',sans-serif",
			'logo'        => '',          // prázdné = textový název webu
			'logo_height' => '48px',
			'kicker'      => '',          // malý text nad formulářem (volitelné)
		];
		return array_merge( $defaults, (array) apply_filters( 'nkzmp/v1/login/tokens', [] ) );
	}

	public function header_url(): string {
		return (string) apply_filters( 'nkzmp/v1/login/logo_url', home_url( '/' ) );
	}

	public function header_text(): string {
		return (string) get_bloginfo( 'name' );
	}

	public function styles(): void {
		$t = $this->tokens();

		// Logo: buď obrázek (background-image), nebo textový název webu.
		if ( $t['logo'] !== '' ) {
			$logo_css = sprintf(
				'background-image:url(%s)!important;background-size:contain!important;background-position:center!important;width:100%%!important;height:%s!important;text-indent:-9999px!important;',
				esc_url( $t['logo'] ),
				esc_attr( $t['logo_height'] )
			);
		} else {
			// Skryj WP logo a ukaž textový název webu.
			$logo_css = sprintf(
				'background:none!important;width:auto!important;height:auto!important;text-indent:0!important;font-family:%s;font-size:26px;font-weight:600;letter-spacing:-0.02em;color:%s!important;line-height:1.1;',
				$t['font'],
				esc_attr( $t['text'] )
			);
		}

		$kicker_html = '';
		if ( $t['kicker'] !== '' ) {
			$kicker_html = '#login h1::before{content:"' . esc_attr( $t['kicker'] ) . '";display:block;font-family:' . $t['font'] . ';font-size:11px;font-weight:600;letter-spacing:0.14em;text-transform:uppercase;color:' . esc_attr( $t['accent'] ) . ';margin-bottom:10px;}';
		}

		?>
		<style id="nkzmp-login-branding">
			body.login {
				background: <?php echo esc_attr( $t['bg'] ); ?> !important;
				font-family: <?php echo $t['font']; // phpcs:ignore ?> !important;
			}
			#login { width: 360px; padding: 5% 0 0; }
			#login h1 a,
			#login h1 a:hover {
				<?php echo $logo_css; // phpcs:ignore ?>
				margin: 0 auto 4px;
			}
			<?php echo $kicker_html; // phpcs:ignore ?>

			/* Karta formuláře */
			.login form {
				background: <?php echo esc_attr( $t['surface'] ); ?> !important;
				border: 1px solid <?php echo esc_attr( $t['border'] ); ?> !important;
				border-radius: <?php echo esc_attr( $t['radius'] ); ?> !important;
				box-shadow: 0 1px 2px rgba(17,17,17,0.04), 0 8px 24px rgba(17,17,17,0.05) !important;
				padding: 28px 24px !important;
				margin-top: 16px !important;
			}
			.login label {
				font-family: <?php echo $t['font']; // phpcs:ignore ?> !important;
				font-size: 14px !important;
				font-weight: 600 !important;
				color: <?php echo esc_attr( $t['text'] ); ?> !important;
			}
			.login form .input,
			.login input[type="text"],
			.login input[type="password"],
			.login input[type="email"] {
				background: <?php echo esc_attr( $t['bg'] ); ?> !important;
				border: 1px solid <?php echo esc_attr( $t['border'] ); ?> !important;
				border-radius: 8px !important;
				padding: 12px 14px !important;
				font-size: 15px !important;
				color: <?php echo esc_attr( $t['text'] ); ?> !important;
				box-shadow: none !important;
				transition: border-color .15s ease, box-shadow .15s ease, background .15s ease !important;
			}
			/* Password wrap + show/hide oko: zarovnání, ať nepřečuhuje a sedí svisle */
			.login .wp-pwd { position: relative !important; }
			.login .wp-pwd input[type="password"],
			.login .wp-pwd input[type="text"] { padding-right: 46px !important; }
			.login .wp-pwd .button.wp-hide-pw {
				position: absolute !important;
				top: 0 !important;
				right: 2px !important;
				height: 100% !important;
				width: 42px !important;
				margin: 0 !important;
				padding: 0 !important;
				background: transparent !important;
				border: 0 !important;
				box-shadow: none !important;
				display: flex !important;
				align-items: center !important;
				justify-content: center !important;
			}
			.login .wp-pwd .button.wp-hide-pw .dashicons { color: <?php echo esc_attr( $t['accent'] ); ?> !important; }
			.login .wp-pwd .button.wp-hide-pw:hover { transform: none !important; }
			.login form .input:focus,
			.login input:focus {
				border-color: <?php echo esc_attr( $t['accent'] ); ?> !important;
				background: <?php echo esc_attr( $t['surface'] ); ?> !important;
				box-shadow: 0 0 0 3px <?php echo esc_attr( self::soft( $t['accent'] ) ); ?> !important;
				outline: none !important;
			}

			/* Tlačítko – plná šířka, jemnější stín, menší výška */
			.login .submit { text-align: left; }
			.login .button-primary,
			.wp-core-ui .button-primary {
				background: <?php echo esc_attr( $t['accent'] ); ?> !important;
				border: 0 !important;
				border-radius: 8px !important;
				box-shadow: 0 2px 8px <?php echo esc_attr( self::soft( $t['accent'], 0.18 ) ); ?> !important;
				padding: 10px 22px !important;
				min-height: 46px !important;
				line-height: 1.2 !important;
				font-family: <?php echo $t['font']; // phpcs:ignore ?> !important;
				font-weight: 600 !important;
				font-size: 15px !important;
				text-shadow: none !important;
				transition: background .15s ease, transform .15s ease, box-shadow .15s ease !important;
				width: 100% !important;
				float: none !important;
			}
			.login .button-primary:hover {
				background: <?php echo esc_attr( $t['accent_ink'] ); ?> !important;
				transform: translateY(-1px);
				box-shadow: 0 6px 16px <?php echo esc_attr( self::soft( $t['accent'], 0.28 ) ); ?> !important;
			}

			/* Odkazy pod formulářem */
			.login #nav a,
			.login #backtoblog a {
				color: <?php echo esc_attr( $t['text'] ); ?> !important;
				opacity: .6;
				text-decoration: none !important;
				transition: opacity .15s ease, color .15s ease;
			}
			.login #nav a:hover,
			.login #backtoblog a:hover {
				opacity: 1;
				color: <?php echo esc_attr( $t['accent'] ); ?> !important;
			}

			/* Notices */
			.login .message,
			.login .notice,
			.login #login_error {
				border-radius: 8px !important;
				border-left: 0 !important;
				font-family: <?php echo $t['font']; // phpcs:ignore ?> !important;
			}
			.login #login_error {
				border: 1px solid rgba(176,0,32,0.25) !important;
				background: rgba(176,0,32,0.05) !important;
			}
			.login .message,
			.login .notice {
				border: 1px solid <?php echo esc_attr( self::soft( $t['accent'], 0.18 ) ); ?> !important;
				background: <?php echo esc_attr( self::soft( $t['accent'] ) ); ?> !important;
			}

			/* Jazykový přepínač dole – splynout s pozadím (žádný bílý pruh) */
			.login .language-switcher {
				margin: 8px 0 0 !important;
				padding: 0 !important;
			}
			.login .language-switcher form { background: none !important; border: 0 !important; box-shadow: none !important; padding: 0 !important; margin: 0 !important; }
			.login .language-switcher select {
				border: 1px solid <?php echo esc_attr( $t['border'] ); ?> !important;
				border-radius: 8px !important;
				background: <?php echo esc_attr( $t['surface'] ); ?> !important;
				padding: 6px 10px !important;
			}
			.login .language-switcher .button {
				border-radius: 8px !important;
				border: 1px solid <?php echo esc_attr( $t['border'] ); ?> !important;
				background: <?php echo esc_attr( $t['surface'] ); ?> !important;
				color: <?php echo esc_attr( $t['text'] ); ?> !important;
				box-shadow: none !important;
			}
		</style>
		<?php
	}

	/**
	 * Vrátí accent barvu jako rgba s daným alpha (pro soft pozadí/ringy).
	 * Podporuje #RRGGBB; jinak fallback na lehce modrou.
	 */
	private static function soft( string $hex, float $alpha = 0.06 ): string {
		if ( preg_match( '/^#([0-9a-f]{6})$/i', $hex, $m ) ) {
			$int = hexdec( $m[1] );
			$r   = ( $int >> 16 ) & 255;
			$g   = ( $int >> 8 ) & 255;
			$b   = $int & 255;
			return sprintf( 'rgba(%d,%d,%d,%s)', $r, $g, $b, rtrim( rtrim( number_format( $alpha, 2, '.', '' ), '0' ), '.' ) );
		}
		return 'rgba(0,96,255,' . $alpha . ')';
	}
}
