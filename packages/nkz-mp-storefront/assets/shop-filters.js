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

	function apply( data ) {
		syncUrl( data );

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

		layout.classList.add( 'is-loading' );

		if ( currentReq && currentReq.abort ) {
			currentReq.abort();
		}
		var controller = new AbortController();
		currentReq = controller;

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
			credentials: 'same-origin',
			signal: controller.signal
		} )
			.then( function ( r ) { return r.json(); } )
			.then( function ( res ) {
				if ( res && res.success && res.data && typeof res.data.html === 'string' ) {
					results.innerHTML = res.data.html;
				}
			} )
			.catch( function ( err ) {
				if ( err && err.name === 'AbortError' ) { return; }
			} )
			.finally( function () {
				layout.classList.remove( 'is-loading' );
				currentReq = null;
			} );
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

	// Enter v search poli = okamžitě hledat, ne submitnout formulář (reload).
	form.addEventListener( 'keydown', function ( e ) {
		if ( e.target.matches( '[data-nkzmp-search]' ) && e.key === 'Enter' ) {
			e.preventDefault();
			applyReset();
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

	// Safari: Enter v search poli odesila cely <form> (GET reload) driv nez
	// stihne AJAX. Zachytime submit formu a prevedeme na AJAX.
	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		applyReset();
	} );

	function debounce( fn, ms ) {
		clearTimeout( debounceTimer );
		debounceTimer = setTimeout( fn, ms );
	}

	function syncRangeFromInputs() {
		var rMin = form.querySelector( '[data-nkzmp-range="min"]' );
		var rMax = form.querySelector( '[data-nkzmp-range="max"]' );
		var iMin = form.querySelector( '[data-nkzmp-price="min"]' );
		var iMax = form.querySelector( '[data-nkzmp-price="max"]' );
		if ( rMin && iMin && iMin.value !== '' ) { rMin.value = iMin.value; }
		if ( rMax && iMax && iMax.value !== '' ) { rMax.value = iMax.value; }
		updateRangeFill();
	}

	// Modrý fill mezi thumby – levý/pravý okraj podle hodnot vůči bounds.
	function updateRangeFill() {
		var rMin = form.querySelector( '[data-nkzmp-range="min"]' );
		var rMax = form.querySelector( '[data-nkzmp-range="max"]' );
		var fill = form.querySelector( '[data-nkzmp-range-fill]' );
		if ( ! rMin || ! rMax || ! fill ) { return; }
		var lo  = parseFloat( rMin.min );
		var hi  = parseFloat( rMin.max );
		var span = hi - lo;
		if ( span <= 0 ) { return; }
		var a = ( parseFloat( rMin.value ) - lo ) / span * 100;
		var b = ( parseFloat( rMax.value ) - lo ) / span * 100;
		if ( a > b ) { var t = a; a = b; b = t; }
		fill.style.left  = a + '%';
		fill.style.right = ( 100 - b ) + '%';
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
		iMin.value = lo;
		iMax.value = hi;
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
		if ( iMin ) { iMin.value = iMin.getAttribute( 'min' ) || ''; }
		if ( iMax ) { iMax.value = iMax.getAttribute( 'max' ) || ''; }
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
} )();
