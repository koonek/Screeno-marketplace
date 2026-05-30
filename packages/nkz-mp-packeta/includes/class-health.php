<?php
/**
 * Health – kontroly pro NKZ Marketplace Dashboard (filter
 * `nkzmp/v1/admin/health_checks`).
 *
 * Hlídá Packeta konfiguraci: vyplněné API klíče, sender label, a hlavně
 * poslední chybu z Packeta API (typicky 'account not activated', špatné
 * API heslo apod.). Drží to v option `nkzmp_packeta_last_error`.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class Health {

	private const ERROR_OPTION = 'nkzmp_packeta_last_error';

	private static ?Health $instance = null;
	public static function instance(): Health { return self::$instance ??= new self(); }

	public function init(): void {
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'checks' ] );
	}

	/** Volá se z LabelService při chybě z Packeta API. */
	public static function record_error( string $message ): void {
		update_option( self::ERROR_OPTION, [
			'message' => $message,
			'time'    => time(),
		], false );
	}

	/** Volá se při úspěšném createPacket/cancelPacket. */
	public static function clear_error(): void {
		delete_option( self::ERROR_OPTION );
	}

	/**
	 * @param array<int,array{label:string,state:string,detail:string}> $rows
	 * @return array<int,array{label:string,state:string,detail:string}>
	 */
	public function checks( array $rows ): array {
		$s = Settings::get();

		// 1) API heslo (pro štítky).
		$rows[] = [
			'label'  => __( 'Packeta API heslo (štítky)', 'nkz-mp-packeta' ),
			'state'  => ! empty( $s['api_password'] ) ? 'ok' : 'warn',
			'detail' => ! empty( $s['api_password'] ) ? '' : __( 'nevyplněno → nelze generovat štítky', 'nkz-mp-packeta' ),
		];

		// 2) Widget API klíč (pro výběr výdejny v checkoutu).
		$rows[] = [
			'label'  => __( 'Packeta API klíč (widget)', 'nkz-mp-packeta' ),
			'state'  => ! empty( $s['api_key'] ) ? 'ok' : 'warn',
			'detail' => ! empty( $s['api_key'] ) ? '' : __( 'nevyplněno → bez widgetu výdejny', 'nkz-mp-packeta' ),
		];

		// 3) Výchozí odesílatel (eshop label).
		$rows[] = [
			'label'  => __( 'Packeta odesílatel (eshop label)', 'nkz-mp-packeta' ),
			'state'  => ! empty( $s['sender_label'] ) ? 'ok' : 'warn',
			'detail' => ! empty( $s['sender_label'] ) ? '' : __( 'nevyplněno → fallback nelze použít', 'nkz-mp-packeta' ),
		];

		// 4) Poslední chyba z API (pokud nějaká byla).
		$err = get_option( self::ERROR_OPTION, [] );
		if ( is_array( $err ) && ! empty( $err['message'] ) ) {
			$when = ! empty( $err['time'] ) ? human_time_diff( (int) $err['time'], time() ) : '';
			$rows[] = [
				'label'  => __( 'Packeta API – poslední chyba', 'nkz-mp-packeta' ),
				'state'  => 'fail',
				'detail' => trim(
					( $when !== '' ? sprintf( __( 'před %s: ', 'nkz-mp-packeta' ), $when ) : '' )
					. (string) $err['message']
				),
			];
		}

		return $rows;
	}
}
