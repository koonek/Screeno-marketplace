<?php
/**
 * VendorDocuments – prodejce má k dispozici doklady vystavené jeho jménem.
 *
 * Při samofakturaci vystavuje doklady platforma za prodejce, prodejce je
 * ale potřebuje do svého účetnictví. Proto:
 *  - u každé objednávky v jeho přehledu odkaz „Doklad (PDF)",
 *  - v „Moje výplaty" přehled po měsících ke stažení (PDF pro archiv,
 *    CSV pro účetní),
 *  - volitelně kopie každého dokladu e-mailem (Faktury → Kopie prodejci).
 *
 * Seznam dokladů prodejce se eviduje u prodejce (meta INDEX), ať měsíční
 * přehled nemusí procházet všechny objednávky.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class VendorDocuments {

	public const INDEX           = '_nkzmp_vendor_docs';
	private const BACKFILL_FLAG  = 'nkzmp_vendor_docs_indexed';

	private static ?VendorDocuments $instance = null;

	public static function instance(): VendorDocuments {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/invoices/issued', [ $this, 'on_issued' ], 10, 2 );
		add_action( 'nkzmp/v1/invoices/credit_issued', [ $this, 'on_credit' ], 20, 3 );
		add_action( 'nkzmp/v1/dashboard/order_after', [ $this, 'order_links' ], 10, 2 );
		add_action( 'nkzmp/v1/dashboard/payouts_after', [ $this, 'overview' ], 10, 2 );
		add_action( 'admin_post_nkzmp_vendor_doc', [ $this, 'download_order' ] );
		add_action( 'admin_post_nkzmp_vendor_docs_month', [ $this, 'download_month' ] );
	}

	/* ============================================================ evidence */

	/** @return array<string,array> číslo dokladu => záznam */
	public static function index( int $vendor_id ): array {
		$i = get_post_meta( $vendor_id, self::INDEX, true );
		return is_array( $i ) ? $i : [];
	}

	/** Zapíše doklady prodejců z objednávky do jejich evidence. Vrací nové doklady po prodejcích. */
	public static function record( \WC_Order $order, array $docs ): array {
		$new = [];
		foreach ( $docs as $d ) {
			$vid = (int) ( $d['issuer_key'] ?? 0 );
			if ( $vid <= 0 || empty( $d['number'] ) ) {
				continue;
			}
			$idx = self::index( $vid );
			if ( isset( $idx[ $d['number'] ] ) ) {
				continue;
			}
			$idx[ $d['number'] ] = [
				'number'    => (string) $d['number'],
				'type'      => (string) $d['type'],
				'order_id'  => $order->get_id(),
				'total'     => (float) $d['total'],
				'currency'  => (string) ( $d['currency'] ?? 'CZK' ),
				'issued_at' => (int) $d['issued_at'],
			];
			update_post_meta( $vid, self::INDEX, $idx );
			$new[ $vid ][] = $d;
		}
		return $new;
	}

	/** Jednorázově doplní evidenci z dokladů vystavených před touto verzí. */
	private static function maybe_backfill(): void {
		if ( get_option( self::BACKFILL_FLAG ) ) {
			return;
		}
		update_option( self::BACKFILL_FLAG, time(), false );
		$ids = wc_get_orders( [
			'limit'      => -1,
			'return'     => 'ids',
			'type'       => 'shop_order',
			'meta_query' => [ [ 'key' => Documents::META, 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				self::record( $order, Documents::all( $order ) );
			}
		}
	}

	/* ============================================================== háčky */

	/** @param \WC_Order $order */
	public function on_issued( $order, $docs ): void {
		if ( $order instanceof \WC_Order ) {
			$this->mail_copies( $order, self::record( $order, (array) $docs ) );
		}
	}

	/** @param \WC_Order $order */
	public function on_credit( $order, $refund_id, $numbers ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$docs = array_filter( Documents::all( $order ), static fn( $d ) => in_array( $d['number'] ?? '', (array) $numbers, true ) );
		$this->mail_copies( $order, self::record( $order, array_values( $docs ) ) );
	}

	/** Kopie dokladů prodejcům e-mailem (když je zapnutá). */
	private function mail_copies( \WC_Order $order, array $by_vendor ): void {
		if ( ( Settings::get()['vendor_copy'] ?? 'yes' ) !== 'yes' ) {
			return;
		}
		foreach ( $by_vendor as $vid => $docs ) {
			$to = (string) get_post_meta( (int) $vid, '_nkv_vendor_email', true );
			if ( ! is_email( $to ) || ! $docs ) {
				continue;
			}
			$file = Pdf::to_file( $docs, 'doklad-' . $docs[0]['number'] );
			if ( $file === '' ) {
				continue;
			}
			$credit  = ( $docs[0]['type'] ?? '' ) === 'credit';
			$numbers = implode( ', ', array_column( $docs, 'number' ) );
			$subject = $credit
				/* translators: 1: čísla dokladů, 2: objednávka */
				? sprintf( __( 'Dobropis %1$s k objednávce #%2$s (vystaven tvým jménem)', 'nkz-mp-invoices' ), $numbers, $order->get_order_number() )
				/* translators: 1: čísla dokladů, 2: objednávka */
				: sprintf( __( 'Doklad %1$s k objednávce #%2$s (vystaven tvým jménem)', 'nkz-mp-invoices' ), $numbers, $order->get_order_number() );
			$body = sprintf(
				/* translators: 1: objednávka, 2: odkaz, 3: web */
				__( "Ahoj,\n\nv příloze je kopie dokladu, který platforma vystavila tvým jménem k objednávce #%1\$s (na základě zmocnění k vystavování dokladů). Ulož si ho do účetnictví.\n\nVšechny doklady po měsících (PDF a CSV pro účetní) najdeš v sekci Moje výplaty:\n%2\$s\n\nTým %3\$s", 'nkz-mp-invoices' ),
				$order->get_order_number(),
				function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'vendor-payouts' ) : home_url( '/' ),
				(string) get_bloginfo( 'name' )
			);
			wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $file ] );
		}
	}

	/* ========================================================= přehled */

	public function order_links( $order_id, $vendor_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$docs = self::vendor_docs( $order, (int) $vendor_id );
		if ( ! $docs ) {
			if ( $order->get_meta( Documents::ISSUED_META ) && ! Mandate::has( (int) $vendor_id ) ) {
				echo '<div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(0,0,0,.1);font-size:13px;color:#8a4b00;">' . esc_html__( 'Doklad k této objednávce vystavuješ sám/sama – zatím jsi nepotvrdil/a zmocnění (výzva nahoře v přehledu).', 'nkz-mp-invoices' ) . '</div>';
			}
			return;
		}
		$url = wp_nonce_url( add_query_arg( [ 'action' => 'nkzmp_vendor_doc', 'order_id' => $order->get_id() ], admin_url( 'admin-post.php' ) ), 'nkzmp_vendor_doc_' . $order->get_id() );
		$labels = array_map(
			static fn( $d ) => ( ( $d['type'] ?? '' ) === 'credit' ? __( 'dobropis', 'nkz-mp-invoices' ) : __( 'doklad', 'nkz-mp-invoices' ) ) . ' ' . $d['number'],
			$docs
		);
		printf(
			'<div style="margin-top:12px;padding-top:12px;border-top:1px solid rgba(0,0,0,.1);font-size:13px;"><span style="color:rgba(0,0,0,.55);">%s</span> <a class="nkzmp-vd-cancel" href="%s">%s →</a></div>',
			esc_html( ucfirst( implode( ', ', $labels ) ) ),
			esc_url( $url ),
			esc_html__( 'Stáhnout (PDF)', 'nkz-mp-invoices' )
		);
	}

	/** Měsíční přehled dokladů v „Moje výplaty". */
	public function overview( $vendor_id, $currency = 'CZK' ): void {
		$vendor_id = (int) $vendor_id;
		if ( $vendor_id <= 0 || ! Settings::enabled() ) {
			return;
		}
		self::maybe_backfill();
		$months = [];
		foreach ( self::index( $vendor_id ) as $e ) {
			$m = wp_date( 'Y-m', (int) $e['issued_at'] );
			$months[ $m ] ??= [ 'count' => 0, 'sum' => 0.0, 'currency' => (string) $e['currency'] ];
			++$months[ $m ]['count'];
			$months[ $m ]['sum'] += (float) $e['total'];
		}
		krsort( $months );
		$months = array_slice( $months, 0, 24, true );

		echo '<h2 class="nkzmp-vd-subhead">' . esc_html__( 'Doklady vystavené tvým jménem', 'nkz-mp-invoices' ) . '</h2>';
		echo '<p class="nkzmp-vd-meta" style="margin:0 0 12px;">' . esc_html__( 'Doklady pro zákazníky vystavuje platforma tvým jménem (samofakturace). Patří do tvého účetnictví – stáhni si je po měsících, CSV je pro účetní.', 'nkz-mp-invoices' ) . '</p>';
		if ( ! $months ) {
			echo '<p class="nkzmp-vd-empty-msg">' . esc_html__( 'Zatím žádné doklady.', 'nkz-mp-invoices' ) . '</p>';
			return;
		}
		echo '<table class="nkzmp-vd-table"><thead><tr><th>' . esc_html__( 'Měsíc', 'nkz-mp-invoices' ) . '</th><th class="col-num">' . esc_html__( 'Dokladů', 'nkz-mp-invoices' ) . '</th><th class="col-num">' . esc_html__( 'Celkem', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Stáhnout', 'nkz-mp-invoices' ) . '</th></tr></thead><tbody>';
		foreach ( $months as $m => $row ) {
			$base = add_query_arg( [ 'action' => 'nkzmp_vendor_docs_month', 'month' => $m ], admin_url( 'admin-post.php' ) );
			$pdf  = wp_nonce_url( add_query_arg( 'format', 'pdf', $base ), 'nkzmp_vendor_docs_' . $vendor_id );
			$csv  = wp_nonce_url( add_query_arg( 'format', 'csv', $base ), 'nkzmp_vendor_docs_' . $vendor_id );
			[ $y, $mm ] = array_map( 'intval', explode( '-', $m ) );
			printf(
				'<tr><td>%s</td><td class="col-num">%d</td><td class="col-num">%s</td><td><a href="%s">PDF</a> · <a href="%s">CSV</a></td></tr>',
				esc_html( $mm . '/' . $y ),
				(int) $row['count'],
				wp_kses_post( wc_price( $row['sum'], [ 'currency' => $row['currency'] ] ) ),
				esc_url( $pdf ),
				esc_url( $csv )
			);
		}
		echo '</tbody></table>';
	}

	/* ========================================================= stahování */

	/** Prodejce přihlášeného uživatele (admin smí ?vendor_id=). */
	private static function current_vendor_id(): int {
		if ( current_user_can( 'manage_woocommerce' ) && ! empty( $_GET['vendor_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return absint( $_GET['vendor_id'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		$uid = get_current_user_id();
		if ( $uid > 0 && class_exists( \NKZMP\Vendor\OwnershipGuard::class ) ) {
			return (int) \NKZMP\Vendor\OwnershipGuard::user_vendor_id( $uid );
		}
		return 0;
	}

	/** Doklady objednávky vystavené jménem daného prodejce. */
	private static function vendor_docs( \WC_Order $order, int $vendor_id ): array {
		return array_values( array_filter(
			Documents::all( $order ),
			static fn( $d ) => (string) ( $d['issuer_key'] ?? '' ) === (string) $vendor_id
		) );
	}

	public function download_order(): void {
		$order_id = absint( $_GET['order_id'] ?? 0 );
		check_admin_referer( 'nkzmp_vendor_doc_' . $order_id );
		$vid   = self::current_vendor_id();
		$order = wc_get_order( $order_id );
		$docs  = ( $vid > 0 && $order instanceof \WC_Order ) ? self::vendor_docs( $order, $vid ) : [];
		if ( ! $docs ) {
			wp_die( esc_html__( 'Doklad nebyl nalezen.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		self::send_pdf( $docs, 'doklad-objednavka-' . $order->get_order_number() );
	}

	public function download_month(): void {
		$vid = self::current_vendor_id();
		check_admin_referer( 'nkzmp_vendor_docs_' . $vid );
		$month  = sanitize_text_field( wp_unslash( (string) ( $_GET['month'] ?? '' ) ) );
		$format = ( $_GET['format'] ?? '' ) === 'csv' ? 'csv' : 'pdf';
		if ( $vid <= 0 || ! preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			wp_die( esc_html__( 'Neplatný požadavek.', 'nkz-mp-invoices' ), '', [ 'response' => 400 ] );
		}
		$docs = [];
		foreach ( self::index( $vid ) as $e ) {
			if ( wp_date( 'Y-m', (int) $e['issued_at'] ) !== $month ) {
				continue;
			}
			$order = wc_get_order( (int) $e['order_id'] );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			foreach ( self::vendor_docs( $order, $vid ) as $d ) {
				if ( $d['number'] === $e['number'] ) {
					$docs[] = $d;
				}
			}
		}
		usort( $docs, static fn( $a, $b ) => [ (int) $a['issued_at'], $a['number'] ] <=> [ (int) $b['issued_at'], $b['number'] ] );
		if ( ! $docs ) {
			wp_die( esc_html__( 'Za tento měsíc nejsou žádné doklady.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		if ( $format === 'csv' ) {
			self::send_csv( $docs, 'doklady-' . $month );
		}
		self::send_pdf( $docs, 'doklady-' . $month );
	}

	private static function send_pdf( array $docs, string $name ): void {
		$pdf = Pdf::render( $docs );
		if ( $pdf === '' ) {
			wp_die( esc_html__( 'PDF se nepodařilo vytvořit.', 'nkz-mp-invoices' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( $name ) . '.pdf"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}

	/** CSV pro účetní: středník, UTF-8 s BOM (otevře se správně v Excelu). */
	public static function csv( array $docs ): string {
		$rates = [];
		foreach ( $docs as $d ) {
			foreach ( (array) ( $d['vat_summary'] ?? [] ) as $v ) {
				$rates[ (int) $v['rate'] ] = true;
			}
		}
		krsort( $rates );
		$rates = array_keys( $rates );

		$head = [ 'Číslo dokladu', 'Typ', 'Dodavatel', 'IČO dodavatele', 'Datum vystavení', 'Datum plnění', 'Objednávka', 'K dokladu', 'Odběratel', 'IČO odběratele' ];
		foreach ( $rates as $r ) {
			$head[] = 'Základ ' . $r . ' %';
			$head[] = 'DPH ' . $r . ' %';
		}
		$head = array_merge( $head, [ 'Celkem', 'Měna', 'Úhrada' ] );

		$fh = fopen( 'php://temp', 'r+' );
		fwrite( $fh, "\xEF\xBB\xBF" );
		fputcsv( $fh, $head, ';' );
		$num = static fn( float $v ): string => number_format( round( $v, 2 ), 2, ',', '' );
		foreach ( $docs as $d ) {
			$vat = [];
			foreach ( (array) ( $d['vat_summary'] ?? [] ) as $v ) {
				$vat[ (int) $v['rate'] ] = $v;
			}
			$p   = (array) ( $d['payment'] ?? [] );
			$pay = [];
			if ( (float) ( $p['paid'] ?? 0 ) > 0 ) {
				$pay[] = trim( (string) ( $p['method'] ?? '' ) ?: 'online' ) . ' ' . $num( (float) $p['paid'] );
			}
			if ( (float) ( $p['voucher'] ?? 0 ) > 0 ) {
				$pay[] = 'poukaz ' . ( $p['code'] ?? '' ) . ' ' . $num( (float) $p['voucher'] );
			}
			$row = [
				$d['number'],
				( $d['type'] ?? '' ) === 'credit' ? 'dobropis' : ( ! empty( $d['subject'] ) && (string) ( $d['order_number'] ?? '' ) === '' ? 'členství' : 'doklad' ),
				$d['issuer']['name'] ?? '',
				$d['issuer']['ico'] ?? '',
				wp_date( 'j. n. Y', (int) $d['issued_at'] ),
				wp_date( 'j. n. Y', (int) $d['duzp'] ),
				$d['order_number'] ?? '',
				$d['related'] ?? '',
				$d['buyer']['name'] ?? '',
				$d['buyer']['ico'] ?? '',
			];
			foreach ( $rates as $r ) {
				$row[] = isset( $vat[ $r ] ) ? $num( (float) $vat[ $r ]['base'] ) : '';
				$row[] = isset( $vat[ $r ] ) ? $num( (float) $vat[ $r ]['vat'] ) : '';
			}
			$row[] = $num( (float) $d['total'] );
			$row[] = $d['currency'] ?? 'CZK';
			$row[] = implode( ', ', $pay );
			fputcsv( $fh, array_map( 'strval', $row ), ';' );
		}
		rewind( $fh );
		$out = (string) stream_get_contents( $fh );
		fclose( $fh );
		return $out;
	}

	private static function send_csv( array $docs, string $name ): void {
		$csv = self::csv( $docs );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $name ) . '.csv"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV soubor.
		exit;
	}
}
