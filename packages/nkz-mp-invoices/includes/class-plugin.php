<?php
/**
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function init(): void {
		Settings::instance()->init();
		VendorBilling::instance()->init();
		Documents::instance()->init();
		MembershipInvoices::instance()->init();
		Delivery::instance()->init();
		VendorDocuments::instance()->init();
	}
}
