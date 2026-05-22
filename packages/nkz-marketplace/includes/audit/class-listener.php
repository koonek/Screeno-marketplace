<?php
/**
 * Audit Listener – konvertuje doménové hooky na audit záznamy.
 *
 * Posloucháme:
 *  - `nkzmp/v1/payout/transition` – každý přechod stavu výplaty
 *  - `nkzmp/v1/ledger/entry_recorded` – jen MANUAL_ADJUSTMENT (auditovat každý
 *    automatický zápis by tabulku rychle zahltil)
 *  - `nkzmp/v1/vendor/status_changed` – approve / suspend / reject / terminate
 *  - `set_user_role` – pro nkzmp_vendor přiřazení / odebrání role
 *
 * Vlastní (manuální) admin akce se logují přímo voláním `Recorder::record()`
 * v daném action handleru.
 *
 * @package NKZMP
 */

namespace NKZMP\Audit;

use NKZMP\Ledger\Entry;
use NKZMP\Ledger\EntryType;
use NKZMP\Payout\State as PayoutState;
use NKZMP\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Listener {

	private static ?Listener $instance = null;

	public static function instance(): Listener {
		return self::$instance ??= new self();
	}

	private Recorder $recorder;

	private function __construct() {
		$this->recorder = new Recorder();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/payout/transition', [ $this, 'on_payout_transition' ], 10, 4 );
		add_action( 'nkzmp/v1/ledger/entry_recorded', [ $this, 'on_ledger_entry' ], 10, 1 );
		add_action( 'nkzmp/v1/vendor/status_changed', [ $this, 'on_vendor_status' ], 10, 3 );
		add_action( 'set_user_role', [ $this, 'on_role_changed' ], 10, 3 );
	}

	public function on_payout_transition( int $payout_id, PayoutState $from, PayoutState $to, array $context = [] ): void {
		$this->recorder->record(
			action:      'payout.transition',
			entity_type: 'payout',
			entity_id:   $payout_id,
			summary:     sprintf( 'Payout #%d: %s → %s', $payout_id, $from->value, $to->value ),
			payload:     [
				'from'    => $from->value,
				'to'      => $to->value,
				'context' => $context,
			],
		);
	}

	public function on_ledger_entry( Entry $entry ): void {
		if ( $entry->type !== EntryType::MANUAL_ADJUSTMENT ) {
			return;
		}
		$this->recorder->record(
			action:      'ledger.manual_adjustment',
			entity_type: 'ledger',
			entity_id:   (int) $entry->id,
			summary:     sprintf( 'Manual adjustment vendor #%d: %d %s', $entry->vendor_id, $entry->amount_minor, $entry->currency ),
			payload:     $entry->to_array(),
		);
	}

	public function on_vendor_status( int $vendor_id, string $from, string $to ): void {
		$this->recorder->record(
			action:      'vendor.status_changed',
			entity_type: 'vendor',
			entity_id:   $vendor_id,
			summary:     sprintf( 'Vendor #%d: %s → %s', $vendor_id, $from, $to ),
			payload:     [ 'from' => $from, 'to' => $to ],
		);
	}

	public function on_role_changed( int $user_id, string $new_role, array $old_roles ): void {
		$vendor_role = Capabilities::ROLE_VENDOR;
		$was_vendor  = in_array( $vendor_role, $old_roles, true );
		$is_vendor   = $new_role === $vendor_role;
		if ( $was_vendor === $is_vendor ) {
			return;
		}
		$this->recorder->record(
			action:      $is_vendor ? 'role.vendor_granted' : 'role.vendor_revoked',
			entity_type: 'user',
			entity_id:   $user_id,
			summary:     sprintf( 'User #%d: %s vendor role', $user_id, $is_vendor ? 'granted' : 'revoked' ),
			payload:     [ 'new_role' => $new_role, 'old_roles' => $old_roles ],
		);
	}
}
