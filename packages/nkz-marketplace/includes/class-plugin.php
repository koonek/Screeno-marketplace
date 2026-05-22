<?php
/**
 * NKZ Marketplace bootstrap.
 *
 * @package NKZMP
 */

namespace NKZMP;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		load_plugin_textdomain( 'nkz-marketplace', false, dirname( plugin_basename( NKZMP_PLUGIN_FILE ) ) . '/languages' );

		// Vendor CPT + role aktivace je opt-in během Fáze 0, aby Screeno
		// produkce (která jede na `nkv_vendor` v Stripe adapteru) nedostala
		// dvě CPT registrace najednou. Po `wp nkzmp migrate-vendors` se
		// gate odebere a Registry pojede vždy.
		if ( defined( 'NKZMP_ENABLE_CORE_CPT' ) && NKZMP_ENABLE_CORE_CPT ) {
			\NKZMP\Vendor\Registry::instance()->init();
		}

		// TODO Phase 0:
		// - Product\Ownership (meta UI panel, capability guard)
		// - Allocation\Service (Order → Allocation[])
		// - Ledger (append-only DB table)
		// - Payout\StateMachine
		// - Audit\Log
		// - REST routes nkzmp/v1/*
		// - WP-CLI: wp nkzmp ...
		// - GDPR exporter/eraser hooks
	}
}
