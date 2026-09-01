<?php
/**
 * Template loader s WC hierarchy override.
 *
 * Hledá v pořadí:
 *  1. <theme>/woocommerce/nkz-mp-storefront/<template>
 *  2. <theme>/nkz-mp-storefront/<template>
 *  3. plugin/templates/<template>
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Templates {

	public static function locate( string $template ): string {
		$paths = [
			trailingslashit( get_stylesheet_directory() ) . 'woocommerce/nkz-mp-storefront/' . $template,
			trailingslashit( get_template_directory() ) . 'woocommerce/nkz-mp-storefront/' . $template,
			trailingslashit( get_stylesheet_directory() ) . 'nkz-mp-storefront/' . $template,
			trailingslashit( get_template_directory() ) . 'nkz-mp-storefront/' . $template,
		];
		foreach ( $paths as $path ) {
			if ( is_readable( $path ) ) {
				return $path;
			}
		}
		$fallback = NKZMP_STOREFRONT_DIR . 'templates/' . $template;
		return is_readable( $fallback ) ? $fallback : '';
	}

	public static function render( string $template, array $vars = [] ): void {
		$file = self::locate( $template );
		if ( ! $file ) {
			return;
		}
		extract( $vars, EXTR_SKIP );
		include $file;
	}
}
