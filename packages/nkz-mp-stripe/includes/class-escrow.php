<?php
/**
 * Escrow – zádržné plateb.
 *
 * Model: při platbě se transfer NEVYTVOŘÍ (peníze drží platforma). Až když
 * prodejce podá zásilku (Zásilkovna → action `nkzmp/v1/packeta/packet_created`),
 * naplánuje se uvolnění výplaty za `escrow_hold_days` dní (ochranná lhůta
 * pro reklamace). Po uplynutí lhůty cron uvolní transfer JEN pro tohoto
 * prodejce (multi-vendor objednávka = každý prodejce zvlášť).
 *
 * Admin může uvolnit kdykoli ručně (order meta box).
 *
 * Aktivní jen když Settings transfer_hook = 'escrow'.
 *
 * @package NKVSVS
 */

namespace NKVSVS;

defined( 'ABSPATH' ) || exit;

final class Escrow {

	public const RELEASE_HOOK = 'nkv_svs_escrow_release';
	public const META         = '_nkv_escrow_release'; // [ vendor_id => ['at'=>ts,'released'=>bool] ]

	private static ?Escrow $instance = null;

	public static function instance(): Escrow {
		return self::$instance ??= new self();
	}

	public static function is_active(): bool {
		return 'escrow' === ( Plugin::settings()['transfer_hook'] ?? '' );
	}

	public function init(): void {
		// Release cron musí být registrovaný vždy (aby doběhly naplánované
		// eventy, i kdyby admin escrow později přepnul). Scheduling jen v escrow.
		add_action( self::RELEASE_HOOK, [ $this, 'release' ], 10, 2 );

		if ( ! self::is_active() ) {
			return;
		}
		add_action( 'nkzmp/v1/packeta/packet_created', [ $this, 'on_packet_created' ], 10, 3 );
	}

	/**
	 * Prodejce podal zásilku → naplánuj uvolnění výplaty po ochranné lhůtě.
	 *
	 * @param \WC_Order $order
	 * @param int       $vendor_id
	 * @param array     $record
	 */
	public function on_packet_created( $order, $vendor_id, $record ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$vendor_id = (int) $vendor_id;
		if ( $vendor_id <= 0 ) {
			return;
		}

		$sched = $order->get_meta( self::META );
		$sched = is_array( $sched ) ? $sched : [];
		if ( ! empty( $sched[ $vendor_id ]['released'] ) || ! empty( $sched[ $vendor_id ]['at'] ) ) {
			return; // už naplánováno / uvolněno
		}

		$days = max( 0, (int) ( Plugin::settings()['escrow_hold_days'] ?? 3 ) );
		$when = time() + $days * DAY_IN_SECONDS;

		$sched[ $vendor_id ] = [ 'at' => $when, 'released' => false ];
		$order->update_meta_data( self::META, $sched );
		$order->add_order_note( sprintf(
			/* translators: 1: vendor id, 2: date, 3: days */
			__( 'Escrow: výplata prodejce #%1$d se uvolní %2$s (ochranná lhůta %3$d dní po podání zásilky).', 'nkz-woo-stripe-vendor-split' ),
			$vendor_id,
			wp_date( 'j. n. Y', $when ),
			$days
		) );
		$order->save();

		if ( ! wp_next_scheduled( self::RELEASE_HOOK, [ $order->get_id(), $vendor_id ] ) ) {
			wp_schedule_single_event( $when, self::RELEASE_HOOK, [ $order->get_id(), $vendor_id ] );
		}

		do_action( 'nkv_svs_escrow_scheduled', $order, $vendor_id, $when );
	}

	/**
	 * Uvolní výplatu pro jednoho prodejce (cron nebo ruční admin akce).
	 *
	 * @param int $order_id
	 * @param int $vendor_id
	 */
	public function release( $order_id, $vendor_id ): void {
		$order_id  = (int) $order_id;
		$vendor_id = (int) $vendor_id;
		$order     = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Bezpečnostní pojistky: zrušené/refundované objednávky NEUVOLŇUJEME.
		if ( $order->has_status( [ 'cancelled', 'refunded', 'failed' ] ) ) {
			$order->add_order_note( sprintf(
				__( 'Escrow: uvolnění výplaty prodejce #%d přeskočeno (objednávka %s).', 'nkz-woo-stripe-vendor-split' ),
				$vendor_id,
				$order->get_status()
			) );
			return;
		}

		Transfer_Service::instance()->maybe_create_transfers( $order_id, false, $vendor_id );

		$sched = $order->get_meta( self::META );
		$sched = is_array( $sched ) ? $sched : [];
		$sched[ $vendor_id ]              = is_array( $sched[ $vendor_id ] ?? null ) ? $sched[ $vendor_id ] : [];
		$sched[ $vendor_id ]['released']  = true;
		$sched[ $vendor_id ]['released_at'] = time();
		$order->update_meta_data( self::META, $sched );
		$order->save();

		do_action( 'nkv_svs_escrow_released', $order, $vendor_id );
	}

	/** Ruční uvolnění adminem (bez čekání na lhůtu). */
	public static function release_now( int $order_id, int $vendor_id ): void {
		// zruš naplánovaný cron event (uvolňujeme hned)
		$ts = wp_next_scheduled( self::RELEASE_HOOK, [ $order_id, $vendor_id ] );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::RELEASE_HOOK, [ $order_id, $vendor_id ] );
		}
		self::instance()->release( $order_id, $vendor_id );
	}

	/**
	 * Stav zádržného pro objednávku (pro admin meta box).
	 *
	 * @return array<int,array{at:int,released:bool}>
	 */
	public static function schedule_for( \WC_Order $order ): array {
		$sched = $order->get_meta( self::META );
		return is_array( $sched ) ? $sched : [];
	}
}
