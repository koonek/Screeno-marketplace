/**
 * NKZ Marketplace – Flér-style seskupení košíku/pokladny po prodejcích.
 *
 * Čistě prezentační: do tabulek košíku a review-order vkládá hlavičku
 * „Balíček od <prodejce>" před první řádek každé skupiny a mezisoučet
 * prodejce za poslední. Spouští se po načtení i na WC AJAX eventech.
 */
(function () {
	'use strict';

	var data = window.nkzmpCartGroups || { names: {}, i18n: {} };

	function vendorOfRow(row) {
		// 1) třída nkzmp-vrow-<id> (košík)
		var m = (row.className || '').match(/nkzmp-vrow-(\d+)/);
		if (m) { return m[1]; }
		// 2) fallback: skrytý marker uvnitř .product-name (review-order v pokladně)
		var tag = row.querySelector('.nkzmp-vtag[data-vendor]');
		if (tag) { return tag.getAttribute('data-vendor'); }
		return null;
	}

	function vendorName(id) {
		if (data.names && Object.prototype.hasOwnProperty.call(data.names, id)) {
			return data.names[id];
		}
		return null;
	}

	function fmt(tpl, val) {
		return (tpl || '%s').replace('%s', val);
	}

	function groupTable(table) {
		if (!table) { return; }
		var tbody = table.querySelector('tbody') || table;
		var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.cart_item'));
		if (rows.length < 1) { return; }

		// Vyčisti předchozí injektované řádky (idempotence při re-renderu).
		Array.prototype.forEach.call(tbody.querySelectorAll('tr.nkzmp-group-head, tr.nkzmp-group-sub'), function (el) {
			el.parentNode.removeChild(el);
		});

		var colCount = 1;
		var anyRow = rows[0];
		if (anyRow) { colCount = Math.max(1, anyRow.children.length); }

		// Seskup po vendorech v pořadí výskytu.
		var order = [];
		var groups = {};
		rows.forEach(function (row) {
			var vid = vendorOfRow(row);
			if (vid === null) { vid = '0'; }
			if (!groups[vid]) { groups[vid] = []; order.push(vid); }
			groups[vid].push(row);
		});

		// Pokud je jen jedna skupina a je to „obchod" (0), nemá smysl hlavičkovat.
		var meaningful = order.filter(function (v) { return v !== '0' || order.length > 1; });
		if (order.length <= 1 && order[0] === '0') { return; }
		if (meaningful.length <= 1 && order.length <= 1) { return; }

		order.forEach(function (vid) {
			var name = vendorName(vid);
			if (!name) { return; }
			var first = groups[vid][0];

			// Hlavička skupiny.
			var head = document.createElement('tr');
			head.className = 'nkzmp-group-head';
			var th = document.createElement('td');
			th.colSpan = colCount;
			th.innerHTML = '<span class="nkzmp-group-head__label">' + fmt(data.i18n.package, escapeHtml(name)) + '</span>';
			head.appendChild(th);
			first.parentNode.insertBefore(head, first);

			// Per-vendor mezisoučet (sečti .product-subtotal / .product-total z řádků).
			var sum = 0, found = false;
			groups[vid].forEach(function (row) {
				var cell = row.querySelector('.product-subtotal') || row.querySelector('.product-total');
				if (cell) {
					var amt = parseAmount(cell.textContent);
					if (!isNaN(amt)) { sum += amt; found = true; }
				}
			});
			if (found) {
				var last = groups[vid][groups[vid].length - 1];
				var sub = document.createElement('tr');
				sub.className = 'nkzmp-group-sub';
				var sc = document.createElement('td');
				sc.colSpan = colCount;
				var html = '<span class="nkzmp-group-sub__row">'
					+ '<span class="nkzmp-group-sub__label">' + escapeHtml(data.i18n.subtotal || '') + '</span>'
					+ '<span class="nkzmp-group-sub__amount">' + formatAmount(sum) + '</span>'
					+ '</span>';
				// Per-vendor poštovné (pokud máme).
				var ship = data.shipping && Object.prototype.hasOwnProperty.call(data.shipping, vid) ? data.shipping[vid] : null;
				if (ship) {
					html += '<span class="nkzmp-group-sub__row nkzmp-group-sub__ship">'
						+ '<span class="nkzmp-group-sub__label">' + escapeHtml(data.i18n.shipping || '') + '</span>'
						+ '<span class="nkzmp-group-sub__amount">' + escapeHtml(ship) + '</span>'
						+ '</span>';
				}
				sc.innerHTML = html;
				sub.appendChild(sc);
				if (last.nextSibling) {
					last.parentNode.insertBefore(sub, last.nextSibling);
				} else {
					last.parentNode.appendChild(sub);
				}
			}
		});
	}

	// Parsování částky z textu („1 000 Kč" → 1000). Bere poslední číselný blok.
	function parseAmount(text) {
		if (!text) { return NaN; }
		var cleaned = text.replace(/\s|&nbsp;/g, '').replace(/[^\d,.\-]/g, '');
		// odděl tisíce tečkou/čárkou: ponech poslední , nebo . jako desetinnou
		cleaned = cleaned.replace(/[.,](?=\d{3}\b)/g, '');
		cleaned = cleaned.replace(',', '.');
		var n = parseFloat(cleaned);
		return n;
	}

	// Zachovej formát měny – vezmi vzor z první ceny na stránce, jinak prosté číslo.
	var currencyTemplate = null;
	function detectTemplate() {
		if (currencyTemplate !== null) { return; }
		var sample = document.querySelector('.woocommerce-Price-amount');
		currencyTemplate = sample ? sample.innerHTML.replace(/[\d.,\s]+/, '%s') : '%s';
	}
	function formatAmount(num) {
		detectTemplate();
		var s = num.toLocaleString('cs-CZ', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
		// vlož do měnového vzoru pokud máme bdi/symbol
		if (currencyTemplate && currencyTemplate.indexOf('%s') !== -1) {
			return '<span class="woocommerce-Price-amount amount">' + currencyTemplate.replace('%s', s) + '</span>';
		}
		return s;
	}

	function escapeHtml(str) {
		return String(str).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function run() {
		// Košík
		groupTable(document.querySelector('table.cart, table.shop_table.cart'));
		// Pokladna – review order
		groupTable(document.querySelector('#order_review table.shop_table, .woocommerce-checkout-review-order-table'));
	}

	function ready(fn) {
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	ready(run);

	// WC AJAX re-render hooks (jQuery eventy).
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_cart_totals updated_checkout updated_shipping_method', function () {
			// drobné zpoždění – WC nejdřív přepíše tabulku.
			setTimeout(run, 30);
		});
	}
})();
