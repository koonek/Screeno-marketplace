<?php
/**
 * Payout state machine.
 *
 * Přechody:
 *   pending  ──(allocation done)─────> payable
 *   payable  ──(adapter accepted)────> paid
 *   payable  ──(admin / dispute)─────> on_hold
 *   on_hold  ──(admin release)───────> payable
 *   payable  ──(adapter rejected)────> failed
 *   paid     ──(refund / chargeback)─> reversed
 *
 * @package NKZMP
 */

namespace NKZMP\Payout;

defined( 'ABSPATH' ) || exit;

enum State: string {
	case PENDING  = 'pending';
	case PAYABLE  = 'payable';
	case ON_HOLD  = 'on_hold';
	case PAID     = 'paid';
	case FAILED   = 'failed';
	case REVERSED = 'reversed';

	public function is_open(): bool {
		return in_array( $this, [ self::PENDING, self::PAYABLE, self::ON_HOLD ], true );
	}

	public function is_terminal(): bool {
		return in_array( $this, [ self::PAID, self::FAILED, self::REVERSED ], true );
	}
}
