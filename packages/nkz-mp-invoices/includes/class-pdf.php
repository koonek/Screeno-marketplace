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
			.r { text-align: right; white-space: nowrap; }
			.total { font-size: 13pt; font-weight: bold; text-align: right; margin: 4mm 0 2mm; }
			.vat { width: 60%; margin-left: 40%; border-collapse: collapse; margin-bottom: 4mm; }
			.vat td, .vat th { padding: 1mm 1.5mm; font-size: 8.5pt; border-bottom: 1px solid #eef0f4; }
			.note { font-size: 8.5pt; color: #50575e; margin: 2mm 0; }
			.muted { color: #6b7280; font-size: 8pt; }
			.paid { display: inline-block; border: 1.5pt solid #1a7f37; color: #1a7f37; padding: 1mm 3mm; font-weight: bold; }
			.break { page-break-after: always; }
		';
		$out = '<!doctype html><html lang="cs"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';
		$n   = count( $docs );
		foreach ( array_values( $docs ) as $i => $d ) {
			$out .= self::doc( $d ) . ( $i < $n - 1 ? '<div class="break"></div>' : '' );
		}
		return $out . '</body></html>';
	}

	private static function doc( array $d ): string {
		$payer  = ! empty( $d['issuer']['vat_payer'] );
		$credit = ( $d['type'] ?? '' ) === 'credit';
		$cur    = (string) ( $d['currency'] ?? 'CZK' );

		if ( $credit ) {
			$title = $payer ? 'Opravný daňový doklad (dobropis)' : 'Dobropis';
		} else {
			$title = $payer ? 'Faktura – daňový doklad' : 'Faktura';
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
		$h .= '<td><span class="muted">Objednávka</span><br>' . self::e( $d['order_number'] ) . '</td>';
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
				$h .= ' Objednávka ' . self::e( $d['order_number'] ) . ' uhrazena: ' . self::e( implode( ', ', $parts ) ) . '.';
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
