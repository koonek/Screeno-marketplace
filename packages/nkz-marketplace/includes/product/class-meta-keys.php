<?php
/**
 * Canonical meta key konstanty pro produkty.
 *
 * @package NKZMP
 */

namespace NKZMP\Product;

defined( 'ABSPATH' ) || exit;

final class MetaKeys {

	public const VENDOR_ID                  = '_nkzmp_vendor_id';
	public const SPLIT_ENABLED              = '_nkzmp_split_enabled';
	public const FEE_PERCENT_OVERRIDE       = '_nkzmp_fee_percent_override';
	public const FEE_FIXED_OVERRIDE         = '_nkzmp_fee_fixed_override';
	public const REQUIRES_SHIPPING          = '_nkzmp_requires_shipping';

	public static function legacy_map(): array {
		return [
			'_nkv_vendor_id'                       => self::VENDOR_ID,
			'_nkv_vendor_split_enabled'            => self::SPLIT_ENABLED,
			'_nkv_platform_fee_percent_override'   => self::FEE_PERCENT_OVERRIDE,
			'_nkv_platform_fee_fixed_override'     => self::FEE_FIXED_OVERRIDE,
		];
	}
}
