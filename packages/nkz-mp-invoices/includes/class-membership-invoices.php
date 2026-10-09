<?php
/**
 * MembershipInvoices – faktury Art of život prodejcům za členství.
 *
 * Členství se platí přes Stripe Billing. Stripe sice umí poslat vlastní
 * fakturu, ale ta má jinou podobu a jiné číslování než ostatní doklady
 * Art of život. Tady vystavíme doklad sami – stejná šablona, stejná
 * číselná řada jako doklady za dopravu a servisní poplatek, takže má
 * Art of život všechny své faktury jednotně.
 *
 * Pozor: ve Stripe je pak potřeba VYPNOUT rozesílání jeho faktur, jinak
 * prodejce dostane za jednu platbu dva doklady s různými čísly.
 *
 * Spouští se webhookem invoice.paid (modul vendor-billing). Jeden Stripe
 * invoice = jeden náš doklad; opakovaný webhook nic nezdvojí.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class MembershipInvoices {

	/** Doklady na prodejci: [ stripe_invoice_id => doc ]. */
	public const META = '_nkzmp_membership_invoices';

	private static ?MembershipInvoices $instance = null;

	public static function instance(): MembershipInvoices {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/billing/invoice_paid', [ $this, 'on_paid' ], 10, 2 );
		add_action( 'nkzmp/v1/billing/account_after', [ $this, 'vendor_list' ] );
		add_action( 'admin_post_nkzmp_membership_pdf', [ $this, 'download' ] );
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
	}

	public static function enabled(): bool {
		return Settings::enabled() && Settings::get()['membership'] === 'yes';
	}

	/** @return array<string,array> */
	public static function all( int $vendor_id ): array {
		$d = get_post_meta( $vendor_id, self::META, true );
		return is_array( $d ) ? $d : [];
	}

	/**
	 * @param int   $vendor_id
	 * @param array $invoice Stripe invoice objekt
	 */
	public function on_paid( $vendor_id, $invoice ): void {
		$vendor_id = (int) $vendor_id;
		if ( ! self::enabled() || $vendor_id <= 0 || ! is_array( $invoice ) ) {
			return;
		}
		$doc = self::issue( $vendor_id, $invoice );
		if ( $doc ) {
			self::mail( $vendor_id, $doc );
		}
	}

	/**
	 * Vystaví doklad k zaplacené Stripe faktuře (jednou).
	 *
	 * @return array|null nový doklad, nebo null (nic k vystavení / už vystaveno)
	 */
	public static function issue( int $vendor_id, array $invoice ): ?array {
		$stripe_id = (string) ( $invoice['id'] ?? '' );
		$paid      = (int) ( $invoice['amount_paid'] ?? 0 );
		if ( $stripe_id === '' || $paid <= 0 ) {
			return null; // zdarma / zkušební období – není co fakturovat
		}
		$all = self::all( $vendor_id );
		if ( isset( $all[ $stripe_id ] ) ) {
			return null;
		}

		$currency  = strtoupper( (string) ( $invoice['currency'] ?? 'czk' ) );
		$gross     = $paid / 100;
		$paid_at   = (int) ( $invoice['status_transitions']['paid_at'] ?? 0 ) ?: time();
		// Období bereme z řádku faktury – u předplatného jsou period_start/end
		// na samotné faktuře posunuté o jedno období zpět (zvyk Stripe).
		$line0     = (array) ( $invoice['lines']['data'][0] ?? [] );
		$p_start   = (int) ( $line0['period']['start'] ?? $invoice['period_start'] ?? 0 );
		$p_end     = (int) ( $line0['period']['end'] ?? $invoice['period_end'] ?? 0 );
		$period    = ( $p_start && $p_end )
			? sprintf( '%s – %s', wp_date( 'j. n. Y', $p_start ), wp_date( 'j. n. Y', $p_end ) )
			: '';

		$rate = Settings::platform_is_vat_payer() ? (int) Settings::get()['vat_rate'] : null;
		$name = (string) apply_filters(
			'nkzmp/v1/invoices/membership_line',
			$period !== ''
				/* translators: %s: období */
				? sprintf( __( 'Členství prodejce na platformě Art of život, období %s', 'nkz-mp-invoices' ), $period )
				: __( 'Členství prodejce na platformě Art of život', 'nkz-mp-invoices' ),
			$vendor_id,
			$invoice
		);
		$lines = [ Documents::line( $name, 1, $gross, $rate ) ];

		$vat = [];
		if ( $rate !== null ) {
			$vat[] = [ 'rate' => $rate, 'base' => $lines[0]['base'], 'vat' => $lines[0]['vat'] ];
		}

		$v     = VendorBilling::issuer( $vendor_id );
		$now   = time();
		$doc   = [
			'type'         => 'invoice',
			'kind'         => 'membership',
			'number'       => Numbering::next( 'platform', 'invoice', $now ),
			'issuer_key'   => 'platform',
			'issuer'       => Documents::platform_issuer(),
			'on_behalf'    => false,
			// Odběratelem je prodejce – firma, takže i IČO a DIČ.
			'buyer'        => [
				'name'    => $v['name'],
				'person'  => '',
				'street'  => $v['street'],
				'city'    => $v['city'],
				'zip'     => $v['zip'],
				'country' => $v['country'],
				'ico'     => $v['ico'],
				'dic'     => $v['dic'],
				'email'   => (string) get_post_meta( $vendor_id, '_nkv_vendor_email', true ),
			],
			'lines'        => $lines,
			'total'        => round( $gross, 2 ),
			'vat_summary'  => $vat,
			'issued_at'    => $now,
			'duzp'         => $paid_at,
			'currency'     => $currency,
			'order_number' => '',
			'subject'      => $period,
			'payment'      => [ 'method' => __( 'kartou (Stripe)', 'nkz-mp-invoices' ), 'paid' => $gross, 'voucher' => 0, 'code' => '' ],
			'related'      => '',
			'stripe_id'    => $stripe_id,
		];

		$all[ $stripe_id ] = $doc;
		update_post_meta( $vendor_id, self::META, $all );
		return $doc;
	}

	private static function mail( int $vendor_id, array $doc ): void {
		$to = (string) get_post_meta( $vendor_id, '_nkv_vendor_email', true );
		if ( ! is_email( $to ) ) {
			return;
		}
		$file = Pdf::to_file( [ $doc ], 'faktura-clenstvi-' . $doc['number'] );
		if ( $file === '' ) {
			return;
		}
		$site    = (string) get_bloginfo( 'name' );
		$subject = sprintf( /* translators: %s: číslo dokladu */ __( 'Faktura za členství %s', 'nkz-mp-invoices' ), $doc['number'] );
		$body    = sprintf(
			/* translators: 1: období, 2: web, 3: odkaz */
			__( "Ahoj,\n\nděkujeme za platbu členství%1\$s. Fakturu posíláme v příloze, najdeš ji také ve svém účtu v sekci Předplatné:\n%3\$s\n\nTým %2\$s", 'nkz-mp-invoices' ),
			$doc['subject'] !== '' ? ' (' . $doc['subject'] . ')' : '',
			$site,
			function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'vendor-billing' ) : home_url( '/' )
		);
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $file ] );
	}

	private static function download_url( int $vendor_id, string $stripe_id ): string {
		return wp_nonce_url(
			add_query_arg(
				[ 'action' => 'nkzmp_membership_pdf', 'vendor_id' => $vendor_id, 'doc' => $stripe_id ],
				admin_url( 'admin-post.php' )
			),
			'nkzmp_membership_pdf_' . $vendor_id . '_' . $stripe_id
		);
	}

	public function download(): void {
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		$stripe_id = sanitize_text_field( wp_unslash( (string) ( $_GET['doc'] ?? '' ) ) );
		check_admin_referer( 'nkzmp_membership_pdf_' . $vendor_id . '_' . $stripe_id );

		// Stáhnout smí admin, nebo prodejce svoji vlastní fakturu.
		$own = class_exists( \NKZMP\Dashboard\VendorContext::class )
			&& \NKZMP\Dashboard\VendorContext::current_vendor_id() === $vendor_id;
		if ( ! $own && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-invoices' ), '', [ 'response' => 403 ] );
		}
		$all = self::all( $vendor_id );
		if ( ! isset( $all[ $stripe_id ] ) ) {
			wp_die( esc_html__( 'Faktura nenalezena.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		$pdf = Pdf::render( [ $all[ $stripe_id ] ] );
		if ( $pdf === '' ) {
			wp_die( esc_html__( 'PDF se nepodařilo vytvořit.', 'nkz-mp-invoices' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'faktura-' . $all[ $stripe_id ]['number'] ) . '.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}

	/** Seznam faktur v sekci Předplatné u prodejce. */
	public function vendor_list( $vendor_id ): void {
		$docs = self::all( (int) $vendor_id );
		if ( ! $docs ) {
			return;
		}
		uasort( $docs, static fn( $a, $b ) => (int) $b['issued_at'] <=> (int) $a['issued_at'] );
		echo '<h2 style="margin:32px 0 12px;font-size:18px;">' . esc_html__( 'Faktury za členství', 'nkz-mp-invoices' ) . '</h2>';
		echo '<table class="shop_table" style="width:100%;"><thead><tr><th>' . esc_html__( 'Číslo', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Období', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Částka', 'nkz-mp-invoices' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $docs as $sid => $d ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a></td></tr>',
				esc_html( (string) $d['number'] ),
				esc_html( (string) ( $d['subject'] ?: wp_date( 'j. n. Y', (int) $d['issued_at'] ) ) ),
				wp_kses_post( wc_price( (float) $d['total'], [ 'currency' => $d['currency'] ?? '' ] ) ),
				esc_url( self::download_url( (int) $vendor_id, (string) $sid ) ),
				esc_html__( 'Stáhnout PDF', 'nkz-mp-invoices' )
			);
		}
		echo '</tbody></table>';
	}

	public function meta_box(): void {
		foreach ( [ 'nkv_vendor', 'nkzmp_vendor' ] as $pt ) {
			add_meta_box( 'nkzmp-membership-invoices', __( 'Faktury za členství', 'nkz-mp-invoices' ), [ $this, 'render_meta_box' ], $pt, 'side', 'low' );
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		$docs = self::all( (int) $post->ID );
		if ( ! $docs ) {
			echo '<p class="description">' . esc_html__( 'Zatím žádné.', 'nkz-mp-invoices' ) . '</p>';
			return;
		}
		echo '<ul style="margin:0;">';
		foreach ( array_reverse( $docs, true ) as $sid => $d ) {
			printf(
				'<li><a href="%s" target="_blank">%s</a> · %s</li>',
				esc_url( self::download_url( (int) $post->ID, (string) $sid ) ),
				esc_html( (string) $d['number'] ),
				wp_kses_post( wc_price( (float) $d['total'], [ 'currency' => $d['currency'] ?? '' ] ) )
			);
		}
		echo '</ul>';
	}
}
