<?php
/**
 * ProductStats – zobrazení a oblíbené u produktů.
 *
 *  - Zobrazení: na detailu produktu pošle prohlížeč jeden signál za den
 *    a produkt (web je za Cloudflare, stránky bývají z mezipaměti – proto
 *    počítá prohlížeč, ne server při vykreslení). Roboti, správci obchodu
 *    a prodejce u vlastního produktu se nepočítají.
 *  - Oblíbené: přidání / odebrání srdíčkem (počet lidí, kteří ho mají
 *    v oblíbených; nikdy pod nulu).
 *  - Prodejce vidí u každého produktu zobrazení za 30 dní, oblíbené
 *    a prodeje, v Přehledu souhrn a nejprohlíženější produkty.
 *  - Veřejně jen „V oblíbených u N lidí", a to od 5 (malá čísla by spíš
 *    odrazovala). Počet zobrazení se veřejně neukazuje.
 *
 * Signál jde přes REST (/wp-json/nkzmp/v1/stats), ne admin-ajax – ten
 * Cloudflare u části návštěvníků blokuje.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ProductStats {

	public const VIEWS_META = '_nkzmp_views';      // [ 'd' => [ Ymd => n ], 't' => celkem ]
	public const FAVS_META  = '_nkzmp_fav_count';  // int
	private const KEEP_DAYS = 90;

	private static ?ProductStats $instance = null;

	public static function instance(): ProductStats {
		return self::$instance ??= new self();
	}

	public function init(): void {
		add_action( 'rest_api_init', [ $this, 'route' ] );
		// Záloha, kdyby REST pro návštěvníka blokoval firewall.
		add_action( 'wp_ajax_nkzmp_stats', [ $this, 'ajax' ] );
		add_action( 'wp_ajax_nopriv_nkzmp_stats', [ $this, 'ajax' ] );
		add_action( 'wp_footer', [ $this, 'script' ], 25 );
		add_action( 'woocommerce_single_product_summary', [ $this, 'public_favs' ], 33 );
		add_action( 'nkzmp/v1/dashboard/product_card_stats', [ $this, 'card_stats' ] );
		add_action( 'nkzmp/v1/dashboard/overview_after_stats', [ $this, 'overview' ] );
	}

	/** Od kolika oblíbených se počet ukáže veřejně. */
	public static function public_min(): int {
		return max( 1, (int) apply_filters( 'nkzmp/v1/storefront/favs_public_min', 5 ) );
	}

	/* ----------------------------------------------------------- čtení */

	public static function views( int $product_id, int $days = 30 ): int {
		$m = get_post_meta( $product_id, self::VIEWS_META, true );
		if ( ! is_array( $m ) || empty( $m['d'] ) ) {
			return 0;
		}
		$from = (int) wp_date( 'Ymd', time() - ( $days - 1 ) * DAY_IN_SECONDS );
		$sum  = 0;
		foreach ( (array) $m['d'] as $day => $n ) {
			if ( (int) $day >= $from ) {
				$sum += (int) $n;
			}
		}
		return $sum;
	}

	public static function favs( int $product_id ): int {
		return max( 0, (int) get_post_meta( $product_id, self::FAVS_META, true ) );
	}

	public static function sold( int $product_id ): int {
		return max( 0, (int) get_post_meta( $product_id, 'total_sales', true ) );
	}

	/* ----------------------------------------------------------- zápis */

	public function route(): void {
		register_rest_route( 'nkzmp/v1', '/stats', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'p' => [ 'type' => 'integer', 'required' => true ],
				'e' => [ 'type' => 'string', 'required' => true, 'enum' => [ 'view', 'fav', 'unfav' ] ],
			],
		] );
	}

	private static function is_bot(): bool {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		return $ua === '' || (bool) preg_match( '/bot|crawl|spider|slurp|preview|facebookexternalhit|headless|lighthouse|pingdom|monitor|curl|wget|python|httpclient/i', $ua );
	}

	private static function product_vendor( int $product_id ): int {
		return (int) ( get_post_meta( $product_id, '_nkv_vendor_id', true ) ?: get_post_meta( $product_id, '_nkzmp_vendor_id', true ) );
	}

	/** Správce obchodu nebo prodejce vlastního produktu se nepočítá. */
	private static function excluded( int $product_id ): bool {
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			return false;
		}
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}
		if ( class_exists( \NKZMP\Vendor\OwnershipGuard::class ) ) {
			$vid = (int) \NKZMP\Vendor\OwnershipGuard::user_vendor_id( $uid );
			return $vid > 0 && $vid === self::product_vendor( $product_id );
		}
		return false;
	}

	public function handle( \WP_REST_Request $req ) {
		$ok = self::record( (int) $req['p'], (string) $req['e'] );
		return new \WP_REST_Response( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	public function ajax(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- veřejné počítadlo, pojistka níže.
		$ev = sanitize_key( wp_unslash( (string) ( $_POST['e'] ?? '' ) ) );
		$ok = in_array( $ev, [ 'view', 'fav', 'unfav' ], true ) && self::record( absint( $_POST['p'] ?? 0 ), $ev );
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		wp_send_json( [ 'ok' => $ok ], $ok ? 200 : 404 );
	}

	/** Zapíše událost; false když produkt neexistuje / není veřejný. */
	public static function record( int $pid, string $ev ): bool {
		if ( $pid <= 0 || get_post_type( $pid ) !== 'product' || get_post_status( $pid ) !== 'publish' ) {
			return false;
		}
		// Pojistka proti nafukování přímými požadavky: z jedné adresy se
		// produktu počítá jedno zobrazení za den a oblíbené jen při změně.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'nkzmp_ps_' . md5( $ip . '|' . $pid . '|' . ( $ev === 'view' ? 'v' : 'f' ) );
		if ( $ev === 'view' ) {
			if ( ! self::is_bot() && ! self::excluded( $pid ) && ! get_transient( $key ) ) {
				set_transient( $key, 1, DAY_IN_SECONDS );
				self::add_view( $pid );
			}
		} elseif ( get_transient( $key ) !== $ev ) {
			set_transient( $key, $ev, 60 * DAY_IN_SECONDS );
			$n = self::favs( $pid ) + ( $ev === 'fav' ? 1 : -1 );
			update_post_meta( $pid, self::FAVS_META, max( 0, $n ) );
		}
		return true;
	}

	public static function add_view( int $product_id ): void {
		$m = get_post_meta( $product_id, self::VIEWS_META, true );
		$m = is_array( $m ) ? $m : [ 'd' => [], 't' => 0 ];
		$d = (array) ( $m['d'] ?? [] );
		$today       = wp_date( 'Ymd' );
		$d[ $today ] = (int) ( $d[ $today ] ?? 0 ) + 1;
		// Starší dny zahodit, ať meta neroste donekonečna.
		$from = (int) wp_date( 'Ymd', time() - self::KEEP_DAYS * DAY_IN_SECONDS );
		foreach ( array_keys( $d ) as $day ) {
			if ( (int) $day < $from ) {
				unset( $d[ $day ] );
			}
		}
		update_post_meta( $product_id, self::VIEWS_META, [ 'd' => $d, 't' => (int) ( $m['t'] ?? 0 ) + 1 ] );
	}

	/* ------------------------------------------------------ prohlížeč */

	public function script(): void {
		if ( is_admin() ) {
			return;
		}
		$pid = function_exists( 'is_product' ) && is_product() ? (int) get_queried_object_id() : 0;
		$cfg = [
			'url'   => esc_url_raw( rest_url( 'nkzmp/v1/stats' ) ),
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'p'     => $pid,
		];
		?>
		<script>
		(function () {
			var C = <?php echo wp_json_encode( $cfg ); ?>;
			if (!window.fetch) { return; }
			function send(p, e) {
				var h = { 'Content-Type': 'application/json' };
				if (C.nonce) { h['X-WP-Nonce'] = C.nonce; }
				function viaAjax() {
					var b = new URLSearchParams(); b.set('action', 'nkzmp_stats'); b.set('p', p); b.set('e', e);
					fetch(C.ajax, { method: 'POST', credentials: 'same-origin', keepalive: true, body: b }).catch(function () {});
				}
				try {
					fetch(C.url, { method: 'POST', credentials: 'same-origin', keepalive: true, headers: h, body: JSON.stringify({ p: p, e: e }) })
						.then(function (r) { if (r.status !== 200 && r.status !== 404) { viaAjax(); } })
						.catch(viaAjax);
				} catch (x) {}
			}
			// Zobrazení: jednou za den a produkt v tomto prohlížeči.
			if (C.p) {
				var d = new Date(), key = 'nkzmp_v_' + C.p + '_' + d.getFullYear() + (d.getMonth() + 1) + d.getDate(), seen = false;
				try { seen = localStorage.getItem(key) === '1'; } catch (x) {}
				if (!seen) {
					try { localStorage.setItem(key, '1'); } catch (x) {}
					setTimeout(function () { send(C.p, 'view'); }, 1500);
				}
			}
			// Oblíbené (srdíčko) – událost posílá modul Oblíbené.
			document.addEventListener('nkzmp:fav', function (ev) {
				if (ev.detail && ev.detail.id) { send(ev.detail.id, ev.detail.on ? 'fav' : 'unfav'); }
			});
		})();
		</script>
		<?php
	}

	/* --------------------------------------------------------- výstupy */

	/** Detail produktu: „V oblíbených u N lidí" (od 5). */
	public function public_favs(): void {
		$pid = (int) get_the_ID();
		$n   = $pid > 0 ? self::favs( $pid ) : 0;
		if ( $n < self::public_min() ) {
			return;
		}
		printf(
			'<p class="nkzmp-favs-public" style="display:flex;align-items:center;gap:8px;margin:12px 0 0;font-size:14px;color:#0060FF;"><svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7.5-4.6-9.6-9.2C.9 8.4 3 4.5 6.8 4.5c2.1 0 3.6 1.2 5.2 3 1.6-1.8 3.1-3 5.2-3 3.8 0 5.9 3.9 4.4 7.3C19.5 16.4 12 21 12 21z" fill="currentColor"/></svg>%s</p>',
			esc_html( sprintf(
				/* translators: %d: počet lidí (vždy 5 a víc) */
				_n( 'V oblíbených u %d člověka', 'V oblíbených u %d lidí', $n, 'nkz-mp-storefront' ),
				$n
			) )
		);
	}

	/** Karta produktu v přehledu prodejce. */
	public function card_stats( $product ): void {
		if ( ! $product instanceof \WC_Product || get_post_status( $product->get_id() ) !== 'publish' ) {
			return;
		}
		$id = (int) $product->get_id();
		printf(
			'<div class="nkzmp-vd-card-stats" style="display:flex;flex-wrap:wrap;gap:4px 12px;margin:8px 0 0;font-size:13px;color:#555;"><span title="%4$s">👁 %1$s</span><span>♡ %2$s</span><span>%3$s</span></div>',
			esc_html( sprintf( /* translators: %d: počet */ __( '%d zobrazení', 'nkz-mp-storefront' ), self::views( $id ) ) ),
			esc_html( sprintf( /* translators: %d: počet */ __( '%d v oblíbených', 'nkz-mp-storefront' ), self::favs( $id ) ) ),
			esc_html( sprintf( /* translators: %d: počet */ __( '%d× prodáno', 'nkz-mp-storefront' ), self::sold( $id ) ) ),
			esc_attr__( 'Zobrazení detailu produktu za posledních 30 dní', 'nkz-mp-storefront' )
		);
	}

	/** @return int[] */
	private static function vendor_products( int $vendor_id ): array {
		global $wpdb;
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			 WHERE p.post_type = 'product' AND p.post_status = 'publish'
			   AND pm.meta_key IN ('_nkzmp_vendor_id','_nkv_vendor_id') AND pm.meta_value = %s",
			(string) $vendor_id
		) ) );
	}

	/** Přehled prodejce: souhrn za 30 dní + nejprohlíženější produkty. */
	public function overview( $vendor_id ): void {
		$ids = self::vendor_products( (int) $vendor_id );
		if ( ! $ids ) {
			return;
		}
		$rows  = [];
		$views = 0;
		$favs  = 0;
		foreach ( $ids as $id ) {
			$v      = self::views( $id );
			$f      = self::favs( $id );
			$views += $v;
			$favs  += $f;
			$rows[] = [ 'id' => $id, 'v' => $v, 'f' => $f ];
		}
		usort( $rows, static fn( $a, $b ) => $b['v'] <=> $a['v'] );
		$top = array_slice( array_filter( $rows, static fn( $r ) => $r['v'] > 0 ), 0, 5 );

		echo '<section class="nkzmp-vd-viewstats" style="margin:0 0 28px;">';
		echo '<h2 class="nkzmp-vd-subhead">' . esc_html__( 'Zájem o tvoje produkty (30 dní)', 'nkz-mp-storefront' ) . '</h2>';
		printf(
			'<p class="nkzmp-vd-meta" style="margin:0 0 10px;">%s</p>',
			esc_html( sprintf(
				/* translators: 1: zobrazení, 2: oblíbené */
				__( '%1$d zobrazení detailu produktů · %2$d× v oblíbených', 'nkz-mp-storefront' ),
				$views,
				$favs
			) )
		);
		if ( $top ) {
			echo '<table class="nkzmp-vd-table"><thead><tr><th>' . esc_html__( 'Nejprohlíženější', 'nkz-mp-storefront' ) . '</th><th class="col-num">' . esc_html__( 'Zobrazení', 'nkz-mp-storefront' ) . '</th><th class="col-num">' . esc_html__( 'Oblíbené', 'nkz-mp-storefront' ) . '</th><th class="col-num">' . esc_html__( 'Prodáno', 'nkz-mp-storefront' ) . '</th></tr></thead><tbody>';
			foreach ( $top as $r ) {
				printf(
					'<tr><td><a href="%s" target="_blank">%s</a></td><td class="col-num">%d</td><td class="col-num">%d</td><td class="col-num">%d</td></tr>',
					esc_url( (string) get_permalink( $r['id'] ) ),
					esc_html( (string) get_the_title( $r['id'] ) ),
					(int) $r['v'],
					(int) $r['f'],
					self::sold( (int) $r['id'] )
				);
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="nkzmp-vd-empty-msg">' . esc_html__( 'Zatím žádná zobrazení – počítají se od nasazení této funkce.', 'nkz-mp-storefront' ) . '</p>';
		}
		echo '</section>';
	}
}
