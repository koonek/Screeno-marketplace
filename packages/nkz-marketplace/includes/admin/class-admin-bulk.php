<?php
/**
 * AdminBulk – hromadné schválení vendorů ve WP list table.
 *
 * Přidá bulk akci „Schválit (NKZ)" do seznamu vendor CPT. Schválí vybrané
 * pending vendory (pending → approved_awaiting_kyc) přes StatusService, což
 * spustí celý approval řetězec (e-mail, WP user, Stripe link).
 *
 * @package NKZMP
 */

namespace NKZMP\Admin;

use NKZMP\Support\Capabilities;
use NKZMP\Vendor\Status;
use NKZMP\Vendor\StatusService;

defined( 'ABSPATH' ) || exit;

final class AdminBulk {

	private const ACTION = 'nkzmp_bulk_approve';

	private static ?AdminBulk $instance = null;

	public static function instance(): AdminBulk {
		return self::$instance ??= new self();
	}

	public function init(): void {
		foreach ( [ 'nkv_vendor', 'nkzmp_vendor' ] as $cpt ) {
			add_filter( 'bulk_actions-edit-' . $cpt, [ $this, 'register' ] );
			add_filter( 'handle_bulk_actions-edit-' . $cpt, [ $this, 'handle' ], 10, 3 );
		}
		add_action( 'admin_notices', [ $this, 'notice' ] );
	}

	public function register( array $actions ): array {
		$actions[ self::ACTION ] = __( 'Schválit (NKZ) → čeká na KYC', 'nkz-marketplace' );
		return $actions;
	}

	public function handle( string $redirect, string $action, array $ids ): string {
		if ( $action !== self::ACTION ) {
			return $redirect;
		}
		if ( ! current_user_can( Capabilities::APPROVE_VENDOR ) && ! current_user_can( Capabilities::MANAGE_VENDORS ) ) {
			return $redirect;
		}
		$service = new StatusService();
		$done    = 0;
		$skipped = 0;
		foreach ( $ids as $id ) {
			$id = (int) $id;
			try {
				$service->transition( $id, Status::APPROVED_AWAITING_KYC, [ 'source' => 'bulk_approve' ] );
				$done++;
			} catch ( \Throwable $e ) {
				$skipped++;
			}
		}
		return add_query_arg(
			[ 'nkzmp_bulk_done' => $done, 'nkzmp_bulk_skipped' => $skipped ],
			$redirect
		);
	}

	public function notice(): void {
		if ( ! isset( $_GET['nkzmp_bulk_done'] ) ) {
			return;
		}
		$done    = (int) $_GET['nkzmp_bulk_done'];
		$skipped = (int) ( $_GET['nkzmp_bulk_skipped'] ?? 0 );
		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: approved count, 2: skipped count */
				__( 'NKZ: schváleno %1$d vendorů, přeskočeno %2$d (neplatný přechod).', 'nkz-marketplace' ),
				$done,
				$skipped
			) )
		);
	}
}
