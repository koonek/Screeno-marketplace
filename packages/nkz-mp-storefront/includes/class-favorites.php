<?php
/**
 * Favorites – oblíbené produkty (srdíčko).
 *
 *  - srdíčko na kartě produktu (pravý horní roh) a na detailu,
 *  - host: seznam v prohlížeči (localStorage), přihlášený: navíc v účtu
 *    (sloučí se – co si uložil jako host, po přihlášení nezmizí),
 *  - stránka „Oblíbené" (shortcode [nkzmp_favorites], založí se sama),
 *  - počet do menu: shortcode [nkzmp_favorites_count] nebo jakýkoli
 *    prvek s atributem data-nkzmp-fav-count.
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class Favorites {

	public const META      = '_nkzmp_favorites';
	public const PAGE_FLAG = 'nkzmp_favorites_page';
	private const NONCE    = 'nkzmp_fav';
	private const MAX      = 200;

	private static ?Favorites $instance = null;
	private bool $printed = false;

	public static function instance(): Favorites {
		return self::$instance ??= new self();
	}

	public function init(): void {
		if ( ! apply_filters( 'nkzmp/v1/storefront/favorites', true ) ) {
			return;
		}
		add_action( 'woocommerce_after_shop_loop_item', [ $this, 'loop_button' ], 6 ); // za odkazem karty (zavírá se na 5)
		add_action( 'woocommerce_single_product_summary', [ $this, 'single_button' ], 32 );
		add_shortcode( 'nkzmp_favorites', [ $this, 'shortcode' ] );
		add_shortcode( 'nkzmp_favorites_count', [ $this, 'count_shortcode' ] );
		add_action( 'wp_footer', [ $this, 'script' ], 20 );
		add_action( 'wp_ajax_nkzmp_fav_sync', [ $this, 'ajax_sync' ] );
		add_action( 'wp_ajax_nkzmp_fav_render', [ $this, 'ajax_render' ] );
		add_action( 'wp_ajax_nopriv_nkzmp_fav_render', [ $this, 'ajax_render' ] );
		add_action( 'init', [ $this, 'maybe_create_page' ], 100 );
	}

	/* ============================================================ tlačítka */

	private static function heart( int $id, bool $big = false ): string {
		$svg = '<svg viewBox="0 0 24 24" width="' . ( $big ? 20 : 18 ) . '" height="' . ( $big ? 20 : 18 ) . '" aria-hidden="true"><path d="M12 21s-7-4.4-9.3-9A5.4 5.4 0 0 1 12 6.2 5.4 5.4 0 0 1 21.3 12C19 16.6 12 21 12 21z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
		return sprintf(
			'<button type="button" class="nkzmp-fav%1$s" data-nkzmp-fav="%2$d" aria-pressed="false" aria-label="%3$s" title="%3$s">%4$s%5$s</button>',
			$big ? ' nkzmp-fav--single' : '',
			$id,
			esc_attr__( 'Přidat do oblíbených', 'nkz-mp-storefront' ),
			$svg, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statické SVG.
			$big ? '<span>' . esc_html__( 'Do oblíbených', 'nkz-mp-storefront' ) . '</span>' : ''
		);
	}

	public function loop_button(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo self::heart( (int) $product->get_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function single_button(): void {
		global $product;
		if ( $product instanceof \WC_Product ) {
			echo self::heart( (int) $product->get_id(), true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}

	public function count_shortcode(): string {
		return '<span class="nkzmp-fav-count" data-nkzmp-fav-count hidden></span>';
	}

	/* ============================================================ stránka */

	public static function page_url(): string {
		$id = (int) get_option( self::PAGE_FLAG, 0 );
		$url = $id > 0 ? get_permalink( $id ) : '';
		return $url ? (string) $url : home_url( '/oblibene/' );
	}

	/** Stránku „Oblíbené" založit jednou (správce ji může upravit/smazat). */
	public function maybe_create_page(): void {
		if ( get_option( self::PAGE_FLAG ) !== false || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$existing = get_page_by_path( 'oblibene' );
		$id = $existing ? (int) $existing->ID : (int) wp_insert_post( [
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => __( 'Oblíbené', 'nkz-mp-storefront' ),
			'post_name'    => 'oblibene',
			'post_content' => '[nkzmp_favorites]',
		] );
		update_option( self::PAGE_FLAG, $id, false );
	}

	public function shortcode(): string {
		return '<div class="nkzmp-favs woocommerce" data-nkzmp-favs><p class="nkzmp-favs__loading">' . esc_html__( 'Načítám oblíbené…', 'nkz-mp-storefront' ) . '</p></div>';
	}

	/* ============================================================ AJAX */

	/** @return int[] */
	private static function clean_ids( $raw ): array {
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $raw ) ) ) );
		return array_slice( $ids, 0, self::MAX );
	}

	/** Přihlášený: uložit sloučený seznam do účtu. */
	public function ajax_sync(): void {
		check_ajax_referer( self::NONCE, 'nonce' );
		$uid = get_current_user_id();
		if ( $uid <= 0 ) {
			wp_send_json_error( null, 403 );
		}
		$ids = self::clean_ids( json_decode( wp_unslash( (string) ( $_POST['ids'] ?? '[]' ) ), true ) );
		update_user_meta( $uid, self::META, $ids );
		wp_send_json_success( [ 'ids' => $ids ] );
	}

	/** Vykreslí produkty podle ID (stránka Oblíbené). */
	public function ajax_render(): void {
		$ids = self::clean_ids( json_decode( wp_unslash( (string) ( $_POST['ids'] ?? '[]' ) ), true ) );
		ob_start();
		if ( $ids ) {
			$q = new \WP_Query( [
				'post_type'           => 'product',
				'post_status'         => 'publish',
				'post__in'            => $ids,
				'orderby'             => 'post__in',
				'posts_per_page'      => count( $ids ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			] );
		}
		if ( ! empty( $q ) && $q->have_posts() ) {
			wc_setup_loop( [ 'columns' => (int) wc_get_default_products_per_row() ] );
			woocommerce_product_loop_start();
			while ( $q->have_posts() ) {
				$q->the_post();
				wc_get_template_part( 'content', 'product' );
			}
			woocommerce_product_loop_end();
			wp_reset_postdata();
		} else {
			echo '<div class="nkzmp-favs__empty" style="padding:32px 20px;border-radius:16px;background:#f7f8fb;text-align:center;"><p style="font-size:18px;margin:0 0 8px;">' . esc_html__( 'Zatím tu nic není', 'nkz-mp-storefront' ) . '</p><p style="color:#6b7280;margin:0 0 16px;">' . esc_html__( 'Klikni na srdíčko u produktu a uložíš si ho sem na později.', 'nkz-mp-storefront' ) . '</p>';
			$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
			echo '<a href="' . esc_url( $shop ) . '" style="display:inline-block;padding:12px 22px;border-radius:999px;background:#0060FF;color:#fff;text-decoration:none;font-weight:600;">' . esc_html__( 'Projít obchod', 'nkz-mp-storefront' ) . '</a></div>';
		}
		wp_send_json_success( [ 'html' => (string) ob_get_clean() ] );
	}

	/* ============================================================ skript */

	public function script(): void {
		if ( $this->printed || is_admin() ) {
			return;
		}
		$this->printed = true;
		$uid    = get_current_user_id();
		$server = $uid > 0 ? self::clean_ids( get_user_meta( $uid, self::META, true ) ) : [];
		$cfg    = [
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => $uid > 0 ? wp_create_nonce( self::NONCE ) : '',
			'loggedIn' => $uid > 0,
			'server'   => $server,
			'page'     => self::page_url(),
			't'        => [
				'add'     => __( 'Přidat do oblíbených', 'nkz-mp-storefront' ),
				'remove'  => __( 'Odebrat z oblíbených', 'nkz-mp-storefront' ),
				'added'   => __( 'Uloženo do oblíbených', 'nkz-mp-storefront' ),
				'removed' => __( 'Odebráno z oblíbených', 'nkz-mp-storefront' ),
				'show'    => __( 'Zobrazit', 'nkz-mp-storefront' ),
			],
		];
		?>
		<style>
		html body ul.products li.product{position:relative}
		html body .nkzmp-fav,html body .nkzmp-fav:hover,html body .nkzmp-fav:focus,html body .nkzmp-fav:active{display:inline-flex!important;align-items:center;justify-content:center;gap:8px;padding:0!important;margin:0!important;border:0!important;box-shadow:none!important;cursor:pointer;line-height:1}
		html body ul.products li.product .nkzmp-fav{position:absolute;top:10px;right:10px;z-index:4;width:38px;height:38px;border-radius:50%!important;background:rgba(255,255,255,.92)!important;color:#111!important;box-shadow:0 1px 6px rgba(0,0,0,.12)!important;transition:transform .15s}
		html body ul.products li.product .nkzmp-fav:hover{transform:scale(1.08)}
		html body .nkzmp-fav--single,html body .nkzmp-fav--single:hover,html body .nkzmp-fav--single:focus{margin:12px 0 0!important;padding:10px 16px!important;border:1.5px solid #d5dbe6!important;border-radius:999px!important;background:#fff!important;color:#111!important;-webkit-text-fill-color:#111!important;font-size:15px!important;font-weight:500!important}
		html body .nkzmp-fav.is-on{color:#0060FF!important;-webkit-text-fill-color:#0060FF!important}
		html body .nkzmp-fav.is-on svg path{fill:currentColor}
		html body .nkzmp-fav--single.is-on{border-color:#0060FF!important}
		.nkzmp-fav-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(20px);opacity:0;z-index:99999;display:flex;gap:14px;align-items:center;padding:12px 18px;border-radius:999px;background:#111;color:#fff;font-size:14px;box-shadow:0 8px 24px rgba(0,0,0,.25);transition:opacity .2s,transform .2s;pointer-events:none}
		.nkzmp-fav-toast.is-shown{opacity:1;transform:translateX(-50%) translateY(0);pointer-events:auto}
		.nkzmp-fav-toast a{color:#9ec0ff;font-weight:600;text-decoration:none}
		.nkzmp-fav-count{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;padding:0 5px;border-radius:999px;background:#0060FF;color:#fff;font-size:11px;font-weight:700}
		.nkzmp-fav-count[hidden]{display:none}
		</style>
		<script>
		(function () {
			var C = <?php echo wp_json_encode( $cfg ); ?>;
			var KEY = 'nkzmp_favs';
			function load() { try { var a = JSON.parse(localStorage.getItem(KEY) || '[]'); return Array.isArray(a) ? a.map(Number).filter(Boolean) : []; } catch (e) { return []; } }
			function save(a) { try { localStorage.setItem(KEY, JSON.stringify(a)); } catch (e) {} }
			function post(action, data) {
				var b = new URLSearchParams(); b.set('action', action);
				Object.keys(data).forEach(function (k) { b.set(k, data[k]); });
				return fetch(C.ajax, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' }, body: b.toString() }).then(function (r) { return r.json(); });
			}
			function track(ev, p) { try { window.dataLayer = window.dataLayer || []; window.dataLayer.push(Object.assign({ event: 'nkzmp_' + ev }, p || {})); if (typeof window.gtag === 'function') { window.gtag('event', ev, p || {}); } } catch (e) {} }

			var favs = load();
			// Přihlášený: sloučit prohlížeč + účet.
			if (C.loggedIn) {
				var merged = favs.slice();
				C.server.forEach(function (id) { if (merged.indexOf(id) === -1) { merged.push(id); } });
				var changed = merged.length !== C.server.length;
				favs = merged; save(favs);
				if (changed && window.fetch) { post('nkzmp_fav_sync', { nonce: C.nonce, ids: JSON.stringify(favs) }).catch(function () {}); }
			}

			function paint() {
				document.querySelectorAll('[data-nkzmp-fav]').forEach(function (b) {
					var on = favs.indexOf(Number(b.getAttribute('data-nkzmp-fav'))) !== -1;
					b.classList.toggle('is-on', on);
					b.setAttribute('aria-pressed', on ? 'true' : 'false');
					b.setAttribute('aria-label', on ? C.t.remove : C.t.add);
					b.title = on ? C.t.remove : C.t.add;
				});
				document.querySelectorAll('[data-nkzmp-fav-count]').forEach(function (c) {
					c.textContent = favs.length; c.hidden = favs.length === 0;
				});
			}

			var toastEl, toastT;
			function toast(text, link) {
				if (!toastEl) { toastEl = document.createElement('div'); toastEl.className = 'nkzmp-fav-toast'; toastEl.setAttribute('role', 'status'); document.body.appendChild(toastEl); }
				toastEl.innerHTML = '';
				toastEl.appendChild(document.createTextNode(text));
				if (link) { var a = document.createElement('a'); a.href = C.page; a.textContent = C.t.show; toastEl.appendChild(a); }
				toastEl.classList.add('is-shown');
				clearTimeout(toastT); toastT = setTimeout(function () { toastEl.classList.remove('is-shown'); }, 2600);
			}

			document.addEventListener('click', function (e) {
				var b = e.target.closest('[data-nkzmp-fav]');
				if (!b) { return; }
				e.preventDefault(); e.stopPropagation();
				var id = Number(b.getAttribute('data-nkzmp-fav'));
				var i = favs.indexOf(id);
				if (i === -1) { favs.unshift(id); toast(C.t.added, true); track('add_to_wishlist', { item_id: id }); }
				else { favs.splice(i, 1); toast(C.t.removed, false); track('remove_from_wishlist', { item_id: id }); }
				save(favs); paint();
				if (C.loggedIn && window.fetch) { post('nkzmp_fav_sync', { nonce: C.nonce, ids: JSON.stringify(favs) }).catch(function () {}); }
				var page = document.querySelector('[data-nkzmp-favs]');
				if (page && i !== -1) { var li = b.closest('li.product'); if (li && page.contains(li)) { li.remove(); if (!page.querySelector('li.product')) { renderPage(); } } }
			}, true);

			function renderPage() {
				var page = document.querySelector('[data-nkzmp-favs]');
				if (!page || !window.fetch) { return; }
				post('nkzmp_fav_render', { ids: JSON.stringify(favs) }).then(function (r) {
					if (r && r.success) { page.innerHTML = r.data.html; paint(); }
				}).catch(function () {});
			}

			// Produkty dočtené AJAXem (filtr, Načíst další) – přebarvit.
			if (window.MutationObserver) {
				var t;
				new MutationObserver(function () { clearTimeout(t); t = setTimeout(paint, 50); }).observe(document.body, { childList: true, subtree: true });
			}
			paint();
			renderPage();
		})();
		</script>
		<?php
	}
}
