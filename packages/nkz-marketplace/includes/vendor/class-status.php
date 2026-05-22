<?php
/**
 * Vendor lifecycle status.
 *
 * Přechody:
 *   pending  ──(admin approve)──> approved_awaiting_kyc
 *   pending  ──(admin reject)───> rejected
 *   approved_awaiting_kyc ──(Stripe account.updated, charges_enabled)──> active
 *   active   ──(billing past_due / admin)──> suspended
 *   suspended ──(billing paid / admin)─────> active
 *   any      ──(admin terminate)─────────> terminated
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

defined( 'ABSPATH' ) || exit;

enum Status: string {
	case PENDING               = 'pending';
	case APPROVED_AWAITING_KYC = 'approved_awaiting_kyc';
	case ACTIVE                = 'active';
	case SUSPENDED             = 'suspended';
	case REJECTED              = 'rejected';
	case TERMINATED            = 'terminated';

	public function can_sell(): bool {
		return $this === self::ACTIVE;
	}

	public function is_terminal(): bool {
		return in_array( $this, [ self::REJECTED, self::TERMINATED ], true );
	}
}
