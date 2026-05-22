<?php
/**
 * Listener — chytá nkzmp/v1/vendor/status_changed a posílá příslušné e-maily.
 *
 * @package NKZMP\Registration
 */

namespace NKZMP\Registration;

defined( 'ABSPATH' ) || exit;

final class Listener {

	private static ?Listener $instance = null;

	public static function instance(): Listener {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/vendor/status_changed', [ $this, 'on_status' ], 10, 4 );
	}

	public function on_status( int $vendor_id, string $from, string $to, array $context = [] ): void {
		switch ( $to ) {
			case 'approved_awaiting_kyc':
				EmailService::send_approved_awaiting_kyc( $vendor_id );
				break;
			case 'active':
				EmailService::send_active( $vendor_id );
				break;
			case 'rejected':
				EmailService::send_rejected( $vendor_id, (string) ( $context['reason'] ?? '' ) );
				break;
		}
	}
}
