<?php
/**
 * AccountantExport – všechny doklady za měsíc naráz pro účetní.
 *
 * Administrace → Faktury → „Doklady pro účetní": zvolí se měsíc a
 * dodavatel (Art of život / prodejci / vše) a stáhne se
 *  - PDF se všemi doklady (každý na vlastní stránce),
 *  - CSV (středník, UTF-8 s BOM – Excel) s rozpisem DPH po sazbách.
 * Zahrnuje doklady k objednávkám, dobropisy i faktury za členství.
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class AccountantExport {

	private const ACTION = 'nkzmp_accountant_export';

	private static ?AccountantExport $instance = null;

	public static function instance(): AccountantExport {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'admin_menu', [ $this, 'menu' ], 45 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
	}

	public function menu(): void {
		add_submenu_page(
			defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce',
			__( 'Doklady pro účetní', 'nkz-mp-invoices' ),
			__( 'Doklady pro účetní', 'nkz-mp-invoices' ),
			'manage_woocommerce',
			'nkz-mp-accountant',
			[ $this, 'page' ]
		);
	}

	/** Měsíc RRRR-MM → [od, do) jako timestamp (časová zóna webu). */
	private static function range( string $month ): array {
		$tz    = wp_timezone();
		$start = new \DateTimeImmutable( $month . '-01 00:00:00', $tz );
		return [ $start->getTimestamp(), $start->modify( '+1 month' )->getTimestamp() ];
	}

	/**
	 * Doklady vystavené v měsíci.
	 *
	 * @param string $who platform | vendors | all
	 * @return array<int,array>
	 */
	public static function collect( string $month, string $who = 'all' ): array {
		[ $from, $to ] = self::range( $month );
		$docs = [];

		// Doklady k objednávkám (dobropisy mohou být i u starších objednávek).
		$ids = wc_get_orders( [
			'limit'        => -1,
			'return'       => 'ids',
			'type'         => 'shop_order',
			'date_created' => '>=' . ( $from - 120 * DAY_IN_SECONDS ),
			'meta_query'   => [ [ 'key' => Documents::META, 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		foreach ( (array) $ids as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order ) {
				foreach ( Documents::all( $order ) as $d ) {
					$docs[] = $d;
				}
			}
		}

		// Faktury za členství (u prodejců).
		if ( class_exists( MembershipInvoices::class ) ) {
			$vendors = get_posts( [ 'post_type' => [ 'nkv_vendor', 'nkzmp_vendor' ], 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ] );
			foreach ( (array) $vendors as $vid ) {
				foreach ( MembershipInvoices::all( (int) $vid ) as $d ) {
					$docs[] = $d;
				}
			}
		}

		$docs = array_values( array_filter( $docs, static function ( $d ) use ( $from, $to, $who ) {
			$t = (int) ( $d['issued_at'] ?? 0 );
			if ( $t < $from || $t >= $to ) {
				return false;
			}
			$platform = ( $d['issuer_key'] ?? '' ) === 'platform';
			return $who === 'all' || ( $who === 'platform' && $platform ) || ( $who === 'vendors' && ! $platform );
		} ) );
		usort( $docs, static fn( $a, $b ) => [ (string) $a['number'] ] <=> [ (string) $b['number'] ] );
		return $docs;
	}

	public function page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$month = isset( $_GET['month'] ) && preg_match( '/^\d{4}-\d{2}$/', (string) $_GET['month'] ) ? (string) $_GET['month'] : wp_date( 'Y-m', strtotime( 'first day of last month' ) );
		$who   = in_array( $_GET['who'] ?? '', [ 'platform', 'vendors', 'all' ], true ) ? (string) $_GET['who'] : 'platform';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$docs  = self::collect( $month, $who );

		$sum = [ 'invoice' => [ 0, 0.0 ], 'credit' => [ 0, 0.0 ] ];
		foreach ( $docs as $d ) {
			$k = ( $d['type'] ?? '' ) === 'credit' ? 'credit' : 'invoice';
			++$sum[ $k ][0];
			$sum[ $k ][1] += (float) $d['total'];
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Doklady pro účetní', 'nkz-mp-invoices' ) . '</h1>';
		echo '<p>' . esc_html__( 'Všechny doklady vystavené v daném měsíci naráz – doklady k objednávkám, dobropisy i faktury za členství. PDF pro archiv, CSV pro účetní program (Excel).', 'nkz-mp-invoices' ) . '</p>';

		echo '<form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;margin:16px 0;">';
		echo '<input type="hidden" name="page" value="nkz-mp-accountant">';
		echo '<label>' . esc_html__( 'Měsíc', 'nkz-mp-invoices' ) . '<br><input type="month" name="month" value="' . esc_attr( $month ) . '"></label>';
		echo '<label>' . esc_html__( 'Dodavatel', 'nkz-mp-invoices' ) . '<br><select name="who">';
		foreach ( [ 'platform' => __( 'Art of život (doprava, poplatky, členství)', 'nkz-mp-invoices' ), 'vendors' => __( 'Prodejci (samofakturace)', 'nkz-mp-invoices' ), 'all' => __( 'Vše', 'nkz-mp-invoices' ) ] as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $who, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></label>';
		submit_button( __( 'Zobrazit', 'nkz-mp-invoices' ), 'secondary', '', false );
		echo '</form>';

		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html( sprintf(
				/* translators: 1: počet dokladů, 2: částka, 3: počet dobropisů, 4: částka */
				__( '%1$d dokladů za %2$s, %3$d dobropisů za %4$s.', 'nkz-mp-invoices' ),
				$sum['invoice'][0],
				wp_strip_all_tags( wc_price( $sum['invoice'][1] ) ),
				$sum['credit'][0],
				wp_strip_all_tags( wc_price( abs( $sum['credit'][1] ) ) )
			) ),
			$who === 'vendors' ? '<span class="description">' . esc_html__( 'Doklady prodejců patří do jejich účetnictví – prodejci je mají i ve svém přehledu.', 'nkz-mp-invoices' ) . '</span>' : ''
		);

		if ( $docs ) {
			foreach ( [ 'pdf' => __( 'Stáhnout PDF (všechny doklady)', 'nkz-mp-invoices' ), 'csv' => __( 'Stáhnout CSV pro účetní', 'nkz-mp-invoices' ) ] as $fmt => $label ) {
				$url = wp_nonce_url( add_query_arg( [ 'action' => self::ACTION, 'month' => $month, 'who' => $who, 'format' => $fmt ], admin_url( 'admin-post.php' ) ), self::ACTION );
				echo '<a class="button ' . ( $fmt === 'pdf' ? 'button-primary' : '' ) . '" style="margin-right:8px;" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
			}
			echo '<table class="widefat striped" style="margin-top:16px;max-width:1000px;"><thead><tr><th>' . esc_html__( 'Číslo', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Typ', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Dodavatel', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Odběratel', 'nkz-mp-invoices' ) . '</th><th>' . esc_html__( 'Vystaveno', 'nkz-mp-invoices' ) . '</th><th style="text-align:right;">' . esc_html__( 'Celkem', 'nkz-mp-invoices' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $docs, 0, 300 ) as $d ) {
				$type = ( $d['type'] ?? '' ) === 'credit' ? __( 'dobropis', 'nkz-mp-invoices' ) : ( (string) ( $d['order_number'] ?? '' ) === '' ? __( 'členství', 'nkz-mp-invoices' ) : __( 'doklad', 'nkz-mp-invoices' ) );
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td style="text-align:right;">%s</td></tr>',
					esc_html( (string) $d['number'] ),
					esc_html( $type ),
					esc_html( (string) ( $d['issuer']['name'] ?? '' ) ),
					esc_html( (string) ( $d['buyer']['name'] ?? '' ) ),
					esc_html( wp_date( 'j. n. Y', (int) $d['issued_at'] ) ),
					wp_kses_post( wc_price( (float) $d['total'] ) )
				);
			}
			echo '</tbody></table>';
			if ( count( $docs ) > 300 ) {
				echo '<p class="description">' . esc_html__( 'Zobrazeno prvních 300 – v PDF a CSV jsou všechny.', 'nkz-mp-invoices' ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'V tomto měsíci nejsou žádné doklady.', 'nkz-mp-invoices' ) . '</p>';
		}
		echo '</div>';
	}

	public function download(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-invoices' ) );
		}
		check_admin_referer( self::ACTION );
		$month  = preg_match( '/^\d{4}-\d{2}$/', (string) ( $_GET['month'] ?? '' ) ) ? (string) $_GET['month'] : '';
		$who    = in_array( $_GET['who'] ?? '', [ 'platform', 'vendors', 'all' ], true ) ? (string) $_GET['who'] : 'platform';
		$format = ( $_GET['format'] ?? '' ) === 'csv' ? 'csv' : 'pdf';
		if ( $month === '' ) {
			wp_die( esc_html__( 'Neplatný měsíc.', 'nkz-mp-invoices' ) );
		}
		$docs = self::collect( $month, $who );
		if ( ! $docs ) {
			wp_die( esc_html__( 'V tomto měsíci nejsou žádné doklady.', 'nkz-mp-invoices' ) );
		}
		$name = 'doklady-' . $month . '-' . ( [ 'platform' => 'art-of-zivot', 'vendors' => 'prodejci', 'all' => 'vse' ][ $who ] );
		nocache_headers();
		if ( $format === 'csv' ) {
			$csv = VendorDocuments::csv( $docs );
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $name . '.csv"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV.
			exit;
		}
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		Pdf::$separate = true;
		$pdf = Pdf::render( $docs );
		if ( $pdf === '' ) {
			wp_die( esc_html__( 'PDF se nepodařilo vytvořit.', 'nkz-mp-invoices' ) );
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: attachment; filename="' . $name . '.pdf"' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}
}
