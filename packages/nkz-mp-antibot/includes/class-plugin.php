<?php
/**
 * Plugin bootstrap.
 *
 * @package NKZMP\Antibot
 */

namespace NKZMP\Antibot;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		Settings::instance()->init();
		Turnstile::instance()->init();
		FormBindings::instance()->init();
	}
}
