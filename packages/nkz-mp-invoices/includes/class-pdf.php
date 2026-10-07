<?php
/**
 * Pdf – vykreslení dokladů objednávky do jednoho PDF.
 *
 * Každý doklad na vlastní stránce. Písmo DejaVu Sans (je přibalené
 * s Dompdf a umí češtinu – výchozí PDF písma diakritiku nemají).
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Pdf {

	/** Adresář pro dočasné soubory a cache písem (musí být zapisovatelný). */
	public static function work_dir(): string {
		$up  = wp_upload_dir();
		$dir = trailingslashit( $up['basedir'] ) . 'nkzmp-invoices';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			// Doklady obsahují osobní údaje – adresář nesmí jít procházet.
			@file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore
			@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		}
		return $dir;
	}

	/**
	 * @param array<int,array> $docs
	 * @return string PDF (binární), '' při chybě
	 */
	public static function render( array $docs ): string {
		if ( ! $docs ) {
			return '';
		}
		require_once NKZMP_INVOICES_DIR . 'lib/autoload.php';

		$work = self::work_dir();
		$opts = new \Dompdf\Options();
		$opts->set( 'defaultFont', 'DejaVu Sans' );
		$opts->set( 'isRemoteEnabled', false );
		$opts->set( 'isHtml5ParserEnabled', true );
		$opts->set( 'fontCache', $work );
		$opts->set( 'tempDir', $work );
		$opts->set( 'chroot', [ NKZMP_INVOICES_DIR, $work ] );

		try {
			$pdf = new \Dompdf\Dompdf( $opts );
			$pdf->loadHtml( self::html( $docs ), 'UTF-8' );
			$pdf->setPaper( 'A4', 'portrait' );
			$pdf->render();
			return (string) $pdf->output();
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] invoice PDF failed: ' . $e->getMessage() );
			return '';
		}
	}

	/** Zapíše PDF do dočasného souboru (pro přílohu e-mailu). */
	public static function to_file( array $docs, string $name ): string {
		$bin = self::render( $docs );
		if ( $bin === '' ) {
			return '';
		}
		$dir = self::work_dir() . '/tmp';
		wp_mkdir_p( $dir );
		// Úklid starých příloh (den a víc).
		foreach ( (array) glob( $dir . '/*.pdf' ) as $old ) {
			if ( is_string( $old ) && filemtime( $old ) < time() - DAY_IN_SECONDS ) {
				@unlink( $old ); // phpcs:ignore
			}
		}
		$file = $dir . '/' . sanitize_file_name( $name ) . '-' . wp_generate_password( 8, false ) . '.pdf';
		return file_put_contents( $file, $bin ) !== false ? $file : ''; // phpcs:ignore
	}

	private static function money( float $v, string $currency ): string {
		$v   = round( $v, 2 );
		$dec = abs( $v - round( $v ) ) > 0.001 ? 2 : 0;
		$sym = $currency === 'CZK' ? 'Kč' : ( $currency === 'EUR' ? '€' : $currency );
		return number_format( $v, $dec, ',', "\u{00A0}" ) . "\u{00A0}" . $sym;
	}

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	private static function party( array $p, bool $issuer ): string {
		$h  = '<span class="sec-name">' . self::e( $p['name'] ?? '' ) . '</span><br>';
		if ( ! empty( $p['person'] ) ) {
			$h .= self::e( $p['person'] ) . '<br>';
		}
		if ( ! empty( $p['street'] ) ) {
			$h .= self::e( $p['street'] ) . '<br>';
		}
		$cityline = trim( ( $p['zip'] ?? '' ) . ' ' . ( $p['city'] ?? '' ) );
		if ( $cityline !== '' ) {
			$h .= self::e( $cityline ) . '<br>';
		}
		if ( ! empty( $p['country'] ) ) {
			$h .= self::e( $p['country'] ) . '<br>';
		}
		if ( ! empty( $p['ico'] ) ) {
			$h .= 'IČO: ' . self::e( $p['ico'] ) . '<br>';
		}
		if ( ! empty( $p['dic'] ) && ( ! $issuer || ! empty( $p['vat_payer'] ) ) ) {
			$h .= 'DIČ: ' . self::e( $p['dic'] ) . '<br>';
		}
		if ( $issuer ) {
			$h .= empty( $p['vat_payer'] ) ? '<span class="muted">není plátcem DPH</span><br>' : '';
			if ( ! empty( $p['registry'] ) ) {
				$h .= '<span class="muted">' . self::e( $p['registry'] ) . '</span><br>';
			}
		} elseif ( ! empty( $p['email'] ) ) {
			$h .= self::e( $p['email'] ) . '<br>';
		}
		return $h;
	}

	/** Písmo Inter (OFL, přibalené) – čistý grotesk ve stylu art of život. */
	private static function fonts_css(): string {
		$dir = 'file://' . NKZMP_INVOICES_DIR . 'assets/fonts/';
		if ( ! is_readable( NKZMP_INVOICES_DIR . 'assets/fonts/Inter-Regular.ttf' ) ) {
			return '';
		}
		return '@font-face { font-family: "Inter"; font-weight: normal; src: url("' . $dir . 'Inter-Regular.ttf"); }
			@font-face { font-family: "Inter"; font-weight: bold; src: url("' . $dir . 'Inter-SemiBold.ttf"); }
			@font-face { font-family: "Inter Light"; font-weight: normal; src: url("' . $dir . 'Inter-Light.ttf"); }';
	}

	public static function html( array $docs ): string {
		// Minimalistický styl art of život: bílá, modrá, lehký grotesk,
		// hodně vzduchu, jen vlasové linky.
		$css = self::fonts_css() . '
			@page { margin: 12mm 16mm 12mm; }
			body { font-family: "Inter", "DejaVu Sans", sans-serif; font-size: 8.6pt; color: #0060FF; line-height: 1.22; }
			strong, b { font-weight: bold; }
			.brand { width: 100%; border-collapse: collapse; margin: 0 0 8mm; }
			.brand td { padding: 0; font-size: 12pt; vertical-align: top; }
			.brand .r { text-align: right; }
			h1 { font-family: "Inter Light", "DejaVu Sans", sans-serif; font-weight: normal; font-size: 27pt; line-height: 0.98; letter-spacing: -0.6pt; margin: 0 0 2.5mm; }
			.num { font-size: 10pt; margin: 0 0 6mm; }
			.lbl { font-size: 7.5pt; color: #4F86F7; margin: 0 0 1mm; }
			.muted { color: #4F86F7; }
			.cols { width: 100%; border-collapse: collapse; margin: 0 0 5mm; }
			.cols td { width: 50%; vertical-align: top; padding: 0 6mm 0 0; }
			.meta { width: 100%; border-collapse: collapse; margin: 0 0 6mm; }
			.meta td { padding: 0 6mm 0 0; vertical-align: top; }
			.items { width: 100%; border-collapse: collapse; margin: 0 0 2mm; }
			.items th { font-weight: normal; text-align: left; font-size: 7.5pt; color: #4F86F7; border-bottom: 0.6pt solid #0060FF; padding: 0 1.5mm 1.5mm 0; }
			.items td { border-bottom: 0.4pt solid #C7D9FF; padding: 1.5mm 1.5mm 1.5mm 0; vertical-align: top; }
			.r, .items th.r, .vat th.r, .items td.r { text-align: right; white-space: nowrap; }
			.items th.r, .items td.r { padding-left: 2mm; padding-right: 0; }
			.vat { width: 58%; margin-left: 42%; border-collapse: collapse; margin: 3mm 0 0 42%; }
			.vat td, .vat th { font-weight: normal; padding: 0.8mm 0; font-size: 7.8pt; text-align: left; }
			.vat th { color: #4F86F7; }
			.vat td.r, .vat th.r { text-align: right; }
			.total { font-family: "Inter Light", "DejaVu Sans", sans-serif; font-size: 22pt; text-align: right; margin: 5mm 0 3mm; letter-spacing: -0.3pt; }
			.total span { font-family: "Inter", "DejaVu Sans", sans-serif; font-size: 8pt; color: #4F86F7; letter-spacing: 0; }
			.paid { display: inline-block; border: 0.8pt solid #0060FF; border-radius: 10pt; padding: 0.6mm 3mm; font-size: 8pt; }
			.note { font-size: 7.8pt; margin: 2mm 0; }
			.foot { font-size: 7.5pt; color: #4F86F7; margin: 2mm 0 0; }
			.sec { margin: 0 0 3.5mm; padding: 3mm 0 0; border-top: 0.6pt solid #0060FF; }
			.sec-head { width: 100%; border-collapse: collapse; margin: 0 0 1.5mm; }
			.sec-head td { vertical-align: top; padding: 0; }
			.sec-name { font-size: 10.5pt; }
			.sec-doc { text-align: right; }
			.sub { text-align: right; }
			.vatline { font-size: 7.5pt; color: #4F86F7; }
			.break { page-break-after: always; }
		';
		$out = '<!doctype html><html lang="cs"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';

		// Doklady k jedné objednávce (bez dobropisů) → jeden přehled.
		$docs     = array_values( $docs );
		$combined = [];
		$rest     = $docs;
		if ( self::combine_enabled() ) {
			$inv = array_values( array_filter( $docs, static fn( $d ) => ( $d['type'] ?? '' ) === 'invoice' && (string) ( $d['order_number'] ?? '' ) !== '' ) );
			if ( count( $inv ) > 1 && count( array_unique( array_map( static fn( $d ) => (string) $d['order_number'], $inv ) ) ) === 1 ) {
				$combined = $inv;
				$rest     = array_values( array_filter( $docs, static fn( $d ) => ! in_array( $d, $inv, true ) ) );
			}
		}

		$pages = [];
		if ( $combined ) {
			$pages[] = self::combined( $combined );
		}
		foreach ( $rest as $d ) {
			$pages[] = self::doc( $d );
		}
		return $out . implode( '<div class="break"></div>', $pages ) . '</body></html>';
	}

	private static function combine_enabled(): bool {
		return class_exists( Settings::class ) ? ( Settings::get()['combined'] ?? 'yes' ) === 'yes' : true;
	}

	/** Hlavička: „art of život" vlevo, druh dokladu vpravo – jako na plakátech. */
	private static function brand( string $right ): string {
		return '<table class="brand"><tr><td>art of život</td><td class="r">' . self::e( $right ) . '</td></tr></table>';
	}

	private static function date( $ts ): string {
		return self::e( wp_date( 'j. n. Y', (int) $ts ) );
	}

	private static function qty( $q ): string {
		return self::e( rtrim( rtrim( number_format( (float) $q, 2, ',', '' ), '0' ), ',' ) );
	}

	/** Tabulka položek jednoho dokladu (+ rekapitulace DPH u plátce). */
	private static function items( array $d, bool $recap_table ): string {
		$payer = ! empty( $d['issuer']['vat_payer'] );
		$cur   = (string) ( $d['currency'] ?? 'CZK' );
		$h     = '<table class="items"><thead><tr><th>položka</th><th class="r">množství</th><th class="r">cena/ks</th>';
		if ( $payer ) {
			$h .= '<th class="r">DPH</th><th class="r">základ</th><th class="r">daň</th>';
		}
		$h .= '<th class="r">celkem</th></tr></thead><tbody>';
		foreach ( (array) $d['lines'] as $l ) {
			$h .= '<tr><td>' . self::e( $l['name'] ) . '</td>';
			$h .= '<td class="r">' . self::qty( $l['qty'] ) . ' ×</td>';
			$h .= '<td class="r">' . self::money( (float) $l['unit'], $cur ) . '</td>';
			if ( $payer ) {
				$h .= '<td class="r">' . ( $l['rate'] === null ? '–' : (int) $l['rate'] . ' %' ) . '</td>';
				$h .= '<td class="r">' . self::money( (float) $l['base'], $cur ) . '</td>';
				$h .= '<td class="r">' . self::money( (float) $l['vat'], $cur ) . '</td>';
			}
			$h .= '<td class="r">' . self::money( (float) $l['total'], $cur ) . '</td></tr>';
		}
		$h .= '</tbody></table>';

		if ( $payer && ! empty( $d['vat_summary'] ) ) {
			if ( $recap_table ) {
				$h .= '<table class="vat"><tr><th>sazba DPH</th><th class="r">základ</th><th class="r">DPH</th></tr>';
				foreach ( (array) $d['vat_summary'] as $v ) {
					$h .= '<tr><td>' . (int) $v['rate'] . ' %</td><td class="r">' . self::money( (float) $v['base'], $cur ) . '</td><td class="r">' . self::money( (float) $v['vat'], $cur ) . '</td></tr>';
				}
				$h .= '</table>';
			}
		}
		return $h;
	}

	private static function vat_line( array $d ): string {
		if ( empty( $d['issuer']['vat_payer'] ) || empty( $d['vat_summary'] ) ) {
			return '';
		}
		$cur   = (string) ( $d['currency'] ?? 'CZK' );
		$parts = [];
		foreach ( (array) $d['vat_summary'] as $v ) {
			$parts[] = (int) $v['rate'] . ' %: základ ' . self::money( (float) $v['base'], $cur ) . ', DPH ' . self::money( (float) $v['vat'], $cur );
		}
		return 'rekapitulace DPH – ' . self::e( implode( ' · ', $parts ) );
	}

	/** Řádek „uhrazeno" s rozpisem platby. */
	private static function paid( array $d, string $prefix ): string {
		$cur   = (string) ( $d['currency'] ?? 'CZK' );
		$p     = (array) ( $d['payment'] ?? [] );
		$parts = [];
		if ( (float) ( $p['paid'] ?? 0 ) > 0 ) {
			$parts[] = trim( ( $p['method'] ?? '' ) ?: 'online' ) . ' ' . self::money( (float) $p['paid'], $cur );
		}
		if ( (float) ( $p['voucher'] ?? 0 ) > 0 ) {
			$parts[] = 'dárkovým poukazem ' . self::e( $p['code'] ?? '' ) . ' ' . self::money( (float) $p['voucher'], $cur );
		}
		$h = '<p class="note"><span class="paid">uhrazeno</span> &nbsp;Neplaťte, částka je zaplacena.';
		if ( $parts ) {
			$h .= ' ' . $prefix . self::e( implode( ', ', $parts ) ) . '.';
		}
		return $h . '</p>';
	}

	private static function doc( array $d ): string {
		$payer  = ! empty( $d['issuer']['vat_payer'] );
		$credit = ( $d['type'] ?? '' ) === 'credit';
		$cur    = (string) ( $d['currency'] ?? 'CZK' );

		// Prodejce bez IČO (prodej vlastní tvorby) neprodává jako podnikatel
		// s IČO – „Faktura" by byla zavádějící, proto neutrální název.
		$has_ico = trim( (string) ( $d['issuer']['ico'] ?? '' ) ) !== '';

		if ( $credit ) {
			$big   = $payer ? 'Opravný<br>daňový doklad' : ( $has_ico ? 'Dobropis' : 'Opravný doklad<br>o prodeji' );
		} else {
			$big   = $payer ? 'Faktura –<br>daňový doklad' : ( $has_ico ? 'Faktura' : 'Doklad<br>o prodeji' );
		}

		$h  = self::brand( $credit ? 'dobropis' : ( ! empty( $d['subject'] ) ? 'členství' : 'online market' ) );
		$h .= '<h1>' . $big . '</h1>';
		$h .= '<div class="num">č. ' . self::e( $d['number'] ) . '</div>';

		$h .= '<table class="cols"><tr>';
		$h .= '<td><div class="lbl">dodavatel</div>' . self::party( (array) $d['issuer'], true ) . '</td>';
		$h .= '<td><div class="lbl">odběratel</div>' . self::party( (array) $d['buyer'], false ) . '</td>';
		$h .= '</tr></table>';

		$h .= '<table class="meta"><tr>';
		$h .= '<td><div class="lbl">datum vystavení</div>' . self::date( $d['issued_at'] ) . '</td>';
		$h .= '<td><div class="lbl">' . ( $payer ? 'datum uskut. zdanitelného plnění' : 'datum plnění' ) . '</div>' . self::date( $d['duzp'] ) . '</td>';
		if ( (string) ( $d['order_number'] ?? '' ) !== '' ) {
			$h .= '<td><div class="lbl">objednávka</div>' . self::e( $d['order_number'] ) . '</td>';
		} elseif ( ! empty( $d['subject'] ) ) {
			// Faktura za členství – bez objednávky, s obdobím.
			$h .= '<td><div class="lbl">období</div>' . self::e( $d['subject'] ) . '</td>';
		}
		if ( $credit && ! empty( $d['related'] ) ) {
			$h .= '<td><div class="lbl">k dokladu</div>' . self::e( $d['related'] ) . '</td>';
		}
		$h .= '</tr></table>';

		$h .= self::items( $d, true );
		$h .= '<div class="total"><span>' . ( $credit ? 'celkem k vrácení' : 'celkem' ) . '</span>&nbsp; ' . self::money( abs( (float) $d['total'] ), $cur ) . '</div>';

		if ( ! $credit ) {
			$h .= self::paid( $d, (string) ( $d['order_number'] ?? '' ) !== '' ? 'Objednávka ' . self::e( $d['order_number'] ) . ' uhrazena: ' : 'Uhrazeno ' );
		}
		if ( ! empty( $d['on_behalf'] ) ) {
			$h .= '<p class="foot">Doklad vystavil provozovatel platformy Art of život jménem a na účet dodavatele na základě jeho zmocnění.</p>';
		}
		foreach ( (array) $d['lines'] as $l ) {
			if ( ! $payer && $l['rate'] === null && str_contains( strtolower( (string) $l['name'] ), 'poukaz' ) ) {
				$h .= '<p class="foot">Dárkový poukaz je víceúčelový poukaz – jeho prodej není předmětem DPH.</p>';
				break;
			}
		}
		return $h;
	}

	/** Jednořádkový zápis dodavatele (adresa, IČO, DIČ) pro souhrnný přehled. */
	private static function party_line( array $p ): string {
		$addr = implode( ', ', array_filter( [
			(string) ( $p['street'] ?? '' ),
			trim( ( $p['zip'] ?? '' ) . ' ' . ( $p['city'] ?? '' ) ),
			(string) ( $p['country'] ?? '' ) !== 'Česká republika' ? (string) ( $p['country'] ?? '' ) : '',
		] ) );
		$ids = [];
		if ( ! empty( $p['ico'] ) ) {
			$ids[] = 'IČO: ' . $p['ico'];
		}
		if ( ! empty( $p['dic'] ) && ! empty( $p['vat_payer'] ) ) {
			$ids[] = 'DIČ: ' . $p['dic'];
		}
		if ( empty( $p['vat_payer'] ) ) {
			$ids[] = 'není plátcem DPH';
		}
		$h = '<div class="sec-name">' . self::e( $p['name'] ?? '' ) . '</div>';
		$line = implode( ' · ', array_filter( [ $addr, implode( ', ', $ids ) ] ) );
		if ( $line !== '' ) {
			$h .= '<span class="muted">' . self::e( $line ) . '</span>';
		}
		if ( ! empty( $p['registry'] ) ) {
			$h .= '<br><span class="muted">' . self::e( $p['registry'] ) . '</span>';
		}
		return $h;
	}

	/**
	 * Souhrnný přehled dokladů jedné objednávky na jedné stránce (vzor GoOut).
	 *
	 * Každý blok je samostatný doklad svého dodavatele – vlastní číslo,
	 * dodavatel, položky, DPH; odběratel, data a platba jsou společné
	 * (u všech dokladů objednávky stejné). Účetně se tedy nic nemění, jen
	 * zákazník dostane jeden přehledný list místo několika stránek.
	 *
	 * @param array<int,array> $docs
	 */
	private static function combined( array $docs ): string {
		$first = $docs[0];
		$cur   = (string) ( $first['currency'] ?? 'CZK' );
		$payer = (bool) array_filter( $docs, static fn( $d ) => ! empty( $d['issuer']['vat_payer'] ) );

		$h  = self::brand( 'online market' );
		$h .= '<h1>Doklad<br>o nákupu</h1>';
		$h .= '<div class="num">objednávka ' . self::e( $first['order_number'] ) . '</div>';

		$h .= '<table class="cols"><tr><td>';
		$h .= '<table class="meta" style="margin:0"><tr>';
		$h .= '<td><div class="lbl">vystaveno</div>' . self::date( $first['issued_at'] ) . '</td>';
		$h .= '<td><div class="lbl">' . ( $payer ? 'DUZP / zaplaceno' : 'plnění / zaplaceno' ) . '</div>' . self::date( $first['duzp'] ) . '</td>';
		$h .= '</tr></table>';
		$b     = (array) $first['buyer'];
		$baddr = implode( ', ', array_filter( [ (string) ( $b['street'] ?? '' ), trim( ( $b['zip'] ?? '' ) . ' ' . ( $b['city'] ?? '' ) ), (string) ( $b['country'] ?? '' ) ] ) );
		$bids  = implode( ', ', array_filter( [ ! empty( $b['ico'] ) ? 'IČO: ' . $b['ico'] : '', ! empty( $b['dic'] ) ? 'DIČ: ' . $b['dic'] : '' ] ) );
		$h .= '</td><td><div class="lbl">odběratel</div><div class="sec-name">' . self::e( $b['name'] ?? '' ) . '</div>';
		if ( ! empty( $b['person'] ) ) {
			$h .= self::e( $b['person'] ) . '<br>';
		}
		$h .= self::e( $baddr ) . ( $bids !== '' ? '<br>' . self::e( $bids ) : '' ) . ( ! empty( $b['email'] ) ? '<br>' . self::e( $b['email'] ) : '' );
		$h .= '</td></tr></table>';

		$sum       = 0.0;
		$on_behalf = false;
		$mpv       = false;
		foreach ( $docs as $d ) {
			$dp      = ! empty( $d['issuer']['vat_payer'] );
			$has_ico = trim( (string) ( $d['issuer']['ico'] ?? '' ) ) !== '';
			$kind    = $dp ? 'daňový doklad' : ( $has_ico ? 'faktura' : 'doklad o prodeji' );

			$h .= '<div class="sec"><table class="sec-head"><tr>';
			$h .= '<td><div class="lbl">dodavatel</div>' . self::party_line( (array) $d['issuer'] ) . '</td>';
			$h .= '<td class="sec-doc"><div class="lbl">' . self::e( $kind ) . '</div>č. ' . self::e( $d['number'] );
			if ( (int) $d['issued_at'] !== (int) $first['issued_at'] ) {
				$h .= '<br><span class="muted">vystaveno ' . self::date( $d['issued_at'] ) . '</span>';
			}
			$h .= '</td></tr></table>';

			$h .= self::items( $d, false );
			$h .= '<table class="sec-head"><tr><td class="vatline">' . self::vat_line( $d ) . '</td><td class="sub">';
			if ( count( $docs ) > 1 ) {
				$h .= '<span class="muted">za dodavatele</span>&nbsp; ' . self::money( (float) $d['total'], $cur );
			}
			$h .= '</td></tr></table></div>';

			foreach ( (array) $d['lines'] as $l ) {
				if ( ! $dp && $l['rate'] === null && str_contains( strtolower( (string) $l['name'] ), 'poukaz' ) ) {
					$mpv = true;
				}
			}
			$sum       += (float) $d['total'];
			$on_behalf  = $on_behalf || ! empty( $d['on_behalf'] );
		}

		$h .= '<div class="total"><span>celkem</span>&nbsp; ' . self::money( $sum, $cur ) . '</div>';
		$h .= self::paid( $first, 'Uhrazeno ' );

		$h .= '<p class="foot">Každý blok je samostatný doklad uvedeného dodavatele. Za zboží odpovídá jeho prodejce.';
		if ( $on_behalf ) {
			$h .= ' Doklady prodejců vystavil provozovatel platformy Art of život jejich jménem a na jejich účet na základě zmocnění.';
		}
		if ( $mpv ) {
			$h .= ' Dárkový poukaz je víceúčelový poukaz – jeho prodej není předmětem DPH.';
		}
		return $h . ' Děkujeme za nákup na art of život.</p>';
	}
}
