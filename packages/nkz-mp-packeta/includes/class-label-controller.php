<?php
/**
 * LabelController – admin-post endpoint pro vytvoření štítku + stažení PDF.
 *
 * Oprávnění: admin (manage_woocommerce) smí pro libovolného prodejce;
 * přihlášený prodejce smí jen pro svoje vendor_id (přes VendorContext,
 * pokud je dashboard modul aktivní).
 *
 * @package NKZMP\Packeta
 */

namespace NKZMP\Packeta;

defined( 'ABSPATH' ) || exit;

final class LabelController {

	public const ACTION        = 'nkzmp_packeta_label';
	public const NONCE         = 'nkzmp_packeta_label';
	public const CANCEL_ACTION = 'nkzmp_packeta_cancel';
	public const CANCEL_NONCE  = 'nkzmp_packeta_cancel';

	private static ?LabelController $instance = null;

	public static function instance(): LabelController {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, [ $this, 'handle' ] );
		add_action( 'admin_post_' . self::CANCEL_ACTION, [ $this, 'handle_cancel' ] );
	}

	/** URL pro tlačítko „štítek" (order + vendor + nonce). */
	public static function label_url( int $order_id, int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&order_id=' . $order_id . '&vendor_id=' . $vendor_id ),
			self::NONCE
		);
	}

	/** URL pro tlačítko „zrušit zásilku". */
	public static function cancel_url( int $order_id, int $vendor_id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::CANCEL_ACTION . '&order_id=' . $order_id . '&vendor_id=' . $vendor_id ),
			self::CANCEL_NONCE
		);
	}

	public function handle(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Nepřihlášený uživatel.', 'nkz-mp-packeta' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE );

		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0;

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || $vendor_id <= 0 ) {
			wp_die( esc_html__( 'Neplatná objednávka nebo prodejce.', 'nkz-mp-packeta' ), '', [ 'response' => 400 ] );
		}

		if ( ! $this->can_access( $vendor_id ) ) {
			wp_die( esc_html__( 'K této objednávce nemáš oprávnění.', 'nkz-mp-packeta' ), '', [ 'response' => 403 ] );
		}

		$packet = LabelService::instance()->create_for_vendor( $order, $vendor_id );
		if ( is_wp_error( $packet ) ) {
			$this->fail( $packet->get_error_message() );
		}

		$client = new ApiClient( Settings::api_password() );
		$pdf    = $client->label_pdf( (string) $packet['id'] );
		if ( is_wp_error( $pdf ) ) {
			$this->fail( $pdf->get_error_message() );
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="packeta-' . $packet['barcode'] . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function handle_cancel(): void {
		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Nepřihlášený uživatel.', 'nkz-mp-packeta' ), '', [ 'response' => 403 ] );
		}
		check_admin_referer( self::CANCEL_NONCE );

		$order_id  = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
		$vendor_id = isset( $_GET['vendor_id'] ) ? absint( $_GET['vendor_id'] ) : 0;

		$order = $order_id ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order || $vendor_id <= 0 ) {
			wp_die( esc_html__( 'Neplatná objednávka nebo prodejce.', 'nkz-mp-packeta' ), '', [ 'response' => 400 ] );
		}
		if ( ! $this->can_access( $vendor_id ) ) {
			wp_die( esc_html__( 'K této objednávce nemáš oprávnění.', 'nkz-mp-packeta' ), '', [ 'response' => 403 ] );
		}

		$res = LabelService::instance()->cancel_for_vendor( $order, $vendor_id );
		if ( is_wp_error( $res ) ) {
			$this->fail( $res->get_error_message() );
		}
		$this->succeed( __( 'Zásilka byla zrušena.', 'nkz-mp-packeta' ) );
	}

	private function can_access( int $vendor_id ): bool {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( class_exists( \NKZMP\Dashboard\VendorContext::class ) ) {
			return \NKZMP\Dashboard\VendorContext::current_vendor_id() === $vendor_id;
		}
		return false;
	}

	private function fail( string $msg ): void {
		$back = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( [ 'nkzmp_packeta_err' => rawurlencode( $msg ) ], $back ) );
		exit;
	}

	private function succeed( string $msg ): void {
		$back = wp_get_referer() ?: home_url( '/' );
		wp_safe_redirect( add_query_arg( [ 'nkzmp_packeta_msg' => rawurlencode( $msg ) ], $back ) );
		exit;
	}
}
