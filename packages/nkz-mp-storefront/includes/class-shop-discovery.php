<?php
/**
 * ShopDiscovery – výrazné vyhledávání a pás prodejců v obchodě.
 *
 * Klient chtěl, ať jsou prodejci a značky „nepřehlédnutelné". Filtr
 * v postranním panelu je na to málo – na mobilu je schovaný za tlačítkem.
 * Proto nahoře v obchodě:
 *
 *  - vyhledávání, které při psaní hned nabízí odpovídající prodejce
 *    (bez dotazu na server – prodejců jsou desítky, seznam je v stránce),
 *    a odesláním hledá v produktech,
 *  - na výsledcích hledání nad produkty i prodejci, kteří odpovídají,
 *  - na úvodu obchodu vodorovný pás prodejců s fotkou.
 *
 * Styly i skript jsou inline: LiteSpeed slepované CSS soubory cachuje a
 * změny vzhledu by se jinak „neprojevily".
 *
 * @package NKZMP\Storefront
 */

namespace NKZMP\Storefront;

defined( 'ABSPATH' ) || exit;

final class ShopDiscovery {

	private static ?ShopDiscovery $instance = null;

	public static function instance(): ShopDiscovery {
		return self::$instance ??= new self();
	}

	public function init(): void {
		// Před úvodní řádek s počty (ShopLoop::intro_row má prioritu 5).
		add_action( 'woocommerce_before_shop_loop', [ $this, 'render' ], 3 );
		// Když vyhledávání nic nenajde, WC hook výše nespustí – vykreslíme
		// pole aspoň tam, ať zákazník může hledat znovu.
		add_action( 'woocommerce_no_products_found', [ $this, 'render' ], 5 );
	}

	/** Jen hlavní stránka obchodu, kategorie a vyhledávání produktů. */
	private static function applicable(): bool {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}
		return is_shop() || is_product_taxonomy() || ( is_search() && get_query_var( 'post_type' ) === 'product' );
	}

	/**
	 * Prodejci pro vyhledávání a pás – id, jméno, odkaz, fotka, počet.
	 *
	 * @return array<int,array{id:int,name:string,url:string,img:string,count:int,key:string}>
	 */
	private static function vendors(): array {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$cache = [];
		foreach ( ShopFilters::product_vendors() as $vid => $v ) {
			$post = get_post( (int) $vid );
			if ( ! $post ) {
				continue;
			}
			$thumb   = get_the_post_thumbnail_url( $post, 'thumbnail' );
			$name    = (string) ( is_array( $v ) ? $v['name'] : $v );
			$cache[] = [
				'id'    => (int) $vid,
				'name'  => $name,
				'url'   => (string) get_permalink( $post ),
				'img'   => $thumb ? (string) $thumb : '',
				'count' => (int) ( is_array( $v ) ? $v['count'] : 0 ),
				'key'   => ShopFilters::fold( $name ),
			];
		}
		return $cache;
	}

	public function render(): void {
		static $done = false;
		if ( $done || ! self::applicable() ) {
			return;
		}
		$done = true;

		$vendors = self::vendors();
		$query   = is_search() ? (string) get_search_query( false ) : '';

		$this->styles();

		echo '<div class="nkzmp-discover">';
		$this->search_form( $query );

		if ( $query !== '' ) {
			$this->matching_vendors( $vendors, $query );
		} elseif ( is_shop() && ! self::filters_active() ) {
			$this->vendor_strip( $vendors );
		}
		echo '</div>';

		$this->script( $vendors );
	}

	/** Má zákazník zapnutý nějaký filtr? Pak pás prodejců jen překáží. */
	private static function filters_active(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		foreach ( [ 'vendor', 'cat', 'min_price', 'max_price', 'instock', 'orderby' ] as $k ) {
			if ( isset( $_GET[ $k ] ) && $_GET[ $k ] !== '' ) {
				return true;
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return is_paged();
	}

	private function search_form( string $query ): void {
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		?>
		<form class="nkzmp-discover__search" role="search" method="get" action="<?php echo esc_url( $shop ); ?>">
			<label class="screen-reader-text" for="nkzmp-discover-q"><?php esc_html_e( 'Hledat produkty a prodejce', 'nkz-mp-storefront' ); ?></label>
			<span class="nkzmp-discover__icon" aria-hidden="true">
				<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
			</span>
			<input id="nkzmp-discover-q" type="search" name="s" value="<?php echo esc_attr( $query ); ?>"
				placeholder="<?php esc_attr_e( 'Hledat produkty a značky…', 'nkz-mp-storefront' ); ?>"
				autocomplete="off" data-nkzmp-discover-input />
			<input type="hidden" name="post_type" value="product" />
			<button type="submit"><?php esc_html_e( 'Hledat', 'nkz-mp-storefront' ); ?></button>
			<div class="nkzmp-discover__suggest" data-nkzmp-discover-suggest hidden></div>
		</form>
		<?php
	}

	/** Prodejci odpovídající hledanému výrazu – nad výsledky produktů. */
	private function matching_vendors( array $vendors, string $query ): void {
		$q     = ShopFilters::fold( trim( $query ) );
		$found = array_values( array_filter( $vendors, static fn( $v ) => $q !== '' && str_contains( $v['key'], $q ) ) );
		if ( ! $found ) {
			return;
		}
		echo '<div class="nkzmp-discover__matches">';
		echo '<span class="nkzmp-discover__label">' . esc_html__( 'Značky:', 'nkz-mp-storefront' ) . '</span>';
		foreach ( array_slice( $found, 0, 12 ) as $v ) {
			$this->chip( $v );
		}
		echo '</div>';
	}

	/** Vodorovný pás prodejců na úvodu obchodu. */
	private function vendor_strip( array $vendors ): void {
		if ( count( $vendors ) < 2 ) {
			return;
		}
		// Náhodné pořadí (klient) – každá značka má šanci být vidět vpředu.
		// Míchá i prohlížeč (data-nkzmp-shuffle), protože stránku často
		// drží mezipaměť a serverové pořadí by bylo pro všechny stejné.
		shuffle( $vendors );
		$s       = class_exists( Settings::class ) ? Settings::get() : [];
		$archive = ( ( $s['enable_archive'] ?? 'yes' ) === 'yes' )
			? home_url( '/' . trim( (string) ( $s['archive_slug'] ?? 'vendors' ), '/' ) . '/' )
			: '';

		echo '<div class="nkzmp-discover__strip-head">';
		echo '<h2>' . esc_html__( 'Značky', 'nkz-mp-storefront' ) . '</h2>';
		if ( $archive !== '' ) {
			printf(
				'<a href="%s">%s</a>',
				esc_url( $archive ),
				esc_html( sprintf( /* translators: %d: počet */ __( 'Všech %d značek', 'nkz-mp-storefront' ), count( $vendors ) ) )
			);
		}
		echo '</div>';
		echo '<div class="nkzmp-discover__strip" data-nkzmp-shuffle>';
		foreach ( array_slice( $vendors, 0, (int) apply_filters( 'nkzmp/v1/storefront/vendor_strip_count', 60 ) ) as $v ) {
			printf(
				'<a class="nkzmp-discover__vendor" href="%1$s"><span class="nkzmp-discover__avatar"%2$s>%3$s</span><span class="nkzmp-discover__vname">%4$s</span></a>',
				esc_url( $v['url'] ),
				$v['img'] !== '' ? ' style="background-image:url(' . esc_url( $v['img'] ) . ')"' : '',
				$v['img'] === '' ? esc_html( self::initials( $v['name'] ) ) : '',
				esc_html( $v['name'] )
			);
		}
		echo '</div>';
	}

	private function chip( array $v ): void {
		printf(
			'<a class="nkzmp-discover__chip" href="%1$s"><span class="nkzmp-discover__cavatar"%2$s>%3$s</span>%4$s</a>',
			esc_url( $v['url'] ),
			$v['img'] !== '' ? ' style="background-image:url(' . esc_url( $v['img'] ) . ')"' : '',
			$v['img'] === '' ? esc_html( self::initials( $v['name'] ) ) : '',
			esc_html( $v['name'] )
		);
	}

	private static function initials( string $name ): string {
		$parts = preg_split( '/\s+/u', trim( $name ) ) ?: [];
		$out   = '';
		foreach ( array_slice( $parts, 0, 2 ) as $p ) {
			$out .= function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 );
		}
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $out ) : strtoupper( $out );
	}

	private function styles(): void {
		?>
		<style>
		.nkzmp-discover{margin:0 0 22px}
		/* Popisek jen pro čtečky – nespoléháme na to, že ho schová šablona. */
		.nkzmp-discover .screen-reader-text{position:absolute!important;width:1px!important;height:1px!important;padding:0!important;margin:-1px!important;overflow:hidden!important;clip:rect(0,0,0,0)!important;white-space:nowrap!important;border:0!important}
		.nkzmp-discover__search{position:relative;display:flex;align-items:center;gap:6px;background:#fff;border:1.5px solid #0060FF;border-radius:999px;padding:3px 3px 3px 14px;box-shadow:none}
		.nkzmp-discover__icon{color:#0060FF;display:flex;flex:0 0 auto}
		.nkzmp-discover__search input[type=search]{flex:1 1 auto;min-width:0;height:auto!important;border:0!important;outline:0;background:none!important;font-size:15px;padding:6px 4px!important;box-shadow:none!important}
		.nkzmp-discover__search button{flex:0 0 auto;background:#0060FF!important;color:#fff!important;-webkit-text-fill-color:#fff!important;border:0!important;border-radius:999px!important;padding:7px 16px!important;font-size:14px!important;font-weight:600;cursor:pointer}
		.nkzmp-discover__suggest{position:absolute;left:0;right:0;top:calc(100% + 6px);background:#fff;border:1px solid #e6e8ee;border-radius:16px;box-shadow:0 12px 32px rgba(0,0,0,.12);padding:6px;z-index:50;max-height:60vh;overflow-y:auto}
		.nkzmp-discover__suggest a{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:10px;text-decoration:none;color:inherit}
		.nkzmp-discover__suggest a:hover,.nkzmp-discover__suggest a.is-active{background:#f2f6ff}
		.nkzmp-discover__suggest small{margin-left:auto;color:#6b7280}
		.nkzmp-discover__suggest .nkzmp-discover__sghead{font-size:12px;text-transform:uppercase;letter-spacing:.06em;color:#6b7280;padding:8px 10px 4px}
		.nkzmp-discover__cavatar,.nkzmp-discover__avatar{display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#e8efff center/cover no-repeat;color:#0060FF;font-weight:700;flex:0 0 auto}
		.nkzmp-discover__cavatar{width:28px;height:28px;font-size:12px}
		.nkzmp-discover__matches{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin:14px 0 0}
		.nkzmp-discover__label{font-size:14px;color:#6b7280}
		.nkzmp-discover__chip{display:inline-flex;align-items:center;gap:8px;padding:4px 14px 4px 4px;border:1px solid #d9dce3;border-radius:999px;text-decoration:none;color:inherit;font-size:14px}
		.nkzmp-discover__chip:hover{border-color:#0060FF}
		.nkzmp-discover__strip-head{display:flex;align-items:baseline;justify-content:space-between;gap:12px;margin:22px 0 10px}
		.nkzmp-discover__strip-head h2{margin:0;font-size:20px}
		.nkzmp-discover__strip-head a{color:#0060FF;font-weight:600;text-decoration:none;white-space:nowrap}
		.nkzmp-discover__strip{display:flex;gap:16px;overflow-x:auto;padding:4px 2px 10px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
		.nkzmp-discover__vendor{flex:0 0 auto;width:84px;display:flex;flex-direction:column;align-items:center;gap:6px;text-decoration:none;color:inherit;scroll-snap-align:start}
		.nkzmp-discover__avatar{width:72px;height:72px;font-size:22px;border:2px solid #fff;box-shadow:0 0 0 2px #0060FF}
		.nkzmp-discover__vendor:hover .nkzmp-discover__avatar{box-shadow:0 0 0 3px #0060FF}
		.nkzmp-discover__vname{font-size:12px;line-height:1.25;text-align:center;max-width:84px;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
		@media(max-width:520px){
			.nkzmp-discover__search button{padding:10px 14px!important}
			.nkzmp-discover__search{padding-left:12px}
		}
		</style>
		<?php
	}

	private function script( array $vendors ): void {
		$data = array_map( static fn( $v ) => [
			'n' => $v['name'],
			'u' => $v['url'],
			'i' => $v['img'],
			'c' => $v['count'],
			'k' => $v['key'],
		], $vendors );
		?>
		<script>
		// Pás značek: zamíchat v prohlížeči (stránka bývá v mezipaměti).
		document.querySelectorAll('[data-nkzmp-shuffle]').forEach(function (strip) {
			var items = Array.prototype.slice.call(strip.children);
			for (var i = items.length - 1; i > 0; i--) {
				var j = Math.floor(Math.random() * (i + 1));
				var t = items[i]; items[i] = items[j]; items[j] = t;
			}
			items.forEach(function (el) { strip.appendChild(el); });
		});
		(function () {
			var input = document.querySelector('[data-nkzmp-discover-input]');
			var box = document.querySelector('[data-nkzmp-discover-suggest]');
			if (!input || !box) { return; }
			var vendors = <?php echo wp_json_encode( $data ); ?>;
			var active = -1;

			function fold(s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); }
			function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }
			function initials(n) { return n.split(/\s+/).slice(0, 2).map(function (p) { return p.charAt(0); }).join('').toUpperCase(); }

			// Naposledy hledané (jen v tomhle prohlížeči).
			var RKEY = 'nkzmp_recent_search';
			function recent() { try { return JSON.parse(localStorage.getItem(RKEY) || '[]'); } catch (e) { return []; } }
			function remember(q) {
				q = (q || '').trim();
				if (q.length < 2) { return; }
				var list = recent().filter(function (x) { return fold(x) !== fold(q); });
				list.unshift(q);
				try { localStorage.setItem(RKEY, JSON.stringify(list.slice(0, 5))); } catch (e) {}
			}
			function track(ev, params) {
				try {
					window.dataLayer = window.dataLayer || [];
					window.dataLayer.push(Object.assign({ event: 'nkzmp_' + ev }, params || {}));
					if (typeof window.gtag === 'function') { window.gtag('event', ev, params || {}); }
				} catch (e) {}
			}
			input.form.addEventListener('submit', function () {
				remember(input.value);
				track('search', { search_term: input.value.trim() });
			});
			document.querySelectorAll('.nkzmp-discover__vendor').forEach(function (a) {
				a.addEventListener('click', function () { track('brand_strip_click', { brand: a.textContent.trim() }); });
			});

			function render() {
				var q = fold(input.value.trim());
				active = -1;
				if (q.length < 2) {
					var r = recent();
					if (!r.length) { box.hidden = true; box.innerHTML = ''; return; }
					var rh = '<div class="nkzmp-discover__sghead"><?php echo esc_js( __( 'Naposledy hledané', 'nkz-mp-storefront' ) ); ?></div>';
					r.forEach(function (t) {
						rh += '<a href="#" data-nkzmp-recent="' + esc(t) + '"><span aria-hidden="true" style="opacity:.5">↺</span>&nbsp;' + esc(t) + '</a>';
					});
					box.innerHTML = rh;
					box.hidden = false;
					return;
				}
				var hits = vendors.filter(function (v) { return v.k.indexOf(q) !== -1; }).slice(0, 6);
				var html = '';
				if (hits.length) {
					html += '<div class="nkzmp-discover__sghead"><?php echo esc_js( __( 'Značky', 'nkz-mp-storefront' ) ); ?></div>';
					hits.forEach(function (v) {
						var av = v.i
							? '<span class="nkzmp-discover__cavatar" style="background-image:url(' + encodeURI(v.i) + ')"></span>'
							: '<span class="nkzmp-discover__cavatar">' + esc(initials(v.n)) + '</span>';
						html += '<a href="' + encodeURI(v.u) + '">' + av + esc(v.n) + (v.c ? '<small>' + v.c + ' <?php echo esc_js( __( 'prod.', 'nkz-mp-storefront' ) ); ?></small>' : '') + '</a>';
					});
				}
				html += '<div class="nkzmp-discover__sghead"><?php echo esc_js( __( 'Produkty', 'nkz-mp-storefront' ) ); ?></div>';
				html += '<a href="#" data-nkzmp-submit><?php echo esc_js( __( 'Hledat v produktech:', 'nkz-mp-storefront' ) ); ?>&nbsp;<strong>' + esc(input.value.trim()) + '</strong></a>';
				box.innerHTML = html;
				box.hidden = false;
			}

			function links() { return Array.prototype.slice.call(box.querySelectorAll('a')); }

			input.addEventListener('input', render);
			input.addEventListener('focus', render);
			input.addEventListener('keydown', function (e) {
				var l = links();
				if (box.hidden || !l.length) { return; }
				if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
					e.preventDefault();
					active = (active + (e.key === 'ArrowDown' ? 1 : -1) + l.length) % l.length;
					l.forEach(function (a, i) { a.classList.toggle('is-active', i === active); });
				} else if (e.key === 'Enter' && active >= 0) {
					e.preventDefault();
					l[active].click();
				} else if (e.key === 'Escape') {
					box.hidden = true;
				}
			});
			box.addEventListener('click', function (e) {
				var rec = e.target.closest('[data-nkzmp-recent]');
				if (rec) {
					e.preventDefault();
					input.value = rec.getAttribute('data-nkzmp-recent');
					remember(input.value);
					track('search', { search_term: input.value, source: 'recent' });
					input.form.submit();
					return;
				}
				var a = e.target.closest('[data-nkzmp-submit]');
				if (a) { e.preventDefault(); remember(input.value); track('search', { search_term: input.value.trim() }); input.form.submit(); }
			});
			document.addEventListener('click', function (e) {
				if (!input.form.contains(e.target)) { box.hidden = true; }
			});
		})();
		</script>
		<?php
	}
}
