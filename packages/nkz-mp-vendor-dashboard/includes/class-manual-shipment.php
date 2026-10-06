<?php
/**
 * ManualShipment – ruční „Odesláno" pro zásilky mimo Zásilkovnu.
 *
 * Ochranná lhůta na výplatu prodejci se spouští podáním zásilky, které
 * zjišťujeme od Zásilkovny. Zásilka poslaná jinak – nadrozměr s dopravou
 * dohodou, nebo objednávka bez výdejního místa Zásilkovny – by výplatu
 * nikdy nespustila a prodejce by peníze nedostal, dokud by je admin ručně
 * neuvolnil. Tady prodejce sám potvrdí, že zboží odeslal (případně
 * s poznámkou typu „Česká pošta, podací číslo …").
 *
 * Potvrzení stojí na důvěře – nikdo ho zvenku neověří. Proto se ukazuje
 * jen tam, kde Zásilkovna opravdu nejde, a zapisuje se do objednávky,
 * aby bylo dohledatelné.
 *
 * @package NKZMP\Dashboard
 */

namespace NKZMP\Dashboard;

defined( 'ABSPATH' ) || exit;

final class ManualShipment {

	public const META = '_nkzmp_manual_shipments'; // [ vendor_id => [ at, note ] ]

	private static ?ManualShipment $instance = null;

	public static function instance(): ManualShipment {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_nkzmp_vd_mark_shipped', [ $this, 'handle' ] );
	}

	/** Fyzické položky prodejce v objednávce. @return \WC_Order_Item_Product[] */
	private static function vendor_physical_items( \WC_Order $order, int $vendor_id ): array {
		$out = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$pid = (int) $item->get_product_id();
			$vid = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
			if ( $vid <= 0 ) {
				$vid = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
			}
			if ( $vid !== $vendor_id ) {
				continue;
			}
			$product = $item->get_product();
			if ( $product && ! $product->needs_shipping() ) {
				continue;
			}
			$out[ (int) $item_id ] = $item;
		}
		return $out;
	}

	/** Jsou všechny fyzické položky prodejce nadrozměrné? */
	public static function all_oversized( \WC_Order $order, int $vendor_id ): bool {
		$items = self::vendor_physical_items( $order, $vendor_id );
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			$pid = (int) $item->get_product_id();
			if ( get_post_meta( $pid, '_nkzmp_oversized', true ) !== 'yes' ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Musí prodejce odeslání potvrdit ručně (Zásilkovna nejde)?
	 */
	public static function needs_manual( \WC_Order $order, int $vendor_id ): bool {
		if ( ! self::vendor_physical_items( $order, $vendor_id ) ) {
			return false; // nic k odeslání
		}
		if ( self::all_oversized( $order, $vendor_id ) ) {
			return true;
		}
		// Zákazník nevybral výdejní místo Zásilkovny → jiný způsob dopravy.
		return (string) $order->get_meta( '_nkzmp_packeta_point_id' ) === '';
	}

	public static function record( \WC_Order $order, int $vendor_id ): ?array {
		$all = $order->get_meta( self::META );
		return ( is_array( $all ) && isset( $all[ $vendor_id ] ) ) ? (array) $all[ $vendor_id ] : null;
	}

	public static function url(): string {
		return admin_url( 'admin-post.php' );
	}

	public function handle(): void {
		$vendor_id = VendorContext::current_vendor_id();
		$order_id  = absint( $_POST['order_id'] ?? 0 );
		check_admin_referer( 'nkzmp_vd_mark_shipped_' . $order_id );

		$back  = wc_get_account_endpoint_url( 'vendor-orders' );
		$order = wc_get_order( $order_id );
		if ( $vendor_id <= 0 || ! $order instanceof \WC_Order || ! self::needs_manual( $order, $vendor_id ) ) {
			wp_safe_redirect( $back );
			exit;
		}
		if ( self::record( $order, $vendor_id ) ) {
			wp_safe_redirect( $back ); // už potvrzeno
			exit;
		}

		$note   = sanitize_text_field( wp_unslash( (string) ( $_POST['shipment_note'] ?? '' ) ) );
		$record = [ 'at' => time(), 'note' => $note, 'manual' => true ];

		$all               = $order->get_meta( self::META );
		$all               = is_array( $all ) ? $all : [];
		$all[ $vendor_id ] = $record;
		$order->update_meta_data( self::META, $all );
		$order->add_order_note( sprintf(
			/* translators: 1: prodejce, 2: poznámka */
			__( 'Prodejce „%1$s" potvrdil odeslání zásilky mimo Zásilkovnu.%2$s', 'nkz-mp-vendor-dashboard' ),
			get_the_title( $vendor_id ) ?: ( '#' . $vendor_id ),
			$note !== '' ? ' ' . sprintf( __( 'Poznámka: %s', 'nkz-mp-vendor-dashboard' ), $note ) : ''
		) );
		$order->save();

		/**
		 * Zásilka odeslaná mimo Zásilkovnu. Escrow na to spouští ochrannou
		 * lhůtu stejně jako na podání u Zásilkovny.
		 */
		do_action( 'nkzmp/v1/shipment/dispatched', $order, $vendor_id, $record );

		// Zákazník u Zásilkovny dostane e-mail s trackingem. U zásilky
		// odeslané jinak by se jinak nedozvěděl nic.
		try {
			self::notify_customer( $order, $vendor_id, $note );
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] manual shipment e-mail failed: ' . $e->getMessage() );
		}

		wp_safe_redirect( add_query_arg( 'nkzmp_msg', 'shipped', $back ) );
		exit;
	}

	private static function notify_customer( \WC_Order $order, int $vendor_id, string $note ): void {
		$to = (string) $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return;
		}
		$vendor_name = get_the_title( $vendor_id ) ?: ( '#' . $vendor_id );
		$vars        = [
			'name'         => $order->get_billing_first_name() ?: __( 'zákazníku', 'nkz-mp-vendor-dashboard' ),
			'vendor_name'  => $vendor_name,
			'order_number' => (string) $order->get_order_number(),
			'note'         => $note !== ''
				/* translators: %s: poznámka prodejce */
				? sprintf( __( 'Poznámka od prodejce: %s', 'nkz-mp-vendor-dashboard' ), $note )
				: '',
			'site_name'    => (string) get_bloginfo( 'name' ),
		];

		$subject = $body = '';
		if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			$subject = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_manual_shipment_subject' ), $vars );
			$body    = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( 'email_manual_shipment_body' ), $vars );
		}
		if ( $subject === '' || $body === '' ) {
			$subject = sprintf( __( 'Tvoje zásilka od %s je na cestě', 'nkz-mp-vendor-dashboard' ), $vendor_name );
			$body    = sprintf( "Ahoj %s,\n\n%s právě odeslal(a) tvoji zásilku z objednávky #%s.\n\n%s\n\n%s", $vars['name'], $vendor_name, $vars['order_number'], $vars['note'], $vars['site_name'] );
		}
		// Prázdná poznámka by v e-mailu nechala dvojitý odstavec.
		$body = (string) preg_replace( "/\n{3,}/", "\n\n", $body );

		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			return;
		}
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}
}
