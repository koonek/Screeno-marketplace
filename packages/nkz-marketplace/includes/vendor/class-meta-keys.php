<?php
/**
 * Canonical meta key konstanty pro vendor entity.
 *
 * Plugin používá prefix `_nkzmp_` pro všechny post meta. Staré klíče
 * `_nkv_*` ze Stripe adapter pluginu jsou během Fáze 0 migrované přes
 * `Vendor\MetaMigrator` – nový kód píše vždy do `_nkzmp_*`, read fallback
 * na `_nkv_*` zůstává po dobu min. 2 minor verzí.
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

defined( 'ABSPATH' ) || exit;

final class MetaKeys {

	public const STATUS              = '_nkzmp_vendor_status';
	public const EMAIL               = '_nkzmp_vendor_email';
	public const ICO                 = '_nkzmp_vendor_ico';
	public const CURRENCY            = '_nkzmp_vendor_currency';
	public const WEBSITE             = '_nkzmp_vendor_website';
	public const BIO                 = '_nkzmp_vendor_bio';
	public const INTERNAL_NOTE       = '_nkzmp_internal_note';
	public const DEFAULT_FEE_PERCENT = '_nkzmp_default_fee_percent';
	public const DEFAULT_FEE_FIXED   = '_nkzmp_default_fee_fixed';
	public const WP_USER_ID          = '_nkzmp_wp_user_id';

	/**
	 * Mapování starých klíčů (Stripe adapter v0.6.x) na nové.
	 * Read fallback chodí oběma směry během migrační periody.
	 *
	 * @return array<string,string>
	 */
	public static function legacy_map(): array {
		return [
			'_nkv_vendor_status'        => self::STATUS,
			'_nkv_vendor_email'         => self::EMAIL,
			'_nkv_vendor_ico'           => self::ICO,
			'_nkv_vendor_currency'      => self::CURRENCY,
			'_nkv_vendor_website'       => self::WEBSITE,
			'_nkv_vendor_bio'           => self::BIO,
			'_nkv_internal_note'        => self::INTERNAL_NOTE,
			'_nkv_default_fee_percent'  => self::DEFAULT_FEE_PERCENT,
			'_nkv_default_fee_fixed'    => self::DEFAULT_FEE_FIXED,
		];
	}
}
