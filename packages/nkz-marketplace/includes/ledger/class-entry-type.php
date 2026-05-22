<?php
/**
 * LedgerEntry typy.
 *
 * @package NKZMP
 */

namespace NKZMP\Ledger;

defined( 'ABSPATH' ) || exit;

enum EntryType: string {
	/** Připsání částky vendorovi z konkrétní objednávky (po commission). */
	case ORDER_CREDIT = 'order_credit';

	/** Provize platformě z konkrétní objednávky. */
	case PLATFORM_COMMISSION = 'platform_commission';

	/** Šipping připsaný komu (platforma nebo vendor). */
	case SHIPPING_CREDIT = 'shipping_credit';

	/** PSP fee odečtený z účtu (vendora nebo platformy). */
	case PSP_FEE = 'psp_fee';

	/** Refund – návrat částky kupujícímu, snižuje vendor balance. */
	case REFUND_DEBIT = 'refund_debit';

	/** Chargeback / dispute. */
	case CHARGEBACK = 'chargeback';

	/** Vyplacení vendorovi (snižuje balance, zápis při PAID stavu payoutu). */
	case PAYOUT = 'payout';

	/** Manuální úprava adminem (audit povinný). */
	case MANUAL_ADJUSTMENT = 'manual_adjustment';

	/** Subscription poplatek od vendora (vendor billing). */
	case VENDOR_SUBSCRIPTION = 'vendor_subscription';

	/** Korekce / reverzace existujícího řádku. Vždy s reverses_id. */
	case REVERSAL = 'reversal';
}
