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

		// Lazy install ledgeru / payouts – pro případ, že aktivace neproběhla
		// (např. must-use load) nebo schema potřebuje upgrade.
		if ( \NKZMP\Ledger\Schema::needs_install() ) {
			\NKZMP\Ledger\Schema::install();
		}
		if ( \NKZMP\Payout\Schema::needs_install() ) {
			\NKZMP\Payout\Schema::install();
		}
		if ( \NKZMP\Audit\Schema::needs_install() ) {
			\NKZMP\Audit\Schema::install();
		}

		// Vendor CPT + role aktivace je opt-in během Fáze 0, aby Screeno
		// produkce (která jede na `nkv_vendor` v Stripe adapteru) nedostala
		// dvě CPT registrace najednou. Po `wp nkzmp migrate-vendors` se
		// gate odebere a Registry pojede vždy.
		if ( defined( 'NKZMP_ENABLE_CORE_CPT' ) && NKZMP_ENABLE_CORE_CPT ) {
			\NKZMP\Vendor\Registry::instance()->init();
		}

		if ( is_admin() ) {
			if ( ! function_exists( 'is_plugin_active' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			\NKZMP\Admin\StatusPage::instance()->init();
			\NKZMP\Admin\ToolsPage::instance()->init();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'nkzmp status', \NKZMP\CLI\StatusCommand::class );
			\WP_CLI::add_command( 'nkzmp backfill', \NKZMP\CLI\BackfillCommand::class );
			\WP_CLI::add_command( 'nkzmp ledger', \NKZMP\CLI\LedgerCommand::class );
			\WP_CLI::add_command( 'nkzmp reconcile', \NKZMP\CLI\ReconcileCommand::class );
			\WP_CLI::add_command( 'nkzmp allocation', \NKZMP\CLI\AllocationCommand::class );
			\WP_CLI::add_command( 'nkzmp migrate-vendors', \NKZMP\CLI\MigrateVendorsCommand::class );
		}

		// Shadow observer: paralelně píše do ledgeru z legacy Stripe hooků.
		// Bez jakékoli interakce se samotným transferem.
		\NKZMP\Integration\LegacyStripeObserver::instance()->init();

		// Audit log – posluchač doménových hooků.
		\NKZMP\Audit\Listener::instance()->init();

		// REST API nkzmp/v1/*.
		\NKZMP\Rest\Router::instance()->init();

		// GDPR exporter / eraser.
		\NKZMP\Gdpr\Registrar::instance()->init();

		// Reconciliation cron (denně).
		\NKZMP\Reconciliation\Cron::instance()->init();

		// TODO Phase 0:
		// - Product\Ownership admin UI panel + capability guard
		// - Allocation\Service (Order → Allocation[])
		// - Payout\StateMachine wire-up se Stripe adapterem
		// - Reconciliation cron (ledger ↔ Stripe balance_transaction)
	}
}
