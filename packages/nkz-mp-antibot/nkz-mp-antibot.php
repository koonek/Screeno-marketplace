<?php
/**
 * Plugin Name: NKZ Marketplace – Antibot
 * Description: Anti-spam/bot ochrana napríč marketplace formuláři: honeypot, time gate, IP rate limit, Cloudflare Turnstile (volitelné). Aktivní hned po nahrání; Turnstile čeká na keys ze Settings.
 * Version: 0.1.2
 * Author: NKZ
 * Requires PHP: 8.1
 * Text Domain: nkz-mp-antibot
 *
 * Aktivní bez konfigurace:
 *  - Honeypot field (skryté pole, bot často vyplní)
 *  - Time gate (form musí být otevřen min N sekund před submitem)
 *  - IP rate limit (max X submitů z 1 IP / hodinu)
 *
 * Aktivní po vyplnění keys v Settings:
 *  - Cloudflare Turnstile (invisible, GDPR friendly)
 *
 * Pokrývá:
 *  - Vendor registrace ([nkzmp_vendor_registration])
 *  - WP login (/wp-login.php)
 *  - WC lost-password
 *  - WP register (pokud povoleno)
 *
 * Per-form toggle v admin Settings. Default: vsechny ON.
 *
 * Extension API:
 *   \NKZMP\Antibot\Protector::render_fields( 'my_form' );
 *   \NKZMP\Antibot\Protector::verify( 'my_form' ); // true | WP_Error
 *
 * @package NKZMP\Antibot
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_ANTIBOT_VERSION', '0.1.2' );
define( 'NKZMP_ANTIBOT_FILE', __FILE__ );
define( 'NKZMP_ANTIBOT_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Antibot\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Antibot\\' ) );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $relative ) ) . '.php';
		$path     = NKZMP_ANTIBOT_DIR . 'includes/' . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		\NKZMP\Antibot\Plugin::instance()->init();
	},
	40
);
