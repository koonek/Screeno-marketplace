<?php
/**
 * Uninstall – odebrání všeho, co NKZ Marketplace core nainstaloval.
 *
 * Pozor: tahle akce smaže DB tabulky `wp_nkzmp_ledger` a `wp_nkzmp_payouts`
 * VČETNĚ DAT. Spouští se pouze přes WP plugin uninstall (ne deactivate).
 *
 * @package NKZMP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Drop tables.
$tables = [
	$wpdb->prefix . 'nkzmp_ledger',
	$wpdb->prefix . 'nkzmp_payouts',
	$wpdb->prefix . 'nkzmp_audit',
];
foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

// Remove schema version options.
delete_option( 'nkzmp_ledger_schema_version' );
delete_option( 'nkzmp_payout_schema_version' );
delete_option( 'nkzmp_audit_schema_version' );

// Remove role.
if ( get_role( 'nkzmp_vendor' ) ) {
	remove_role( 'nkzmp_vendor' );
}

// Remove admin/shop_manager caps.
$caps_to_remove = [
	'nkzmp_manage_vendors',
	'nkzmp_approve_vendor',
	'nkzmp_view_audit_log',
	'nkzmp_manage_payouts',
];
foreach ( [ 'administrator', 'shop_manager' ] as $role_name ) {
	$role = get_role( $role_name );
	if ( ! $role ) {
		continue;
	}
	foreach ( $caps_to_remove as $cap ) {
		$role->remove_cap( $cap );
	}
}
