<?php
/**
 * Delivery – jak se doklady dostanou k zákazníkovi a adminovi.
 *
 *  - příloha potvrzení objednávky (PDF se všemi doklady),
 *  - odkaz ke stažení u objednávky v Můj účet a na děkovací stránce –
 *    autorizace klíčem objednávky, takže funguje i hostům bez účtu,
 *  - panel v adminu u objednávky,
 *  - e-mail s dobropisem po refundaci.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Delivery {

	private static ?Delivery $instance = null;

	public static function instance(): Delivery {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'woocommerce_email_attachments', [ $this, 'attach' ], 10, 4 );
		add_action( 'template_redirect', [ $this, 'public_download' ] );
		add_action( 'admin_post_nkzmp_invoice_pdf', [ $this, 'admin_download' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'customer_link' ], 15 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'admin_panel' ], 30 );
		add_action( 'nkzmp/v1/invoices/credit_issued', [ $this, 'mail_credit' ], 10, 3 );
	}

	/**
	 * PDF do potvrzení objednávky.
	 *
	 * @param array  $attachments
	 * @param string $email_id
	 * @param mixed  $order
	 * @param mixed  $email
	 */
	public function attach( $attachments, $email_id, $order = null, $email = null ): array {
		$attachments = (array) $attachments;
		if ( ! Settings::enabled() || Settings::get()['attach_email'] !== 'yes' ) {
			return $attachments;
		}
		if ( ! in_array( $email_id, [ 'customer_processing_order', 'customer_completed_order' ], true ) ) {
			return $attachments;
		}
		if ( ! $order instanceof \WC_Order || ! $order->is_paid() ) {
			return $attachments;
		}
		// E-mail může přijít dřív než náš háček na změnu stavu – vystavíme
		// teď (idempotentní). Čteme čerstvou objednávku, ne instanci e-mailu.
		$fresh = wc_get_order( $order->get_id() );
		$docs  = $fresh instanceof \WC_Order ? array_values( array_filter(
			Documents::issue( $fresh ),
			static fn( $d ) => ( $d['type'] ?? '' ) === 'invoice'
		) ) : [];
		if ( ! $docs ) {
			return $attachments;
		}
		$file = Pdf::to_file( $docs, 'doklady-objednavka-' . $order->get_order_number() );
		if ( $file !== '' ) {
			$attachments[] = $file;
		}
		return $attachments;
	}

	/** URL ke stažení s klíčem objednávky (funguje i pro hosty). */
	public static function public_url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'nkzmp_invoice' => $order->get_id(), 'key' => $order->get_order_key() ],
			home_url( '/' )
		);
	}

	public function public_download(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- autorizace klíčem objednávky.
		if ( empty( $_GET['nkzmp_invoice'] ) ) {
			return;
		}
		$order = wc_get_order( absint( $_GET['nkzmp_invoice'] ) );
		$key   = sanitize_text_field( wp_unslash( (string) ( $_GET['key'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! $order instanceof \WC_Order || $key === '' || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			wp_die( esc_html__( 'Doklad nebyl nalezen.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		$this->stream( $order );
	}

	public function admin_download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-invoices' ) );
		}
		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'nkzmp_invoice_pdf_' . $order_id );
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Objednávka nenalezena.', 'nkz-mp-invoices' ) );
		}
		$this->stream( $order );
	}

	private function stream( \WC_Order $order ): void {
		$docs = Documents::all( $order );
		if ( ! $docs ) {
			wp_die( esc_html__( 'K objednávce zatím nebyl vystaven doklad.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		$pdf = Pdf::render( $docs );
		if ( $pdf === '' ) {
			wp_die( esc_html__( 'PDF se nepodařilo vytvořit.', 'nkz-mp-invoices' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="doklady-objednavka-' . sanitize_file_name( (string) $order->get_order_number() ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}

	public function customer_link( $order ): void {
		if ( ! $order instanceof \WC_Order || ! Documents::all( $order ) ) {
			return;
		}
		printf(
			'<p style="margin-top:16px;"><a class="button" href="%s" target="_blank" rel="noopener">%s</a></p>',
			esc_url( self::public_url( $order ) ),
			esc_html__( 'Stáhnout doklady (PDF)', 'nkz-mp-invoices' )
		);
	}

	public function admin_panel( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$docs = Documents::all( $order );
		echo '<div style="clear:both;margin-top:12px;"><p><strong>' . esc_html__( 'Doklady', 'nkz-mp-invoices' ) . ':</strong></p>';
		if ( ! $docs ) {
			echo '<p class="description">' . esc_html( $order->is_paid()
				? __( 'Zatím nevystaveny (vystaví se při přechodu na Zpracovává se / Dokončeno).', 'nkz-mp-invoices' )
				: __( 'Vystaví se po zaplacení.', 'nkz-mp-invoices' ) ) . '</p></div>';
			return;
		}
		echo '<ul style="margin:0 0 8px 16px;list-style:disc;">';
		foreach ( $docs as $d ) {
			printf(
				'<li>%s <code>%s</code> — %s, %s</li>',
				esc_html( $d['type'] === 'credit' ? __( 'Dobropis', 'nkz-mp-invoices' ) : __( 'Doklad', 'nkz-mp-invoices' ) ),
				esc_html( (string) $d['number'] ),
				esc_html( (string) ( $d['issuer']['name'] ?? '' ) ),
				wp_kses_post( wc_price( (float) $d['total'], [ 'currency' => $d['currency'] ?? '' ] ) )
			);
		}
		echo '</ul>';
		printf(
			'<a class="button button-small" href="%s" target="_blank">%s</a></div>',
			esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'nkzmp_invoice_pdf', 'order_id' => $order->get_id() ], admin_url( 'admin-post.php' ) ), 'nkzmp_invoice_pdf_' . $order->get_id() ) ),
			esc_html__( 'Stáhnout PDF', 'nkz-mp-invoices' )
		);
	}

	/**
	 * Dobropis zákazníkovi e-mailem.
	 *
	 * @param \WC_Order $order
	 * @param int       $refund_id
	 * @param string[]  $numbers
	 */
	public function mail_credit( $order, $refund_id, $numbers ): void {
		if ( ! $order instanceof \WC_Order || ! is_email( $order->get_billing_email() ) ) {
			return;
		}
		$docs = array_values( array_filter(
			Documents::all( $order ),
			static fn( $d ) => ( $d['type'] ?? '' ) === 'credit' && in_array( $d['number'], (array) $numbers, true )
		) );
		if ( ! $docs ) {
			return;
		}
		$file = Pdf::to_file( $docs, 'dobropis-objednavka-' . $order->get_order_number() );
		if ( $file === '' ) {
			return;
		}
		$site    = (string) get_bloginfo( 'name' );
		$subject = sprintf( /* translators: 1: číslo objednávky */ __( 'Dobropis k objednávce #%1$s', 'nkz-mp-invoices' ), $order->get_order_number() );
		$body    = sprintf(
			/* translators: 1: číslo objednávky, 2: web */
			__( "Dobrý den,\n\nv příloze posíláme dobropis k objednávce #%1\$s. Peníze vám vracíme stejnou cestou, jakou jste platili.\n\n%2\$s", 'nkz-mp-invoices' ),
			$order->get_order_number(),
			$site
		);
		wp_mail( $order->get_billing_email(), $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $file ] );
	}
}
