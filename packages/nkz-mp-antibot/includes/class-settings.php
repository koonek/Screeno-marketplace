<?php
/**
 * Admin Settings stránka.
 *
 * Pokud existuje NKZMP\Admin\SettingsHub, registruje se tam jako tab.
 * Jinak vytvori vlastni submenu pod Settings.
 *
 * @package NKZMP\Antibot
 */

namespace NKZMP\Antibot;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPTION = 'nkzmp_antibot_settings';

	private static ?Settings $instance = null;

	public static function instance(): Settings {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		$defaults = [
			'turnstile_site_key'   => '',
			'turnstile_secret_key' => '',
			'min_time_seconds'     => 3,
			'rate_limit_per_hour'  => 5,
			'protect'              => [
				'vendor_registration' => 1,
				'wp_login'            => 1,
				'wp_register'         => 1,
				'wc_lost_password'    => 1,
				'wc_checkout'         => 0, // opt-in (risk: legit users)
			],
		];
		$saved = get_option( self::OPTION, [] );
		if ( ! is_array( $saved ) ) {
			$saved = [];
		}
		return array_replace_recursive( $defaults, $saved );
	}

	public static function is_form_protected( string $form_key ): bool {
		$s = self::get();
		return ! empty( $s['protect'][ $form_key ] );
	}

	public function register_menu(): void {
		add_options_page(
			__( 'NKZ Marketplace – Antibot', 'nkz-mp-antibot' ),
			__( 'NKZ Antibot', 'nkz-mp-antibot' ),
			'manage_options',
			'nkzmp-antibot',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting( 'nkzmp_antibot', self::OPTION, [
			'type'              => 'array',
			'sanitize_callback' => [ $this, 'sanitize' ],
			'default'           => [],
		] );
	}

	/**
	 * @param mixed $input
	 * @return array<string,mixed>
	 */
	public function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : [];
		$out = [
			'turnstile_site_key'   => sanitize_text_field( $input['turnstile_site_key'] ?? '' ),
			'turnstile_secret_key' => sanitize_text_field( $input['turnstile_secret_key'] ?? '' ),
			'min_time_seconds'     => max( 0, (int) ( $input['min_time_seconds'] ?? 3 ) ),
			'rate_limit_per_hour'  => max( 0, (int) ( $input['rate_limit_per_hour'] ?? 5 ) ),
			'protect'              => [],
		];
		$keys = [ 'vendor_registration', 'wp_login', 'wp_register', 'wc_lost_password', 'wc_checkout' ];
		foreach ( $keys as $k ) {
			$out['protect'][ $k ] = ! empty( $input['protect'][ $k ] ) ? 1 : 0;
		}
		return $out;
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = self::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'NKZ Marketplace – Antibot', 'nkz-mp-antibot' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Honeypot, time gate a IP rate limit běží automaticky bez konfigurace. Cloudflare Turnstile se zapne až po vyplnění klíčů.', 'nkz-mp-antibot' ); ?>
			</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'nkzmp_antibot' ); ?>

				<h2><?php esc_html_e( 'Cloudflare Turnstile', 'nkz-mp-antibot' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %s = link */
						wp_kses_post( __( 'Vytvoř si zdarma site na %s a vlož siteKey + secretKey. Bez klíčů se Turnstile widget neukáže, ostatní vrstvy jedou dál.', 'nkz-mp-antibot' ) ),
						'<a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com → Turnstile</a>'
					);
					?>
				</p>
				<table class="form-table">
					<tr>
						<th><label for="ts_site"><?php esc_html_e( 'Site Key', 'nkz-mp-antibot' ); ?></label></th>
						<td><input id="ts_site" type="text" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[turnstile_site_key]" value="<?php echo esc_attr( $s['turnstile_site_key'] ); ?>" placeholder="0x4AAAAA…"></td>
					</tr>
					<tr>
						<th><label for="ts_secret"><?php esc_html_e( 'Secret Key', 'nkz-mp-antibot' ); ?></label></th>
						<td><input id="ts_secret" type="password" class="regular-text code" name="<?php echo esc_attr( self::OPTION ); ?>[turnstile_secret_key]" value="<?php echo esc_attr( $s['turnstile_secret_key'] ); ?>" placeholder="0x4AAAAA…" autocomplete="new-password"></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Heuristické vrstvy', 'nkz-mp-antibot' ); ?></h2>
				<table class="form-table">
					<tr>
						<th><label for="min_time"><?php esc_html_e( 'Time gate (sekundy)', 'nkz-mp-antibot' ); ?></label></th>
						<td>
							<input id="min_time" type="number" min="0" step="1" name="<?php echo esc_attr( self::OPTION ); ?>[min_time_seconds]" value="<?php echo esc_attr( (string) $s['min_time_seconds'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( 'Minimum sekund mezi otevřením formuláře a odesláním. Bot odesílá v ms. Default 3.', 'nkz-mp-antibot' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rate"><?php esc_html_e( 'Rate limit (submitů / IP / hodinu)', 'nkz-mp-antibot' ); ?></label></th>
						<td>
							<input id="rate" type="number" min="0" step="1" name="<?php echo esc_attr( self::OPTION ); ?>[rate_limit_per_hour]" value="<?php echo esc_attr( (string) $s['rate_limit_per_hour'] ); ?>" class="small-text">
							<p class="description"><?php esc_html_e( '0 = bez limitu. Default 5 pro registraci, login má vlastní WP limity.', 'nkz-mp-antibot' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Které formuláře chránit', 'nkz-mp-antibot' ); ?></h2>
				<table class="form-table">
					<?php
					$labels = [
						'vendor_registration' => __( 'Vendor registrace ([nkzmp_vendor_registration])', 'nkz-mp-antibot' ),
						'wp_login'            => __( 'WordPress login (/wp-login.php)', 'nkz-mp-antibot' ),
						'wp_register'         => __( 'WordPress registrace (pokud povolena)', 'nkz-mp-antibot' ),
						'wc_lost_password'    => __( 'WC zapomenuté heslo', 'nkz-mp-antibot' ),
						'wc_checkout'         => __( 'WC checkout (opt-in, může blokovat legit kupce)', 'nkz-mp-antibot' ),
					];
					foreach ( $labels as $key => $label ) :
						?>
						<tr>
							<th><?php echo esc_html( $label ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr( self::OPTION ); ?>[protect][<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( ! empty( $s['protect'][ $key ] ) ); ?>>
									<?php esc_html_e( 'Zapnuto', 'nkz-mp-antibot' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
