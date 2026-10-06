<?php
/**
 * Odstoupení od smlouvy.
 *
 * Funkce pro odstoupení u online smluv (směrnice 2023/2673, platí od
 * 19. 6. 2026). Na marketplace zákazník neodstupuje od smlouvy s
 * provozovatelem, ale s konkrétním prodejcem, takže se odstupuje zvlášť
 * za každého prodejce v objednávce a lhůta běží zvlášť od doručení jeho
 * zásilky.
 *
 * Přístup:
 *  - odkazem z e-mailu / Můj účet: ?nkzmp_o=<order_id>&nkzmp_k=<order_key>
 *    (order_key je tajný klíč objednávky – stejný mechanismus, jakým
 *    WooCommerce pouští hosty na stránku „objednávka přijata"),
 *  - na veřejné stránce číslem objednávky + e-mailem z objednávky.
 *    Většina zákazníků nakupuje jako host, bez účtu.
 *
 * Peníze se automaticky NEVRACEJÍ. Zákazník musí zboží poslat zpět a
 * prodejce ho má právo nejdřív obdržet. Odstoupení jen zaznamená, rozešle
 * potvrzení a pozastaví výplatu prodejci (Escrow), aby peníze zůstaly
 * na platformě, dokud se vrácení nevyřeší. Refundaci pak udělá admin
 * běžně přes WooCommerce.
 *
 * Stránka: shortcode [nkzmp_withdrawal]. Když neexistuje, admin dostane
 * upozornění s tlačítkem pro vytvoření.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Withdrawal {

	/** Záznamy odstoupení na objednávce: [ vendor_id => record ]. */
	public const META = '_nkzmp_withdrawals';

	/** Otevřená (nevyřízená) odstoupení pro admin upozornění: [ "order:vendor" => ts ]. */
	public const OPEN_OPTION = 'nkzmp_withdrawals_open';

	/** Cache ID stránky s formulářem. */
	public const PAGE_OPTION = 'nkzmp_withdrawal_page_id';

	public const SHORTCODE = 'nkzmp_withdrawal';

	private static ?Withdrawal $instance = null;

	public static function instance(): Withdrawal {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_shortcode( self::SHORTCODE, [ $this, 'shortcode' ] );
		add_action( 'template_redirect', [ $this, 'handle_submit' ], 5 );
		add_action( 'template_redirect', [ $this, 'no_cache' ], 1 );

		// Tlačítko v Můj účet → detail objednávky.
		add_action( 'woocommerce_order_details_after_order_table', [ $this, 'account_button' ], 20 );
		// Odkaz v zákaznických e-mailech (host jinak nemá jak se k funkci dostat).
		add_action( 'woocommerce_email_after_order_table', [ $this, 'email_link' ], 30, 4 );

		// Admin.
		add_action( 'woocommerce_admin_order_data_after_shipping_address', [ $this, 'admin_panel' ], 30 );
		add_action( 'admin_notices', [ $this, 'admin_notice' ] );
		add_action( 'admin_post_nkzmp_withdrawal_resolve', [ $this, 'handle_resolve' ] );
		add_action( 'admin_post_nkzmp_withdrawal_create_page', [ $this, 'handle_create_page' ] );
	}

	/* ================================================================ stránka */

	/** ID publikované stránky se shortcodem, 0 když není. */
	public static function page_id(): int {
		$id = (int) get_option( self::PAGE_OPTION, 0 );
		if ( $id > 0 && get_post_status( $id ) === 'publish' && has_shortcode( (string) get_post_field( 'post_content', $id ), self::SHORTCODE ) ) {
			return $id;
		}
		global $wpdb;
		$found = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
			'%' . $wpdb->esc_like( '[' . self::SHORTCODE ) . '%'
		) );
		update_option( self::PAGE_OPTION, $found, false );
		return $found;
	}

	public static function page_url( array $args = [] ): string {
		$id = self::page_id();
		if ( $id <= 0 ) {
			return '';
		}
		$url = (string) get_permalink( $id );
		return $args ? add_query_arg( $args, $url ) : $url;
	}

	/** Odkaz s klíčem objednávky – funguje i pro hosty. */
	public static function order_url( \WC_Order $order ): string {
		return self::page_url( [
			'nkzmp_o' => $order->get_id(),
			'nkzmp_k' => $order->get_order_key(),
		] );
	}

	/**
	 * Stránka s formulářem se nesmí cachovat – LiteSpeed by jinak jednomu
	 * zákazníkovi servíroval objednávku jiného.
	 */
	public function no_cache(): void {
		$id = self::page_id();
		if ( $id > 0 && is_page( $id ) ) {
			nocache_headers();
			do_action( 'litespeed_control_set_nocache', 'nkzmp withdrawal form' );
		}
	}

	/* ========================================================= vyhodnocení */

	/** Prodejce položky. */
	private static function item_vendor_id( \WC_Order_Item_Product $item ): int {
		$pid = (int) $item->get_product_id();
		$v   = (int) get_post_meta( $pid, '_nkzmp_vendor_id', true );
		if ( $v <= 0 ) {
			$v = (int) get_post_meta( $pid, '_nkv_vendor_id', true );
		}
		return $v;
	}

	/**
	 * Lze u položky odstoupit?
	 *
	 * Elektronické zboží bez dopravy vynecháváme. Typicky jde o vstupenky na
	 * akci s pevným datem, u kterých zákon právo na odstoupení nedává
	 * (§ 1837 písm. j) OZ). Filtrem jde pravidlo změnit.
	 */
	private static function item_eligible( \WC_Order_Item_Product $item ): bool {
		$product  = $item->get_product();
		$eligible = $product ? $product->needs_shipping() : true;
		return (bool) apply_filters( 'nkzmp/v1/withdrawal/item_eligible', $eligible, $item );
	}

	/**
	 * Položky objednávky seskupené podle prodejce.
	 *
	 * @return array<int,\WC_Order_Item_Product[]> vendor_id => [ item_id => item ]
	 */
	private static function groups( \WC_Order $order ): array {
		$groups = [];
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$vid = self::item_vendor_id( $item );
			if ( $vid > 0 ) {
				$groups[ $vid ][ (int) $item_id ] = $item;
			}
		}
		return $groups;
	}

	/**
	 * Kdy zákazník zásilku od prodejce převzal (0 = ještě ne / nevíme).
	 *
	 * Primárně ze stavu Zásilkovny („doručeno"), záložně datum dokončení
	 * objednávky.
	 */
	private static function delivered_ts( \WC_Order $order, int $vendor_id ): int {
		$meta    = defined( 'NKZMP_PACKETA_PACKETS_META' ) ? NKZMP_PACKETA_PACKETS_META : '_nkzmp_packeta_packets';
		$packets = $order->get_meta( $meta );
		if ( is_array( $packets ) && isset( $packets[ $vendor_id ] ) ) {
			$p = (array) $packets[ $vendor_id ];
			if ( ( $p['state'] ?? '' ) === 'delivered' && ! empty( $p['state_at'] ) ) {
				return (int) $p['state_at'];
			}
		}
		if ( $order->has_status( 'completed' ) && $order->get_date_completed() ) {
			return $order->get_date_completed()->getTimestamp();
		}
		return 0;
	}

	/** Konec lhůty (0 = zatím neběží, zboží nedoručeno). */
	public static function deadline_ts( \WC_Order $order, int $vendor_id ): int {
		$delivered = self::delivered_ts( $order, $vendor_id );
		if ( $delivered <= 0 ) {
			return 0;
		}
		$days = (int) apply_filters( 'nkzmp/v1/withdrawal/days', 14, $order, $vendor_id );
		// Do konce posledního dne lhůty.
		$end = ( new \DateTimeImmutable( '@' . ( $delivered + $days * DAY_IN_SECONDS ) ) )
			->setTimezone( wp_timezone() )
			->setTime( 23, 59, 59 );
		return $end->getTimestamp();
	}

	/** Záznam odstoupení pro prodejce, nebo null. */
	public static function record( \WC_Order $order, int $vendor_id ): ?array {
		$all = $order->get_meta( self::META );
		return ( is_array( $all ) && isset( $all[ $vendor_id ] ) ) ? (array) $all[ $vendor_id ] : null;
	}

	/**
	 * Proč u prodejce nejde odstoupit, nebo '' když jde.
	 */
	private static function blocked_reason( \WC_Order $order, int $vendor_id, array $items ): string {
		if ( ! $order->has_status( [ 'processing', 'on-hold', 'completed' ] ) ) {
			return __( 'Objednávka není v takovém stavu, aby od ní šlo odstoupit.', 'nkz-mp-storefront' );
		}
		if ( self::record( $order, $vendor_id ) ) {
			return __( 'Odstoupení u tohoto prodejce už bylo přijato.', 'nkz-mp-storefront' );
		}
		$any = false;
		foreach ( $items as $item ) {
			if ( self::item_eligible( $item ) ) {
				$any = true;
				break;
			}
		}
		if ( ! $any ) {
			return __( 'U elektronického zboží a vstupenek na akce s pevným datem nelze od smlouvy odstoupit.', 'nkz-mp-storefront' );
		}
		$deadline = self::deadline_ts( $order, $vendor_id );
		if ( $deadline > 0 && time() > $deadline ) {
			return sprintf(
				/* translators: %s: datum */
				__( 'Lhůta pro odstoupení skončila %s.', 'nkz-mp-storefront' ),
				wp_date( 'j. n. Y', $deadline )
			);
		}
		return '';
	}

	/* ========================================================= dohledání */

	/** Objednávka z odkazu (id + klíč), nebo null. */
	private static function order_from_key(): ?\WC_Order {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- autorizace klíčem objednávky.
		$id  = absint( $_REQUEST['nkzmp_o'] ?? 0 );
		$key = sanitize_text_field( wp_unslash( (string) ( $_REQUEST['nkzmp_k'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( $id <= 0 || $key === '' ) {
			return null;
		}
		$order = wc_get_order( $id );
		if ( ! $order instanceof \WC_Order || ! hash_equals( (string) $order->get_order_key(), $key ) ) {
			return null;
		}
		return $order;
	}

	/**
	 * Objednávka podle čísla + e-mailu. Hledáme mezi objednávkami s tímhle
	 * e-mailem, takže samotné číslo bez e-mailu nikam nevede.
	 */
	private static function order_from_lookup( string $number, string $email ): ?\WC_Order {
		$number = ltrim( trim( $number ), '#' );
		if ( $number === '' || ! is_email( $email ) ) {
			return null;
		}
		$orders = wc_get_orders( [
			'billing_email' => $email,
			'limit'         => 50,
			'orderby'       => 'date',
			'order'         => 'DESC',
			'return'        => 'objects',
		] );
		foreach ( $orders as $order ) {
			if ( $order instanceof \WC_Order && (string) $order->get_order_number() === $number ) {
				return $order;
			}
		}
		return null;
	}

	/** Jednoduchý limit pokusů o dohledání z jedné IP (hádání čísel). */
	private static function rate_limited(): bool {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'x';
		$key = 'nkzmp_wd_rl_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= 10 ) {
			return true;
		}
		set_transient( $key, $n + 1, 10 * MINUTE_IN_SECONDS );
		return false;
	}

	/* =========================================================== odeslání */

	public function handle_submit(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- autorizace klíčem objednávky; stránka je necachovaná.
		if ( ( $_POST['nkzmp_wd_action'] ?? '' ) !== 'submit' ) {
			return;
		}
		if ( ! empty( $_POST['nkzmp_wd_hp'] ) ) {
			return; // honeypot
		}

		$order = self::order_from_key();
		if ( ! $order ) {
			return;
		}
		$vendor_id = absint( $_POST['nkzmp_v'] ?? 0 );
		$groups    = self::groups( $order );
		$back      = self::order_url( $order );

		if ( $vendor_id <= 0 || empty( $groups[ $vendor_id ] ) ) {
			wp_safe_redirect( add_query_arg( 'nkzmp_wd_err', 'vendor', $back ) );
			exit;
		}
		$items = $groups[ $vendor_id ];
		if ( self::blocked_reason( $order, $vendor_id, $items ) !== '' ) {
			wp_safe_redirect( $back );
			exit;
		}

		$picked = array_map( 'absint', (array) ( $_POST['nkzmp_items'] ?? [] ) );
		$chosen = [];
		foreach ( $picked as $item_id ) {
			if ( isset( $items[ $item_id ] ) && self::item_eligible( $items[ $item_id ] ) ) {
				$chosen[ $item_id ] = (int) $items[ $item_id ]->get_quantity();
			}
		}
		$name   = sanitize_text_field( wp_unslash( (string) ( $_POST['nkzmp_name'] ?? '' ) ) );
		$email  = sanitize_email( wp_unslash( (string) ( $_POST['nkzmp_email'] ?? '' ) ) );
		$reason = sanitize_textarea_field( wp_unslash( (string) ( $_POST['nkzmp_reason'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( ! $chosen ) {
			wp_safe_redirect( add_query_arg( 'nkzmp_wd_err', 'items', $back ) );
			exit;
		}
		if ( $name === '' || ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'nkzmp_wd_err', 'contact', $back ) );
			exit;
		}

		$record = [
			'at'     => time(),
			'items'  => $chosen,
			'name'   => $name,
			'email'  => $email,
			'reason' => $reason,
			'ip'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'status' => 'open',
		];

		$all               = $order->get_meta( self::META );
		$all               = is_array( $all ) ? $all : [];
		$all[ $vendor_id ] = $record;
		$order->update_meta_data( self::META, $all );

		$vendor_name = get_the_title( $vendor_id ) ?: ( '#' . $vendor_id );
		$order->add_order_note( sprintf(
			/* translators: 1: prodejce, 2: položky */
			__( 'Zákazník odstoupil od smlouvy u prodejce „%1$s". Vrací: %2$s', 'nkz-mp-storefront' ),
			$vendor_name,
			self::items_text( $items, $chosen, ', ' )
		) );
		$order->save();

		$open = get_option( self::OPEN_OPTION, [] );
		$open = is_array( $open ) ? $open : [];
		$open[ $order->get_id() . ':' . $vendor_id ] = $record['at'];
		update_option( self::OPEN_OPTION, $open, false );

		/**
		 * Zákazník odstoupil od smlouvy u prodejce. Escrow na to pozastavuje
		 * výplatu.
		 */
		do_action( 'nkzmp/v1/withdrawal/submitted', $order, $vendor_id, $record );

		try {
			$this->send_emails( $order, $vendor_id, $items, $record );
		} catch ( \Throwable $e ) {
			error_log( '[NKZMP] withdrawal e-mail failed: ' . $e->getMessage() );
		}

		wp_safe_redirect( add_query_arg( [ 'nkzmp_wd_done' => $vendor_id ], $back ) );
		exit;
	}

	/**
	 * @param \WC_Order_Item_Product[] $items
	 * @param array<int,int>          $chosen item_id => qty
	 */
	private static function items_text( array $items, array $chosen, string $sep = "\n" ): string {
		$lines = [];
		foreach ( $chosen as $item_id => $qty ) {
			if ( ! isset( $items[ $item_id ] ) ) {
				continue;
			}
			$line = $items[ $item_id ]->get_name() . ' × ' . (int) $qty;
			$lines[] = $sep === "\n" ? '- ' . $line : $line;
		}
		return implode( $sep, $lines );
	}

	private function send_emails( \WC_Order $order, int $vendor_id, array $items, array $record ): void {
		$vendor_name  = get_the_title( $vendor_id ) ?: ( '#' . $vendor_id );
		$vendor_email = (string) get_post_meta( $vendor_id, '_nkv_vendor_email', true );
		$site         = (string) get_bloginfo( 'name' );
		$submitted    = wp_date( 'j. n. Y H:i', (int) $record['at'] );
		$items_txt    = self::items_text( $items, (array) $record['items'] );
		$reason       = $record['reason'] !== '' ? (string) $record['reason'] : '—';

		$common = [
			'order_number'   => (string) $order->get_order_number(),
			'vendor_name'    => $vendor_name,
			'items'          => $items_txt,
			'submitted_at'   => $submitted,
			'reason'         => $reason,
			'customer_name'  => (string) $record['name'],
			'customer_email' => (string) $record['email'],
			'vendor_email'   => $vendor_email !== '' ? $vendor_email : '—',
			'site_name'      => $site,
		];

		// Zákazník – potvrzení na trvalém nosiči (zákonná povinnost).
		$this->mail(
			(string) $record['email'],
			'email_withdrawal_customer',
			$common + [ 'name' => (string) $record['name'] ]
		);

		// Prodejce.
		if ( is_email( $vendor_email ) ) {
			$this->mail(
				$vendor_email,
				'email_withdrawal_vendor',
				$common + [
					'name'       => $vendor_name,
					'orders_url' => wc_get_account_endpoint_url( 'vendor-orders' ),
				]
			);
		}

		// Admin.
		$admin = '';
		if ( class_exists( \NKZMP\Registration\Settings::class ) ) {
			$admin = (string) ( \NKZMP\Registration\Settings::get()['admin_notification_email'] ?? '' );
		}
		if ( ! is_email( $admin ) ) {
			$admin = (string) get_option( 'admin_email' );
		}
		$this->mail(
			$admin,
			'email_withdrawal_admin',
			$common + [ 'order_admin_url' => $order->get_edit_order_url() ]
		);
	}

	private function mail( string $to, string $key, array $vars ): void {
		if ( ! is_email( $to ) ) {
			return;
		}
		$subject = $body = '';
		if ( class_exists( \NKZMP\Admin\EmailSettings::class ) ) {
			$subject = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( $key . '_subject' ), $vars );
			$body    = \NKZMP\Admin\EmailSettings::interpolate( \NKZMP\Admin\EmailSettings::raw( $key . '_body' ), $vars );
		}
		if ( $subject === '' || $body === '' ) {
			$subject = sprintf( 'Odstoupení od smlouvy — #%s', $vars['order_number'] ?? '' );
			$body    = sprintf( "Odstoupení od smlouvy\n\nObjednávka: #%s\nProdejce: %s\nKdy: %s\n\n%s", $vars['order_number'] ?? '', $vars['vendor_name'] ?? '', $vars['submitted_at'] ?? '', $vars['items'] ?? '' );
		}
		if ( class_exists( \NKZMP\Registration\EmailService::class ) ) {
			\NKZMP\Registration\EmailService::send_raw( $to, $subject, $body );
			return;
		}
		wp_mail( $to, $subject, $body, [ 'Content-Type: text/plain; charset=UTF-8' ] );
	}

	/* ============================================================ shortcode */

	public function shortcode(): string {
		ob_start();
		$this->styles();
		echo '<div class="nkzmp-wd">';

		$order = self::order_from_key();

		// Krok 1: dohledání číslem + e-mailem (bez účtu).
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$lookup_error = '';
		if ( ! $order && ( $_POST['nkzmp_wd_action'] ?? '' ) === 'lookup' ) {
			if ( ! empty( $_POST['nkzmp_wd_hp'] ) ) {
				$lookup_error = __( 'Objednávku se nepodařilo najít.', 'nkz-mp-storefront' );
			} elseif ( self::rate_limited() ) {
				$lookup_error = __( 'Příliš mnoho pokusů. Zkus to prosím za pár minut.', 'nkz-mp-storefront' );
			} else {
				$order = self::order_from_lookup(
					sanitize_text_field( wp_unslash( (string) ( $_POST['nkzmp_number'] ?? '' ) ) ),
					sanitize_email( wp_unslash( (string) ( $_POST['nkzmp_lookup_email'] ?? '' ) ) )
				);
				if ( ! $order ) {
					$lookup_error = __( 'Objednávku se nepodařilo najít. Zkontroluj prosím číslo objednávky a e-mail, který jsi při nákupu zadal/a.', 'nkz-mp-storefront' );
				}
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $order ) {
			$this->render_order( $order );
		} else {
			$this->render_lookup( $lookup_error );
		}

		echo '</div>';
		return (string) ob_get_clean();
	}

	private function render_lookup( string $error ): void {
		echo '<p>' . esc_html__( 'Od smlouvy můžeš odstoupit do 14 dnů od převzetí zboží, bez udání důvodu. Najdi svoji objednávku:', 'nkz-mp-storefront' ) . '</p>';
		if ( $error !== '' ) {
			echo '<p class="nkzmp-wd__err">' . esc_html( $error ) . '</p>';
		}
		echo '<form method="post" class="nkzmp-wd__form">';
		echo '<input type="hidden" name="nkzmp_wd_action" value="lookup" />';
		echo '<input type="text" name="nkzmp_wd_hp" value="" tabindex="-1" autocomplete="off" class="nkzmp-wd__hp" />';
		echo '<p><label>' . esc_html__( 'Číslo objednávky', 'nkz-mp-storefront' ) . '<br><input type="text" name="nkzmp_number" required /></label></p>';
		echo '<p><label>' . esc_html__( 'E-mail z objednávky', 'nkz-mp-storefront' ) . '<br><input type="email" name="nkzmp_lookup_email" required /></label></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Najít objednávku', 'nkz-mp-storefront' ) . '</button></p>';
		echo '</form>';
	}

	private function render_order( \WC_Order $order ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$done = absint( $_GET['nkzmp_wd_done'] ?? 0 );
		$err  = sanitize_key( (string) ( $_GET['nkzmp_wd_err'] ?? '' ) );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		printf(
			'<h2>%s</h2>',
			esc_html( sprintf( /* translators: %s: číslo objednávky */ __( 'Objednávka #%s', 'nkz-mp-storefront' ), $order->get_order_number() ) )
		);

		$errors = [
			'items'   => __( 'Vyber prosím aspoň jednu položku, kterou vracíš.', 'nkz-mp-storefront' ),
			'contact' => __( 'Vyplň prosím jméno a platný e-mail – pošleme na něj potvrzení.', 'nkz-mp-storefront' ),
			'vendor'  => __( 'Něco se nepovedlo. Zkus to prosím znovu.', 'nkz-mp-storefront' ),
		];
		if ( isset( $errors[ $err ] ) ) {
			echo '<p class="nkzmp-wd__err">' . esc_html( $errors[ $err ] ) . '</p>';
		}

		$groups = self::groups( $order );
		if ( ! $groups ) {
			echo '<p>' . esc_html__( 'V objednávce nejsou položky, od kterých by šlo odstoupit.', 'nkz-mp-storefront' ) . '</p>';
			return;
		}

		foreach ( $groups as $vendor_id => $items ) {
			$vendor_name = get_the_title( $vendor_id ) ?: ( '#' . $vendor_id );
			echo '<section class="nkzmp-wd__vendor">';
			echo '<h3>' . esc_html( sprintf( /* translators: %s: prodejce */ __( 'Prodejce: %s', 'nkz-mp-storefront' ), $vendor_name ) ) . '</h3>';

			$record = self::record( $order, $vendor_id );
			if ( $record ) {
				printf(
					'<p class="nkzmp-wd__ok">%s</p>',
					esc_html( sprintf(
						/* translators: %s: datum a čas */
						__( 'Odstoupení jsme přijali %s. Potvrzení jsme poslali e-mailem. Zboží prosím zašli zpět prodejci do 14 dnů.', 'nkz-mp-storefront' ),
						wp_date( 'j. n. Y H:i', (int) $record['at'] )
					) )
				);
				if ( $done === (int) $vendor_id ) {
					echo '<p>' . esc_html__( 'Peníze vrátíme do 14 dnů od odstoupení, nejdříve však poté, co prodejce zboží obdrží nebo nám doložíš jeho odeslání.', 'nkz-mp-storefront' ) . '</p>';
				}
				echo '</section>';
				continue;
			}

			$blocked = self::blocked_reason( $order, $vendor_id, $items );
			if ( $blocked !== '' ) {
				echo '<p class="nkzmp-wd__muted">' . esc_html( $blocked ) . '</p>';
				echo '</section>';
				continue;
			}

			$deadline = self::deadline_ts( $order, $vendor_id );
			echo '<p class="nkzmp-wd__muted">' . esc_html(
				$deadline > 0
					/* translators: %s: datum */
					? sprintf( __( 'Odstoupit můžeš do %s.', 'nkz-mp-storefront' ), wp_date( 'j. n. Y', $deadline ) )
					: __( 'Zboží zatím nebylo doručeno – odstoupit můžeš už teď, nebo do 14 dnů od převzetí.', 'nkz-mp-storefront' )
			) . '</p>';

			echo '<form method="post" class="nkzmp-wd__form">';
			echo '<input type="hidden" name="nkzmp_wd_action" value="submit" />';
			echo '<input type="hidden" name="nkzmp_o" value="' . esc_attr( (string) $order->get_id() ) . '" />';
			echo '<input type="hidden" name="nkzmp_k" value="' . esc_attr( (string) $order->get_order_key() ) . '" />';
			echo '<input type="hidden" name="nkzmp_v" value="' . esc_attr( (string) $vendor_id ) . '" />';
			echo '<input type="text" name="nkzmp_wd_hp" value="" tabindex="-1" autocomplete="off" class="nkzmp-wd__hp" />';

			echo '<p><strong>' . esc_html__( 'Co vracíš:', 'nkz-mp-storefront' ) . '</strong></p><ul class="nkzmp-wd__items">';
			foreach ( $items as $item_id => $item ) {
				$ok = self::item_eligible( $item );
				printf(
					'<li><label%s><input type="checkbox" name="nkzmp_items[]" value="%d"%s%s /> %s × %d%s</label></li>',
					$ok ? '' : ' class="nkzmp-wd__muted"',
					(int) $item_id,
					$ok ? ' checked' : '',
					$ok ? '' : ' disabled',
					esc_html( $item->get_name() ),
					(int) $item->get_quantity(),
					$ok ? '' : ' — ' . esc_html__( 'u tohoto zboží odstoupit nelze', 'nkz-mp-storefront' )
				);
			}
			echo '</ul>';

			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			echo '<p><label>' . esc_html__( 'Jméno a příjmení', 'nkz-mp-storefront' ) . '<br><input type="text" name="nkzmp_name" required value="' . esc_attr( $name ) . '" /></label></p>';
			echo '<p><label>' . esc_html__( 'E-mail pro potvrzení', 'nkz-mp-storefront' ) . '<br><input type="email" name="nkzmp_email" required value="' . esc_attr( (string) $order->get_billing_email() ) . '" /></label></p>';
			echo '<p><label>' . esc_html__( 'Důvod (nepovinné)', 'nkz-mp-storefront' ) . '<br><textarea name="nkzmp_reason" rows="3"></textarea></label></p>';
			echo '<p><button type="submit" class="button alt">' . esc_html__( 'Odstoupit od smlouvy', 'nkz-mp-storefront' ) . '</button></p>';
			echo '</form>';
			echo '</section>';
		}
	}

	private function styles(): void {
		echo '<style>
		.nkzmp-wd{max-width:640px}
		.nkzmp-wd__vendor{border:1px solid #e6e8ee;border-radius:12px;padding:18px 20px;margin:0 0 16px}
		.nkzmp-wd__vendor h3{margin:0 0 8px;font-size:18px}
		.nkzmp-wd__items{list-style:none;margin:0 0 12px;padding:0}
		.nkzmp-wd__items li{margin:4px 0}
		.nkzmp-wd__form input[type=text],.nkzmp-wd__form input[type=email],.nkzmp-wd__form textarea{width:100%;max-width:420px}
		.nkzmp-wd__muted{color:#6b7280}
		.nkzmp-wd__err{color:#b00020;font-weight:600}
		.nkzmp-wd__ok{color:#1a7f37;font-weight:600}
		.nkzmp-wd__hp{position:absolute!important;left:-9999px!important;width:1px;height:1px;opacity:0}
		</style>';
	}

	/* ===================================================== Můj účet, e-mail */

	/** Tlačítko u objednávky v Můj účet (ne na děkovací stránce hned po nákupu). */
	public function account_button( $order ): void {
		if ( ! $order instanceof \WC_Order || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}
		if ( ! self::any_open( $order ) ) {
			return;
		}
		$url = self::order_url( $order );
		if ( $url === '' ) {
			return;
		}
		printf(
			'<p style="margin-top:16px;"><a class="button" href="%s">%s</a></p>',
			esc_url( $url ),
			esc_html__( 'Odstoupit od smlouvy', 'nkz-mp-storefront' )
		);
	}

	/** Dá se u objednávky ještě u někoho odstoupit? */
	private static function any_open( \WC_Order $order ): bool {
		foreach ( self::groups( $order ) as $vendor_id => $items ) {
			if ( self::blocked_reason( $order, (int) $vendor_id, $items ) === '' ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Odkaz na odstoupení v e-mailech zákazníkovi.
	 *
	 * @param \WC_Order $order
	 * @param bool      $sent_to_admin
	 * @param bool      $plain_text
	 * @param mixed     $email
	 */
	public function email_link( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof \WC_Order ) {
			return;
		}
		$id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		if ( ! in_array( $id, [ 'customer_processing_order', 'customer_completed_order' ], true ) ) {
			return;
		}
		$url = self::order_url( $order );
		if ( $url === '' ) {
			return;
		}
		$text = __( 'Od smlouvy můžete odstoupit do 14 dnů od převzetí zboží:', 'nkz-mp-storefront' );
		if ( $plain_text ) {
			echo "\n" . esc_html( $text ) . "\n" . esc_url_raw( $url ) . "\n";
			return;
		}
		printf(
			'<p style="margin:16px 0;font-size:13px;color:#555;">%s <a href="%s">%s</a></p>',
			esc_html( $text ),
			esc_url( $url ),
			esc_html__( 'Odstoupit od smlouvy', 'nkz-mp-storefront' )
		);
	}

	/* ================================================================= admin */

	public function admin_panel( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$all = $order->get_meta( self::META );
		if ( ! is_array( $all ) || ! $all ) {
			return;
		}
		$groups = self::groups( $order );
		echo '<div style="clear:both;margin-top:12px;padding:10px 12px;border-left:4px solid #b32d2e;background:#fcf0f1;">';
		echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'Odstoupení od smlouvy', 'nkz-mp-storefront' ) . '</strong></p>';
		foreach ( $all as $vendor_id => $rec ) {
			$rec         = (array) $rec;
			$vendor_name = get_the_title( (int) $vendor_id ) ?: ( '#' . (int) $vendor_id );
			$resolved    = ( $rec['status'] ?? 'open' ) === 'resolved';
			printf(
				'<p style="margin:6px 0;">%s — %s<br><span style="color:#666;">%s</span>%s</p>',
				esc_html( $vendor_name ),
				esc_html( wp_date( 'j. n. Y H:i', (int) ( $rec['at'] ?? 0 ) ) ),
				esc_html( self::items_text( $groups[ (int) $vendor_id ] ?? [], (array) ( $rec['items'] ?? [] ), ', ' ) ),
				$resolved
					? ' <span style="color:#1a7f37;">✓ ' . esc_html__( 'vyřízeno', 'nkz-mp-storefront' ) . '</span>'
					: ''
			);
			if ( ! empty( $rec['reason'] ) ) {
				echo '<p style="margin:0 0 6px;color:#666;">' . esc_html__( 'Důvod:', 'nkz-mp-storefront' ) . ' ' . esc_html( (string) $rec['reason'] ) . '</p>';
			}
			if ( ! $resolved ) {
				printf(
					'<p style="margin:0 0 8px;"><a class="button button-small button-primary" href="%s">%s</a> ',
					esc_url( WithdrawalRefund::url( $order, (int) $vendor_id ) ),
					esc_html__( 'Vrátit peníze…', 'nkz-mp-storefront' )
				);
				printf(
					'<a class="button button-small" href="%s">%s</a></p>',
					esc_url( wp_nonce_url(
						add_query_arg(
							[ 'action' => 'nkzmp_withdrawal_resolve', 'order_id' => $order->get_id(), 'vendor_id' => (int) $vendor_id ],
							admin_url( 'admin-post.php' )
						),
						'nkzmp_withdrawal_resolve_' . $order->get_id() . '_' . (int) $vendor_id
					) ),
					esc_html__( 'Označit jako vyřízené', 'nkz-mp-storefront' )
				);
			}
		}
		echo '<p style="margin:6px 0 0;color:#666;font-size:12px;">' . esc_html__( 'Výplata prodejci je pozastavená. Až zboží dorazí zpět, klikni na „Vrátit peníze" – refundace, dobropis i vrácení poukazu se udělají naráz. „Označit jako vyřízené" použij, když se vrácení nekoná (zákazník si to rozmyslel) – výplatu prodejci pak uvolni tlačítkem „Uvolnit teď" v boxu Stripe.', 'nkz-mp-storefront' ) . '</p>';
		echo '</div>';
	}

	public function handle_resolve(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-storefront' ) );
		}
		$order_id  = absint( $_GET['order_id'] ?? 0 );
		$vendor_id = absint( $_GET['vendor_id'] ?? 0 );
		check_admin_referer( 'nkzmp_withdrawal_resolve_' . $order_id . '_' . $vendor_id );

		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order ) {
			$all = $order->get_meta( self::META );
			if ( is_array( $all ) && isset( $all[ $vendor_id ] ) ) {
				$all[ $vendor_id ]['status']      = 'resolved';
				$all[ $vendor_id ]['resolved_at'] = time();
				$order->update_meta_data( self::META, $all );
				$order->add_order_note( __( 'Odstoupení od smlouvy označeno jako vyřízené.', 'nkz-mp-storefront' ) );
				$order->save();
			}
			$open = get_option( self::OPEN_OPTION, [] );
			if ( is_array( $open ) ) {
				unset( $open[ $order_id . ':' . $vendor_id ] );
				update_option( self::OPEN_OPTION, $open, false );
			}
		}
		wp_safe_redirect( $order instanceof \WC_Order ? $order->get_edit_order_url() : admin_url() );
		exit;
	}

	public function admin_notice(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// Chybí stránka → funkce pro zákazníky neexistuje.
		if ( self::page_id() <= 0 ) {
			printf(
				'<div class="notice notice-error"><p><strong>%s</strong> %s</p><p><a class="button button-primary" href="%s">%s</a></p></div>',
				esc_html__( 'Chybí stránka pro odstoupení od smlouvy.', 'nkz-mp-storefront' ),
				esc_html__( 'Bez ní zákazníci nemají jak odstoupit a odkazy v e-mailech i v Můj účet se nezobrazí.', 'nkz-mp-storefront' ),
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=nkzmp_withdrawal_create_page' ), 'nkzmp_withdrawal_create_page' ) ),
				esc_html__( 'Vytvořit stránku', 'nkz-mp-storefront' )
			);
		}

		$open = get_option( self::OPEN_OPTION, [] );
		if ( ! is_array( $open ) || ! $open ) {
			return;
		}
		$links = [];
		foreach ( array_slice( array_keys( $open ), 0, 10 ) as $key ) {
			[ $order_id ] = array_map( 'intval', explode( ':', (string) $key ) + [ 0, 0 ] );
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$links[] = sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( (string) $order->get_order_number() ) );
		}
		if ( ! $links ) {
			return;
		}
		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s %s</p></div>',
			esc_html__( 'Odstoupení od smlouvy k vyřízení:', 'nkz-mp-storefront' ),
			esc_html__( 'výplaty prodejcům jsou u těchto objednávek pozastavené.', 'nkz-mp-storefront' ),
			wp_kses( implode( ', ', array_unique( $links ) ), [ 'a' => [ 'href' => [] ] ] )
		);
	}

	public function handle_create_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Nemáš oprávnění.', 'nkz-mp-storefront' ) );
		}
		check_admin_referer( 'nkzmp_withdrawal_create_page' );
		if ( self::page_id() <= 0 ) {
			$id = wp_insert_post( [
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Odstoupení od smlouvy', 'nkz-mp-storefront' ),
				'post_content' => '[' . self::SHORTCODE . ']',
			] );
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( self::PAGE_OPTION, (int) $id, false );
			}
		}
		$id = self::page_id();
		wp_safe_redirect( $id > 0 ? (string) get_edit_post_link( $id, 'url' ) : admin_url() );
		exit;
	}
}
