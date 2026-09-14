<?php
/**
 * StatusSync – doptávání skutečného stavu zásilek u Zásilkovny.
 *
 * Zásilkovna nám sama nic neposílá (nemá webhook), takže se ptáme my.
 * Cron projde objednávky, které mají vytvořený štítek a ještě nedoběhly,
 * a u každé zásilky zjistí stav přes `packetStatus`. Na přechodech pak
 * vystřelí akce, na které se věší zbytek systému:
 *
 *   nkzmp/v1/packeta/packet_dispatched – prodejce zásilku fyzicky podal
 *   nkzmp/v1/packeta/packet_delivered  – zákazník si ji vyzvedl
 *   nkzmp/v1/packeta/packet_returned   – vrací se prodejci
 *
 * Proč to existuje: doteď se ochranná lhůta na výplatu spouštěla vytvořením
 * štítku. Jenže vytištěná etiketa neznamená odeslaný balík – prodejce mohl
 * dostat peníze, aniž by cokoli podal, a u vrácené zásilky se nedělo nic.
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class StatusSync {

	public const CRON_HOOK = 'nkzmp_packeta_sync_status';

	/** Příznak na objednávce: má smysl se ptát. */
	public const PENDING_META = '_nkzmp_packeta_sync';

	/** Seznam objednávek s vrácenou zásilkou (pro admin upozornění). */
	public const RETURNED_OPTION = 'nkzmp_packeta_returned';

	/** Poslední výsledek synchronizace (pro Health). */
	public const HEALTH_OPTION = 'nkzmp_packeta_sync_health';

	private const LOCK_TRANSIENT = 'nkzmp_packeta_sync_lock';

	/** Kolik objednávek zpracovat v jednom běhu. */
	private const BATCH = 30;

	private static ?StatusSync $instance = null;

	public static function instance(): StatusSync {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( self::CRON_HOOK, [ $this, 'run' ] );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}

		// Nová zásilka → od teď ji hlídáme.
		add_action( 'nkzmp/v1/packeta/packet_created', [ $this, 'mark_pending' ], 5, 3 );

		add_action( 'admin_notices', [ $this, 'returned_notice' ] );
	}

	public static function unschedule(): void {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/**
	 * @param \WC_Order $order
	 * @param int       $vendor_id
	 * @param array     $record
	 */
	public function mark_pending( $order, $vendor_id, $record ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$order->update_meta_data( self::PENDING_META, 'yes' );
		$order->save();
	}

	/* ------------------------------------------------------------------ běh */

	public function run(): void {
		if ( ! Settings::is_configured() ) {
			return;
		}
		if ( get_transient( self::LOCK_TRANSIENT ) ) {
			return;
		}
		set_transient( self::LOCK_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

		$this->backfill();

		$orders = wc_get_orders( [
			'limit'      => self::BATCH,
			'orderby'    => 'date',
			'order'      => 'ASC',
			'status'     => [ 'processing', 'on-hold', 'completed' ],
			'meta_key'   => self::PENDING_META,
			'meta_value' => 'yes',
			'return'     => 'objects',
		] );

		$checked = 0;
		$errors  = 0;
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$res = $this->sync_order( $order );
			$checked += $res['checked'];
			$errors  += $res['errors'];
		}

		update_option( self::HEALTH_OPTION, [
			'time'    => time(),
			'orders'  => is_array( $orders ) ? count( $orders ) : 0,
			'checked' => $checked,
			'errors'  => $errors,
		], false );

		delete_transient( self::LOCK_TRANSIENT );
	}

	/**
	 * Jednorázově označí objednávky, které měly štítek vytvořený ještě před
	 * zavedením sledování. Bez toho by se u nich nikdy nezjistilo doručení
	 * a zůstaly by viset ve „zpracovává se".
	 */
	private function backfill(): void {
		if ( get_option( 'nkzmp_packeta_sync_backfilled' ) ) {
			return;
		}
		$orders = wc_get_orders( [
			'limit'    => 100,
			'status'   => [ 'processing', 'on-hold' ],
			'meta_key' => NKZMP_PACKETA_PACKETS_META,
			'return'   => 'objects',
		] );
		foreach ( $orders as $order ) {
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			if ( $order->get_meta( self::PENDING_META ) === 'yes' ) {
				continue;
			}
			$order->update_meta_data( self::PENDING_META, 'yes' );
			$order->save();
		}
		// Míň než plná dávka = došli jsme na konec, hotovo.
		if ( ! is_array( $orders ) || count( $orders ) < 100 ) {
			update_option( 'nkzmp_packeta_sync_backfilled', time(), false );
		}
	}

	/**
	 * Projde zásilky jedné objednávky.
	 *
	 * @return array{checked:int,errors:int}
	 */
	public function sync_order( \WC_Order $order ): array {
		$all = $order->get_meta( NKZMP_PACKETA_PACKETS_META );
		$all = is_array( $all ) ? $all : [];
		if ( ! $all ) {
			$order->delete_meta_data( self::PENDING_META );
			$order->save();
			return [ 'checked' => 0, 'errors' => 0 ];
		}

		$client  = new ApiClient( Settings::api_password() );
		$checked = 0;
		$errors  = 0;
		$dirty   = false;
		$open    = 0; // kolik zásilek ještě nedoběhlo

		foreach ( $all as $vendor_id => $record ) {
			$vendor_id = (int) $vendor_id;
			$packet_id = (string) ( $record['id'] ?? '' );
			$was       = (string) ( $record['state'] ?? ApiClient::STATE_PENDING );

			if ( $packet_id === '' || self::is_final( $was ) ) {
				continue;
			}

			$status = $client->packet_status( $packet_id );
			++$checked;
			if ( is_wp_error( $status ) ) {
				++$errors;
				++$open; // nevíme → zkusíme příště
				continue;
			}

			$now = (string) $status['state'];
			if ( ! self::is_final( $now ) ) {
				++$open;
			}
			if ( $now === $was ) {
				continue;
			}

			$record['state']      = $now;
			$record['state_text'] = (string) $status['text'];
			$record['state_at']   = $status['at'] ?: time();
			$all[ $vendor_id ]    = $record;
			$dirty                = true;

			$this->on_transition( $order, $vendor_id, $was, $now, $record );
		}

		if ( $dirty ) {
			$order->update_meta_data( NKZMP_PACKETA_PACKETS_META, $all );
		}
		if ( 0 === $open ) {
			$order->delete_meta_data( self::PENDING_META );
			$dirty = true;
		}
		if ( $dirty ) {
			$order->save();
		}

		$this->maybe_complete_order( $order, $all );

		return [ 'checked' => $checked, 'errors' => $errors ];
	}

	/** Stav, u kterého se už nemá cenu ptát. */
	public static function is_final( string $state ): bool {
		return in_array(
			$state,
			[ ApiClient::STATE_DELIVERED, ApiClient::STATE_RETURNED, ApiClient::STATE_CANCELLED ],
			true
		);
	}

	/** Zapíše poznámku a vystřelí akci pro daný přechod. */
	private function on_transition( \WC_Order $order, int $vendor_id, string $from, string $to, array $record ): void {
		$vendor_name = get_the_title( $vendor_id ) ?: ( '#' . $vendor_id );

		$order->add_order_note( sprintf(
			/* translators: 1: název prodejce, 2: stav, 3: kód zásilky */
			__( 'Zásilkovna: zásilka prodejce „%1$s" – %2$s (kód %3$s).', 'nkz-mp-packeta' ),
			$vendor_name,
			ApiClient::state_label( $to ),
			(string) ( $record['barcode'] ?? '' )
		) );

		switch ( $to ) {
			case ApiClient::STATE_DISPATCHED:
				/**
				 * Prodejce zásilku fyzicky podal (první naskenování).
				 * Tady startuje ochranná lhůta na výplatu.
				 */
				do_action( 'nkzmp/v1/packeta/packet_dispatched', $order, $vendor_id, $record );
				break;

			case ApiClient::STATE_DELIVERED:
				// Doručení může přijít dřív, než jsme stihli zachytit podání
				// (cron běží po hodině, výdejna vedle prodejce). Ať lhůta
				// nezůstane nenaplánovaná, pošleme oba signály.
				if ( $from === ApiClient::STATE_PENDING ) {
					do_action( 'nkzmp/v1/packeta/packet_dispatched', $order, $vendor_id, $record );
				}
				do_action( 'nkzmp/v1/packeta/packet_delivered', $order, $vendor_id, $record );
				break;

			case ApiClient::STATE_RETURNED:
				self::flag_returned( $order->get_id() );
				do_action( 'nkzmp/v1/packeta/packet_returned', $order, $vendor_id, $record );
				break;

			case ApiClient::STATE_CANCELLED:
				do_action( 'nkzmp/v1/packeta/packet_cancelled', $order, $vendor_id, $record );
				break;
		}
	}

	/**
	 * Když je doručeno všem prodejcům, objednávka je hotová.
	 *
	 * Tohle je jediné místo, kde se objednávka dokončuje automaticky –
	 * a smí to být právě proto, že vycházíme z reálného doručení, ne
	 * z vytištěného štítku.
	 *
	 * @param array $all Packet záznamy z objednávky.
	 */
	private function maybe_complete_order( \WC_Order $order, array $all ): void {
		if ( ! apply_filters( 'nkzmp/v1/packeta/auto_complete_on_delivery', true, $order ) ) {
			return;
		}
		if ( $order->has_status( [ 'completed', 'refunded', 'cancelled' ] ) ) {
			return;
		}
		if ( ! $all ) {
			return;
		}
		foreach ( $all as $record ) {
			if ( ( $record['state'] ?? '' ) !== ApiClient::STATE_DELIVERED ) {
				return; // někdo ještě nedoručil
			}
		}
		$order->update_status( 'completed', __( 'Zásilkovna: všechny zásilky doručeny → objednávka dokončena.', 'nkz-mp-packeta' ) );
	}

	/* --------------------------------------------------------- vrácené kusy */

	public static function flag_returned( int $order_id ): void {
		$list = get_option( self::RETURNED_OPTION, [] );
		$list = is_array( $list ) ? $list : [];
		if ( ! isset( $list[ $order_id ] ) ) {
			$list[ $order_id ] = time();
			update_option( self::RETURNED_OPTION, $list, false );
		}
	}

	public static function clear_returned( int $order_id ): void {
		$list = get_option( self::RETURNED_OPTION, [] );
		if ( is_array( $list ) && isset( $list[ $order_id ] ) ) {
			unset( $list[ $order_id ] );
			update_option( self::RETURNED_OPTION, $list, false );
		}
	}

	/**
	 * Upozornění v adminu na vrácené zásilky.
	 *
	 * Vrácený balík je jediný případ, kdy peníze reálně nemají jít prodejci –
	 * a zároveň jediný, o kterém by se jinak nikdo nedozvěděl, dokud se
	 * neozve zákazník.
	 */
	public function returned_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$list = get_option( self::RETURNED_OPTION, [] );
		if ( ! is_array( $list ) || ! $list ) {
			return;
		}
		$links = [];
		foreach ( array_slice( array_keys( $list ), 0, 10 ) as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				self::clear_returned( (int) $order_id );
				continue;
			}
			$links[] = sprintf(
				'<a href="%s">#%s</a>',
				esc_url( $order->get_edit_order_url() ),
				esc_html( (string) $order->get_order_number() )
			);
		}
		if ( ! $links ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Zásilkovna: vrácené zásilky.', 'nkz-mp-packeta' ),
			esc_html__( 'U těchto objednávek se balík vrací prodejci — výplata je pozastavená a je potřeba ji vyřešit ručně (vrátit peníze zákazníkovi, nebo výplatu uvolnit):', 'nkz-mp-packeta' ),
			wp_kses( implode( ', ', $links ), [ 'a' => [ 'href' => [] ] ] )
		);
	}
}
