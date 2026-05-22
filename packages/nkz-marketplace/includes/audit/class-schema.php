<?php
/**
 * Audit DB schema.
 *
 * Tabulka `{prefix}nkzmp_audit`. Append-only. Zapisuje:
 *  - vendor status changes (approve / suspend / terminate / reject)
 *  - payout state transitions
 *  - manual ledger adjustments
 *  - capability / role změny pro vendor uživatele
 *  - manuální admin akce z REST / WP-CLI
 *
 * @package NKZMP
 */

namespace NKZMP\Audit;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const VERSION_OPTION  = 'nkzmp_audit_schema_version';
	public const CURRENT_VERSION = '1';

	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'nkzmp_audit';
	}

	public static function install(): void {
		global $wpdb;

		$table   = self::table_name();
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occurred_at BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			actor_label VARCHAR(191) NULL,
			action VARCHAR(80) NOT NULL,
			entity_type VARCHAR(40) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			summary VARCHAR(255) NULL,
			payload_json LONGTEXT NULL,
			ip VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			PRIMARY KEY (id),
			KEY action (action),
			KEY entity (entity_type, entity_id),
			KEY actor (actor_user_id),
			KEY occurred (occurred_at)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::VERSION_OPTION, self::CURRENT_VERSION, false );
	}

	public static function needs_install(): bool {
		return get_option( self::VERSION_OPTION ) !== self::CURRENT_VERSION;
	}
}
