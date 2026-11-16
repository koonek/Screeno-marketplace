<?php
/**
 * VoucherPrint – dárkový poukaz k vytištění (A4, PDF).
 *
 * Poukaz se nejčastěji kupuje jako dárek. Zákazník proto dostane kromě
 * kódu v e-mailu i pěkně zpracovaný poukaz na A4: vytiskne ho, přeloží
 * na třetiny a vloží do obálky (DL).
 *
 *  - horní třetina = přední strana (dárkový poukaz, hodnota),
 *  - prostřední = „Pro / Od", kód a platnost,
 *  - spodní = jak poukaz uplatnit a podmínky.
 *
 * Každý kód na vlastní stránce. PDF jde v příloze e-mailu se zaplacenou
 * objednávkou a stáhne se i z děkovací stránky a z účtu (odkaz s klíčem
 * objednávky, funguje i bez přihlášení). PDF vykresluje modul Faktury
 * (Dompdf + písmo Inter) – bez něj se nabídne jen kód v e-mailu.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class VoucherPrint {

	private const ACTION = 'nkzmp_voucher_print';

	/** E-maily, ke kterým se poukaz přikládá. */
	private const EMAILS = [ 'customer_processing_order', 'customer_completed_order', 'customer_on_hold_order', 'customer_invoice' ];

	private static ?VoucherPrint $instance = null;

	/** @var array<int,string> objednávka => soubor (v rámci požadavku) */
	private array $files = [];

	public static function instance(): VoucherPrint {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_filter( 'woocommerce_email_attachments', [ $this, 'attach' ], 20, 4 );
		add_action( 'admin_post_' . self::ACTION, [ $this, 'download' ] );
		add_action( 'admin_post_nopriv_' . self::ACTION, [ $this, 'download' ] );
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'order_button' ], 15 );
		add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'admin_link' ], 30 );
	}

	public static function available(): bool {
		return class_exists( \NKZMP\Invoices\Pdf::class ) && defined( 'NKZMP_INVOICES_DIR' );
	}

	/** Odkaz ke stažení – klíč objednávky, takže funguje i bez přihlášení. */
	public static function url( \WC_Order $order ): string {
		return add_query_arg(
			[ 'action' => self::ACTION, 'order' => $order->get_id(), 'key' => $order->get_order_key() ],
			admin_url( 'admin-post.php' )
		);
	}

	/* ------------------------------------------------------------ PDF */

	private static function e( $s ): string {
		return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
	}

	private static function money( float $v, string $currency ): string {
		$dec = abs( $v - round( $v ) ) > 0.001 ? 2 : 0;
		$sym = $currency === 'CZK' ? 'Kč' : ( $currency === 'EUR' ? '€' : $currency );
		return number_format( $v, $dec, ',', "\u{00A0}" ) . "\u{00A0}" . $sym;
	}

	/** Kdo poukaz vydává (údaje z modulu Faktury, jinak název webu). */
	private static function issuer(): string {
		if ( class_exists( \NKZMP\Invoices\Settings::class ) ) {
			$s = \NKZMP\Invoices\Settings::get();
			$parts = array_filter( [
				(string) ( $s['name'] ?? '' ),
				trim( (string) ( $s['street'] ?? '' ) . ', ' . (string) ( $s['zip'] ?? '' ) . ' ' . (string) ( $s['city'] ?? '' ), ' ,' ),
				! empty( $s['ico'] ) ? 'IČO ' . $s['ico'] : '',
			] );
			if ( $parts ) {
				return implode( ' · ', $parts );
			}
		}
		return (string) get_bloginfo( 'name' );
	}

	/**
	 * @param array<int,array> $rows poukazy (Voucher::data)
	 */
	public static function html( array $rows, string $currency ): string {
		$host  = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$host  = preg_replace( '/^www\./', '', $host );
		$shop  = function_exists( 'wc_get_page_permalink' ) ? (string) wp_parse_url( wc_get_page_permalink( 'shop' ), PHP_URL_PATH ) : '/obchod/';
		$fonts = \NKZMP\Invoices\Pdf::fonts_css();
		// Věta o propadnutí zůstatku je níže tučně – v běžném textu ji vynecháme.
		$terms = trim( str_replace( 'Nevyčerpaný zůstatek propadá a nevrací se.', '', Voucher::terms_text() ) );

		$css = $fonts . '
			@page { margin: 0; }
			body { margin: 0; font-family: "Inter", "DejaVu Sans", sans-serif; color: #0060FF; }
			.page { position: relative; width: 210mm; height: 296mm; page-break-after: always; }
			.page.last { page-break-after: auto; }
			.panel { position: absolute; left: 0; width: 210mm; height: 99mm; }
			.p1 { top: 0; } .p2 { top: 99mm; } .p3 { top: 198mm; height: 98mm; }
			.in { position: absolute; left: 22mm; width: 166mm; top: 13mm; height: 74mm; }
			.fold { position: absolute; left: 0; width: 210mm; height: 0; border-top: 0.4pt dashed #B9CEFF; }
			.f1 { top: 99mm; } .f2 { top: 198mm; }
			.foldlbl { position: absolute; right: 6mm; font-size: 6pt; color: #9DB8FF; }
			.fl1 { top: 96.4mm; } .fl2 { top: 195.4mm; }
			.brand { width: 100%; border-collapse: collapse; font-size: 11pt; }
			.brand td { padding: 0; }
			.brand .r { text-align: right; }
			.p1 { background: #0060FF; color: #fff; }
			.p1 .brand td { color: #fff; }
			.title { font-family: "Inter Light", "DejaVu Sans", sans-serif; font-size: 44pt; line-height: 1; letter-spacing: -1pt; margin: 10mm 0 0; color: #fff; }
			.sub { font-size: 13pt; line-height: 1.3; margin: 3.5mm 0 0; color: #fff; }
			.value { position: absolute; left: 0; top: 52mm; font-family: "Inter Light", "DejaVu Sans", sans-serif; font-size: 40pt; letter-spacing: -0.8pt; color: #fff; }
			.valuelbl { position: absolute; right: 0; top: 61mm; font-size: 8.5pt; color: #CFE0FF; text-align: right; }
			.lbl { font-size: 7.5pt; color: #4F86F7; margin: 0 0 1.5mm; }
			.line { border-bottom: 0.6pt solid #0060FF; height: 9mm; margin: 0 0 5mm; }
			.who { width: 100%; border-collapse: collapse; }
			.who td { width: 50%; padding: 0 6mm 0 0; vertical-align: top; }
			.who td.last { padding: 0 0 0 6mm; }
			.code { border: 1.2pt dashed #0060FF; border-radius: 8pt; padding: 4mm 0; text-align: center; font-size: 22pt; letter-spacing: 2.5pt; margin: 3mm 0 3mm; }
			.valid { width: 100%; border-collapse: collapse; font-size: 9pt; }
			.valid td { padding: 0; }
			.valid .r { text-align: right; }
			h2 { font-family: "Inter Light", "DejaVu Sans", sans-serif; font-weight: normal; font-size: 17pt; margin: 0 0 4mm; letter-spacing: -0.3pt; }
			.steps { width: 100%; border-collapse: collapse; font-size: 9pt; line-height: 1.3; }
			.steps td { vertical-align: top; padding: 0 0 2.5mm; }
			.steps td.n { width: 8mm; font-size: 11pt; }
			.terms { font-size: 7.6pt; line-height: 1.3; color: #4F86F7; margin: 2mm 0 0; }
			.terms b { color: #0060FF; }
			.foot { position: absolute; left: 0; width: 166mm; top: 68mm; font-size: 6.8pt; color: #9DB8FF; }
		';

		$pages = [];
		$n     = count( $rows );
		foreach ( array_values( $rows ) as $i => $r ) {
			$h  = '<div class="page' . ( $i === $n - 1 ? ' last' : '' ) . '">';

			// 1) Přední strana.
			$h .= '<div class="panel p1"><div class="in">';
			$h .= '<table class="brand"><tr><td>art of život</td><td class="r">online market</td></tr></table>';
			$h .= '<div class="title">Dárkový poukaz</div>';
			$h .= '<div class="sub">Platí na nákup u všech tvůrců na našem online marketu.</div>';
			$h .= '<div class="value">' . self::e( self::money( (float) $r['value'], $currency ) ) . '</div>';
			$h .= '<div class="valuelbl">' . self::e( $host ) . '</div>';
			$h .= '</div></div>';

			// 2) Pro / Od, kód, platnost.
			$h .= '<div class="panel p2"><div class="in">';
			$h .= '<table class="who"><tr><td><div class="lbl">pro</div><div class="line"></div></td><td class="last"><div class="lbl">od</div><div class="line"></div></td></tr></table>';
			$h .= '<div class="lbl">kód poukazu</div>';
			$h .= '<div class="code">' . self::e( $r['code'] ) . '</div>';
			$h .= '<table class="valid"><tr><td>hodnota ' . self::e( self::money( (float) $r['value'], $currency ) ) . '</td><td class="r">platí do ' . self::e( wp_date( 'j. n. Y', (int) $r['expires'] ) ) . '</td></tr></table>';
			$h .= '</div></div>';

			// 3) Jak uplatnit + podmínky.
			$h .= '<div class="panel p3"><div class="in">';
			$h .= '<h2>Jak poukaz uplatnit</h2>';
			$h .= '<table class="steps">';
			$h .= '<tr><td class="n">1</td><td>Vyberte si na <b>' . self::e( $host . $shop ) . '</b> – keramiku, šperky, oblečení, obrazy i zážitky od českých tvůrců.</td></tr>';
			$h .= '<tr><td class="n">2</td><td>V košíku zadejte kód do pole „Máte dárkový poukaz?“.</td></tr>';
			$h .= '<tr><td class="n">3</td><td>Hodnota se odečte z celé objednávky včetně poštovného, zbytek doplatíte kartou.</td></tr>';
			$h .= '</table>';
			$h .= '<p class="terms">' . self::e( $terms ) . ' <b>Poukaz je jednorázový – nevyčerpaný zůstatek propadá a nevrací se.</b> Kód chraňte, uplatnit ho může kdokoli, kdo ho zná.</p>';
			$h .= '<div class="foot">Vydává ' . self::e( self::issuer() ) . '</div>';
			$h .= '</div></div>';

			// Značky pro přeložení na třetiny.
			$h .= '<div class="fold f1"></div><div class="fold f2"></div>';
			$h .= '<div class="foldlbl fl1">přeložte</div><div class="foldlbl fl2">přeložte</div>';
			$h .= '</div>';
			$pages[] = $h;
		}

		return '<!doctype html><html lang="cs"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . implode( '', $pages ) . '</body></html>';
	}

	/** Poukazy k tisku – jen platné (uplatněný už dárkem není). */
	public static function printable( \WC_Order $order ): array {
		return array_values( array_filter(
			Voucher::for_order( (int) $order->get_id() ),
			static fn( $r ) => in_array( $r['status'], [ Voucher::STATUS_ACTIVE, Voucher::STATUS_RESERVED ], true )
		) );
	}

	/** PDF poukazů z objednávky ('' když nejsou nebo se nepovedlo). */
	public static function pdf( \WC_Order $order ): string {
		if ( ! self::available() ) {
			return '';
		}
		$rows = self::printable( $order );
		if ( ! $rows ) {
			return '';
		}
		return \NKZMP\Invoices\Pdf::render_html( self::html( $rows, $order->get_currency() ) );
	}

	/* ------------------------------------------------------- doručení */

	/**
	 * @param array  $attachments
	 * @param string $email_id
	 * @param mixed  $order
	 */
	public function attach( $attachments, $email_id, $order = null, $email = null ): array {
		$attachments = (array) $attachments;
		if ( ! in_array( (string) $email_id, self::EMAILS, true ) || ! $order instanceof \WC_Order ) {
			return $attachments;
		}
		$id = (int) $order->get_id();
		if ( ! isset( $this->files[ $id ] ) ) {
			$this->files[ $id ] = '';
			$bin = self::pdf( $order );
			if ( $bin !== '' ) {
				$this->files[ $id ] = \NKZMP\Invoices\Pdf::save( $bin, 'darkovy-poukaz-' . $order->get_order_number() );
			}
		}
		if ( $this->files[ $id ] !== '' ) {
			$attachments[] = $this->files[ $id ];
		}
		return $attachments;
	}

	public function download(): void {
		$order = wc_get_order( absint( $_GET['order'] ?? 0 ) );
		$key   = sanitize_text_field( wp_unslash( (string) ( $_GET['key'] ?? '' ) ) );
		$ok    = $order instanceof \WC_Order && (
			( $key !== '' && hash_equals( (string) $order->get_order_key(), $key ) )
			|| current_user_can( 'manage_woocommerce' )
		);
		if ( ! $ok ) {
			wp_die( esc_html__( 'Poukaz nenalezen.', 'nkz-mp-storefront' ), '', [ 'response' => 404 ] );
		}
		$bin = self::pdf( $order );
		if ( $bin === '' ) {
			wp_die( esc_html__( 'Poukaz k tisku není k dispozici – vystaví se po zaplacení objednávky, uplatněný poukaz se už netiskne.', 'nkz-mp-storefront' ), '', [ 'response' => 404 ] );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline; filename="' . sanitize_file_name( 'darkovy-poukaz-' . $order->get_order_number() ) . '.pdf"' );
		echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binární PDF.
		exit;
	}

	/** Tlačítko na děkovací stránce a v detailu objednávky v účtu. */
	public function order_button( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::available() || ! self::printable( $order ) ) {
			return;
		}
		printf(
			'<p style="margin:16px 0;"><a href="%s" target="_blank" rel="noopener" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#0060FF;color:#fff;-webkit-text-fill-color:#fff;font-weight:600;text-decoration:none;">%s</a> <span style="display:block;margin-top:6px;font-size:13px;color:#666;">%s</span></p>',
			esc_url( self::url( $order ) ),
			esc_html__( 'Stáhnout poukaz k tisku (A4)', 'nkz-mp-storefront' ),
			esc_html__( 'Vytiskněte, přeložte na třetiny a vložte do obálky.', 'nkz-mp-storefront' )
		);
	}

	public function admin_link( $order ): void {
		if ( ! $order instanceof \WC_Order || ! self::available() || ! self::printable( $order ) ) {
			return;
		}
		printf(
			'<p class="form-field form-field-wide"><a class="button" href="%s" target="_blank">%s</a></p>',
			esc_url( self::url( $order ) ),
			esc_html__( 'Dárkový poukaz k tisku (PDF)', 'nkz-mp-storefront' )
		);
	}
}
