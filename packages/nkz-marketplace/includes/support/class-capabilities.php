<?php
/**
 * Capabilities matrix pro NKZ Marketplace.
 *
 * Role:
 * - administrator           – plný přístup (managed by WordPress)
 * - shop_manager            – WC default + nkzmp_manage_*
 * - nkzmp_vendor            – přístup ke svým produktům / objednávkám / payoutům
 *
 * Capabilities mapování:
 *
 * |                                   | admin | shop_manager | vendor |
 * |-----------------------------------|-------|--------------|--------|
 * | nkzmp_manage_vendors              |   ✓   |      ✓       |        |
 * | nkzmp_approve_vendor              |   ✓   |      ✓       |        |
 * | nkzmp_view_audit_log              |   ✓   |              |        |
 * | nkzmp_manage_payouts              |   ✓   |      ✓       |        |
 * | nkzmp_view_own_dashboard          |   ✓   |      ✓       |   ✓    |
 * | nkzmp_edit_own_products           |   ✓   |      ✓       |   ✓    |
 * | nkzmp_view_own_orders             |   ✓   |      ✓       |   ✓    |
 * | nkzmp_view_own_payouts            |   ✓   |      ✓       |   ✓    |
 *
 * Capability guard pro „own" akce probíhá v `Vendor\OwnershipGuard`
 * (REST + admin filter), který odmítne přístup k cizím entitám i když
 * uživatel má capability.
 *
 * @package NKZMP
 */

namespace NKZMP\Support;

defined( 'ABSPATH' ) || exit;

final class Capabilities {

	public const ROLE_VENDOR = 'nkzmp_vendor';

	public const MANAGE_VENDORS     = 'nkzmp_manage_vendors';
	public const APPROVE_VENDOR     = 'nkzmp_approve_vendor';
	public const VIEW_AUDIT_LOG     = 'nkzmp_view_audit_log';
	public const MANAGE_PAYOUTS     = 'nkzmp_manage_payouts';
	public const VIEW_OWN_DASHBOARD = 'nkzmp_view_own_dashboard';
	public const EDIT_OWN_PRODUCTS  = 'nkzmp_edit_own_products';
	public const VIEW_OWN_ORDERS    = 'nkzmp_view_own_orders';
	public const VIEW_OWN_PAYOUTS   = 'nkzmp_view_own_payouts';

	/**
	 * Capabilities přidělené vendor roli.
	 *
	 * @return string[]
	 */
	public static function vendor_caps(): array {
		return [
			'read',
			self::VIEW_OWN_DASHBOARD,
			self::EDIT_OWN_PRODUCTS,
			self::VIEW_OWN_ORDERS,
			self::VIEW_OWN_PAYOUTS,
			// WP/WC caps aby vendor mohl přes náš controller uploadovat fotky
			// a vytvořit / editovat vlastní produkt. Vlastnictví je vynuceno
			// v ProductSubmitController (pouze own produkty).
			'upload_files',
			'edit_posts',
			'edit_published_posts',
			'edit_products',
			'edit_published_products',
		];
	}

	/**
	 * Admin-only capabilities přidělené roli administrator při instalaci.
	 *
	 * @return string[]
	 */
	public static function admin_caps(): array {
		return [
			self::MANAGE_VENDORS,
			self::APPROVE_VENDOR,
			self::VIEW_AUDIT_LOG,
			self::MANAGE_PAYOUTS,
		];
	}
}
