<?php
/**
 * Plugin Name: NKZ Marketplace – Zásilkovna
 * Description: Výběr výdejního místa Zásilkovny v checkoutu (Packeta widget) + zakládání zásilek a tisk štítků per prodejce. Cena dopravy = per-vendor paušál (z nkz-mp-shipping).
 * Version: 0.2.5
 * Author: NKZ
 * Requires at least: 6.2
 * Requires PHP: 8.1
 * WC requires at least: 8.0
 * Text Domain: nkz-mp-packeta
 *
 * @package NKZMP\Packeta
 */

defined( 'ABSPATH' ) || exit;

define( 'NKZMP_PACKETA_VERSION', '0.2.5' );
define( 'NKZMP_PACKETA_FILE', __FILE__ );
define( 'NKZMP_PACKETA_DIR', plugin_dir_path( __FILE__ ) );
define( 'NKZMP_PACKETA_URL', plugin_dir_url( __FILE__ ) );

// Meta klíče (na objednávce).
define( 'NKZMP_PACKETA_POINT_ID_META', '_nkzmp_packeta_point_id' );
define( 'NKZMP_PACKETA_POINT_NAME_META', '_nkzmp_packeta_point_name' );
// Založené zásilky: order meta = pole [ vendor_id => [ id, barcode, created ] ].
define( 'NKZMP_PACKETA_PACKETS_META', '_nkzmp_packeta_packets' );
// Per-vendor odesílatel (eshop label v Packeta účtu).
define( 'NKZMP_PACKETA_VENDOR_SENDER_LABEL_META', '_nkzmp_packeta_sender_label' );

// HPOS compatibility (modul čte/píše objednávky přes WC_Order API).
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

spl_autoload_register(
	static function ( string $class ): void {
		if ( ! str_starts_with( $class, 'NKZMP\\Packeta\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'NKZMP\\Packeta\\' ) );
		$parts    = explode( '\\', $relative );
		$file     = array_pop( $parts );
		$file     = 'class-' . strtolower( preg_replace( '/(?<!^)[A-Z]/', '-$0', $file ) ) . '.php';
		$path     = NKZMP_PACKETA_DIR . 'includes/' . ( $parts ? strtolower( implode( '/', $parts ) ) . '/' : '' ) . $file;
		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		\NKZMP\Packeta\Plugin::instance()->init();
	},
	32 // po shipping modulu (30)
);

add_filter(
	'woocommerce_shipping_methods',
	static function ( array $methods ): array {
		$methods['nkzmp_packeta'] = \NKZMP\Packeta\Method::class;
		return $methods;
	}
);
