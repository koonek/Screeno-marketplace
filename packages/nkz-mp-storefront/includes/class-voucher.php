<?php
/**
 * Dárkové poukazy (vouchery) Art of život.
 *
 * Model podle klienta:
 *  - platforma prodává poukaz (např. 500 / 1 000 / 1 500 Kč); peníze jdou
 *    na její Stripe účet – účetně je to závazek, ne tržba,
 *  - zákazník kód uplatní v košíku; poukaz se odečte z CELÉ částky
 *    (zboží + poštovné + servisní poplatek),
 *  - je jednorázový – když nákup vyjde levněji, zbytek propadá,
 *  - platí 12 měsíců, pak propadá,
 *  - prodejce dostane jako vždy plnou cenu zboží minus 11 % provize.
 *
 * Proč záporný poplatek a ne kupón WooCommerce:
 * Rozdělování peněz počítá prodejcům podíl z položek produktů. Kupón by
 * slevu rozpustil do položek a prodejce by dostal provizi ze snížené ceny.
 * Záporný poplatek položky nechá na pokoji – prodejcův podíl se nezmění
 * a o poukaz méně zaplatí jen zákazník. Peníze, které tím na objednávce
 * „chybí", leží na účtu platformy z prodeje poukazu. Navíc kupón
 * WooCommerce neumí odečíst poštovné ani poplatky, což klient chce.
 *
 * Dvojí utracení: při vytvoření objednávky se poukaz zarezervuje, po
 * zaplacení spotřebuje, při zrušení / neúspěšné platbě uvolní. Rezervace
 * starší než hodina u nezaplacené objednávky se bere jako opuštěná.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Voucher {

	public const CPT = 'nkzmp_voucher';

	/** Příznak produktu „tohle je dárkový poukaz". */
	public const PRODUCT_META = '_nkzmp_is_voucher';

	public const SESSION_KEY = 'nkzmp_voucher_code';

	/** Na objednávce, kde byl poukaz uplatněn. */
	public const ORDER_CODE_META   = '_nkzmp_voucher_code';
	public const ORDER_AMOUNT_META = '_nkzmp_voucher_amount';

	/** Na položce nákupu poukazu – vydané kódy (interní). */
	public const ITEM_CODES_META = '_nkzmp_voucher_codes';

	public const FEE_ID = 'nkzmp-voucher';

	public const STATUS_ACTIVE   = 'active';
	public const STATUS_RESERVED = 'reserved';
	public const STATUS_USED     = 'used';
	public const STATUS_VOID     = 'void';

	/** Jak dlouho držíme rezervaci u nezaplacené objednávky. */
	private const RESERVATION_TTL = HOUR_IN_SECONDS;

	private static ?Voucher $instance = null;

	public static function instance(): Voucher {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'init', [ $this, 'register_cpt' ] );

		// Admin: produkt jako poukaz, přehled poukazů.
		add_action( 'woocommerce_product_options_general_product_data', [ $this, 'product_field' ] );
		add_action( 'woocommerce_admin_process_product_object', [ $this, 'save_product_field' ] );
		add_filter( 'manage_' . self::CPT . '_posts_columns', [ $this, 'admin_columns' ] );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', [ $this, 'admin_column' ], 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_order_panel' ], 40 );
		add_action( 'admin_post_nkzmp_voucher_set_status', [ $this, 'handle_set_status' ] );

		// Košík / pokladna.
		add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fee' ], 999 );
		add_action( 'woocommerce_before_cart_totals', [ $this, 'render_field' ] );
		add_action( 'woocommerce_review_order_before_payment', [ $this, 'render_field' ] );
		add_action( 'wp_ajax_nkzmp_voucher', [ $this, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_nkzmp_voucher', [ $this, 'ajax' ] );
		add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );

		// Životní cyklus objednávky.
		add_action( 'woocommerce_checkout_create_order', [ $this, 'attach_to_order' ], 20, 2 );
		add_action( 'woocommerce_checkout_order_created', [ $this, 'reserve' ] );
		// Priorita 1 = dřív než e-maily WooCommerce (10), ať jsou kódy v e-mailu.
		add_action( 'woocommerce_order_status_processing', [ $this, 'on_paid' ], 1 );
		add_action( 'woocommerce_order_status_completed', [ $this, 'on_paid' ], 1 );
		add_action( 'woocommerce_order_status_cancelled', [ $this, 'on_failed' ] );
		add_action( 'woocommerce_order_status_failed', [ $this, 'on_failed' ] );
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_codes' ], 25, 4 );

		// Rozdělování peněz prodejcům (Stripe modul).
		add_filter( 'nkv_svs_filter_split_non_stripe_order', [ $this, 'split_voucher_only_order' ], 10, 2 );
		add_filter( 'nkv_svs_filter_use_source_transaction', [ $this, 'use_source_transaction' ], 10, 2 );

		// Informace o podmínkách poukazu při NÁKUPU (právník: zákazník musí
		// vědět dopředu – nejen v obchodních podmínkách, ale viditelně při
		// nákupu i na poukazu samotném).
		add_action( 'woocommerce_single_product_summary', [ $this, 'product_terms' ], 25 );
		add_filter( 'woocommerce_get_item_data', [ $this, 'cart_item_terms' ], 10, 2 );
		add_action( 'woocommerce_review_order_before_submit', [ $this, 'checkout_ack' ], 5 );
		add_action( 'woocommerce_checkout_process', [ $this, 'validate_ack' ] );
		add_action( 'woocommerce_checkout_create_order', [ $this, 'save_ack' ], 25, 2 );

		// Kolik peněz za poukazy musí zůstat na Stripe.
		add_action( 'admin_notices', [ $this, 'liability_notice' ] );
		add_filter( 'nkzmp/v1/admin/health_checks', [ $this, 'health_row' ] );
	}

	/* ===================================================== podmínky poukazu */

	public static function valid_months(): int {
		return max( 1, (int) apply_filters( 'nkzmp/v1/voucher/valid_months', 12 ) );
	}

	/** Podmínky poukazu jedním textem (produkt, košík, pokladna, e-mail). */
	public static function terms_text(): string {
		return sprintf(
			/* translators: %d: počet měsíců */
			__( 'Poukaz platí %d měsíců od zakoupení. Uplatňuje se jednorázově na celý nákup u kteréhokoli tvůrce, včetně poštovného. Nevyčerpaný zůstatek propadá a nevrací se.', 'nkz-mp-storefront' ),
			self::valid_months()
		);
	}

	public function product_terms(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || ! self::is_voucher_product( $product ) ) {
			return;
		}
		echo '<div class="nkzmp-voucher-terms" style="margin:0 0 18px;padding:14px 16px;border-radius:14px;background:#f2f6ff;border:1px solid #d6e2ff;font-size:14px;line-height:1.5;color:#1f2937;">';
		echo '<strong style="display:block;margin:0 0 6px;">' . esc_html__( 'Jak poukaz funguje', 'nkz-mp-storefront' ) . '</strong>';
		echo '<ul style="margin:0;padding-left:18px;">';
		/* translators: %d: počet měsíců */
		echo '<li>' . esc_html( sprintf( __( 'Platí %d měsíců od zakoupení.', 'nkz-mp-storefront' ), self::valid_months() ) ) . '</li>';
		echo '<li>' . esc_html__( 'Uplatní se na celý nákup u kteréhokoli tvůrce, včetně poštovného.', 'nkz-mp-storefront' ) . '</li>';
		echo '<li><strong>' . esc_html__( 'Je jednorázový – nevyčerpaný zůstatek propadá a nevrací se.', 'nkz-mp-storefront' ) . '</strong></li>';
		echo '</ul></div>';
	}

	/** @param array $data @param array $item */
	public function cart_item_terms( $data, $item ): array {
		$data    = (array) $data;
		$product = $item['data'] ?? null;
		if ( $product instanceof \WC_Product && self::is_voucher_product( $product ) ) {
			$data[] = [
				'key'   => __( 'Podmínky poukazu', 'nkz-mp-storefront' ),
				/* translators: %d: počet měsíců */
				'value' => sprintf( __( 'platí %d měsíců, jednorázový – nevyčerpaný zůstatek propadá', 'nkz-mp-storefront' ), self::valid_months() ),
			];
		}
		return $data;
	}

	/** Povinné potvrzení podmínek v pokladně, když se kupuje poukaz. */
	public function checkout_ack(): void {
		if ( ! self::cart_has_voucher_product() ) {
			return;
		}
		echo '<p class="form-row validate-required nkzmp-voucher-ack" style="margin:0 0 14px;"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display:flex;gap:8px;align-items:flex-start;font-size:14px;line-height:1.45;">'
			. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="nkzmp_voucher_ack" value="1" style="margin-top:3px;" /> '
			. '<span>' . esc_html__( 'Beru na vědomí podmínky dárkového poukazu:', 'nkz-mp-storefront' ) . ' ' . esc_html( self::terms_text() ) . ' <abbr class="required" title="' . esc_attr__( 'povinné', 'nkz-mp-storefront' ) . '">*</abbr></span>'
			. '</label></p>';
	}

	public function validate_ack(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce ověřuje WooCommerce pokladna.
		if ( self::cart_has_voucher_product() && empty( $_POST['nkzmp_voucher_ack'] ) ) {
			wc_add_notice( __( 'Potvrď prosím, že bereš na vědomí podmínky dárkového poukazu (platnost a propadnutí nevyčerpaného zůstatku).', 'nkz-mp-storefront' ), 'error' );
		}
	}

	/** Důkaz o potvrzení podmínek u objednávky. */
	public function save_ack( $order, $data ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $order instanceof \WC_Order || empty( $_POST['nkzmp_voucher_ack'] ) ) {
			return;
		}
		$order->update_meta_data( '_nkzmp_voucher_terms_ack', [ 'at' => time(), 'text' => self::terms_text() ] );
		$order->add_order_note( __( 'Zákazník v pokladně potvrdil podmínky dárkového poukazu:', 'nkz-mp-storefront' ) . ' ' . self::terms_text() );
	}

	/**
	 * Nevyčerpané platné poukazy – závazek platformy.
	 *
	 * Peníze za prodané poukazy leží na Stripe účtu a platí se z nich
	 * prodejcům, když zákazník poukaz uplatní. Když je automatické výplaty
	 * Stripe pošlou na banku, převody prodejcům selžou. Tohle číslo říká,
	 * kolik tam musí zůstat. Propadlé poukazy se nepočítají – ty peníze
	 * už platformě patří.
	 *
	 * @return array{count:int,total:float}
	 */
	public static function liability(): array {
		$ids = get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => '_status', 'value' => [ self::STATUS_ACTIVE, self::STATUS_RESERVED ], 'compare' => 'IN' ] ],
		] );
		$count = 0;
		$total = 0.0;
		$now   = time();
		foreach ( $ids as $id ) {
			$status = (string) ( get_post_meta( (int) $id, '_status', true ) ?: self::STATUS_ACTIVE );
			if ( ! in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_RESERVED ], true ) ) {
				continue; // použitý / zneplatněný
			}
			$exp = (int) get_post_meta( (int) $id, '_expires', true );
			if ( $exp > 0 && $exp < $now ) {
				continue; // propadlý
			}
			++$count;
			$total += (float) get_post_meta( (int) $id, '_value', true );
		}
		return [ 'count' => $count, 'total' => $total ];
	}

	public function liability_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'edit-' . self::CPT ) {
			return; // jen na seznamu poukazů
		}
		$l = self::liability();
		printf(
			'<div class="notice notice-info"><p><strong>%s</strong> %s<br>%s</p></div>',
			esc_html( sprintf(
				/* translators: 1: počet, 2: částka */
				__( 'Nevyčerpané poukazy: %1$d ks, celkem %2$s.', 'nkz-mp-storefront' ),
				$l['count'],
				wp_strip_all_tags( wc_price( $l['total'] ) )
			) ),
			esc_html__( 'Tolik musí zůstat na Stripe účtu – z těchto peněz se platí prodejcům, když zákazník poukaz uplatní.', 'nkz-mp-storefront' ),
			esc_html__( 'Pokud máš ve Stripe automatické výplaty na banku, přepni je na ruční, nebo tam drž rezervu alespoň v této výši. Propadlé poukazy se nepočítají.', 'nkz-mp-storefront' )
		);
	}

	/**
	 * @param array<int,array{label:string,state:string,detail:string}> $rows
	 */
	public function health_row( $rows ): array {
		$rows = (array) $rows;
		$l    = self::liability();
		if ( $l['count'] === 0 ) {
			return $rows;
		}
		$rows[] = [
			'label'  => __( 'Nevyčerpané dárkové poukazy', 'nkz-mp-storefront' ),
			'state'  => 'warn',
			'detail' => sprintf(
				/* translators: 1: počet, 2: částka */
				__( '%1$d ks za %2$s – tolik musí zůstat na Stripe účtu', 'nkz-mp-storefront' ),
				$l['count'],
				wp_strip_all_tags( wc_price( $l['total'] ) )
			),
		];
		return $rows;
	}

	/* ================================================================ data */

	public function register_cpt(): void {
		register_post_type( self::CPT, [
			'labels'          => [
				'name'          => __( 'Dárkové poukazy', 'nkz-mp-storefront' ),
				'singular_name' => __( 'Dárkový poukaz', 'nkz-mp-storefront' ),
				'menu_name'     => __( 'Poukazy', 'nkz-mp-storefront' ),
				'search_items'  => __( 'Hledat kód', 'nkz-mp-storefront' ),
				'not_found'     => __( 'Zatím žádné poukazy.', 'nkz-mp-storefront' ),
			],
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => defined( 'NKZMP_ADMIN_MENU_SLUG' ) ? NKZMP_ADMIN_MENU_SLUG : 'woocommerce',
			'supports'        => [ 'title' ],
			'capability_type' => 'post',
			'capabilities'    => [ 'create_posts' => 'do_not_allow' ], // vznikají jen nákupem
			'map_meta_cap'    => true,
		] );
	}

	/** Normalizace kódu z formuláře. */
	public static function normalize( string $code ): string {
		return strtoupper( preg_replace( '/[^A-Za-z0-9-]/', '', trim( $code ) ) ?? '' );
	}

	/** Poukaz podle kódu, nebo null. */
	public static function find( string $code ): ?\WP_Post {
		$code = self::normalize( $code );
		if ( $code === '' ) {
			return null;
		}
		$posts = get_posts( [
			'post_type'      => self::CPT,
			'title'          => $code,
			'post_status'    => 'any',
			'posts_per_page' => 1,
		] );
		return $posts ? $posts[0] : null;
	}

	/** @return array{code:string,value:float,expires:int,status:string,purchase_order:int,redeem_order:int,reserved_at:int} */
	public static function data( \WP_Post $v ): array {
		return [
			'code'           => (string) $v->post_title,
			'value'          => (float) get_post_meta( $v->ID, '_value', true ),
			'expires'        => (int) get_post_meta( $v->ID, '_expires', true ),
			'status'         => (string) ( get_post_meta( $v->ID, '_status', true ) ?: self::STATUS_ACTIVE ),
			'purchase_order' => (int) get_post_meta( $v->ID, '_purchase_order', true ),
			'redeem_order'   => (int) get_post_meta( $v->ID, '_redeem_order', true ),
			'reserved_at'    => (int) get_post_meta( $v->ID, '_reserved_at', true ),
		];
	}

	private static function set_status( int $id, string $status, array $extra = [] ): void {
		update_post_meta( $id, '_status', $status );
		foreach ( $extra as $k => $val ) {
			update_post_meta( $id, $k, $val );
		}
	}

	/**
	 * Dá se poukaz právě teď uplatnit? Vrací '' když ano, jinak důvod.
	 *
	 * @param int $for_order Objednávka, které rezervace patří (její vlastní
	 *                       rezervace ji neblokuje).
	 */
	public static function unavailable_reason( ?\WP_Post $v, int $for_order = 0 ): string {
		if ( ! $v ) {
			return __( 'Tenhle kód neznáme. Zkontroluj prosím, že je opsaný správně.', 'nkz-mp-storefront' );
		}
		$d = self::data( $v );
		if ( $d['status'] === self::STATUS_USED ) {
			return __( 'Tento poukaz už byl použitý.', 'nkz-mp-storefront' );
		}
		if ( $d['status'] === self::STATUS_VOID ) {
			return __( 'Tento poukaz byl zneplatněn.', 'nkz-mp-storefront' );
		}
		if ( $d['expires'] > 0 && time() > $d['expires'] ) {
			return sprintf(
				/* translators: %s: datum */
				__( 'Platnost poukazu skončila %s.', 'nkz-mp-storefront' ),
				wp_date( 'j. n. Y', $d['expires'] )
			);
		}
		if ( $d['status'] === self::STATUS_RESERVED && $d['redeem_order'] !== $for_order && ! self::reservation_stale( $d ) ) {
			return __( 'Tento poukaz se právě používá v jiné objednávce.', 'nkz-mp-storefront' );
		}
		if ( $d['value'] <= 0 ) {
			return __( 'Poukaz nemá žádnou hodnotu.', 'nkz-mp-storefront' );
		}
		return '';
	}

	/** Rezervace patří objednávce, která už zaplacená nebude. */
	private static function reservation_stale( array $d ): bool {
		$order = $d['redeem_order'] > 0 ? wc_get_order( $d['redeem_order'] ) : null;
		if ( ! $order instanceof \WC_Order ) {
			return true;
		}
		if ( $order->has_status( [ 'cancelled', 'failed' ] ) ) {
			return true;
		}
		if ( $order->has_status( 'pending' ) && time() - $d['reserved_at'] > self::RESERVATION_TTL ) {
			return true;
		}
		return false;
	}

	/** Vygeneruje unikátní kód, např. AOZ-K7P3-M9QX. */
	private static function generate_code(): string {
		$alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // bez 0/O, 1/I/L – špatně se čtou
		$prefix   = (string) apply_filters( 'nkzmp/v1/voucher/code_prefix', 'AOZ' );
		do {
			$parts = [];
			for ( $p = 0; $p < 2; $p++ ) {
				$s = '';
				for ( $i = 0; $i < 4; $i++ ) {
					$s .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
				}
				$parts[] = $s;
			}
			$code = $prefix . '-' . implode( '-', $parts );
		} while ( self::find( $code ) );
		return $code;
	}

	/* ============================================================== košík */

	/** Je v košíku poukaz? Poukaz poukazem platit nejde. */
	private static function cart_has_voucher_product(): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$p = $item['data'] ?? null;
			if ( $p instanceof \WC_Product && self::is_voucher_product( $p ) ) {
				return true;
			}
		}
		return false;
	}

	public static function is_voucher_product( \WC_Product $p ): bool {
		$pid = $p->get_parent_id() ?: $p->get_id();
		return get_post_meta( $pid, self::PRODUCT_META, true ) === 'yes';
	}

	private static function session_code(): string {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return '';
		}
		return (string) WC()->session->get( self::SESSION_KEY, '' );
	}

	private static function set_session_code( string $code ): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_KEY, $code === '' ? null : $code );
		}
	}

	/**
	 * Odečte poukaz jako záporný poplatek z celé částky košíku.
	 *
	 * Běží jako poslední (priorita 999), aby už byly známé poštovné
	 * i servisní poplatek.
	 */
	public function apply_fee( \WC_Cart $cart ): void {
		$code = self::session_code();
		if ( $code === '' ) {
			return;
		}
		$v = self::find( $code );
		if ( self::unavailable_reason( $v ) !== '' || self::cart_has_voucher_product() ) {
			self::set_session_code( '' );
			return;
		}

		$total = (float) $cart->get_cart_contents_total() + (float) $cart->get_cart_contents_tax()
			+ (float) $cart->get_shipping_total() + (float) $cart->get_shipping_tax();
		foreach ( $cart->fees_api()->get_fees() as $fee ) {
			if ( ( $fee->id ?? '' ) !== self::FEE_ID ) {
				$total += (float) $fee->amount;
			}
		}
		$discount = min( self::data( $v )['value'], max( 0.0, $total ) );
		if ( $discount <= 0 ) {
			return;
		}

		$cart->fees_api()->add_fee( [
			'id'      => self::FEE_ID,
			/* translators: %s: kód poukazu */
			'name'    => sprintf( __( 'Dárkový poukaz %s', 'nkz-mp-storefront' ), self::data( $v )['code'] ),
			'amount'  => -1 * round( $discount, wc_get_price_decimals() ),
			'taxable' => false,
		] );
	}

	public function render_field(): void {
		if ( self::cart_has_voucher_product() ) {
			return; // poukaz poukazem nekupujeme
		}
		static $printed = 0;
		++$printed;
		$code  = self::session_code();
		$nonce = wp_create_nonce( 'nkzmp_voucher' );
		$id    = 'nkzmp-voucher-' . $printed;
		?>
		<div class="nkzmp-voucher" id="<?php echo esc_attr( $id ); ?>" style="margin:0 0 16px;">
			<?php if ( $code !== '' ) : ?>
				<p style="margin:0;">
					<?php echo esc_html( sprintf( /* translators: %s: kód */ __( 'Uplatněn dárkový poukaz %s.', 'nkz-mp-storefront' ), $code ) ); ?>
					<a href="#" data-nkzmp-voucher-remove style="margin-left:6px;"><?php esc_html_e( 'Odebrat', 'nkz-mp-storefront' ); ?></a>
				</p>
			<?php else : ?>
				<details>
					<summary style="cursor:pointer;color:#0060FF;font-weight:600;"><?php esc_html_e( 'Máte dárkový poukaz?', 'nkz-mp-storefront' ); ?></summary>
					<div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap;">
						<input type="text" data-nkzmp-voucher-code placeholder="AOZ-XXXX-XXXX" autocomplete="off" style="flex:1 1 180px;min-width:0;text-transform:uppercase;" />
						<button type="button" class="button" data-nkzmp-voucher-apply><?php esc_html_e( 'Uplatnit', 'nkz-mp-storefront' ); ?></button>
					</div>
					<p style="margin:6px 0 0;font-size:12px;color:#6b7280;"><?php esc_html_e( 'Poukaz se odečte z celé částky včetně poštovného. Je jednorázový – nevyčerpaná částka propadá.', 'nkz-mp-storefront' ); ?></p>
				</details>
			<?php endif; ?>
			<p data-nkzmp-voucher-msg style="margin:6px 0 0;font-size:13px;" hidden></p>
		</div>
		<script>
		(function () {
			var box = document.getElementById(<?php echo wp_json_encode( $id ); ?>);
			if (!box) { return; }
			var msg = box.querySelector('[data-nkzmp-voucher-msg]');
			function send(op, code) {
				var body = new FormData();
				body.append('action', 'nkzmp_voucher');
				body.append('op', op);
				body.append('code', code || '');
				body.append('_ajax_nonce', <?php echo wp_json_encode( $nonce ); ?>);
				return fetch(<?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: body, credentials: 'same-origin' })
					.then(function (r) { return r.json(); });
			}
			function refresh() {
				// Pokladna se přepočítá sama, košík obnovíme.
				if (window.jQuery && document.querySelector('form.checkout')) {
					window.jQuery(document.body).trigger('update_checkout');
				} else {
					window.location.reload();
				}
			}
			box.addEventListener('click', function (e) {
				var apply = e.target.closest('[data-nkzmp-voucher-apply]');
				var remove = e.target.closest('[data-nkzmp-voucher-remove]');
				if (!apply && !remove) { return; }
				e.preventDefault();
				var input = box.querySelector('[data-nkzmp-voucher-code]');
				send(apply ? 'apply' : 'remove', input ? input.value : '').then(function (res) {
					msg.hidden = false;
					msg.style.color = res.success ? '#1a7f37' : '#b00020';
					msg.textContent = (res.data && res.data.message) || '';
					if (res.success) { setTimeout(refresh, 300); }
				}).catch(function () {
					msg.hidden = false;
					msg.style.color = '#b00020';
					msg.textContent = <?php echo wp_json_encode( __( 'Něco se nepovedlo, zkus to prosím znovu.', 'nkz-mp-storefront' ) ); ?>;
				});
			});
			box.addEventListener('keydown', function (e) {
				if (e.key === 'Enter' && e.target.matches('[data-nkzmp-voucher-code]')) {
					e.preventDefault();
					box.querySelector('[data-nkzmp-voucher-apply]').click();
				}
			});
		})();
		</script>
		<?php
	}

	public function ajax(): void {
		check_ajax_referer( 'nkzmp_voucher' );
		$op = sanitize_key( (string) ( $_POST['op'] ?? '' ) );

		if ( $op === 'remove' ) {
			self::set_session_code( '' );
			wp_send_json_success( [ 'message' => __( 'Poukaz odebrán.', 'nkz-mp-storefront' ) ] );
		}

		// Jednoduchá brzda proti hádání kódů.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'nkzmp_voucher_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 15 ) {
			wp_send_json_error( [ 'message' => __( 'Příliš mnoho pokusů. Zkus to prosím za pár minut.', 'nkz-mp-storefront' ) ] );
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );

		if ( self::cart_has_voucher_product() ) {
			wp_send_json_error( [ 'message' => __( 'Dárkový poukaz nejde zaplatit jiným poukazem.', 'nkz-mp-storefront' ) ] );
		}
		$code   = self::normalize( wp_unslash( (string) ( $_POST['code'] ?? '' ) ) );
		$v      = self::find( $code );
		$reason = self::unavailable_reason( $v );
		if ( $reason !== '' ) {
			wp_send_json_error( [ 'message' => $reason ] );
		}
		self::set_session_code( $code );
		$d = self::data( $v );
		wp_send_json_success( [
			'message' => sprintf(
				/* translators: 1: hodnota, 2: datum */
				__( 'Poukaz v hodnotě %1$s uplatněn (platí do %2$s).', 'nkz-mp-storefront' ),
				wp_strip_all_tags( wc_price( $d['value'] ) ),
				$d['expires'] > 0 ? wp_date( 'j. n. Y', $d['expires'] ) : '—'
			),
		] );
	}

	/**
	 * Poslední kontrola před vytvořením objednávky – poukaz mohl mezitím
	 * utratit někdo jiný.
	 *
	 * @param array     $data
	 * @param \WP_Error $errors
	 */
	public function validate_checkout( $data, $errors ): void {
		$code = self::session_code();
		if ( $code === '' || ! $errors instanceof \WP_Error ) {
			return;
		}
		$reason = self::unavailable_reason( self::find( $code ) );
		if ( $reason !== '' ) {
			self::set_session_code( '' );
			$errors->add( 'nkzmp_voucher', $reason . ' ' . __( 'Poukaz jsme z objednávky odebrali, zkontroluj prosím novou částku.', 'nkz-mp-storefront' ) );
		}
	}

	/* =================================================== životní cyklus */

	/**
	 * Zapíše uplatněný poukaz do objednávky.
	 *
	 * @param \WC_Order $order
	 * @param array     $data
	 */
	public function attach_to_order( $order, $data ): void {
		$code = self::session_code();
		if ( $code === '' || ! $order instanceof \WC_Order ) {
			return;
		}
		$amount = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			if ( str_contains( (string) $fee->get_name(), $code ) ) {
				$amount += abs( (float) $fee->get_total() );
			}
		}
		if ( $amount > 0 ) {
			$order->update_meta_data( self::ORDER_CODE_META, $code );
			$order->update_meta_data( self::ORDER_AMOUNT_META, $amount );
		}
	}

	/** Objednávka vytvořena → rezervuj poukaz, ať ho nikdo neutratí podruhé. */
	public function reserve( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$code = (string) $order->get_meta( self::ORDER_CODE_META );
		$v    = $code !== '' ? self::find( $code ) : null;
		if ( ! $v ) {
			return;
		}
		self::set_status( $v->ID, self::STATUS_RESERVED, [
			'_redeem_order' => $order->get_id(),
			'_reserved_at'  => time(),
		] );
	}

	/** Zaplaceno → spotřebuj uplatněný poukaz a vydej koupené poukazy. */
	public function on_paid( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// 1) Spotřebovat uplatněný poukaz.
		$code = (string) $order->get_meta( self::ORDER_CODE_META );
		if ( $code !== '' ) {
			$v = self::find( $code );
			if ( $v && self::data( $v )['status'] !== self::STATUS_USED ) {
				self::set_status( $v->ID, self::STATUS_USED, [
					'_redeem_order' => $order->get_id(),
					'_used_at'      => time(),
				] );
				$order->add_order_note( sprintf(
					/* translators: 1: kód, 2: částka */
					__( 'Uplatněn dárkový poukaz %1$s (%2$s). Zbytek hodnoty propadl.', 'nkz-mp-storefront' ),
					$code,
					wp_strip_all_tags( wc_price( (float) $order->get_meta( self::ORDER_AMOUNT_META ) ) )
				) );
			}
			if ( function_exists( 'WC' ) && WC()->session ) {
				self::set_session_code( '' );
			}
		}

		// 2) Vydat koupené poukazy (idempotentně – kódy se uloží na položku).
		$months = (int) apply_filters( 'nkzmp/v1/voucher/valid_months', 12 );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product = $item->get_product();
			if ( ! $product || ! self::is_voucher_product( $product ) ) {
				continue;
			}
			if ( $item->get_meta( self::ITEM_CODES_META ) ) {
				continue; // už vydáno
			}
			$qty   = max( 1, (int) $item->get_quantity() );
			// Hodnota = cena, kterou zákazník za kus skutečně zaplatil.
			$value = round( (float) $item->get_subtotal() / $qty, wc_get_price_decimals() );
			$exp   = strtotime( '+' . $months . ' months', time() );
			$codes = [];
			for ( $i = 0; $i < $qty; $i++ ) {
				$code = self::generate_code();
				$id   = wp_insert_post( [
					'post_type'   => self::CPT,
					'post_status' => 'publish',
					'post_title'  => $code,
				] );
				if ( ! $id || is_wp_error( $id ) ) {
					continue;
				}
				update_post_meta( $id, '_value', $value );
				update_post_meta( $id, '_expires', $exp );
				update_post_meta( $id, '_status', self::STATUS_ACTIVE );
				update_post_meta( $id, '_purchase_order', $order->get_id() );
				update_post_meta( $id, '_buyer_email', (string) $order->get_billing_email() );
				$codes[] = $code;
			}
			if ( $codes ) {
				$item->update_meta_data( self::ITEM_CODES_META, $codes );
				// Viditelná meta – ukáže se v e-mailu, na děkovací stránce i v účtu.
				$item->update_meta_data( __( 'Kód poukazu', 'nkz-mp-storefront' ), implode( ', ', $codes ) );
				$item->update_meta_data( __( 'Platnost do', 'nkz-mp-storefront' ), wp_date( 'j. n. Y', $exp ) );
				$item->save();
				$order->add_order_note( sprintf(
					/* translators: %s: kódy */
					__( 'Vydány dárkové poukazy: %s', 'nkz-mp-storefront' ),
					implode( ', ', $codes )
				) );
			}
		}
	}

	/**
	 * Vrácení peněz poukazem – část objednávky zaplacená poukazem.
	 *
	 * Spotřebitel dostává peníze zpět stejným prostředkem, jakým platil.
	 * Nový poukaz má platnost původního (když ho známe a ještě neprošel),
	 * jinak standardních 12 měsíců. Zákazníkovi přijde kód e-mailem.
	 *
	 * @return string vydaný kód ('' při chybě)
	 */
	public static function issue_credit( \WC_Order $order, float $amount ): string {
		if ( $amount <= 0 ) {
			return '';
		}
		$exp  = strtotime( '+' . (int) apply_filters( 'nkzmp/v1/voucher/valid_months', 12 ) . ' months' );
		$orig = (string) $order->get_meta( self::ORDER_CODE_META );
		if ( $orig !== '' && ( $ov = self::find( $orig ) ) ) {
			$oe = self::data( $ov )['expires'];
			if ( $oe > time() ) {
				$exp = $oe;
			}
		}
		$code = self::generate_code();
		$id   = wp_insert_post( [ 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $code ] );
		if ( ! $id || is_wp_error( $id ) ) {
			return '';
		}
		update_post_meta( $id, '_value', round( $amount, wc_get_price_decimals() ) );
		update_post_meta( $id, '_expires', $exp );
		update_post_meta( $id, '_status', self::STATUS_ACTIVE );
		update_post_meta( $id, '_credit_for_order', $order->get_id() );
		update_post_meta( $id, '_buyer_email', (string) $order->get_billing_email() );

		$to = (string) $order->get_billing_email();
		if ( is_email( $to ) ) {
			$subject = sprintf( __( 'Vrácení peněz poukazem – objednávka #%s', 'nkz-mp-storefront' ), $order->get_order_number() );
			$body    = sprintf(
				/* translators: 1: částka, 2: kód, 3: datum, 4: web */
				__( "Dobrý den,\n\nčást objednávky jste platili dárkovým poukazem, proto vám tuto část vracíme stejnou cestou – novým poukazem.\n\nHodnota: %1\$s\nKód: %2\$s\nPlatí do: %3\$s\n\nKód zadáte v košíku do pole „Máte dárkový poukaz?\". Poukaz je jednorázový – nevyčerpaný zůstatek propadá a nevrací se.\n\n%4\$s", 'nkz-mp-storefront' ),
				wp_strip_all_tags( wc_price( $amount ) ),
				$code,
				wp_date( 'j. n. Y', $exp ),
				(string) get_bloginfo( 'name' )
			);
			if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
				\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			} else {
				wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
			}
		}
		return $code;
	}

	/** Zrušená / neúspěšná objednávka → uvolni rezervovaný poukaz. */
	public function on_failed( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$code = (string) $order->get_meta( self::ORDER_CODE_META );
		$v    = $code !== '' ? self::find( $code ) : null;
		if ( ! $v ) {
			return;
		}
		$d = self::data( $v );
		if ( $d['status'] === self::STATUS_RESERVED && $d['redeem_order'] === $order->get_id() ) {
			self::set_status( $v->ID, self::STATUS_ACTIVE, [ '_redeem_order' => 0, '_reserved_at' => 0 ] );
			$order->add_order_note( sprintf( __( 'Dárkový poukaz %s uvolněn (objednávka nezaplacena).', 'nkz-mp-storefront' ), $code ) );
		}
	}

	/**
	 * Kódy koupených poukazů zvlášť v e-mailu zákazníkovi – výrazněji než
	 * v řádku položky.
	 */
	public function email_codes( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}
		// Kódy bereme z poukazů podle objednávky, NE z položek objednávky.
		// E-mail dostává instanci objednávky, která si položky načetla dřív,
		// než jsme kódy vygenerovali (on_paid běží těsně před e-mailem) –
		// z položek by kódy v prvním e-mailu chyběly.
		$rows = [];
		foreach ( get_posts( [
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'posts_per_page' => 50,
			'meta_key'       => '_purchase_order',
			'meta_value'     => $order->get_id(),
			'orderby'        => 'ID',
			'order'          => 'ASC',
		] ) as $v ) {
			$rows[] = self::data( $v );
		}
		if ( ! $rows ) {
			return;
		}
		if ( $plain_text ) {
			echo "\n" . esc_html__( 'VAŠE DÁRKOVÉ POUKAZY', 'nkz-mp-storefront' ) . "\n";
			foreach ( $rows as $r ) {
				echo esc_html( sprintf( '%s — %s — platí do %s', $r['code'], wp_strip_all_tags( wc_price( $r['value'] ) ), wp_date( 'j. n. Y', $r['expires'] ) ) ) . "\n";
			}
			echo esc_html__( 'Kód zadejte v košíku do pole „Máte dárkový poukaz?". Odečte se z celé částky včetně poštovného. POUKAZ JE JEDNORÁZOVÝ – NEVYČERPANÝ ZŮSTATEK PROPADÁ A NEVRACÍ SE.', 'nkz-mp-storefront' ) . "\n";
			return;
		}
		echo '<h2 style="margin-top:24px;">' . esc_html__( 'Vaše dárkové poukazy', 'nkz-mp-storefront' ) . '</h2>';
		foreach ( $rows as $r ) {
			printf(
				'<div style="border:2px dashed #0060FF;border-radius:12px;padding:14px 18px;margin:0 0 10px;"><div style="font-size:22px;font-weight:700;letter-spacing:.08em;">%s</div><div>%s · %s</div></div>',
				esc_html( $r['code'] ),
				wp_kses_post( wc_price( $r['value'] ) ),
				esc_html( sprintf( /* translators: %s: datum */ __( 'platí do %s', 'nkz-mp-storefront' ), wp_date( 'j. n. Y', $r['expires'] ) ) )
			);
		}
		echo '<p style="font-size:13px;color:#555;">' . esc_html__( 'Kód zadejte v košíku do pole „Máte dárkový poukaz?". Odečte se z celé částky včetně poštovného.', 'nkz-mp-storefront' ) . ' <strong style="color:#111;">' . esc_html__( 'Poukaz je jednorázový – nevyčerpaný zůstatek propadá a nevrací se.', 'nkz-mp-storefront' ) . '</strong></p>';
	}

	/* ===================================================== výplaty */

	/** Objednávka celá zaplacená poukazem (0 Kč) se má rozdělit i bez Stripe. */
	public function split_voucher_only_order( $allow, $order ): bool {
		if ( $allow || ! $order instanceof \WC_Order ) {
			return (bool) $allow;
		}
		return (float) $order->get_meta( self::ORDER_AMOUNT_META ) > 0
			&& (float) $order->get_total() <= 0
			&& $order->is_paid();
	}

	/** U objednávky s poukazem neposíláme převod vázaný na platbu kartou. */
	public function use_source_transaction( $use, $order ): bool {
		if ( $order instanceof \WC_Order && (float) $order->get_meta( self::ORDER_AMOUNT_META ) > 0 ) {
			return false;
		}
		return (bool) $use;
	}

	/* ================================================================ admin */

	public function product_field(): void {
		woocommerce_wp_checkbox( [
			'id'          => self::PRODUCT_META,
			'label'       => __( 'Dárkový poukaz', 'nkz-mp-storefront' ),
			'description' => __( 'Po zaplacení se zákazníkovi vygeneruje kód v hodnotě zaplacené ceny (platnost 12 měsíců). Produkt nastav jako virtuální a bez prodejce – peníze patří platformě. Servisní poplatek se u poukazu neúčtuje.', 'nkz-mp-storefront' ),
		] );
	}

	public function save_product_field( \WC_Product $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WC ověřuje nonce.
		$on = isset( $_POST[ self::PRODUCT_META ] );
		$product->update_meta_data( self::PRODUCT_META, $on ? 'yes' : 'no' );
		if ( $on ) {
			// Poukaz: bez dopravy a bez servisního poplatku.
			$product->set_virtual( true );
			$product->update_meta_data( '_nkzmp_no_platform_fee', 'yes' );
		}
	}

	public function admin_columns( array $cols ): array {
		return [
			'cb'               => $cols['cb'] ?? '',
			'title'            => __( 'Kód', 'nkz-mp-storefront' ),
			'nkzmp_value'      => __( 'Hodnota', 'nkz-mp-storefront' ),
			'nkzmp_status'     => __( 'Stav', 'nkz-mp-storefront' ),
			'nkzmp_expires'    => __( 'Platí do', 'nkz-mp-storefront' ),
			'nkzmp_orders'     => __( 'Objednávky', 'nkz-mp-storefront' ),
		];
	}

	public function admin_column( string $col, int $post_id ): void {
		$v = get_post( $post_id );
		if ( ! $v ) {
			return;
		}
		$d = self::data( $v );
		switch ( $col ) {
			case 'nkzmp_value':
				echo wp_kses_post( wc_price( $d['value'] ) );
				break;
			case 'nkzmp_status':
				$expired = $d['expires'] > 0 && time() > $d['expires'] && $d['status'] === self::STATUS_ACTIVE;
				$labels  = [
					self::STATUS_ACTIVE   => __( 'aktivní', 'nkz-mp-storefront' ),
					self::STATUS_RESERVED => __( 'v rozpracované objednávce', 'nkz-mp-storefront' ),
					self::STATUS_USED     => __( 'použitý', 'nkz-mp-storefront' ),
					self::STATUS_VOID     => __( 'zneplatněný', 'nkz-mp-storefront' ),
				];
				echo esc_html( $expired ? __( 'propadlý', 'nkz-mp-storefront' ) : ( $labels[ $d['status'] ] ?? $d['status'] ) );
				break;
			case 'nkzmp_expires':
				echo esc_html( $d['expires'] > 0 ? wp_date( 'j. n. Y', $d['expires'] ) : '—' );
				break;
			case 'nkzmp_orders':
				foreach ( [ __( 'koupen', 'nkz-mp-storefront' ) => $d['purchase_order'], __( 'uplatněn', 'nkz-mp-storefront' ) => $d['redeem_order'] ] as $label => $oid ) {
					$o = $oid > 0 ? wc_get_order( $oid ) : null;
					if ( $o instanceof \WC_Order ) {
						printf( '%s <a href="%s">#%s</a><br>', esc_html( $label ), esc_url( $o->get_edit_order_url() ), esc_html( (string) $o->get_order_number() ) );
					}
				}
				break;
		}
	}

	/** Panel u objednávky: uplatněný i koupené poukazy + akce. */
	public function admin_order_panel( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$rows = [];
		$code = (string) $order->get_meta( self::ORDER_CODE_META );
		if ( $code !== '' && ( $v = self::find( $code ) ) ) {
			$rows[] = [ $v, __( 'Uplatněný poukaz', 'nkz-mp-storefront' ), self::STATUS_ACTIVE, __( 'Obnovit poukaz (např. po vrácení objednávky)', 'nkz-mp-storefront' ) ];
		}
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			foreach ( array_filter( (array) ( $item instanceof \WC_Order_Item_Product ? $item->get_meta( self::ITEM_CODES_META ) : [] ) ) as $c ) {
				if ( $v = self::find( (string) $c ) ) {
					$rows[] = [ $v, __( 'Koupený poukaz', 'nkz-mp-storefront' ), self::STATUS_VOID, __( 'Zneplatnit (např. po vrácení peněz za poukaz)', 'nkz-mp-storefront' ) ];
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		echo '<div style="clear:both;margin-top:12px;padding:10px 12px;border-left:4px solid #0060FF;background:#f2f6ff;">';
		foreach ( $rows as [ $v, $label, $target, $action ] ) {
			$d = self::data( $v );
			printf(
				'<p style="margin:4px 0;"><strong>%s:</strong> <code>%s</code> · %s · %s</p>',
				esc_html( $label ),
				esc_html( $d['code'] ),
				wp_kses_post( wc_price( $d['value'] ) ),
				esc_html( $d['status'] )
			);
			if ( $d['status'] !== $target ) {
				printf(
					'<p style="margin:0 0 6px;"><a class="button button-small" href="%s" onclick="return confirm(\'%s\');">%s</a></p>',
					esc_url( wp_nonce_url( add_query_arg( [ 'action' => 'nkzmp_voucher_set_status', 'voucher' => $v->ID, 'status' => $target, 'order_id' => $order->get_id() ], admin_url( 'admin-post.php' ) ), 'nkzmp_voucher_' . $v->ID ) ),
					esc_js( __( 'Opravdu?', 'nkz-mp-storefront' ) ),
					esc_html( $action )
				);
			}
		}
		echo '</div>';
	}

	public function handle_set_status(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-storefront' ) );
		}
		$id     = absint( $_GET['voucher'] ?? 0 );
		$status = sanitize_key( (string) ( $_GET['status'] ?? '' ) );
		check_admin_referer( 'nkzmp_voucher_' . $id );
		$v = get_post( $id );
		if ( $v && $v->post_type === self::CPT && in_array( $status, [ self::STATUS_ACTIVE, self::STATUS_VOID ], true ) ) {
			$extra = $status === self::STATUS_ACTIVE ? [ '_redeem_order' => 0, '_reserved_at' => 0 ] : [];
			self::set_status( $id, $status, $extra );
			$order = wc_get_order( absint( $_GET['order_id'] ?? 0 ) );
			if ( $order instanceof \WC_Order ) {
				$order->add_order_note( sprintf(
					/* translators: 1: kód, 2: stav */
					__( 'Dárkový poukaz %1$s: stav změněn na „%2$s".', 'nkz-mp-storefront' ),
					$v->post_title,
					$status
				) );
			}
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ?: admin_url() );
		exit;
	}
}
