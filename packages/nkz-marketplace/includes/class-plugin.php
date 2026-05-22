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

		// TODO Phase 0: register core domains
		// - Vendor\Registry (CPT nkzmp_vendor + role + capabilities)
		// - Product\Ownership (meta _nkzmp_vendor_id, _nkzmp_requires_shipping)
		// - Allocation\Service (Order → Allocation[])
		// - Ledger (append-only DB table)
		// - Payout\StateMachine
		// - Audit\Log
		// - REST routes nkzmp/v1/*
		// - WP-CLI: wp nkzmp ...
		// - GDPR exporter/eraser hooks
	}
}
