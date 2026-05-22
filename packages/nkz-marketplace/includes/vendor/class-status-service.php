<?php
/**
 * Vendor StatusService – přechody mezi vendor statusy.
 *
 * Validuje povolené přechody podle business rules a fire-uje
 * `nkzmp/v1/vendor/status_changed`, který Listener konvertuje do auditu.
 *
 * Povolené přechody:
 *   pending  → approved_awaiting_kyc | rejected
 *   approved_awaiting_kyc → active | rejected | terminated
 *   active   → suspended | terminated
 *   suspended → active | terminated
 *   rejected | terminated → (terminal)
 *
 * @package NKZMP
 */

namespace NKZMP\Vendor;

use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class StatusService {

	private const ALLOWED = [
		'pending'               => [ 'approved_awaiting_kyc', 'rejected' ],
		'approved_awaiting_kyc' => [ 'active', 'rejected', 'terminated' ],
		'active'                => [ 'suspended', 'terminated' ],
		'suspended'             => [ 'active', 'terminated' ],
		'rejected'              => [],
		'terminated'            => [],
		''                      => [ 'pending', 'approved_awaiting_kyc', 'active' ], // bootstrap z neznámého
	];

	/**
	 * Provede přechod, validuje, ukládá meta, fire hook.
	 *
	 * @param array $context Volitelné: reason, actor_user_id (pro audit).
	 *
	 * @throws \InvalidArgumentException Když přechod není povolený nebo vendor neexistuje.
	 */
	public function transition( int $vendor_id, Status $to, array $context = [] ): Status {
		$post = get_post( $vendor_id );
		if ( ! $post ) {
			throw new \InvalidArgumentException( sprintf( 'Vendor #%d nenalezen.', $vendor_id ) );
		}

		$current_raw = (string) get_post_meta( $vendor_id, MetaKeys::STATUS, true );
		if ( $current_raw === '' ) {
			// Fallback na legacy klíč pro migrační období.
			$current_raw = (string) get_post_meta( $vendor_id, '_nkv_vendor_status', true );
		}

		if ( $current_raw === $to->value ) {
			return $to; // no-op
		}

		$allowed = self::ALLOWED[ $current_raw ] ?? [];
		if ( ! in_array( $to->value, $allowed, true ) ) {
			throw new \InvalidArgumentException( sprintf(
				'Nepovolený přechod %s → %s pro vendor #%d.',
				$current_raw === '' ? '(none)' : $current_raw,
				$to->value,
				$vendor_id
			) );
		}

		update_post_meta( $vendor_id, MetaKeys::STATUS, $to->value );

		do_action(
			'nkzmp/v1/vendor/status_changed',
			$vendor_id,
			$current_raw,
			$to->value,
			$context
		);

		return $to;
	}

	/**
	 * Convenience wrappers – validují capability volajícího.
	 */
	public function approve( int $vendor_id, array $context = [] ): Status {
		$this->require_cap( Capabilities::APPROVE_VENDOR );
		return $this->transition( $vendor_id, Status::APPROVED_AWAITING_KYC, $context );
	}

	public function activate( int $vendor_id, array $context = [] ): Status {
		// Activate je obvykle webhook-driven (Stripe account.updated), capability check skip.
		return $this->transition( $vendor_id, Status::ACTIVE, $context );
	}

	public function suspend( int $vendor_id, array $context = [] ): Status {
		$this->require_cap( Capabilities::MANAGE_VENDORS );
		return $this->transition( $vendor_id, Status::SUSPENDED, $context );
	}

	public function reactivate( int $vendor_id, array $context = [] ): Status {
		$this->require_cap( Capabilities::MANAGE_VENDORS );
		return $this->transition( $vendor_id, Status::ACTIVE, $context );
	}

	public function terminate( int $vendor_id, array $context = [] ): Status {
		$this->require_cap( Capabilities::MANAGE_VENDORS );
		return $this->transition( $vendor_id, Status::TERMINATED, $context );
	}

	public function reject( int $vendor_id, array $context = [] ): Status {
		$this->require_cap( Capabilities::APPROVE_VENDOR );
		return $this->transition( $vendor_id, Status::REJECTED, $context );
	}

	private function require_cap( string $cap ): void {
		if ( ! current_user_can( $cap ) ) {
			throw new \InvalidArgumentException( sprintf( 'Chybí oprávnění %s.', $cap ) );
		}
	}
}
