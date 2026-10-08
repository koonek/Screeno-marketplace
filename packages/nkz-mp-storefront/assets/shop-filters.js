/**
 * Shop filters – AJAX překreslení gridu bez reloadu.
 *
 * - Změna kteréhokoliv filtru (checkbox / cena / skladem) → fetch → swap.
 * - Klik na stránkování uvnitř výsledků → fetch s paged.
 * - Změna řazení (.orderby) uvnitř výsledků → fetch s orderby.
 * - Stav se promítá do URL (history), takže refresh/sdílení funguje.
 * - Mobil: tlačítko „Filtry" otevře sidebar (off-canvas).
 *
 * @package NKZMP\Storefront
 */
( function () {
	'use strict';

	var cfg = window.nkzmpShopFilters || {};
	if ( ! cfg.ajaxUrl ) {
		return;
	}

	var form    = document.querySelector( '.nkzmp-filters' );
	var results = document.getElementById( 'nkzmp-shop-results' );
	var layout  = document.querySelector( '.nkzmp-shop-layout' );
	if ( ! form || ! results || ! layout ) {
		return;
	}

	var debounceTimer = null;
	var currentReq    = null;
	var lastChanged   = null; // poslední změněný filtr (pro „Zrušit poslední filtr")
	var currentPaged  = 1;

	/* Měření: dataLayer (GTM) + gtag (GA4), když jsou na webu. */
	function track( ev, params ) {
		try {
			window.dataLayer = window.dataLayer || [];
			window.dataLayer.push( Object.assign( { event: 'nkzmp_' + ev }, params || {} ) );
			if ( typeof window.gtag === 'function' ) { window.gtag( 'event', ev, params || {} ); }
		} catch ( e ) {}
	}

	form.addEventListener( 'change', function ( e ) { lastChanged = e.target; }, true );
	form.addEventListener( 'input', function ( e ) {
		if ( e.target.matches( '[data-nkzmp-price], [data-nkzmp-range], [data-nkzmp-search]' ) ) { lastChanged = e.target; }
	}, true );

	/* ───────── Počítač: filtry jako řada tlačítek s nabídkou ───────── */

	function setupBar() {
		if ( form.getAttribute( 'data-layout' ) === 'side' ) { return; } // nastavení: filtry vlevo
		layout.classList.add( 'is-bar' );
		form.querySelectorAll( 'fieldset.nkzmp-filters__group' ).forEach( function ( g ) {
			if ( g.classList.contains( 'nkzmp-filters__group--search' ) || g.classList.contains( 'nkzmp-filters__stock' ) ) { return; }
			var legend = g.querySelector( 'legend' );
			if ( ! legend || g.querySelector( '.nkzmp-bar-btn' ) ) { return; }
			var label = ( legend.firstChild && legend.firstChild.nodeType === 3 ? legend.firstChild.textContent : legend.textContent ).trim();
			var btn = document.createElement( 'button' );
			btn.type = 'button';
			btn.className = 'nkzmp-bar-btn';
			btn.setAttribute( 'aria-expanded', 'false' );
			btn.setAttribute( 'data-label', label );
			btn.textContent = label;
			var panel = document.createElement( 'div' );
			panel.className = 'nkzmp-bar-panel';
			Array.prototype.slice.call( g.childNodes ).forEach( function ( n ) {
				if ( n !== legend ) { panel.appendChild( n ); }
			} );
			g.appendChild( btn );
			g.appendChild( panel );
			g.classList.add( 'has-panel' );
			btn.addEventListener( 'click', function ( e ) {
				e.stopPropagation();
				var open = ! g.classList.contains( 'is-open' );
				closeBar();
				g.classList.toggle( 'is-open', open );
				btn.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			} );
		} );
		document.addEventListener( 'click', function ( e ) {
			if ( ! e.target.closest( '.nkzmp-filters__group.is-open' ) ) { closeBar(); }
		} );
		document.addEventListener( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { closeBar(); } } );
	}

	function closeBar() {
		form.querySelectorAll( '.nkzmp-filters__group.is-open' ).forEach( function ( g ) {
			g.classList.remove( 'is-open' );
			var b = g.querySelector( '.nkzmp-bar-btn' );
			if ( b ) { b.setAttribute( 'aria-expanded', 'false' ); }
		} );
	}

	// Počet vybraných v tlačítku („Značky · 2") + zvýraznění aktivního.
	function updateBarState() {
		form.querySelectorAll( '.nkzmp-filters__group.has-panel' ).forEach( function ( g ) {
			var btn = g.querySelector( '.nkzmp-bar-btn' );
			var n = g.querySelectorAll( 'input[type="checkbox"]:checked' ).length;
			if ( g.classList.contains( 'nkzmp-filters__price' ) ) {
				var a = g.querySelector( '[data-nkzmp-price="min"]' ), b = g.querySelector( '[data-nkzmp-price="max"]' );
				n = ( a && a.value !== '' ) || ( b && b.value !== '' ) ? 1 : 0;
			}
			g.classList.toggle( 'is-active', n > 0 );
			if ( btn ) { btn.textContent = btn.getAttribute( 'data-label' ) + ( n > 1 ? ' · ' + n : '' ); }
		} );
	}

	/* ───────── Prázdný výsledek ───────── */

	function undoLast() {
		var el = lastChanged;
		if ( ! el ) { var c = form.querySelector( '[data-nkzmp-clear]' ); if ( c ) { c.click(); } return; }
		if ( el.type === 'checkbox' ) {
			el.checked = false;
		} else if ( el.matches( '[data-nkzmp-range="min"], [data-nkzmp-price="min"]' ) ) {
			var mi = form.querySelector( '[data-nkzmp-price="min"]' ); if ( mi ) { mi.value = ''; } syncRangeFromInputs();
		} else if ( el.matches( '[data-nkzmp-range="max"], [data-nkzmp-price="max"]' ) ) {
			var ma = form.querySelector( '[data-nkzmp-price="max"]' ); if ( ma ) { ma.value = ''; } syncRangeFromInputs();
		} else if ( el.matches( '[data-nkzmp-search]' ) ) {
			el.value = '';
		}
		lastChanged = null;
		applyReset();
	}

	function renderEmpty( total ) {
		var old = results.querySelector( '.nkzmp-empty' );
		if ( old ) { old.remove(); }
		if ( total !== 0 ) { return; }
		var box = document.createElement( 'div' );
		box.className = 'nkzmp-empty';
		box.innerHTML = '<h3>Tady nic není</h3><p>Zkus zrušit poslední filtr nebo vybrat jinou kategorii.</p><div class="nkzmp-empty-actions"></div>';
		var acts = box.querySelector( '.nkzmp-empty-actions' );
		if ( lastChanged ) {
			var u = document.createElement( 'button' );
			u.type = 'button'; u.className = 'is-primary'; u.textContent = 'Zrušit poslední filtr';
			u.addEventListener( 'click', undoLast );
			acts.appendChild( u );
		}
		var c = document.createElement( 'button' );
		c.type = 'button'; c.textContent = 'Vymazat všechny filtry';
		c.addEventListener( 'click', function () { var x = form.querySelector( '[data-nkzmp-clear]' ); if ( x ) { x.click(); } } );
		acts.appendChild( c );
		var chips = results.querySelector( '.nkzmp-active-chips' );
		if ( chips && chips.nextSibling ) { results.insertBefore( box, chips.nextSibling ); } else { results.insertBefore( box, results.firstChild ); }
		var srv = results.querySelector( '.nkzmp-shop-empty' );
		if ( srv ) { srv.style.display = 'none'; }
	}

	/* ───────── Mobil: „Načíst další" místo stránkování ───────── */

	function isMobile() { return !! ( window.matchMedia && window.matchMedia( '(max-width: 767px)' ).matches ); }

	function setupLoadMore() {
		var old = results.querySelector( '.nkzmp-load-more' );
		if ( old ) { old.remove(); }
		if ( ! isMobile() || ! window.fetch ) { return; }
		var pag = results.querySelector( '.woocommerce-pagination' );
		if ( ! pag ) { return; }
		var next = pag.querySelector( 'a.next' );
		if ( ! next ) { return; }
		pag.style.display = 'none';
		var btn = document.createElement( 'button' );
		btn.type = 'button';
		btn.className = 'nkzmp-load-more';
		btn.textContent = 'Načíst další produkty';
		pag.parentNode.insertBefore( btn, pag.nextSibling );
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			btn.textContent = 'Načítám…';
			var data = collect();
			data.paged = currentPaged + 1;
			fetch( sameOriginAjax(), {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: buildBody( data ).toString(),
				credentials: 'same-origin'
			} )
				.then( function ( r ) { if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); } return r.json(); } )
				.then( function ( res ) {
					var tmp = document.createElement( 'div' );
					tmp.innerHTML = ( res && res.data && res.data.html ) || '';
					var ul = results.querySelector( 'ul.products' );
					tmp.querySelectorAll( 'ul.products > li' ).forEach( function ( li ) { if ( ul ) { ul.appendChild( li ); } } );
					currentPaged = data.paged;
					track( 'load_more', { page: currentPaged } );
					if ( tmp.querySelector( '.woocommerce-pagination a.next' ) ) {
						btn.disabled = false;
						btn.textContent = 'Načíst další produkty';
					} else {
						btn.remove();
					}
				} )
				.catch( function () { window.location.href = next.href; } );
		} );
	}

	/* ───────── Počty ve filtru podle aktuálního výběru ───────── */

	function updateFacets( f ) {
		if ( ! f ) { return; }
		// Značky: počty, nulové schovat (zaškrtnuté nechat), prvních 8 vidět.
		var wrap = form.querySelector( '[data-nkzmp-vendorwrap]' );
		if ( wrap && f.brands ) {
			var visible = 8, shownN = 0, live = 0;
			wrap.querySelectorAll( 'li[data-nkzmp-vendor-name]' ).forEach( function ( li ) {
				var inp = li.querySelector( 'input' );
				var n = parseInt( f.brands[ inp.value ] || 0, 10 );
				var em = li.querySelector( 'em' );
				if ( em ) { em.textContent = n > 0 ? n : ''; }
				var zero = n === 0 && ! inp.checked;
				li.classList.toggle( 'is-zero', zero );
				if ( zero ) { li.removeAttribute( 'data-nkzmp-vendor-extra' ); return; }
				live++;
				if ( shownN >= visible ) { li.setAttribute( 'data-nkzmp-vendor-extra', '' ); } else { li.removeAttribute( 'data-nkzmp-vendor-extra' ); }
				shownN++;
			} );
			var group = wrap.closest( '.nkzmp-filters__group' );
			var cnt = group ? group.querySelector( '.nkzmp-filters__legend-count' ) : null;
			if ( cnt ) { cnt.textContent = live; }
			var vs = wrap.querySelector( '[data-nkzmp-vendorsearch]' );
			if ( vs ) { vs.placeholder = vs.placeholder.replace( /\(\d+\)/, '(' + live + ')' ); }
			var more = wrap.querySelector( '[data-nkzmp-vendormore]' );
			if ( typeof wrap.nkzmpRefresh === 'function' ) {
				wrap.nkzmpRefresh( more ? more.textContent.replace( /^.*?\(/, 'Zobrazit všechny značky (' ).replace( /\(\d+\)/, '(' + live + ')' ) : '' );
			}
			if ( more && live <= visible ) { more.hidden = true; }
		}
		// Graf cen: výšky sloupců pro aktuální výběr (stupnice zůstává).
		var bars = form.querySelectorAll( '.nkzmp-filters__hist span' );
		if ( bars.length && f.hist && f.hist.length === bars.length ) {
			var peak = Math.max.apply( null, f.hist );
			Array.prototype.forEach.call( bars, function ( b, i ) {
				var c = f.hist[ i ];
				b.style.height = peak > 0 && c > 0 ? Math.max( 5, Math.round( c / peak * 100 ) ) + '%' : '0%';
			} );
		}
	}

	function afterRender( total ) {
		updateBarState();
		renderEmpty( total );
		setupLoadMore();
	}

	/* ───────── Collect filter state ───────── */

	function collect() {
		var data = {
			cat: [],
			vendor: [],
			min_price: '',
			max_price: '',
			instock: '',
			q: '',
			orderby: '',
			paged: 1
		};

		var searchEl = form.querySelector( '[data-nkzmp-search]' );
		if ( searchEl && searchEl.value.trim() !== '' ) { data.q = searchEl.value.trim(); }

		form.querySelectorAll( 'input[name="cat[]"]:checked' ).forEach( function ( el ) {
			data.cat.push( el.value );
		} );
		form.querySelectorAll( 'input[name="vendor[]"]:checked' ).forEach( function ( el ) {
			data.vendor.push( el.value );
		} );

		var minEl = form.querySelector( '[data-nkzmp-price="min"]' );
		var maxEl = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( minEl && minEl.value !== '' ) { data.min_price = minEl.value; }
		if ( maxEl && maxEl.value !== '' ) { data.max_price = maxEl.value; }

		var stockEl = form.querySelector( 'input[name="instock"]' );
		if ( stockEl && stockEl.checked ) { data.instock = '1'; }

		var orderEl = results.querySelector( 'select.orderby' );
		if ( orderEl ) { data.orderby = orderEl.value; }

		return data;
	}

	/* ───────── URL sync ───────── */

	function toQuery( data ) {
		var p = new URLSearchParams();
		data.cat.forEach( function ( v ) { p.append( 'cat[]', v ); } );
		data.vendor.forEach( function ( v ) { p.append( 'vendor[]', v ); } );
		if ( data.min_price !== '' ) { p.set( 'min_price', data.min_price ); }
		if ( data.max_price !== '' ) { p.set( 'max_price', data.max_price ); }
		if ( data.instock ) { p.set( 'instock', '1' ); }
		if ( data.q ) { p.set( 'q', data.q ); }
		if ( data.orderby ) { p.set( 'orderby', data.orderby ); }
		if ( data.paged > 1 ) { p.set( 'paged', data.paged ); }
		return p.toString();
	}

	function syncUrl( data ) {
		var qs  = toQuery( data );
		var url = window.location.pathname + ( qs ? '?' + qs : '' );
		window.history.replaceState( null, '', url );
	}

	/* ───────── Fetch + render ───────── */

	function buildBody( data ) {
		var body = new URLSearchParams();
		body.set( 'action', cfg.action );
		body.set( 'nonce', cfg.nonce );
		data.cat.forEach( function ( v ) { body.append( 'cat[]', v ); } );
		data.vendor.forEach( function ( v ) { body.append( 'vendor[]', v ); } );
		if ( data.min_price !== '' ) { body.set( 'min_price', data.min_price ); }
		if ( data.max_price !== '' ) { body.set( 'max_price', data.max_price ); }
		if ( data.instock ) { body.set( 'instock', '1' ); }
		if ( data.q ) { body.set( 'q', data.q ); }
		if ( data.orderby ) { body.set( 'orderby', data.orderby ); }
		body.set( 'paged', data.paged );
		return body;
	}

	function sameOriginAjax() {
		var ajaxUrl = cfg.ajaxUrl;
		try {
			var u = new URL( ajaxUrl, window.location.href );
			if ( u.origin !== window.location.origin ) {
				ajaxUrl = window.location.origin + u.pathname + u.search;
			}
		} catch ( e ) {}
		return ajaxUrl;
	}

	function apply( data ) {
		syncUrl( data );

		var body = buildBody( data );

		layout.classList.add( 'is-loading' );

		// fetch / AbortController nemusi byt ve starsim Safari → fallback na
		// nativni odeslani formulare (GET reload, server-side filtr).
		if ( ! window.fetch ) {
			if ( form.requestSubmit ) { form.requestSubmit(); } else { form.submit(); }
			return;
		}
		var hasAbort = 'AbortController' in window;
		if ( hasAbort && currentReq && currentReq.abort ) {
			currentReq.abort();
		}
		var controller = hasAbort ? new AbortController() : null;
		currentReq = controller;

		// Přepiš AJAX URL na STEJNÝ origin jako aktuální stránka. admin_url()
		// může po migraci / za proxy vracet jinou doménu nebo http místo https
		// → Safari/WebKit to tiše zablokuje jako cross-origin/mixed-content.
		var ajaxUrl = cfg.ajaxUrl;
		try {
			var u = new URL( ajaxUrl, window.location.href );
			if ( u.origin !== window.location.origin ) {
				ajaxUrl = window.location.origin + u.pathname + u.search;
			}
		} catch ( e ) {}

		// Když AJAX selže (Safari blok, síť), spadni na nativní reload s GET
		// parametry → server vyfiltruje. Zaručeně funguje ve všech prohlížečích.
		var fellBack = false;
		function fallbackReload() {
			if ( fellBack ) { return; }
			fellBack = true;
			var qs = toQuery( data );
			window.location.href = window.location.pathname + ( qs ? '?' + qs : '' );
		}

		fetch( ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
			signal: controller ? controller.signal : undefined
		} )
			.then( function ( r ) {
				if ( ! r.ok ) { throw new Error( 'HTTP ' + r.status ); }
				return r.json();
			} )
			.then( function ( res ) {
				if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
					results.innerHTML = res.data.html;
					var total = parseInt( res.data.total, 10 );
					currentPaged = data.paged || 1;
					updateDone( total );
					updateFacets( res.data.facets );
					renderChips();
					afterRender( total );
					track( 'filter_apply', {
						categories: data.cat.join( ',' ),
						brands: data.vendor.join( ',' ),
						min_price: data.min_price,
						max_price: data.max_price,
						search_term: data.q,
						in_stock: data.instock ? 1 : 0,
						results: total
					} );
					if ( total === 0 ) { track( 'empty_results', { search_term: data.q, categories: data.cat.join( ',' ) } ); }
				}
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) { return; }
				if ( window.console && console.warn ) {
					console.warn( '[nkzmp filters] AJAX selhal, fallback na reload:', err );
				}
				fallbackReload();
			} )
			.finally( function () {
				layout.classList.remove( 'is-loading' );
				currentReq = null;
			} );
	}

	/* ───────── Aktivní filtry jako štítky + počet na „Hotovo" ───────── */

	function showLabel( n ) {
		if ( isNaN( n ) ) { return null; }
		if ( n === 0 ) { return 'Žádné produkty – uprav filtr'; }
		if ( n === 1 ) { return 'Zobrazit 1 produkt'; }
		if ( n >= 2 && n <= 4 ) { return 'Zobrazit ' + n + ' produkty'; }
		return 'Zobrazit ' + n + ' produktů';
	}

	function updateDone( n ) {
		var done = form.querySelector( '[data-nkzmp-done]' );
		var label = showLabel( n );
		if ( done && label ) { done.textContent = label; done.setAttribute( 'data-total', n ); }
	}

	function labelOf( input ) {
		var l = input.closest( 'label' );
		var sp = l ? l.querySelector( 'span' ) : null;
		return sp ? sp.textContent.trim() : input.value;
	}

	function chip( text, onRemove, cls ) {
		var b = document.createElement( 'button' );
		b.type = 'button';
		if ( cls ) { b.className = cls; }
		b.appendChild( document.createTextNode( text ) );
		if ( ! cls ) {
			var x = document.createElement( 'span' );
			x.className = 'x';
			x.setAttribute( 'aria-hidden', 'true' );
			x.textContent = '×';
			b.appendChild( x );
			b.setAttribute( 'aria-label', 'Zrušit filtr: ' + text );
		}
		b.addEventListener( 'click', onRemove );
		return b;
	}

	function renderChips() {
		var box = results.querySelector( '.nkzmp-active-chips' );
		if ( ! box ) {
			box = document.createElement( 'div' );
			box.className = 'nkzmp-active-chips';
			results.insertBefore( box, results.firstChild );
		}
		box.innerHTML = '';
		var n = 0;
		form.querySelectorAll( 'input[name="cat[]"]:checked, input[name="vendor[]"]:checked' ).forEach( function ( el ) {
			n++;
			box.appendChild( chip( labelOf( el ), function () { el.checked = false; applyReset(); } ) );
		} );
		var iMin = form.querySelector( '[data-nkzmp-price="min"]' );
		var iMax = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( iMin && iMax && ( iMin.value !== '' || iMax.value !== '' ) ) {
			n++;
			var txt = iMin.value !== '' && iMax.value !== '' ? iMin.value + ' – ' + iMax.value + ' Kč'
				: ( iMin.value !== '' ? 'od ' + iMin.value + ' Kč' : 'do ' + iMax.value + ' Kč' );
			box.appendChild( chip( txt, function () {
				iMin.value = ''; iMax.value = ''; syncRangeFromInputs(); applyReset();
			} ) );
		}
		var st = form.querySelector( 'input[name="instock"]' );
		if ( st && st.checked ) {
			n++;
			box.appendChild( chip( 'Skladem', function () { st.checked = false; applyReset(); } ) );
		}
		var q = form.querySelector( '[data-nkzmp-search]' );
		if ( q && q.value.trim() !== '' ) {
			n++;
			box.appendChild( chip( '„' + q.value.trim() + '“', function () { q.value = ''; applyReset(); } ) );
		}
		if ( n > 1 ) {
			box.appendChild( chip( 'Vymazat vše', function () {
				var c = form.querySelector( '[data-nkzmp-clear]' );
				if ( c ) { c.click(); }
			}, 'is-clear' ) );
		}
		box.hidden = n === 0;
	}

	function applyReset() {
		var data = collect();
		data.paged = 1;
		apply( data );
	}

	/* ───────── Bindings ───────── */

	// Checkboxy (kategorie, prodejce, skladem).
	form.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( 'input[type="checkbox"]' ) ) {
			applyReset();
		}
	} );

	// Cena – number inputs (debounce) + sync s rangem.
	form.addEventListener( 'input', function ( e ) {
		var t = e.target;
		if ( t.matches( '[data-nkzmp-price]' ) ) {
			syncRangeFromInputs();
			debounce( applyReset, 450 );
		} else if ( t.matches( '[data-nkzmp-range]' ) ) {
			syncInputsFromRange();
			debounce( applyReset, 250 );
		} else if ( t.matches( '[data-nkzmp-search]' ) ) {
			debounce( applyReset, 400 );
		}
	} );

	// Enter v search poli: pokud fetch funguje, hledej AJAXem (bez reloadu).
	// Když fetch chybí (staré Safari), nechame formular odeslat nativne (GET
	// reload = spolehlivý server-side filtr).
	form.addEventListener( 'keydown', function ( e ) {
		if ( e.target.matches( '[data-nkzmp-search]' ) && e.key === 'Enter' ) {
			if ( window.fetch ) {
				e.preventDefault();
				applyReset();
			}
		}
	} );

	// Safari fallback: delegovany 'input' na type=search nemusi spolehlive
	// vystrelit + nativni X (clear) strili 'search' event. Navesime primo.
	var searchInput = form.querySelector( '[data-nkzmp-search]' );
	if ( searchInput ) {
		[ 'input', 'keyup', 'search', 'change' ].forEach( function ( ev ) {
			searchInput.addEventListener( ev, function () {
				debounce( applyReset, 400 );
			} );
		} );
	}

	// Submit formu: fetch OK → AJAX (bez reloadu). Bez fetch → necháme
	// nativní odeslání (GET reload = spolehlivý fallback ve všech prohlížečích).
	form.addEventListener( 'submit', function ( e ) {
		if ( window.fetch ) {
			e.preventDefault();
			applyReset();
		}
	} );

	function debounce( fn, ms ) {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( fn, ms );
	}

	/* Logaritmická stupnice posuvníku: pozice 0–1000 ↔ cena lo–hi. */
	function priceScale() {
		var w = form.querySelector( '.nkzmp-filters__range' );
		if ( ! w ) { return null; }
		var lo = parseFloat( w.getAttribute( 'data-lo' ) ), hi = parseFloat( w.getAttribute( 'data-hi' ) );
		if ( ! ( lo > 0 ) || ! ( hi > lo ) ) { return null; }
		return { lo: lo, hi: hi };
	}
	function toPos( price ) {
		var s = priceScale(); if ( ! s ) { return 0; }
		var p = Math.max( s.lo, Math.min( s.hi, parseFloat( price ) ) );
		return Math.round( 1000 * Math.log( p / s.lo ) / Math.log( s.hi / s.lo ) );
	}
	function nice( p ) {
		var step = p < 200 ? 10 : p < 2000 ? 50 : p < 20000 ? 500 : 1000;
		return Math.max( step, Math.round( p / step ) * step );
	}
	function toPrice( pos ) {
		var s = priceScale(); if ( ! s ) { return ''; }
		return nice( s.lo * Math.pow( s.hi / s.lo, pos / 1000 ) );
	}

	function syncRangeFromInputs() {
		var rMin = form.querySelector( '[data-nkzmp-range="min"]' );
		var rMax = form.querySelector( '[data-nkzmp-range="max"]' );
		var iMin = form.querySelector( '[data-nkzmp-price="min"]' );
		var iMax = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( rMin && iMin ) { rMin.value = iMin.value === '' ? 0 : toPos( iMin.value ); }
		if ( rMax && iMax ) { rMax.value = iMax.value === '' ? 1000 : toPos( iMax.value ); }
		updateRangeFill();
	}

	// Modrý fill mezi thumby + zvýraznění sloupců grafu ve zvoleném rozsahu.
	function updateRangeFill() {
		var rMin = form.querySelector( '[data-nkzmp-range="min"]' );
		var rMax = form.querySelector( '[data-nkzmp-range="max"]' );
		var fill = form.querySelector( '[data-nkzmp-range-fill]' );
		if ( ! rMin || ! rMax ) { return; }
		var a = parseFloat( rMin.value ) / 10, b = parseFloat( rMax.value ) / 10;
		if ( a > b ) { var t = a; a = b; b = t; }
		if ( fill ) { fill.style.left = a + '%'; fill.style.right = ( 100 - b ) + '%'; }
		var hist = form.querySelector( '.nkzmp-filters__hist' );
		if ( hist ) {
			var bars = hist.querySelectorAll( 'span' );
			var n = bars.length, full = a <= 0 && b >= 100;
			hist.classList.toggle( 'is-filtered', ! full );
			Array.prototype.forEach.call( bars, function ( bar, i ) {
				var mid = ( i + 0.5 ) / n * 100;
				bar.classList.toggle( 'is-in', full || ( mid >= a && mid <= b ) );
			} );
		}
	}

	function syncInputsFromRange() {
		var rMin = form.querySelector( '[data-nkzmp-range="min"]' );
		var rMax = form.querySelector( '[data-nkzmp-range="max"]' );
		var iMin = form.querySelector( '[data-nkzmp-price="min"]' );
		var iMax = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( ! rMin || ! rMax || ! iMin || ! iMax ) { return; }
		// Nedovolíme překřížení.
		var lo = parseInt( rMin.value, 10 );
		var hi = parseInt( rMax.value, 10 );
		if ( lo > hi ) { var tmp = lo; lo = hi; hi = tmp; }
		// Krajní poloha = bez omezení (prázdné pole).
		iMin.value = lo <= 0 ? '' : toPrice( lo );
		iMax.value = hi >= 1000 ? '' : toPrice( hi );
		updateRangeFill();
	}

	// Vymazat filtry.
	form.addEventListener( 'click', function ( e ) {
		if ( ! e.target.closest( '[data-nkzmp-clear]' ) ) { return; }
		e.preventDefault();
		form.querySelectorAll( 'input[type="checkbox"]' ).forEach( function ( el ) { el.checked = false; } );
		var searchEl = form.querySelector( '[data-nkzmp-search]' );
		if ( searchEl ) { searchEl.value = ''; }
		var iMin = form.querySelector( '[data-nkzmp-price="min"]' );
		var iMax = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( iMin ) { iMin.value = ''; }
		if ( iMax ) { iMax.value = ''; }
		syncRangeFromInputs();
		applyReset();
	} );

	// Stránkování + řazení uvnitř výsledků (delegace, přežije swap).
	results.addEventListener( 'click', function ( e ) {
		var link = e.target.closest( '.woocommerce-pagination a.page-numbers' );
		if ( ! link ) { return; }
		e.preventDefault();
		var paged = pagedFromHref( link.getAttribute( 'href' ) );
		var data  = collect();
		data.paged = paged;
		apply( data );
		results.scrollIntoView( { behavior: 'smooth', block: 'start' } );
	} );

	results.addEventListener( 'change', function ( e ) {
		if ( e.target.matches( 'select.orderby' ) ) {
			e.preventDefault();
			applyReset();
		}
	} );

	// WC řazení normálně auto-submituje form → potlačíme submit uvnitř výsledků.
	results.addEventListener( 'submit', function ( e ) {
		if ( e.target.matches( 'form.woocommerce-ordering' ) ) {
			e.preventDefault();
		}
	} );

	function pagedFromHref( href ) {
		if ( ! href ) { return 1; }
		try {
			var u = new URL( href, window.location.origin );
			var p = u.searchParams.get( 'paged' ) || u.searchParams.get( 'product-page' );
			if ( p ) { return parseInt( p, 10 ) || 1; }
			var m = u.pathname.match( /\/page\/(\d+)/ );
			if ( m ) { return parseInt( m[ 1 ], 10 ) || 1; }
		} catch ( err ) {}
		return 1;
	}

	/* ───────── Mobile drawer ───────── */

	var toggle  = document.querySelector( '.nkzmp-shop-filters-toggle' );
	var sidebar = document.getElementById( 'nkzmp-shop-filters' );

	function closeDrawer() {
		layout.classList.remove( 'filters-open' );
		if ( toggle ) { toggle.setAttribute( 'aria-expanded', 'false' ); }
		document.body.classList.remove( 'nkzmp-filters-locked' );
	}

	if ( toggle && sidebar ) {
		toggle.addEventListener( 'click', function ( e ) {
			e.stopPropagation();
			var open = layout.classList.toggle( 'filters-open' );
			toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			document.body.classList.toggle( 'nkzmp-filters-locked', open );
		} );
		// Klik mimo sidebar (na ztmavený overlay) zavře.
		document.addEventListener( 'click', function ( e ) {
			if ( ! layout.classList.contains( 'filters-open' ) ) { return; }
			if ( sidebar.contains( e.target ) || toggle.contains( e.target ) ) { return; }
			closeDrawer();
		} );
		// Escape zavře.
		document.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'Escape' ) { closeDrawer(); }
		} );
		// "Hotovo" zavře sheet.
		var done = form.querySelector( '[data-nkzmp-done]' );
		if ( done ) {
			done.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				closeDrawer();
			} );
		}
	}

	// Init modrého fillu slideru podle počátečních hodnot.
	updateRangeFill();
	setupBar();
	renderChips();
	currentPaged = pagedFromHref( window.location.href );
	var doneBtn = form.querySelector( '[data-nkzmp-done]' );
	afterRender( doneBtn ? parseInt( doneBtn.getAttribute( 'data-total' ), 10 ) : NaN );
} )();
