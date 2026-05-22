<?php
/**
 * MetaMigrator – kopíruje legacy `_nkv_*` meta do nových `_nkzmp_*` klíčů.
 *
 * Idempotentní: pokud `_nkzmp_*` klíč už existuje a má hodnotu, nepřepíše ho.
 * Po dokončení migrace lze v admin nastavení (nebo přes filter
 * `nkzmp/v1/vendor/disable_legacy_meta_fallback` → __return_true) vypnout
 * read fallback.
 *
 * Migrace NEMĚNÍ legacy klíče – ty zůstávají read-shimu k dispozici minimálně
 * 2 minor verze pro případ rollbacku.
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

defined( 'ABSPATH' ) || exit;

final class MetaMigrator {

	public const MIGRATED_FLAG_META = '_nkzmp_meta_migrated_v1';

	/**
	 * Najde všechny vendor posty (legacy + nový CPT) a vrátí jejich ID.
	 *
	 * @return int[]
	 */
	public static function find_vendor_ids( int $limit = 0 ): array {
		$args = [
			'post_type'      => [ 'nkv_vendor', Registry::POST_TYPE ],
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => $limit > 0 ? $limit : -1,
			'no_found_rows'  => true,
		];
		$ids = get_posts( $args );
		return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
	}

	/**
	 * Migrace jednoho vendora. Vrátí asociativní pole `meta_key => 'copied'|'skipped_exists'|'skipped_empty'`.
	 *
	 * @return array<string,string>
	 */
	public static function migrate_one( int $vendor_id, bool $dry_run = false ): array {
		$result = [];
		foreach ( MetaKeys::legacy_map() as $legacy_key => $new_key ) {
			$legacy_val = get_post_meta( $vendor_id, $legacy_key, true );
			if ( $legacy_val === '' || $legacy_val === null || $legacy_val === false ) {
				$result[ $new_key ] = 'skipped_empty';
				continue;
			}
			$new_val = get_post_meta( $vendor_id, $new_key, true );
			if ( $new_val !== '' && $new_val !== null && $new_val !== false ) {
				$result[ $new_key ] = 'skipped_exists';
				continue;
			}
			if ( ! $dry_run ) {
				update_post_meta( $vendor_id, $new_key, $legacy_val );
			}
			$result[ $new_key ] = 'copied';
		}

		if ( ! $dry_run ) {
			update_post_meta( $vendor_id, self::MIGRATED_FLAG_META, time() );
		}

		return $result;
	}

	/**
	 * Hromadná migrace. Vrátí souhrn.
	 *
	 * @return array{processed:int, copied:int, skipped_exists:int, skipped_empty:int, vendor_ids:int[]}
	 */
	public static function migrate_all( bool $dry_run = false, int $limit = 0 ): array {
		$ids       = self::find_vendor_ids( $limit );
		$summary   = [
			'processed'      => 0,
			'copied'         => 0,
			'skipped_exists' => 0,
			'skipped_empty'  => 0,
			'vendor_ids'     => [],
		];

		foreach ( $ids as $vid ) {
			$res = self::migrate_one( $vid, $dry_run );
			$summary['processed']++;
			$summary['vendor_ids'][] = $vid;
			foreach ( $res as $status ) {
				if ( isset( $summary[ $status ] ) ) {
					$summary[ $status ]++;
				}
			}
		}

		return $summary;
	}

	/**
	 * Byla migrace dokončena pro daný vendor?
	 */
	public static function is_migrated( int $vendor_id ): bool {
		return (bool) get_post_meta( $vendor_id, self::MIGRATED_FLAG_META, true );
	}
}
