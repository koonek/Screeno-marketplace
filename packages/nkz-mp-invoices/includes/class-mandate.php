<?php
/**
 * Mandate – zmocnění k vystavování dokladů jménem prodejce (samofakturace).
 *
 * Právník: doklady za prodejce lze vystavovat, jen když je zmocnění
 * v podmínkách pro prodejce a prodejce je přijal (zpravidla při registraci).
 *
 * Jak se zmocnění pozná:
 *  - v nastavení Faktury je datum, od kdy podmínky zmocnění obsahují,
 *  - prodejce, který podmínky přijal při registraci v ten den a později,
 *    zmocnění má,
 *  - stávající prodejci ho potvrdí jedním kliknutím v přehledu prodejce
 *    (uloží se kdy, odkud a k jakým podmínkám).
 *
 * Bez zmocnění se doklad jeho jménem nevystaví – prodejce si ho k té
 * objednávce vystaví sám (poznámka u objednávky, upozornění v přehledu).
 *
 * @package NKZMP\Invoices
 */

namespace NKZMP\Invoices;

defined( 'ABSPATH' ) || exit;

final class Mandate {

	public const META = '_nkzmp_selfbilling_mandate';

	private static ?Mandate $instance = null;

	public static function instance(): Mandate {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'nkzmp/v1/dashboard/notices', [ $this, 'dashboard_notice' ] );
		add_action( 'admin_post_nkzmp_mandate_accept', [ $this, 'handle_accept' ] );
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'health_row' ] );
	}

	/** Datum (timestamp), od kdy podmínky pro prodejce obsahují zmocnění; 0 = ještě ne. */
	public static function since(): int {
		$d = trim( (string) ( Settings::get()['mandate_from'] ?? '' ) );
		$t = $d !== '' ? strtotime( $d . ' 00:00:00' ) : false;
		return $t ? (int) $t : 0;
	}

	/** Má prodejce platné zmocnění? */
	public static function has( int $vendor_id ): bool {
		if ( $vendor_id <= 0 ) {
			return false;
		}
		$since = self::since();
		if ( $since <= 0 ) {
			return false; // zmocnění zatím v podmínkách není
		}
		$m = get_post_meta( $vendor_id, self::META, true );
		if ( is_array( $m ) && ! empty( $m['at'] ) ) {
			return true;
		}
		// Přijal podmínky pro prodejce při registraci po zavedení zmocnění.
		$c = get_post_meta( $vendor_id, '_nkzmp_consents', true );
		return is_array( $c ) && ! empty( $c['vendor_terms']['accepted'] ) && (int) ( $c['at'] ?? 0 ) >= $since;
	}

	private static function terms_url(): string {
		$url = class_exists( \NKZMP\Registration\Settings::class ) ? (string) ( \NKZMP\Registration\Settings::get()['vendor_terms_url'] ?? '' ) : '';
		return $url !== '' ? home_url( $url ) : '';
	}

	/** Upozornění v přehledu prodejce + potvrzení jedním kliknutím. */
	public function dashboard_notice( $vendor_id ): void {
		$vendor_id = (int) $vendor_id;
		if ( self::since() <= 0 || self::has( $vendor_id ) ) {
			return;
		}
		$terms = self::terms_url();
		echo '<div class="nkzmp-vd-flash" style="border-color:rgba(0,96,255,.25);"><div class="icon">!</div><div>';
		echo '<strong>' . esc_html__( 'Potvrď prosím aktualizované podmínky pro prodejce', 'nkz-mp-invoices' ) . '</strong>';
		echo '<p>' . esc_html__( 'Doplnili jsme zmocnění, abychom za tebe mohli zákazníkům vystavovat doklady (samofakturace) – nemusíš je psát ručně. Dokud podmínky nepotvrdíš, doklady ke svým objednávkám vystavuješ sám/sama.', 'nkz-mp-invoices' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;">';
		echo '<input type="hidden" name="action" value="nkzmp_mandate_accept">';
		wp_nonce_field( 'nkzmp_mandate_accept' );
		echo '<label style="display:flex;gap:8px;align-items:flex-start;font-size:14px;"><input type="checkbox" name="accept" value="1" required> <span>';
		echo $terms !== ''
			/* translators: %s: odkaz na podmínky */
			? wp_kses( sprintf( __( 'Souhlasím s aktualizovanými <a href="%s" target="_blank" rel="noopener">podmínkami pro prodejce</a> včetně zmocnění k vystavování dokladů mým jménem.', 'nkz-mp-invoices' ), esc_url( $terms ) ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] )
			: esc_html__( 'Souhlasím s aktualizovanými podmínkami pro prodejce včetně zmocnění k vystavování dokladů mým jménem.', 'nkz-mp-invoices' );
		echo '</span></label>';
		echo '<button type="submit" class="nkzmp-vd-cta" style="background:#0060FF!important;color:#fff!important;border:0!important;border-radius:999px!important;padding:10px 18px!important;">' . esc_html__( 'Potvrdit', 'nkz-mp-invoices' ) . '</button>';
		echo '</form></div></div>';
	}

	public function handle_accept(): void {
		check_admin_referer( 'nkzmp_mandate_accept' );
		$uid = get_current_user_id();
		$vid = ( $uid > 0 && class_exists( \NKZMP\Vendor\OwnershipGuard::class ) ) ? (int) \NKZMP\Vendor\OwnershipGuard::user_vendor_id( $uid ) : 0;
		if ( $vid <= 0 || empty( $_POST['accept'] ) ) {
			wp_safe_redirect( wp_get_referer() ?: home_url( '/' ) );
			exit;
		}
		// Důkaz: kdy, odkud, kdo a k jakým podmínkám.
		update_post_meta( $vid, self::META, [
			'at'      => time(),
			'ip'      => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'user_id' => $uid,
			'url'     => self::terms_url(),
			'since'   => (string) ( Settings::get()['mandate_from'] ?? '' ),
		] );
		wp_safe_redirect( add_query_arg( 'nkzmp_mandate', 'ok', wp_get_referer() ?: home_url( '/' ) ) );
		exit;
	}

	/** @param array $rows */
	public function health_row( $rows ): array {
		$rows = (array) $rows;
		if ( ! Settings::enabled() ) {
			return $rows;
		}
		if ( self::since() <= 0 ) {
			$rows[] = [
				'label'  => __( 'Samofakturace – zmocnění prodejců', 'nkz-mp-invoices' ),
				'state'  => 'warn',
				'detail' => __( 'V nastavení Faktury není datum, od kdy podmínky pro prodejce obsahují zmocnění. Do té doby se doklady jménem prodejců nevystavují (Art of život vystavuje jen své doklady).', 'nkz-mp-invoices' ),
			];
			return $rows;
		}
		$ids     = get_posts( [ 'post_type' => [ 'nkv_vendor', 'nkzmp_vendor' ], 'post_status' => 'publish', 'posts_per_page' => -1, 'fields' => 'ids' ] );
		$missing = array_filter( (array) $ids, static fn( $id ) => ! self::has( (int) $id ) );
		$rows[]  = [
			'label'  => __( 'Samofakturace – zmocnění prodejců', 'nkz-mp-invoices' ),
			'state'  => $missing ? 'warn' : 'ok',
			'detail' => $missing
				/* translators: %d: počet */
				? sprintf( __( '%d prodejců zatím nepotvrdilo zmocnění – za jejich zboží se doklady nevystavují (vidí výzvu v přehledu).', 'nkz-mp-invoices' ), count( $missing ) )
				: __( 'Všichni prodejci mají zmocnění.', 'nkz-mp-invoices' ),
		];
		return $rows;
	}
}
