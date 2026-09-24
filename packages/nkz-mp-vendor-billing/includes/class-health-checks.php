<?php
/**
 * HealthChecks – billing varování do core Status/Dashboard health listu.
 *
 * @package NKZMP\Billing
 */

namespace NKZMP\Billing;

defined( 'ABSPATH' ) || exit;

final class HealthChecks {

	private static ?HealthChecks $instance = null;

	public static function instance(): HealthChecks {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'add' ] );
	}

	public function add( array $rows ): array {
		if ( ! Settings::is_enabled() ) {
			return $rows;
		}
		$s = Settings::get();

		// Webhook secret chybí → renewaly/failures se neověřují a (bez webhooku
		// vůbec) se neprojeví.
		if ( empty( $s['webhook_secret'] ) ) {
			$rows[] = [
				'label'  => __( 'Billing webhook', 'nkz-mp-vendor-billing' ),
				'state'  => 'fail',
				'detail' => __( 'chybí signing secret – Stripe webhook je odmítán (503), renewaly se vůbec neprojeví', 'nkz-mp-vendor-billing' ),
			];
		}

		// Stripe klíč (z adapteru).
		$has_key = class_exists( \NKVSVS\Plugin::class ) && \NKVSVS\Plugin::secret_key() !== '';
		if ( ! $has_key ) {
			$rows[] = [
				'label'  => __( 'Billing Stripe klíč', 'nkz-mp-vendor-billing' ),
				'state'  => 'fail',
				'detail' => __( 'Stripe adapter nemá secret key – předplatné nepojede', 'nkz-mp-vendor-billing' ),
			];
		}

		// Grace cron naplánovaný.
		if ( ! wp_next_scheduled( GraceCron::EVENT ) ) {
			$rows[] = [
				'label'  => __( 'Billing grace cron', 'nkz-mp-vendor-billing' ),
				'state'  => 'warn',
				'detail' => __( 'neplánovaný', 'nkz-mp-vendor-billing' ),
			];
		}

		return $rows;
	}
}
