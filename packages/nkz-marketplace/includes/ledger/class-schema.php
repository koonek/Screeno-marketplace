<?php
/**
 * Ledger DB schema.
 *
 * Tabulka `{prefix}nkzmp_ledger`. Append-only, žádné UPDATE krom recorded_at
 * pro řádky, které musely být lazily back-fillnuté (interní použití).
 *
 * Indexy:
 *  - PRIMARY (id)
 *  - UNIQUE (idempotency_key)             – dedup webhook retry
 *  - INDEX (vendor_id, occurred_at)       – per-vendor přehledy / payable
 *  - INDEX (order_id)                     – reconciliation per order
 *  - INDEX (reverses_id)                  – dohledání reverzních záznamů
 *
 * @package NKZMP
 */

namespace NKZMP\Ledger;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION_OPTION = 'nkzmp_ledger_schema_version';
	public const CURRENT_VERSION = '1';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'nkzmp_ledger';
	}

	public static function install(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			vendor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(40) NOT NULL,
			amount_minor BIGINT NOT NULL,
			currency CHAR(3) NOT NULL,
			order_id BIGINT UNSIGNED NULL,
			source_adapter VARCHAR(40) NULL,
			source_ref VARCHAR(191) NULL,
			idempotency_key VARCHAR(191) NOT NULL,
			reverses_id BIGINT UNSIGNED NULL,
			occurred_at BIGINT UNSIGNED NOT NULL,
			recorded_at BIGINT UNSIGNED NOT NULL,
			meta_json LONGTEXT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY vendor_occurred (vendor_id, occurred_at),
			KEY order_id (order_id),
			KEY reverses_id (reverses_id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
	}

	public static function needs_install(): bool {
		return get_option( self::VERSION_OPTION ) !== self::CURRENT_VERSION;
	}
}
