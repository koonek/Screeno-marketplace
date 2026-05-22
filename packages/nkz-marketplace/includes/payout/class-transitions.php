<?php
/**
 * Payout přípustné přechody stavu.
 *
 *   pending  → payable | failed
 *   payable  → on_hold | paid | failed
 *   on_hold  → payable | failed
 *   paid     → reversed
 *   failed   → payable          (admin re-try)
 *   reversed → (terminal)
 *
 * Disallow přechody hází `InvalidArgumentException` v repository.
 *
 * @package NKZMP
 */

namespace NKZMP\Payout;

defined( 'ABSPATH' ) || exit;

final class Transitions {

	/**
	 * Mapa from → list of allowed to-states.
	 *
	 * @return array<string,list<State>>
	 */
	public static function map(): array {
		return [
			State::PENDING->value  => [ State::PAYABLE, State::FAILED ],
			State::PAYABLE->value  => [ State::ON_HOLD, State::PAID, State::FAILED ],
			State::ON_HOLD->value  => [ State::PAYABLE, State::FAILED ],
			State::PAID->value     => [ State::REVERSED ],
			State::FAILED->value   => [ State::PAYABLE ],
			State::REVERSED->value => [],
		];
	}

	public static function allowed( State $from, State $to ): bool {
		if ( $from === $to ) {
			return false;
		}
		$allowed = self::map()[ $from->value ] ?? [];
		return in_array( $to, $allowed, true );
	}
}
