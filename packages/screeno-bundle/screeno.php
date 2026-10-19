<?php
/**
 * Plugin Name: Screeno
 * Description: Balík pluginů pro Screeno v jednom souboru – rozdělení plateb prodejcům přes Stripe (Screenuj) a rezervace studia, workshopy a poukazy.
 * Version: 1.0.5
 * Author: NKZ
 * Requires at least: 6.8
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: screeno
 *
 * Tenký obal kolem samostatných modulů v `modules/`. Moduly se dál vyvíjejí ve
 * svých repozitářích, sem se jen kopírují při sestavení (build.sh).
 *
 * Složka se jmenuje `screeno/`, takže se načítá AŽ po samostatných pluginech
 * (`nkz-…`, WordPress řadí aktivní pluginy abecedně). Dokud běží staré samostatné
 * pluginy, moduly v balíku se díky pojistce v nich nenačtou a v administraci se
 * ukáže upozornění, ať se staré pluginy deaktivují.
 *
 * @package Screeno
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'SCREENO_BUNDLE_VERSION' ) ) {
	return;
}

define( 'SCREENO_BUNDLE_VERSION', '1.0.5' );
define( 'SCREENO_BUNDLE_DIR', plugin_dir_path( __FILE__ ) );

foreach (
	[
		'modules/nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php',
		'modules/nkz-studio-booking/nkz-studio-booking.php',
	] as $screeno_module
) {
	if ( is_readable( SCREENO_BUNDLE_DIR . $screeno_module ) ) {
		require_once SCREENO_BUNDLE_DIR . $screeno_module;
	}
}
unset( $screeno_module );

/** Běží daný modul z tohoto balíku (a ne ze samostatného pluginu)? */
function screeno_bundle_owns( string $file_constant ): bool {
	return defined( $file_constant ) && str_starts_with( wp_normalize_path( (string) constant( $file_constant ) ), wp_normalize_path( SCREENO_BUNDLE_DIR ) );
}

/**
 * Instalace modulů. WordPress u vložených souborů aktivační háčky nespouští, takže je
 * voláme sami – při aktivaci balíku i jednou po každé změně verze (aktualizace, nebo
 * přechod ze samostatných pluginů, kdy se moduly při aktivaci balíku ještě nenačetly).
 * Vše je idempotentní (dbDelta, add_option, plánování cronu jen když chybí).
 */
function screeno_bundle_install(): void {
	if ( screeno_bundle_owns( 'NKZ_SB_FILE' ) && class_exists( \NKZ\StudioBooking\Plugin::class ) ) {
		\NKZ\StudioBooking\Plugin::activate();
		// Smazání starého samostatného bookingu spustí jeho uninstall.php, který při zapnuté
		// volbě smaže tabulky rezervací – a ty teď používá balík. V balíku se uninstall modulu
		// nikdy nespouští, volba tu tedy nemá smysl: vypnout, ať data přežijí úklid.
		update_option( \NKZ\StudioBooking\Plugin::OPTION_UNINSTALL, 0 );
	}
	flush_rewrite_rules( false );
}

register_activation_hook( __FILE__, 'screeno_bundle_install' );

add_action(
	'init',
	static function (): void {
		$stamp = SCREENO_BUNDLE_VERSION . '|' . ( screeno_bundle_owns( 'NKZ_SB_FILE' ) ? 'sb' : '' ) . '|' . ( screeno_bundle_owns( 'NKVSVS_PLUGIN_FILE' ) ? 'svs' : '' );
		if ( get_option( 'screeno_bundle_installed', '' ) === $stamp ) {
			return;
		}
		screeno_bundle_install();
		update_option( 'screeno_bundle_installed', $stamp, false );
	},
	100
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		if ( screeno_bundle_owns( 'NKZ_SB_FILE' ) && class_exists( \NKZ\StudioBooking\Plugin::class ) ) {
			\NKZ\StudioBooking\Plugin::deactivate();
		}
		if ( screeno_bundle_owns( 'NKVSVS_PLUGIN_FILE' ) && class_exists( \NKVSVS\Cron_Sync::class ) ) {
			\NKVSVS\Cron_Sync::unschedule();
		}
		delete_option( 'screeno_bundle_installed' );
	}
);
