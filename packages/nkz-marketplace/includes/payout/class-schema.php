<?php
/**
 * Payouts DB schema.
 *
 * Tabulka `{prefix}nkzmp_payouts`. Stavový stroj: viz Payout\State.
 *
 * @package NKZMP
 */

namespace NKZMP\Payout;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION_OPTION  = 'nkzmp_payout_schema_version';
	public const CURRENT_VERSION = '1';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'nkzmp_payouts';
	}

	public static function install(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vendor_id BIGINT UNSIGNED NOT NULL,
			amount_minor BIGINT NOT NULL,
			currency CHAR(3) NOT NULL,
			state VARCHAR(20) NOT NULL,
			adapter VARCHAR(40) NULL,
			adapter_ref VARCHAR(191) NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			created_at BIGINT UNSIGNED NOT NULL,
			updated_at BIGINT UNSIGNED NOT NULL,
			scheduled_for BIGINT UNSIGNED NULL,
			completed_at BIGINT UNSIGNED NULL,
			meta_json LONGTEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY vendor_state (vendor_id, state),
			KEY state_scheduled (state, scheduled_for)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
	}

	public static function needs_install(): bool {
		return get_option( self::VERSION_OPTION ) !== self::CURRENT_VERSION;
	}
}
