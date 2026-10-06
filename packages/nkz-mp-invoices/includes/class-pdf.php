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
		$h  = '<strong>' . self::e( $p['name'] ?? '' ) . '</strong><br>';
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
			$h .= empty( $p['vat_payer'] ) ? '<em>Není plátcem DPH.</em><br>' : '';
			if ( ! empty( $p['registry'] ) ) {
				$h .= '<span class="muted">' . self::e( $p['registry'] ) . '</span><br>';
			}
		} elseif ( ! empty( $p['email'] ) ) {
			$h .= self::e( $p['email'] ) . '<br>';
		}
		return $h;
	}

	public static function html( array $docs ): string {
		$css = '
			@page { margin: 18mm 16mm; }
			body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1d2327; }
			h1 { font-size: 16pt; margin: 0 0 2mm; }
			.num { font-size: 11pt; color: #0060FF; margin: 0 0 6mm; }
			.cols { width: 100%; border-collapse: collapse; margin: 0 0 6mm; }
			.cols td { width: 50%; vertical-align: top; padding: 0 4mm 0 0; }
			.lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .5pt; color: #6b7280; margin: 0 0 1.5mm; }
			.meta { width: 100%; border-collapse: collapse; margin: 0 0 6mm; }
			.meta td { padding: 1mm 4mm 1mm 0; }
			.items { width: 100%; border-collapse: collapse; margin: 0 0 4mm; }
			.items th { text-align: left; font-size: 8pt; color: #6b7280; border-bottom: 1px solid #d0d4dc; padding: 2mm 1.5mm; }
			.items td { border-bottom: 1px solid #eef0f4; padding: 2mm 1.5mm; vertical-align: top; }
			.r, .items th.r, .vat th.r { text-align: right; white-space: nowrap; }
			.total { font-size: 13pt; font-weight: bold; text-align: right; margin: 4mm 0 2mm; }
			.vat { width: 60%; margin-left: 40%; border-collapse: collapse; margin-bottom: 4mm; }
			.vat td, .vat th { padding: 1mm 1.5mm; font-size: 8.5pt; border-bottom: 1px solid #eef0f4; text-align: left; }
			.vat td.r, .vat th.r { text-align: right; }
			.note { font-size: 8.5pt; color: #50575e; margin: 2mm 0; }
			.muted { color: #6b7280; font-size: 8pt; }
			.paid { display: inline-block; border: 1.5pt solid #1a7f37; color: #1a7f37; padding: 1mm 3mm; font-weight: bold; }
			.break { page-break-after: always; }
			.sec { margin: 0 0 5mm; padding: 3mm 0 0; border-top: 1px solid #d0d4dc; }
			.sec-head { width: 100%; border-collapse: collapse; margin: 0 0 2mm; }
			.sec-head td { vertical-align: top; padding: 0; }
			.sec-doc { text-align: right; font-size: 8.5pt; color: #50575e; }
			.sec-doc strong { color: #0060FF; font-weight: normal; }
			.sub { text-align: right; font-size: 9pt; margin: 0 0 1mm; }
			.vatline { text-align: right; font-size: 8pt; color: #6b7280; margin: 0; }
			.cmp { font-size: 8.5pt; }
			.cmp h1 { font-size: 15pt; }
			.cmp .num { margin: 0 0 4mm; }
			.cmp .cols { margin: 0 0 3mm; }
			.cmp .items { margin: 0 0 1.5mm; }
			.cmp .items th { padding: 1.2mm 1.5mm; font-size: 7.5pt; }
			.cmp .items td { padding: 1.3mm 1.5mm; }
			.cmp .sec { margin: 0 0 3mm; padding: 2.5mm 0 0; }
			.cmp .sec-head { margin: 0 0 1mm; }
			.cmp .note { font-size: 8pt; margin: 1.5mm 0; }
			.grand { font-size: 14pt; font-weight: bold; text-align: right; margin: 5mm 0 2mm; padding-top: 3mm; border-top: 1.5pt solid #1d2327; }
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
		$h = '<strong>' . self::e( $p['name'] ?? '' ) . '</strong><br>';
		if ( $addr !== '' ) {
			$h .= self::e( $addr ) . '<br>';
		}
		if ( $ids ) {
			$h .= '<span class="muted">' . self::e( implode( ', ', $ids ) ) . '</span>';
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

		$h  = '<div class="cmp"><h1>Doklad o nákupu</h1>';
		$h .= '<div class="num">Objednávka ' . self::e( $first['order_number'] ) . '</div>';

		$h .= '<table class="cols"><tr><td>';
		$h .= '<span class="muted">Vystaveno:</span> ' . self::e( wp_date( 'j. n. Y', (int) $first['issued_at'] ) ) . '<br>';
		$h .= '<span class="muted">' . ( $payer ? 'Datum uskut. zdanit. plnění:' : 'Datum plnění:' ) . '</span> ' . self::e( wp_date( 'j. n. Y', (int) $first['duzp'] ) ) . '<br>';
		$h .= '<span class="muted">Zaplaceno:</span> ' . self::e( wp_date( 'j. n. Y', (int) $first['duzp'] ) );
		$h .= '</td><td><div class="lbl">Odběratel</div>' . self::party( (array) $first['buyer'], false ) . '</td></tr></table>';

		$sum       = 0.0;
		$on_behalf = false;
		$mpv       = false;
		foreach ( $docs as $d ) {
			$dp      = ! empty( $d['issuer']['vat_payer'] );
			$has_ico = trim( (string) ( $d['issuer']['ico'] ?? '' ) ) !== '';
			$kind    = $dp ? 'Daňový doklad' : ( $has_ico ? 'Faktura' : 'Doklad o prodeji' );

			$h .= '<div class="sec"><table class="sec-head"><tr>';
			$h .= '<td><div class="lbl">Dodavatel</div>' . self::party_line( (array) $d['issuer'] ) . '</td>';
			$h .= '<td class="sec-doc">' . self::e( $kind ) . '<br><strong>č. ' . self::e( $d['number'] ) . '</strong>';
			if ( (int) $d['issued_at'] !== (int) $first['issued_at'] ) {
				$h .= '<br>vystaveno ' . self::e( wp_date( 'j. n. Y', (int) $d['issued_at'] ) );
			}
			$h .= '</td></tr></table>';

			$h .= '<table class="items"><thead><tr><th>Položka</th><th class="r">Množství</th><th class="r">Cena/ks</th>';
			if ( $dp ) {
				$h .= '<th class="r">DPH</th><th class="r">Základ</th><th class="r">Daň</th>';
			}
			$h .= '<th class="r">Celkem</th></tr></thead><tbody>';
			foreach ( (array) $d['lines'] as $l ) {
				$h .= '<tr><td>' . self::e( $l['name'] ) . '</td>';
				$h .= '<td class="r">' . self::e( rtrim( rtrim( number_format( (float) $l['qty'], 2, ',', '' ), '0' ), ',' ) ) . ' ×</td>';
				$h .= '<td class="r">' . self::money( (float) $l['unit'], $cur ) . '</td>';
				if ( $dp ) {
					$h .= '<td class="r">' . ( $l['rate'] === null ? '–' : (int) $l['rate'] . ' %' ) . '</td>';
					$h .= '<td class="r">' . self::money( (float) $l['base'], $cur ) . '</td>';
					$h .= '<td class="r">' . self::money( (float) $l['vat'], $cur ) . '</td>';
				}
				$h .= '<td class="r">' . self::money( (float) $l['total'], $cur ) . '</td></tr>';
				if ( ! $dp && $l['rate'] === null && str_contains( strtolower( (string) $l['name'] ), 'poukaz' ) ) {
					$mpv = true;
				}
			}
			$h .= '</tbody></table>';

			$h .= '<table class="sec-head"><tr><td class="vatline" style="text-align:left">';
			if ( $dp && ! empty( $d['vat_summary'] ) ) {
				$parts = [];
				foreach ( (array) $d['vat_summary'] as $v ) {
					$parts[] = (int) $v['rate'] . ' %: základ ' . self::money( (float) $v['base'], $cur ) . ', DPH ' . self::money( (float) $v['vat'], $cur );
				}
				$h .= 'Rekapitulace DPH – ' . self::e( implode( ' · ', $parts ) );
			}
			$h .= '</td><td class="sub">';
			if ( count( $docs ) > 1 ) {
				$h .= 'Celkem za dodavatele: <strong>' . self::money( (float) $d['total'], $cur ) . '</strong>';
			}
			$h .= '</td></tr></table>';
			$h .= '</div>';

			$sum       += (float) $d['total'];
			$on_behalf  = $on_behalf || ! empty( $d['on_behalf'] );
		}

		$h .= '<div class="grand">Celkem: ' . self::money( $sum, $cur ) . '</div>';

		$p     = (array) ( $first['payment'] ?? [] );
		$parts = [];
		if ( (float) ( $p['paid'] ?? 0 ) > 0 ) {
			$parts[] = trim( ( $p['method'] ?? '' ) ?: 'online' ) . ' ' . self::money( (float) $p['paid'], $cur );
		}
		if ( (float) ( $p['voucher'] ?? 0 ) > 0 ) {
			$parts[] = 'dárkovým poukazem ' . self::e( $p['code'] ?? '' ) . ' ' . self::money( (float) $p['voucher'], $cur );
		}
		$h .= '<p class="note"><span class="paid">UHRAZENO</span> &nbsp;Neplaťte, částka je zaplacena.';
		if ( $parts ) {
			$h .= ' Uhrazeno ' . self::e( implode( ', ', $parts ) ) . '.';
		}
		$h .= '</p>';

		$h .= '<p class="note">Každý blok je samostatný doklad uvedeného dodavatele. Za zboží odpovídá jeho prodejce.</p>';
		if ( $on_behalf ) {
			$h .= '<p class="note">Doklady prodejců vystavil provozovatel platformy Art of život jejich jménem a na jejich účet na základě zmocnění.</p>';
		}
		if ( $mpv ) {
			$h .= '<p class="note">Dárkový poukaz je víceúčelový poukaz – jeho prodej není předmětem DPH.</p>';
		}
		return $h . '<p class="note">Děkujeme za nákup na Art of život.</p></div>';
	}

	private static function doc( array $d ): string {
		$payer  = ! empty( $d['issuer']['vat_payer'] );
		$credit = ( $d['type'] ?? '' ) === 'credit';
		$cur    = (string) ( $d['currency'] ?? 'CZK' );

		// Prodejce bez IČO (prodej vlastní tvorby) neprodává jako podnikatel
		// s IČO – „Faktura" by byla zavádějící, proto neutrální název.
		$has_ico = trim( (string) ( $d['issuer']['ico'] ?? '' ) ) !== '';

		if ( $credit ) {
			$title = $payer ? 'Opravný daňový doklad (dobropis)' : ( $has_ico ? 'Dobropis' : 'Opravný doklad o prodeji' );
		} else {
			$title = $payer ? 'Faktura – daňový doklad' : ( $has_ico ? 'Faktura' : 'Doklad o prodeji' );
		}

		$h  = '<h1>' . self::e( $title ) . '</h1>';
		$h .= '<div class="num">č. ' . self::e( $d['number'] ) . '</div>';

		$h .= '<table class="cols"><tr>';
		$h .= '<td><div class="lbl">Dodavatel</div>' . self::party( (array) $d['issuer'], true ) . '</td>';
		$h .= '<td><div class="lbl">Odběratel</div>' . self::party( (array) $d['buyer'], false ) . '</td>';
		$h .= '</tr></table>';

		$h .= '<table class="meta"><tr>';
		$h .= '<td><span class="muted">Datum vystavení</span><br>' . self::e( wp_date( 'j. n. Y', (int) $d['issued_at'] ) ) . '</td>';
		$h .= '<td><span class="muted">' . ( $payer ? 'Datum uskut. zdanitelného plnění' : 'Datum plnění' ) . '</span><br>' . self::e( wp_date( 'j. n. Y', (int) $d['duzp'] ) ) . '</td>';
		if ( (string) ( $d['order_number'] ?? '' ) !== '' ) {
			$h .= '<td><span class="muted">Objednávka</span><br>' . self::e( $d['order_number'] ) . '</td>';
		} elseif ( ! empty( $d['subject'] ) ) {
			// Faktura za členství – bez objednávky, s obdobím.
			$h .= '<td><span class="muted">Období</span><br>' . self::e( $d['subject'] ) . '</td>';
		}
		if ( $credit && ! empty( $d['related'] ) ) {
			$h .= '<td><span class="muted">K dokladu</span><br>' . self::e( $d['related'] ) . '</td>';
		}
		$h .= '</tr></table>';

		$h .= '<table class="items"><thead><tr><th>Položka</th><th class="r">Množství</th><th class="r">Cena/ks</th>';
		if ( $payer ) {
			$h .= '<th class="r">DPH</th><th class="r">Základ</th><th class="r">Daň</th>';
		}
		$h .= '<th class="r">Celkem</th></tr></thead><tbody>';
		foreach ( (array) $d['lines'] as $l ) {
			$qty = (float) $l['qty'];
			$h  .= '<tr><td>' . self::e( $l['name'] ) . '</td>';
			$h  .= '<td class="r">' . self::e( rtrim( rtrim( number_format( $qty, 2, ',', '' ), '0' ), ',' ) ) . '</td>';
			$h  .= '<td class="r">' . self::money( (float) $l['unit'], $cur ) . '</td>';
			if ( $payer ) {
				$h .= '<td class="r">' . ( $l['rate'] === null ? '–' : (int) $l['rate'] . ' %' ) . '</td>';
				$h .= '<td class="r">' . self::money( (float) $l['base'], $cur ) . '</td>';
				$h .= '<td class="r">' . self::money( (float) $l['vat'], $cur ) . '</td>';
			}
			$h .= '<td class="r">' . self::money( (float) $l['total'], $cur ) . '</td></tr>';
		}
		$h .= '</tbody></table>';

		if ( $payer && ! empty( $d['vat_summary'] ) ) {
			$h .= '<table class="vat"><tr><th>Sazba</th><th class="r">Základ</th><th class="r">DPH</th></tr>';
			foreach ( (array) $d['vat_summary'] as $v ) {
				$h .= '<tr><td>' . (int) $v['rate'] . ' %</td><td class="r">' . self::money( (float) $v['base'], $cur ) . '</td><td class="r">' . self::money( (float) $v['vat'], $cur ) . '</td></tr>';
			}
			$h .= '</table>';
		}

		$h .= '<div class="total">' . ( $credit ? 'Celkem k vrácení: ' : 'Celkem: ' ) . self::money( abs( (float) $d['total'] ), $cur ) . '</div>';

		if ( ! $credit ) {
			$p     = (array) ( $d['payment'] ?? [] );
			$parts = [];
			if ( (float) ( $p['paid'] ?? 0 ) > 0 ) {
				$parts[] = trim( ( $p['method'] ?? '' ) ?: 'online' ) . ' ' . self::money( (float) $p['paid'], $cur );
			}
			if ( (float) ( $p['voucher'] ?? 0 ) > 0 ) {
				$parts[] = 'dárkovým poukazem ' . self::e( $p['code'] ?? '' ) . ' ' . self::money( (float) $p['voucher'], $cur );
			}
			$h .= '<p class="note"><span class="paid">UHRAZENO</span> &nbsp;Neplaťte, částka je zaplacena.';
			if ( $parts ) {
				$h .= (string) ( $d['order_number'] ?? '' ) !== ''
					? ' Objednávka ' . self::e( $d['order_number'] ) . ' uhrazena: ' . self::e( implode( ', ', $parts ) ) . '.'
					: ' Uhrazeno ' . self::e( implode( ', ', $parts ) ) . '.';
			}
			$h .= '</p>';
		}

		if ( ! empty( $d['on_behalf'] ) ) {
			$h .= '<p class="note">Doklad vystavil provozovatel platformy Art of život jménem a na účet dodavatele na základě jeho zmocnění.</p>';
		}
		foreach ( (array) $d['lines'] as $l ) {
			if ( ! $payer && $l['rate'] === null && str_contains( strtolower( (string) $l['name'] ), 'poukaz' ) ) {
				$h .= '<p class="note">Dárkový poukaz je víceúčelový poukaz – jeho prodej není předmětem DPH.</p>';
				break;
			}
		}
		return $h;
	}
}
