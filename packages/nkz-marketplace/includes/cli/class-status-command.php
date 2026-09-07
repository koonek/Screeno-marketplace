<?php
/**
 * `wp nkzmp status` – CLI ekvivalent admin status page.
 *
 * Vrací JSON pro skripty (CI healthcheck), nebo tabulku do terminálu.
 *
 * @package NKZMP
 */

namespace NKZMP\CLI;

use NKZMP\Ledger\Schema as LedgerSchema;
use NKZMP\Payout\Schema as PayoutSchema;
use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class StatusCommand {

	/**
	 * Vrátí status NKZ Marketplace.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table. Accepts: table, json.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nkzmp status
	 *     wp nkzmp status --format=json
	 *
	 * @param array $args
	 * @param array $assoc_args
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		global $wpdb;

		$ledger_table  = LedgerSchema::table_name();
		$payouts_table = PayoutSchema::table_name();
		$audit_table   = \NKZMP\Audit\Schema::table_name();

		$data = [
			'version'         => defined( 'NKZMP_VERSION' ) ? NKZMP_VERSION : 'unknown',
			'php'             => PHP_VERSION,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'ledger_table'    => $ledger_table,
			'ledger_exists'   => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ledger_table ) ) === $ledger_table,
			'ledger_count'    => 0,
			'payouts_table'   => $payouts_table,
			'payouts_exists'  => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $payouts_table ) ) === $payouts_table,
			'payouts_count'   => 0,
			'audit_table'     => $audit_table,
			'audit_exists'    => $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $audit_table ) ) === $audit_table,
			'audit_count'     => 0,
			'vendor_role'     => (bool) get_role( Capabilities::ROLE_VENDOR ),
			'core_cpt_active' => defined( 'NKZMP_ENABLE_CORE_CPT' ) && NKZMP_ENABLE_CORE_CPT,
			'legacy_active'   => is_plugin_active( 'nkz-woo-stripe-vendor-split/nkz-woo-stripe-vendor-split.php' ) || class_exists( \NKVSVS\Plugin::class ),
			'bundle_active'   => defined( 'NKZMP_AOZ_BUNDLE_VERSION' ),
			'legacy_vendors'  => post_type_exists( 'nkv_vendor' ) ? (int) wp_count_posts( 'nkv_vendor' )->publish : 0,
		];
		if ( $data['ledger_exists'] ) {
			$data['ledger_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ledger_table}" ); // phpcs:ignore
		}
		if ( $data['payouts_exists'] ) {
			$data['payouts_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$payouts_table}" ); // phpcs:ignore
		}
		if ( $data['audit_exists'] ) {
			$data['audit_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" ); // phpcs:ignore
		}

		$format = $assoc_args['format'] ?? 'table';
		if ( 'json' === $format ) {
			\WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			return;
		}

		\WP_CLI::line( '' );
		\WP_CLI::line( 'NKZ Marketplace status' );
		\WP_CLI::line( str_repeat( '-', 50 ) );
		foreach ( $data as $k => $v ) {
			if ( is_bool( $v ) ) {
				$v = $v ? 'yes' : 'no';
			}
			\WP_CLI::line( sprintf( '%-20s : %s', $k, (string) $v ) );
		}
		\WP_CLI::line( '' );

		$fatal = ! $data['ledger_exists'] || ! $data['payouts_exists'] || ! $data['audit_exists'] || ! $data['vendor_role'];
		if ( $fatal ) {
			\WP_CLI::warning( 'Some core install steps are missing. Try deactivate + activate.' );
		} else {
			\WP_CLI::success( 'Core install OK.' );
		}
	}
}
