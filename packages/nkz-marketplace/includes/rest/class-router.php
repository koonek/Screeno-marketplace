<?php
/**
 * REST router – registruje všechny controllery při `rest_api_init`.
 *
 * @package NKZMP
 */

namespace NKZMP\Rest;

defined( 'ABSPATH' ) || exit;

final class Router {

	private static ?Router $instance = null;

	public static function instance(): Router {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'register' ] );
	}

	public function register(): void {
		( new VendorsController() )->register_routes();
		( new OrdersController() )->register_routes();
		( new LedgerController() )->register_routes();
	}
}
