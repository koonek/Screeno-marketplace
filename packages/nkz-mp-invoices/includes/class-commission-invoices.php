<?php
/**
 * CommissionInvoices – měsíční doklady Art of život prodejcům za provizi.
 *
 * Z každého prodeje si platforma strhne provizi (výchozí 11 %) přímo
 * z výplaty prodejci. Prodejce za ni potřebuje doklad do účetnictví a Art
 * of život ho potřebuje jako svůj příjem. Účetní: stačí jeden souhrnný
 * doklad za měsíc.
 *
 *  - 1. den v měsíci se za předchozí měsíc vystaví každému prodejci, který
 *    něco prodal, jeden doklad: položka = objednávka (základ, %, provize),
 *  - částka je provize z původní ceny – u vráceného zboží podle podmínek
 *    zůstává platformě (dluh prodejce), takže se nesnižuje; jen provizi,
 *    kterou správce prodejci odpustil, doklad nezapočítá,
 *  - uhrazeno započtením (provize je stržená z výplat), neplátce DPH –
 *    cena je konečná,
 *  - prodejci přijde e-mailem, najde ho ve Výplatách, je i v „Doklady pro
 *    účetní". Objednávka spadá do měsíce, kdy byla zaplacena.
 *
 * Automaticky se vystavuje od měsíce nasazení; starší měsíce může správce
 * vystavit ručně v „Doklady pro účetní".
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class CommissionInvoices {

	/** Doklady na prodejci: [ 'RRRR-MM' => doc ]. */
	public const META   = '_nkzmp_commission_invoices';
	public const EVENT  = 'nkzmp_commission_monthly';
	private const SINCE = 'nkzmp_commission_since';

	private static ?CommissionInvoices $instance = null;

	public static function instance(): CommissionInvoices {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ $this, 'schedule' ] );
		add_action( self::EVENT, [ $this, 'run' ] );
		add_action( 'nkzmp/v1/dashboard/payouts_after', [ $this, 'vendor_list' ], 20 );
		add_action( 'admin_post_nkzmp_commission_pdf', [ $this, 'download' ] );
		add_action( 'admin_post_nkzmp_commission_issue', [ $this, 'handle_manual' ] );
		add_action( 'add_meta_boxes', [ $this, 'meta_box' ] );
	}

	public static function enabled(): bool {
		return Settings::enabled() && ( Settings::get()['commission'] ?? 'yes' ) === 'yes'
			&& class_exists( \NKVSVS\Split_Calculator::class );
	}

	/** @return array<string,array> */
	public static function all( int $vendor_id ): array {
		$d = get_post_meta( $vendor_id, self::META, true );
		return is_array( $d ) ? $d : [];
	}

	/* -------------------------------------------------------------- plán */

	public function schedule(): void {
		// První měsíc s automatickými doklady = měsíc nasazení.
		add_option( self::SINCE, wp_date( 'Y-m' ), '', false );
		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::EVENT );
	}

	/** Denně: je-li za minulý měsíc co vystavit, vystaví (idempotentní). */
	public function run(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$month = wp_date( 'Y-m', ( new \DateTimeImmutable( 'first day of last month', wp_timezone() ) )->getTimestamp() );
		if ( $month < (string) get_option( self::SINCE, wp_date( 'Y-m' ) ) ) {
			return;
		}
		if ( get_transient( 'nkzmp_commission_lock' ) ) {
			return;
		}
		set_transient( 'nkzmp_commission_lock', 1, 15 * MINUTE_IN_SECONDS );
		self::issue_month( $month, true );
		delete_transient( 'nkzmp_commission_lock' );
	}

	/** Měsíc RRRR-MM → [od, do) jako timestamp (časová zóna webu). */
	private static function range( string $month ): array {
		$start = new \DateTimeImmutable( $month . '-01 00:00:00', wp_timezone() );
		return [ $start->getTimestamp(), $start->modify( '+1 month' )->getTimestamp() ];
	}

	/* ----------------------------------------------------------- výpočet */

	/**
	 * Provize po prodejcích za objednávky zaplacené v měsíci.
	 *
	 * @return array<int,array<int,array{order:string,base:float,fee:float,returned:bool,currency:string}>>
	 */
	public static function collect( string $month ): array {
		[ $from, $to ] = self::range( $month );
		$ids = wc_get_orders( [
			'limit'     => -1,
			'return'    => 'ids',
			'type'      => 'shop_order',
			'date_paid' => $from . '...' . ( $to - 1 ),
		] );
		$out = [];
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( ! $order instanceof \WC_Order || in_array( $order->get_status(), [ 'cancelled', 'failed', 'pending' ], true ) ) {
				continue;
			}
			$currency = $order->get_currency();
			$factor   = function_exists( 'nkvsvs_minor_factor' ) ? (int) nkvsvs_minor_factor( $currency ) : 100;
			// Provize z původní ceny (u vráceného zboží zůstává platformě).
			$calc = \NKVSVS\Split_Calculator::calculate( $order, false );
			$now  = \NKVSVS\Split_Calculator::calculate( $order, true );
			$now_base = [];
			foreach ( (array) ( $now['vendors'] ?? [] ) as $vs ) {
				$now_base[ (int) $vs['vendor_id'] ] = (int) $vs['base_minor'];
			}
			foreach ( (array) ( $calc['vendors'] ?? [] ) as $vs ) {
				$vid = (int) $vs['vendor_id'];
				$fee = (int) $vs['platform_fee_minor'] - self::forgiven( $vid, (int) $order->get_id() );
				if ( $vid <= 0 || $fee <= 0 ) {
					continue;
				}
				$out[ $vid ][] = [
					'order'    => (string) $order->get_order_number(),
					'base'     => (int) $vs['base_minor'] / $factor,
					'fee'      => $fee / $factor,
					'returned' => ( $now_base[ $vid ] ?? 0 ) < (int) $vs['base_minor'],
					'currency' => $currency,
				];
			}
		}
		return $out;
	}

	/** Provize odpuštěná správcem (nesplacená část odpuštěného dluhu). */
	private static function forgiven( int $vendor_id, int $order_id ): int {
		if ( ! class_exists( \NKVSVS\Vendor_Debt::class ) ) {
			return 0;
		}
		$sum = 0;
		foreach ( \NKVSVS\Vendor_Debt::entries( $vendor_id ) as $e ) {
			if ( (int) $e['order_id'] === $order_id && ! empty( $e['forgiven'] ) ) {
				$sum += max( 0, (int) $e['amount_minor'] - (int) ( $e['settled_minor'] ?? 0 ) );
			}
		}
		return $sum;
	}

	/* ---------------------------------------------------------- vystavení */

	/**
	 * Vystaví doklady za měsíc všem prodejcům, kteří ho ještě nemají.
	 *
	 * @return int počet nových dokladů
	 */
	public static function issue_month( string $month, bool $mail ): int {
		$n = 0;
		foreach ( self::collect( $month ) as $vid => $rows ) {
			$doc = self::issue( (int) $vid, $month, $rows );
			if ( $doc ) {
				++$n;
				if ( $mail ) {
					self::mail( (int) $vid, $doc );
				}
			}
		}
		return $n;
	}

	private static function pct( float $fee, float $base ): string {
		if ( $base <= 0 ) {
			return '';
		}
		$p = round( $fee / $base * 100, 1 );
		return rtrim( rtrim( number_format( $p, 1, ',', '' ), '0' ), ',' ) . ' %';
	}

	public static function issue( int $vendor_id, string $month, array $rows ): ?array {
		if ( ! $rows ) {
			return null;
		}
		$all = self::all( $vendor_id );
		if ( isset( $all[ $month ] ) ) {
			return null;
		}
		$v = VendorBilling::issuer( $vendor_id );
		// Art of život neprodává sám sobě (vlastní značka vedená jako prodejce).
		$p_ico = preg_replace( '/\D/', '', (string) ( Settings::get()['ico'] ?? '' ) );
		if ( $p_ico !== '' && preg_replace( '/\D/', '', (string) $v['ico'] ) === $p_ico ) {
			return null;
		}

		$currency = (string) $rows[0]['currency'];
		$rate     = Settings::platform_is_vat_payer() ? (int) Settings::get()['vat_rate'] : null;
		$lines    = [];
		$total    = 0.0;
		foreach ( $rows as $r ) {
			$cur_fmt = static fn( float $x ) => number_format( $x, ( floor( $x ) == $x ) ? 0 : 2, ',', ' ' ) . ' ' . ( $currency === 'CZK' ? 'Kč' : $currency );
			$pct     = self::pct( (float) $r['fee'], (float) $r['base'] );
			$name    = sprintf(
				/* translators: 1: číslo objednávky, 2: procento a základ */
				__( 'Provize za zprostředkování prodeje – objednávka %1$s%2$s', 'nkz-mp-invoices' ),
				$r['order'],
				$pct !== '' ? ' (' . $pct . ' z ' . $cur_fmt( (float) $r['base'] ) . ')' : ''
			) . ( $r['returned'] ? ' – ' . __( 'zboží vráceno, provize podle podmínek zůstává', 'nkz-mp-invoices' ) : '' );
			$lines[] = Documents::line( $name, 1, (float) $r['fee'], $rate );
			$total  += (float) $r['fee'];
		}
		$total = round( $total, 2 );

		$vat = [];
		if ( $rate !== null ) {
			$base = array_sum( array_column( $lines, 'base' ) );
			$vat[] = [ 'rate' => $rate, 'base' => round( $base, 2 ), 'vat' => round( $total - $base, 2 ) ];
		}

		[ , $to ] = self::range( $month );
		[ $y, $m ] = array_map( 'intval', explode( '-', $month ) );
		$now = time();
		$doc = [
			'type'         => 'invoice',
			'kind'         => 'commission',
			'number'       => Numbering::next( 'platform', 'invoice', $now ),
			'issuer_key'   => 'platform',
			'issuer'       => Documents::platform_issuer(),
			'on_behalf'    => false,
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
			'total'        => $total,
			'vat_summary'  => $vat,
			'issued_at'    => $now,
			'duzp'         => $to - 1, // poslední den měsíce
			'currency'     => $currency,
			'order_number' => '',
			'subject'      => $m . '/' . $y,
			'payment'      => [ 'method' => __( 'započtením proti výplatě tržeb', 'nkz-mp-invoices' ), 'paid' => $total, 'voucher' => 0, 'code' => '' ],
			'related'      => '',
			'month'        => $month,
			'vendor_id'    => $vendor_id,
		];

		$all[ $month ] = $doc;
		update_post_meta( $vendor_id, self::META, $all );
		return $doc;
	}

	private static function mail( int $vendor_id, array $doc ): void {
		$to = (string) get_post_meta( $vendor_id, '_nkv_vendor_email', true );
		if ( ! is_email( $to ) ) {
			return;
		}
		$file = Pdf::to_file( [ $doc ], 'provize-' . $doc['number'] );
		if ( $file === '' ) {
			return;
		}
		$site    = (string) get_bloginfo( 'name' );
		$subject = sprintf( /* translators: 1: období, 2: číslo dokladu */ __( 'Doklad za provizi %1$s (%2$s)', 'nkz-mp-invoices' ), $doc['subject'], $doc['number'] );
		$body    = sprintf(
			/* translators: 1: období, 2: částka, 3: web, 4: odkaz */
			__( "Ahoj,\n\nv příloze posíláme doklad za provizi platformy za %1\$s – celkem %2\$s. Provize už byla stržena z tvých výplat, nic neplatíš; doklad je pro tvoje účetnictví.\n\nNajdeš ho také ve svém účtu v sekci Výplaty:\n%4\$s\n\nTým %3\$s", 'nkz-mp-invoices' ),
			$doc['subject'],
			wp_strip_all_tags( wc_price( (float) $doc['total'], [ 'currency' => $doc['currency'] ] ) ),
			$site,
			function_exists( 'wc_get_account_endpoint_url' ) ? wc_get_account_endpoint_url( 'vendor-payouts' ) : home_url( '/' )
		);
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ], [ $file ] );
	}

	/* ------------------------------------------------------- stahování */

	private static function download_url( int $vendor_id, string $month ): string {
		return wp_nonce_url(
			add_query_arg( [ 'action' => 'nkzmp_commission_pdf', 'vendor_id' => $vendor_id, 'month' => $month ], admin_url( 'admin-post.php' ) ),
			'nkzmp_commission_pdf_' . $vendor_id . '_' . $month
		);
	}

	public function download(): void {
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		$month     = sanitize_text_field( wp_unslash( (string) ( $_GET['month'] ?? '' ) ) );
		check_admin_referer( 'nkzmp_commission_pdf_' . $vendor_id . '_' . $month );
		$own = class_exists( \NKZMP\Dashboard\VendorContext::class )
			&& \NKZMP\Dashboard\VendorContext::current_vendor_id() === $vendor_id;
		if ( ! $own && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-invoices' ), '', [ 'response' => 403 ] );
		}
		$all = self::all( $vendor_id );
		if ( ! isset( $all[ $month ] ) ) {
			wp_die( esc_html__( 'Doklad nenalezen.', 'nkz-mp-invoices' ), '', [ 'response' => 404 ] );
		}
		$pdf = Pdf::render( [ $all[ $month ] ] );
		if ( $pdf === '' ) {
			wp_die( esc_html__( 'PDF se nepodařilo vytvořit.', 'nkz-mp-invoices' ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'provize-' . $all[ $month ]['number'] ) . '.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}

	/** Ruční vystavení za zvolený měsíc (Doklady pro účetní). */
	public function handle_manual(): void {
		check_admin_referer( 'nkzmp_commission_issue' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-invoices' ), '', [ 'response' => 403 ] );
		}
		$month = sanitize_text_field( wp_unslash( (string) ( $_POST['month'] ?? '' ) ) );
		$n     = 0;
		if ( preg_match( '/^\d{4}-\d{2}$/', $month ) && $month < wp_date( 'Y-m' ) && self::enabled() ) {
			$n = self::issue_month( $month, ! empty( $_POST['mail'] ) );
		}
		wp_safe_redirect( add_query_arg( [ 'month' => $month, 'commission_issued' => $n ], wp_get_referer() ?: admin_url( 'admin.php?page=nkz-mp-accountant' ) ) );
		exit;
	}

	/* ------------------------------------------------------- přehledy */

	/** Seznam dokladů za provize ve Výplatách u prodejce. */
	public function vendor_list( $vendor_id ): void {
		$vendor_id = (int) $vendor_id;
		$docs      = self::all( $vendor_id );
		if ( $vendor_id <= 0 || ! $docs ) {
			return;
		}
		krsort( $docs );
		echo '<h2 class="nkzmp-vd-subhead">' . esc_html__( 'Doklady za provizi platformy', 'nkz-mp-invoices' ) . '</h2>';
		echo '<p class="nkzmp-vd-meta" style="margin:0 0 12px;">' . esc_html__( 'Jednou měsíčně ti vystavíme doklad za provizi, kterou strháváme z výplat. Neplatíš nic, je pro tvoje účetnictví.', 'nkz-mp-invoices' ) . '</p>';
		echo '<table class="nkzmp-vd-table"><thead><tr><th>' . esc_html__( 'Měsíc', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Číslo', 'nkz-mp-invoices' ) . '</th><th class="col-num">' . esc_html__( 'Provize', 'nkz-mp-invoices' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $docs as $month => $d ) {
			printf(
				'<tr><td>%s</td><td>%s</td><td class="col-num">%s</td><td><a href="%s" target="_blank" rel="noopener">PDF</a></td></tr>',
				esc_html( (string) $d['subject'] ),
				esc_html( (string) $d['number'] ),
				wp_kses_post( wc_price( (float) $d['total'], [ 'currency' => $d['currency'] ?? '' ] ) ),
				esc_url( self::download_url( $vendor_id, (string) $month ) )
			);
		}
		echo '</tbody></table>';
	}

	public function meta_box(): void {
		foreach ( [ 'nkv_vendor', 'nkzmp_vendor' ] as $pt ) {
			add_meta_box( 'nkzmp-commission-invoices', __( 'Doklady za provizi', 'nkz-mp-invoices' ), [ $this, 'render_meta_box' ], $pt, 'side', 'low' );
		}
	}

	public function render_meta_box( \WP_Post $post ): void {
		$docs = self::all( (int) $post->ID );
		if ( ! $docs ) {
			echo '<p class="description">' . esc_html__( 'Zatím žádné. Vystavují se 1. den v měsíci za předchozí měsíc.', 'nkz-mp-invoices' ) . '</p>';
			return;
		}
		krsort( $docs );
		echo '<ul style="margin:0;">';
		foreach ( $docs as $month => $d ) {
			printf(
				'<li><a href="%s" target="_blank">%s</a> · %s · %s</li>',
				esc_url( self::download_url( (int) $post->ID, (string) $month ) ),
				esc_html( (string) $d['number'] ),
				esc_html( (string) $d['subject'] ),
				wp_kses_post( wc_price( (float) $d['total'], [ 'currency' => $d['currency'] ?? '' ] ) )
			);
		}
		echo '</ul>';
	}
}
