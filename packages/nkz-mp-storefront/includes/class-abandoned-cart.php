<?php
/**
 * AbandonedCart – jedna připomínka nedokončeného nákupu e-mailem.
 *
 * Jak to funguje:
 *  - košík si zapamatujeme, jakmile známe e-mail: přihlášený zákazník
 *    hned, host ve chvíli, kdy v pokladně vyplní e-mail (pod polem je
 *    krátká informace),
 *  - po N hodinách (nastavení Storefront, výchozí 2 h) bez objednávky
 *    pošleme JEDEN e-mail s produkty a tlačítkem „Dokončit nákup", které
 *    košík obnoví (i na jiném zařízení),
 *  - objednávkou se záznam smaže, v e-mailu je odkaz na odhlášení
 *    připomínek, staré záznamy se mažou po 30 dnech.
 *
 * Záznamy: neveřejný typ příspěvku nkzmp_abandoned (e-mail, položky,
 * token, kdy naposledy změněn, kdy odeslán).
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class AbandonedCart {

	public const CPT     = 'nkzmp_abandoned';
	public const CRON    = 'nkzmp_abandoned_cart_cron';
	public const OPTOUT  = 'nkzmp_abandoned_optout';
	private const NONCE  = 'nkzmp_ac';
	private const KEEP   = 30 * DAY_IN_SECONDS;
	private const WINDOW = 7 * DAY_IN_SECONDS; // starší košíky už nepřipomínat

	private static ?AbandonedCart $instance = null;

	public static function instance(): AbandonedCart {
		return self::$instance ??= new self();
	}

	public static function enabled(): bool {
		$on = class_exists( Settings::class ) ? ( Settings::get()['abandoned_cart'] ?? 'yes' ) === 'yes' : true;
		return (bool) apply_filters( 'nkzmp/v1/storefront/abandoned_cart', $on );
	}

	public static function delay(): int {
		$h = class_exists( Settings::class ) ? (int) ( Settings::get()['abandoned_delay'] ?? 2 ) : 2;
		return max( 1, min( 72, $h ) ) * HOUR_IN_SECONDS;
	}

	public function init(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_action( self::CRON, [ $this, 'run' ] );
		add_action( 'init', [ $this, 'schedule' ], 30 );
		add_action( 'template_redirect', [ $this, 'maybe_restore' ], 5 );
		add_action( 'template_redirect', [ $this, 'maybe_unsubscribe' ], 5 );
		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_cart_updated', [ $this, 'capture_logged_in' ], 20 );
		add_action( 'wp_ajax_nkzmp_ac_capture', [ $this, 'ajax_capture' ] );
		add_action( 'wp_ajax_nopriv_nkzmp_ac_capture', [ $this, 'ajax_capture' ] );
		add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'checkout_note' ] );
		add_action( 'wp_footer', [ $this, 'checkout_script' ], 30 );
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order' ], 10, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', [ $this, 'on_order_block' ] );
	}

	public function register_cpt(): void {
		register_post_type( self::CPT, [
			'public'              => false,
			'show_ui'             => false,
			'exclude_from_search' => true,
			'supports'            => [ 'title' ],
			'label'               => 'Opuštěné košíky',
		] );
	}

	public function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON );
	}

	/* ============================================================ zachycení */

	/** Obsah košíku jako jednoduché položky. */
	private static function cart_items(): array {
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) {
			return [];
		}
		$out = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$out[] = [
				'product_id'   => (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => (int) ( $item['variation_id'] ?? 0 ),
				'quantity'     => (float) ( $item['quantity'] ?? 1 ),
				'variation'    => (array) ( $item['variation'] ?? [] ),
			];
		}
		return $out;
	}

	private static function find( string $email ): int {
		$ids = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_email', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $email,   // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	public static function opted_out( string $email ): bool {
		return in_array( strtolower( $email ), (array) get_option( self::OPTOUT, [] ), true );
	}

	/**
	 * Uloží / aktualizuje košík pro e-mail. Prázdný košík záznam smaže.
	 */
	public static function save( string $email, array $items, int $user_id = 0 ): int {
		$email = strtolower( trim( $email ) );
		if ( ! is_email( $email ) || self::opted_out( $email ) ) {
			return 0;
		}
		$id = self::find( $email );
		if ( ! $items ) {
			if ( $id ) {
				wp_delete_post( $id, true );
			}
			return 0;
		}
		$hash = md5( (string) wp_json_encode( $items ) );
		if ( ! $id ) {
			$id = (int) wp_insert_post( [
				'post_type'   => self::CPT,
				'post_status' => 'publish',
				'post_title'  => $email,
			] );
			if ( ! $id ) {
				return 0;
			}
			update_post_meta( $id, '_email', $email );
			update_post_meta( $id, '_token', wp_generate_password( 32, false ) );
		}
		$changed = get_post_meta( $id, '_hash', true ) !== $hash;
		update_post_meta( $id, '_items', $items );
		update_post_meta( $id, '_hash', $hash );
		update_post_meta( $id, '_user_id', $user_id );
		if ( $changed ) {
			// Nový obsah košíku = nová lhůta i nová (jedna) připomínka.
			update_post_meta( $id, '_updated', time() );
			delete_post_meta( $id, '_sent' );
		}
		return $id;
	}

	public function capture_logged_in(): void {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->ID || ! is_email( $user->user_email ) ) {
			return;
		}
		self::save( $user->user_email, self::cart_items(), (int) $user->ID );
	}

	public function ajax_capture(): void {
		check_ajax_referer( self::NONCE, 'nonce', false );
		$email = sanitize_email( wp_unslash( (string) ( $_POST['email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			wp_send_json_error();
		}
		self::save( $email, self::cart_items(), get_current_user_id() );
		wp_send_json_success();
	}

	/** Informace pod e-mailem v pokladně (transparentnost vůči zákazníkovi). */
	public function checkout_note(): void {
		echo '<p class="nkzmp-ac-note" style="margin:-4px 0 16px;font-size:12px;color:#6b7280;">' . esc_html__( 'Když nákup nedokončíš, můžeme ti jednou e-mailem připomenout, co máš v košíku. Z připomínky se jde jedním klikem odhlásit.', 'nkz-mp-storefront' ) . '</p>';
	}

	public function checkout_script(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
			return;
		}
		$cfg = [ 'ajax' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( self::NONCE ) ];
		?>
		<script>
		(function () {
			var C = <?php echo wp_json_encode( $cfg ); ?>, last = '';
			function send(v) {
				v = (v || '').trim();
				if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v) || v === last || !window.fetch) { return; }
				last = v;
				var b = new URLSearchParams(); b.set('action', 'nkzmp_ac_capture'); b.set('nonce', C.nonce); b.set('email', v);
				fetch(C.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: b.toString() }).catch(function () {});
			}
			// Klasická pokladna i bloková (Store API).
			document.addEventListener('change', function (e) {
				if (e.target.matches('input[name="billing_email"], input#email, input[type="email"][autocomplete="email"]')) { send(e.target.value); }
			}, true);
			var pre = document.querySelector('input[name="billing_email"], input#email');
			if (pre && pre.value) { send(pre.value); }
		})();
		</script>
		<?php
	}

	/* ============================================================ objednávka */

	/** @param int $order_id */
	public function on_order( $order_id, $data = [], $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			self::converted( $order );
		}
	}

	public function on_order_block( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::converted( $order );
		}
	}

	public static function converted( \WC_Order $order ): void {
		$email = strtolower( (string) $order->get_billing_email() );
		$id    = $email !== '' ? self::find( $email ) : 0;
		// Nákup přes odkaz z připomínky – pro přehled, kolik připomínky vrátí.
		$from = function_exists( 'WC' ) && WC() && WC()->session ? (string) WC()->session->get( 'nkzmp_ac_restored', '' ) : '';
		if ( $from !== '' ) {
			$order->update_meta_data( '_nkzmp_ac_recovered', 1 );
			$order->add_order_note( __( 'Objednávka po připomínce opuštěného košíku.', 'nkz-mp-storefront' ) );
			$order->save();
			update_option( 'nkzmp_ac_recovered', (int) get_option( 'nkzmp_ac_recovered', 0 ) + 1, false );
			WC()->session->set( 'nkzmp_ac_restored', '' );
		}
		if ( $id ) {
			wp_delete_post( $id, true );
		}
	}

	/* ============================================================ cron */

	public function run(): void {
		$now = time();
		$ids = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 200,
			'fields'         => 'ids',
		] );
		foreach ( $ids as $id ) {
			$updated = (int) get_post_meta( $id, '_updated', true );
			if ( $updated < $now - self::KEEP ) {
				wp_delete_post( $id, true );
				continue;
			}
			if ( ! self::enabled() || get_post_meta( $id, '_sent', true ) ) {
				continue;
			}
			if ( $updated > $now - self::delay() || $updated < $now - self::WINDOW ) {
				continue;
			}
			$email = (string) get_post_meta( $id, '_email', true );
			if ( self::opted_out( $email ) ) {
				wp_delete_post( $id, true );
				continue;
			}
			if ( self::send( (int) $id ) ) {
				update_post_meta( $id, '_sent', $now );
				update_option( 'nkzmp_ac_sent', (int) get_option( 'nkzmp_ac_sent', 0 ) + 1, false );
			}
		}
	}

	/* ============================================================ e-mail */

	private static function link( string $param, string $token ): string {
		return add_query_arg( [ $param => $token, 'utm_source' => 'email', 'utm_medium' => 'email', 'utm_campaign' => 'abandoned_cart' ], home_url( '/' ) );
	}

	public static function send( int $id ): bool {
		$email = (string) get_post_meta( $id, '_email', true );
		$token = (string) get_post_meta( $id, '_token', true );
		$items = (array) get_post_meta( $id, '_items', true );
		if ( ! is_email( $email ) || $token === '' || ! $items ) {
			return false;
		}
		$rows  = '';
		$count = 0;
		foreach ( $items as $it ) {
			$product = wc_get_product( (int) ( $it['variation_id'] ?: $it['product_id'] ) );
			if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue; // mezitím vyprodáno – nepřipomínat
			}
			++$count;
			$img   = wp_get_attachment_image_url( (int) $product->get_image_id(), 'woocommerce_thumbnail' );
			$rows .= '<tr><td style="padding:8px 12px 8px 0;width:72px;">' . ( $img ? '<img src="' . esc_url( $img ) . '" width="64" height="64" style="border-radius:8px;object-fit:cover;display:block;" alt="">' : '' ) . '</td>'
				. '<td style="padding:8px 0;font-size:15px;color:#111;">' . esc_html( $product->get_name() ) . ( (float) $it['quantity'] > 1 ? ' × ' . (int) $it['quantity'] : '' ) . '</td>'
				. '<td style="padding:8px 0;text-align:right;font-size:15px;color:#111;white-space:nowrap;">' . wp_kses_post( wc_price( wc_get_price_to_display( $product ) * (float) $it['quantity'] ) ) . '</td></tr>';
		}
		if ( $count === 0 ) {
			return false;
		}
		$site    = (string) get_bloginfo( 'name' );
		$subject = (string) apply_filters( 'nkzmp/v1/storefront/abandoned_subject', __( 'Něco jsi nechal/a v košíku', 'nkz-mp-storefront' ), $id );
		$heading = __( 'Tvůj košík na tebe čeká', 'nkz-mp-storefront' );
		$body    = '<p style="margin:0 0 16px;">' . esc_html__( 'Ahoj, všimli jsme si, že jsi nákup nedokončil/a. Produkty z ruční tvorby bývají v jednom kusu – kdyby ses k nim chtěl/a vrátit, tady jsou:', 'nkz-mp-storefront' ) . '</p>'
			. '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin:0 0 20px;">' . $rows . '</table>'
			. '<p style="margin:0 0 24px;"><a href="' . esc_url( self::link( 'nkzmp_restore', $token ) ) . '" style="display:inline-block;padding:14px 26px;border-radius:999px;background:#0060FF;color:#fff;text-decoration:none;font-weight:600;">' . esc_html__( 'Dokončit nákup', 'nkz-mp-storefront' ) . '</a></p>'
			. '<p style="margin:0;font-size:12px;color:#6b7280;">' . esc_html__( 'Tuhle připomínku posíláme jen jednou. Nechceš ji dostávat?', 'nkz-mp-storefront' ) . ' <a href="' . esc_url( add_query_arg( 'nkzmp_ac_unsub', $token, home_url( '/' ) ) ) . '" style="color:#6b7280;">' . esc_html__( 'Odhlásit připomínky', 'nkz-mp-storefront' ) . '</a></p>';

		$mailer = function_exists( 'WC' ) && WC() ? WC()->mailer() : null;
		$html   = $mailer ? $mailer->wrap_message( $heading, $body ) : $body;
		$headers = [ 'Content-Type: text/html; charset=UTF-8' ];
		return $mailer
			? (bool) $mailer->send( $email, $subject, $html, $headers )
			: (bool) wp_mail( $email, $subject . ' – ' . $site, $html, $headers );
	}

	/* ============================================================ odkazy */

	private static function by_token( string $token ): int {
		if ( strlen( $token ) < 20 ) {
			return 0;
		}
		$ids = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'meta_key'       => '_token', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $token,   // phpcs:ignore WordPress.DB.SlowDBQuery
		] );
		return $ids ? (int) $ids[0] : 0;
	}

	/** „Dokončit nákup" – obnoví košík a pošle do košíku. */
	public function maybe_restore(): void {
		if ( empty( $_GET['nkzmp_restore'] ) || ! function_exists( 'WC' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_text_field( wp_unslash( (string) $_GET['nkzmp_restore'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$id    = self::by_token( $token );
		$cart  = wc_get_cart_url();
		if ( $id && WC()->cart ) {
			if ( WC()->session && ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->cart->empty_cart();
			foreach ( (array) get_post_meta( $id, '_items', true ) as $it ) {
				WC()->cart->add_to_cart( (int) $it['product_id'], max( 1, (int) $it['quantity'] ), (int) $it['variation_id'], (array) $it['variation'] );
			}
			$email = (string) get_post_meta( $id, '_email', true );
			if ( WC()->customer && is_email( $email ) && ! WC()->customer->get_billing_email() ) {
				WC()->customer->set_billing_email( $email );
			}
			if ( WC()->session ) {
				WC()->session->set( 'nkzmp_ac_restored', $token );
			}
		}
		wp_safe_redirect( $cart );
		exit;
	}

	public function maybe_unsubscribe(): void {
		if ( empty( $_GET['nkzmp_ac_unsub'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$id = self::by_token( sanitize_text_field( wp_unslash( (string) $_GET['nkzmp_ac_unsub'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			$list   = (array) get_option( self::OPTOUT, [] );
			$list[] = strtolower( (string) get_post_meta( $id, '_email', true ) );
			update_option( self::OPTOUT, array_values( array_unique( $list ) ), false );
			wp_delete_post( $id, true );
		}
		wp_die(
			esc_html__( 'Hotovo – připomínky košíku ti už posílat nebudeme.', 'nkz-mp-storefront' ),
			esc_html__( 'Odhlášeno', 'nkz-mp-storefront' ),
			[ 'response' => 200, 'link_url' => home_url( '/' ), 'link_text' => __( 'Zpět do obchodu', 'nkz-mp-storefront' ) ]
		);
	}
}
