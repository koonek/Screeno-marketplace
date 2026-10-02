<?php
/**
 * Health – kontroly pro NKZ Marketplace Dashboard (filter
 * `nkzmp/v1/admin/health_checks`).
 *
 * Hlavní účel: zachytit „No such charge" PŘED platbou – tj. že adapter má
 * jiný Stripe účet / režim než gateway, který reálně účtuje kartu.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Health {

	private static ?Health $instance = null;
	public static function instance(): Health { return self::$instance ??= new self(); }

	public function init(): void {
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'checks' ] );
	}

	/**
	 * @param array<int,array{label:string,state:string,detail:string}> $rows
	 * @return array<int,array{label:string,state:string,detail:string}>
	 */
	public function checks( array $rows ): array {
		$settings  = Plugin::settings();
		$mode      = $settings['mode'] ?? 'test';
		$adapter_key = (string) get_option( 'live' === $mode ? 'nkv_svs_secret_live' : 'nkv_svs_secret_test', '' );

		// 1) Má adapter vyplněný secret pro aktuální režim?
		$rows[] = [
			'label'  => __( 'Stripe split – secret key', 'nkz-woo-stripe-vendor-split' ),
			'state'  => $adapter_key !== '' ? 'ok' : 'fail',
			'detail' => $adapter_key !== ''
				? sprintf( __( 'režim %s', 'nkz-woo-stripe-vendor-split' ), $mode )
				: __( 'nevyplněn pro aktuální režim', 'nkz-woo-stripe-vendor-split' ),
		];

		// 2) Soulad s WooCommerce Stripe Gateway (kdo reálně účtuje kartu).
		$gw = get_option( 'woocommerce_stripe_settings', [] );
		if ( is_array( $gw ) && ! empty( $gw ) ) {
			$gw_testmode = ( $gw['testmode'] ?? 'yes' ) === 'yes';
			$gw_mode     = $gw_testmode ? 'test' : 'live';
			$gw_key      = (string) ( $gw_testmode ? ( $gw['test_secret_key'] ?? '' ) : ( $gw['secret_key'] ?? '' ) );

			// Režim sedí?
			$mode_match = ( $gw_mode === $mode );
			$rows[] = [
				'label'  => __( 'Stripe režim (gateway × split)', 'nkz-woo-stripe-vendor-split' ),
				'state'  => $mode_match ? 'ok' : 'fail',
				'detail' => $mode_match
					? sprintf( __( 'oba %s', 'nkz-woo-stripe-vendor-split' ), $mode )
					: sprintf( __( 'NESEDÍ: gateway %1$s, split %2$s', 'nkz-woo-stripe-vendor-split' ), $gw_mode, $mode ),
			];

			// Stejný účet? (porovnáme secret key – musí být stejný Stripe účet,
			// jinak transfer hlásí „No such charge".)
			if ( $adapter_key !== '' && $gw_key !== '' ) {
				$same = hash_equals( $gw_key, $adapter_key );
				$rows[] = [
					'label'  => __( 'Stripe účet (gateway × split)', 'nkz-woo-stripe-vendor-split' ),
					'state'  => $same ? 'ok' : 'fail',
					'detail' => $same
						? __( 'stejný klíč/účet', 'nkz-woo-stripe-vendor-split' )
						: __( 'RŮZNÉ klíče → transfery selžou (No such charge)', 'nkz-woo-stripe-vendor-split' ),
				];
			}
		} else {
			// Gateway nenalezen → charge možná vytváří jiný plugin.
			$rows[] = [
				'label'  => __( 'WooCommerce Stripe Gateway', 'nkz-woo-stripe-vendor-split' ),
				'state'  => 'warn',
				'detail' => __( 'nenalezen – ověř čím se účtuje karta (adapter charge nečte)', 'nkz-woo-stripe-vendor-split' ),
			];
		}

		return $rows;
	}
}
