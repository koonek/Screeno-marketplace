<?php
/**
 * Subscription adapter contract.
 *
 * Implementuje balíček, který udržuje vendor billing subscription (např.
 * Stripe Billing). Core sleduje status vendora a podle něj blokuje prodej.
 *
 * @package NKZMP
 */

namespace NKZMP\Contracts;

defined( 'ABSPATH' ) || exit;

interface SubscriptionAdapter {

	public function id(): string;

	/**
	 * Založí subscription pro vendora.
	 *
	 * @param int    $vendor_id
	 * @param int    $amount_minor   Pravidelná částka.
	 * @param string $currency       ISO kód (CZK, EUR, ...).
	 * @param string $interval       month | year.
	 * @return AdapterResult
	 */
	public function create( int $vendor_id, int $amount_minor, string $currency, string $interval ): AdapterResult;

	/**
	 * Zruší subscription (např. při terminaci vendora).
	 */
	public function cancel( int $vendor_id ): AdapterResult;

	/**
	 * Vrátí aktuální status subscription vendora (active|past_due|canceled|...).
	 */
	public function status( int $vendor_id ): string;
}
