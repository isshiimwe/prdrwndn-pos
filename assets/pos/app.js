/* PRDRWNDN POS app. Works online first; sales made without signal wait on the device and upload later. */
(function () {
	'use strict';

	var C = window.PRDPOS || {};
	var root = document.getElementById('app');

	/* ---------------- Storage ---------------- */

	var store = {
		get: function (k, d) { try { var v = localStorage.getItem(k); return v ? JSON.parse(v) : d; } catch (e) { return d; } },
		set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* full or private */ } }
	};
	var KEY = {
		catalog: 'prdpos_catalog_' + C.userId,
		cart: 'prdpos_cart_' + C.userId,
		queue: 'prdpos_queue',
		recent: 'prdpos_recent'
	};

	var catalog = store.get(KEY.catalog, null);
	var cart = store.get(KEY.cart, { lines: [], discount: 0 });
	var queue = store.get(KEY.queue, []);
	var recent = store.get(KEY.recent, []);
	var online = navigator.onLine;
	var signedOut = false;
	var reloadWhenIdle = false;
	var byId = {};
	var byCode = {};
	var tab = 'sell';
	var query = '';
	var syncing = false;

	/* ---------------- Helpers ---------------- */

	function h(s) {
		return String(s === undefined || s === null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function money(n) {
		var cur = (catalog && catalog.currency) || '$';
		n = Math.round((Number(n) || 0) * 100) / 100;
		var neg = n < 0;
		var abs = Math.abs(n);
		var txt = abs % 1 === 0 ? String(abs) : abs.toFixed(2);
		return (neg ? '-' : '') + cur + txt.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
	}

	function uuid() {
		if (window.crypto && crypto.randomUUID) { return crypto.randomUUID(); }
		return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
			var r = Math.random() * 16 | 0;
			return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
		});
	}

	function shortName(name) {
		var n = String(name || '').replace(/PRDRWNDN\s*®?\s*/i, '').trim();
		return n || String(name || '');
	}

	var ICON = {
		scan: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8V5a1 1 0 0 1 1-1h3M16 4h3a1 1 0 0 1 1 1v3M20 16v3a1 1 0 0 1-1 1h-3M8 20H5a1 1 0 0 1-1-1v-3M7 8v8M10 8v8M13 8v8M16 8v8"/></svg>',
		menu: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>',
		close: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>',
		minus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 12h12"/></svg>',
		plus: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 6v12M6 12h12"/></svg>',
		trash: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 7h14M10 7V5h4v2M7 7l1 12h8l1-12"/></svg>',
		check: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>',
		cloud: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18h10a4 4 0 0 0 .5-8A6 6 0 0 0 6 9.5 4.3 4.3 0 0 0 7 18z"/><path d="M12 11v5M9.5 13.5L12 16l2.5-2.5"/></svg>'
	};

	/* ---------------- Catalog and codes ---------------- */

	function indexCatalog() {
		byId = {};
		byCode = {};
		if (!catalog) { return; }
		catalog.items.forEach(function (it) {
			byId[it.id] = it;
			[it.barcode, it.gtin, it.sku && it.sku.toUpperCase()].forEach(function (c) { if (c) { byCode[String(c)] = it; } });
		});
	}

	function candidates(code) {
		var list = [code, code.toUpperCase()];
		if (/^\d+$/.test(code)) {
			if (code.length === 13 && code.charAt(0) === '0') { list.push(code.slice(1)); }
			if (code.length === 14 && code.slice(0, 2) === '00') { list.push(code.slice(2)); }
			if (code.length === 12) { list.push('0' + code); }
		}
		return list;
	}

	function findLocal(code) {
		var list = candidates(code);
		for (var i = 0; i < list.length; i++) {
			if (byCode[list[i]]) { return byCode[list[i]]; }
		}
		// In-store numbers: 4 + product ID (10 digits) + check digit.
		for (var j = 0; j < list.length; j++) {
			var c = list[j];
			if (/^4\d{11}$/.test(c)) {
				var id = parseInt(c.slice(1, 11), 10);
				if (byId[id]) { return byId[id]; }
			}
		}
		return null;
	}

	/* ---------------- Network ---------------- */

	function refreshNonce() {
		return fetch(C.nonceUrl, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (t) {
			t = String(t).trim();
			if (/^[a-f0-9]{10}$/.test(t)) { C.nonce = t; return true; }
			return false;
		}).catch(function () { return false; });
	}

	function api(path, opts, retried) {
		opts = opts || {};
		var headers = { 'X-WP-Nonce': C.nonce };
		if (opts.body) { headers['Content-Type'] = 'application/json'; }
		return fetch(C.rest + path, { method: opts.method || 'GET', credentials: 'same-origin', headers: headers, body: opts.body, cache: 'no-store' })
			.then(function (r) {
				return r.json().catch(function () { return {}; }).then(function (data) {
					if (r.ok) { setOnline(true); return data; }
					if (!retried && (data.code === 'rest_cookie_invalid_nonce' || r.status === 403)) {
						return refreshNonce().then(function (ok) {
							if (ok) { return api(path, opts, true); }
							throw httpError(data, r.status);
						});
					}
					throw httpError(data, r.status);
				});
			}, function (netErr) {
				setOnline(false);
				var e = new Error('No connection');
				e.offline = true;
				throw e;
			});
	}

	function httpError(data, status) {
		var e = new Error((data && data.message) || ('Error ' + status));
		e.status = status;
		e.code = data && data.code;
		if (status === 401 || e.code === 'rest_not_logged_in' || (status === 403 && e.code === 'rest_cookie_invalid_nonce')) {
			signedOut = true;
			renderStatus();
		}
		return e;
	}

	function setOnline(v) {
		if (online !== v) { online = v; renderStatus(); if (v) { syncQueue(); } }
	}

	function loadCatalog(quiet) {
		return api('pos/catalog').then(function (data) {
			catalog = data;
			store.set(KEY.catalog, data);
			indexCatalog();
			renderAll();
			if (!quiet) { toast('Products updated'); }
		}).catch(function (e) {
			if (!catalog) {
				root.innerHTML = '<div class="empty-state"><h1>Can\'t load products</h1><p>' + h(e.offline ? 'Connect to the internet once so the app can save your products. After that it works without signal.' : e.message) + '</p><button class="btn btn-primary" data-action="retry-boot">Try again</button></div>';
			}
		});
	}

	/* ---------------- Cart and totals ---------------- */

	function saveCart() { store.set(KEY.cart, cart); }

	function cartLines() {
		return cart.lines.map(function (l) {
			var it = byId[l.id];
			return it ? { item: it, qty: l.qty, price: Number(it.price) || 0, cats: it.cats || [] } : null;
		}).filter(Boolean);
	}

	// Same math as the server: group the most expensive matching items first.
	function dealDiscounts(lines) {
		var out = [];
		((catalog && catalog.deals) || []).forEach(function (rule) {
			var units = [];
			lines.forEach(function (l) {
				if (l.cats.indexOf(rule.cat) !== -1) { for (var i = 0; i < l.qty; i++) { units.push(l.price); } }
			});
			var bundles = Math.floor(units.length / rule.qty);
			if (bundles < 1) { return; }
			units.sort(function (a, b) { return b - a; });
			var normal = units.slice(0, bundles * rule.qty).reduce(function (a, b) { return a + b; }, 0);
			var off = Math.round((normal - bundles * rule.price) * 100) / 100;
			if (off > 0) { out.push({ label: rule.label + (bundles > 1 ? ' x' + bundles : ''), amount: off }); }
		});
		return out;
	}

	function totals() {
		var lines = cartLines();
		var subtotal = lines.reduce(function (a, l) { return a + l.price * l.qty; }, 0);
		var discounts = dealDiscounts(lines);
		if (cart.discount > 0) { discounts.push({ label: 'Discount', amount: cart.discount }); }
		var off = Math.min(subtotal, discounts.reduce(function (a, d) { return a + d.amount; }, 0));
		var count = lines.reduce(function (a, l) { return a + l.qty; }, 0);
		return { lines: lines, count: count, subtotal: subtotal, discounts: discounts, total: Math.round((subtotal - off) * 100) / 100 };
	}

	function addItem(it, n, quiet) {
		var line = cart.lines.filter(function (l) { return l.id === it.id; })[0];
		if (line) { line.qty += n || 1; } else { cart.lines.push({ id: it.id, qty: n || 1 }); }
		saveCart();
		renderCart();
		renderGridCounts();
		if (quiet) { return; }
		var left = it.stock === null || it.stock === undefined ? '' : ' (' + Math.max(0, it.stock - inCart(it.id)) + ' left)';
		toast('Added ' + shortName(it.name) + (it.variant ? ', ' + it.variant : '') + left);
	}

	function inCart(id) {
		var l = cart.lines.filter(function (x) { return x.id === id; })[0];
		return l ? l.qty : 0;
	}

	// done(result) is given by the camera scanner, which shows the result in its own panel.
	function handleCode(raw, done) {
		var code = String(raw || '').replace(/[\x00-\x1F\x7F]/g, '').trim();
		if (!code) { return; }
		var fail = function (msg) { beep(false); if (done) { done({ ok: false, msg: msg }); } else { toast(msg, true); } };
		var ok = function (item) { beep(true); addItem(item, 1, !!done); if (done) { done({ ok: true, item: item }); } };
		var it = findLocal(code);
		if (it) { ok(it); return; }
		if (!online) { fail('No product has code ' + code); return; }
		api('lookup?code=' + encodeURIComponent(code)).then(function (p) {
			if (p.type === 'variable') {
				beep(true);
				if (done) { stopScanner(); done({ pick: true }); }
				openPicker(p.id);
				return;
			}
			return loadCatalog(true).then(function () {
				var fresh = byId[p.id];
				if (fresh) { ok(fresh); } else { fail('That product cannot be sold here yet.'); }
			});
		}).catch(function () { fail('No product has code ' + code); });
	}

	/* ---------------- Sound ---------------- */

	var audio;
	function beep(ok) {
		try {
			audio = audio || new (window.AudioContext || window.webkitAudioContext)();
			var o = audio.createOscillator();
			var g = audio.createGain();
			o.frequency.value = ok ? 1100 : 300;
			g.gain.value = 0.08;
			o.connect(g);
			g.connect(audio.destination);
			o.start();
			o.stop(audio.currentTime + (ok ? 0.08 : 0.25));
			if (navigator.vibrate) { navigator.vibrate(ok ? 40 : [60, 40, 60]); }
		} catch (e) { /* no sound available */ }
	}

	/* ---------------- Rendering ---------------- */

	function renderShell() {
		root.removeAttribute('aria-busy');
		document.body.classList.toggle('has-stock', !!C.stock);
		root.innerHTML =
			'<header class="top">' +
				'<div class="top-brand"><img class="top-logo" src="' + h(C.logo || '') + '" alt="Proud Rwandan" width="51" height="38"><span class="pos-tag">POS</span></div>' +
				'<nav class="tabs" aria-label="Sections">' +
					'<button type="button" class="tab" data-action="tab" data-tab="sell">Sell</button>' +
					'<button type="button" class="tab" data-action="tab" data-tab="sales">Today</button>' +
					(C.stock ? '<button type="button" class="tab" data-action="tab" data-tab="stock">Stock</button>' : '') +
				'</nav>' +
				'<button type="button" class="net" id="net" data-action="sync"></button>' +
				'<button type="button" class="icon-btn" data-action="menu" aria-label="Menu">' + ICON.menu + '</button>' +
			'</header>' +
			'<div class="stripe" aria-hidden="true"><i></i><i></i><i></i></div>' +
			'<div class="signed-out" id="signed-out" hidden>You were signed out. Sales are saved on this device. <a href="' + h(C.scope) + '">Sign in again</a></div>' +
			'<main class="main">' +
				'<section class="pane pane-sell" id="pane-sell" aria-label="Products">' +
					'<form class="finder" id="finder" autocomplete="off">' +
						'<label class="sr" for="q">Search or type a barcode</label>' +
						'<input id="q" type="search" enterkeyhint="search" placeholder="Search or type a barcode">' +
						'<button type="button" class="btn btn-scan" data-action="scan">' + ICON.scan + '<span>Scan</span></button>' +
					'</form>' +
					'<div class="grid" id="grid"></div>' +
				'</section>' +
				'<section class="pane pane-sales" id="pane-sales" aria-label="Today\'s sales" hidden></section>' +
				(C.stock ? '<section class="pane pane-stock" id="pane-stock" aria-label="Jersey stock" hidden></section>' : '') +
				'<aside class="cart" id="cart" aria-label="Cart"></aside>' +
			'</main>' +
			'<button type="button" class="cartbar" id="cartbar" data-action="open-cart"></button>' +
			'<div class="sheet-wrap" id="sheet" hidden><div class="sheet-backdrop" data-action="close-sheet"></div><div class="sheet" role="dialog" aria-modal="true" id="sheet-body"></div></div>' +
			'<div class="toast" id="toast" role="status" aria-live="polite"></div>';
		var q = document.getElementById('q');
		q.addEventListener('input', function () { query = q.value; renderGrid(); });
		document.getElementById('finder').addEventListener('submit', function (e) {
			e.preventDefault();
			var v = q.value.trim();
			if (!v) { return; }
			var it = findLocal(v);
			if (it || /^\d{6,14}$/.test(v)) {
				q.value = '';
				query = '';
				handleCode(v);
				renderGrid();
			} else {
				q.blur();
			}
		});
	}

	function renderAll() {
		if (!document.getElementById('grid')) { renderShell(); }
		renderTabs();
		renderStatus();
		renderGrid();
		renderCart();
		if (tab === 'sales') { renderSales(); }
	}

	function renderTabs() {
		Array.prototype.forEach.call(root.querySelectorAll('.tab'), function (b) {
			b.setAttribute('aria-current', b.getAttribute('data-tab') === tab ? 'page' : 'false');
		});
		document.getElementById('pane-sell').hidden = tab !== 'sell';
		document.getElementById('pane-sales').hidden = tab !== 'sales';
		var ps = document.getElementById('pane-stock');
		if (ps) { ps.hidden = tab !== 'stock'; }
		document.body.classList.toggle('tab-sales', tab === 'sales');
		document.body.classList.toggle('tab-stock', tab === 'stock');
	}

	function renderStatus() {
		var net = document.getElementById('net');
		if (!net) { return; }
		var waiting = queue.length;
		var bad = queue.filter(function (s) { return s.error; }).length;
		var text = online ? 'Online' : 'Offline';
		if (waiting) { text += ' · ' + waiting + ' to upload'; }
		net.innerHTML = '<i class="net-dot" aria-hidden="true"></i><span class="net-text">' + h(text) + '</span>';
		net.className = 'net ' + (online ? (bad ? 'is-warn' : 'is-on') : 'is-off');
		net.setAttribute('aria-label', text + (waiting ? '. Tap to upload now.' : ''));
		var so = document.getElementById('signed-out');
		if (so) { so.hidden = !signedOut; }
	}

	function groups() {
		var map = {};
		var order = [];
		(catalog ? catalog.items : []).forEach(function (it) {
			if (!map[it.parent]) { map[it.parent] = { id: it.parent, name: it.name, image: it.image, items: [] }; order.push(it.parent); }
			map[it.parent].items.push(it);
			if (!map[it.parent].image && it.image) { map[it.parent].image = it.image; }
		});
		return order.map(function (k) { return map[k]; });
	}

	function stockText(n) {
		if (n === null || n === undefined) { return ''; }
		return n <= 0 ? '<span class="stock is-out">0 left</span>' : '<span class="stock">' + n + ' left</span>';
	}

	function renderGrid() {
		var grid = document.getElementById('grid');
		if (!grid || !catalog) { return; }
		var q = query.trim().toLowerCase();
		var html = '';
		if (q) {
			var hits = catalog.items.filter(function (it) {
				return (it.name + ' ' + it.variant + ' ' + it.sku + ' ' + it.barcode).toLowerCase().indexOf(q) !== -1;
			});
			html = hits.length ? hits.map(function (it) {
				return '<button type="button" class="tile tile-item" data-action="add" data-id="' + it.id + '">' +
					'<span class="tile-name">' + h(shortName(it.name)) + '</span>' +
					(it.variant ? '<span class="tile-variant">' + h(it.variant) + '</span>' : '') +
					'<span class="tile-foot"><span class="price">' + money(it.price) + '</span>' + stockText(it.stock) + '</span>' +
					'<span class="tile-count" data-count="' + it.id + '"></span>' +
				'</button>';
			}).join('') : '<p class="muted pad">Nothing matches "' + h(query) + '".</p>';
		} else {
			html = groups().map(function (g) {
				var prices = g.items.map(function (i) { return Number(i.price); });
				var lo = Math.min.apply(null, prices);
				var hi = Math.max.apply(null, prices);
				var tracked = g.items.filter(function (i) { return i.stock !== null && i.stock !== undefined; });
				var stock = tracked.length ? tracked.reduce(function (a, i) { return a + Math.max(0, i.stock); }, 0) : null;
				var single = g.items.length === 1 && !g.items[0].variant;
				return '<button type="button" class="tile" data-action="' + (single ? 'add' : 'pick') + '" data-id="' + (single ? g.items[0].id : g.id) + '">' +
					(g.image ? '<img class="tile-img" src="' + h(g.image) + '" alt="" loading="lazy">' : '<span class="tile-img tile-img-empty" aria-hidden="true">' + h(shortName(g.name).charAt(0)) + '</span>') +
					'<span class="tile-name">' + h(shortName(g.name)) + '</span>' +
					'<span class="tile-foot"><span class="price">' + (lo === hi ? money(lo) : money(lo) + ' to ' + money(hi)) + '</span>' + stockText(stock) + '</span>' +
					(single ? '<span class="tile-count" data-count="' + g.items[0].id + '"></span>' : '<span class="tile-sub">' + g.items.length + ' options</span>') +
				'</button>';
			}).join('');
		}
		grid.innerHTML = html;
		renderGridCounts();
	}

	function renderGridCounts() {
		Array.prototype.forEach.call(document.querySelectorAll('[data-count]'), function (el) {
			var n = inCart(Number(el.getAttribute('data-count')));
			el.textContent = n ? n + ' in cart' : '';
		});
	}

	function cartHTML(t) {
		if (!t.lines.length) {
			return '<div class="cart-head"><h2>Cart</h2><button type="button" class="icon-btn only-phone" data-action="close-sheet" aria-label="Close cart">' + ICON.close + '</button></div>' +
				'<p class="muted pad">Scan a barcode or tap a product to start a sale.</p>';
		}
		var lines = t.lines.map(function (l) {
			var it = l.item;
			return '<li class="line">' +
				'<div class="line-main"><span class="line-name">' + h(shortName(it.name)) + '</span>' +
					(it.variant ? '<span class="line-variant">' + h(it.variant) + '</span>' : '') +
					'<span class="line-each">' + money(l.price) + ' each</span></div>' +
				'<div class="stepper">' +
					'<button type="button" class="step" data-action="dec" data-id="' + it.id + '" aria-label="One less ' + h(shortName(it.name)) + '">' + (l.qty === 1 ? ICON.trash : ICON.minus) + '</button>' +
					'<span class="qty" aria-live="polite">' + l.qty + '</span>' +
					'<button type="button" class="step" data-action="inc" data-id="' + it.id + '" aria-label="One more ' + h(shortName(it.name)) + '">' + ICON.plus + '</button>' +
				'</div>' +
				'<span class="line-total">' + money(l.price * l.qty) + '</span>' +
			'</li>';
		}).join('');
		var sums = '<div class="sum"><span>Subtotal (' + t.count + (t.count === 1 ? ' item' : ' items') + ')</span><span>' + money(t.subtotal) + '</span></div>' +
			t.discounts.map(function (d) {
				var rm = d.label === 'Discount' ? ' <button type="button" class="link" data-action="clear-discount">Remove</button>' : '';
				return '<div class="sum sum-deal"><span>' + h(d.label) + rm + '</span><span>-' + money(d.amount) + '</span></div>';
			}).join('') +
			(catalog.taxes ? '<div class="sum muted"><span>Sales tax</span><span>added at charge</span></div>' : '') +
			'<div class="sum sum-total"><span>Total</span><span>' + money(t.total) + '</span></div>';
		var holdNote = cart.holds && cart.holds.length ? '<div class="hold-note"><strong>Picking up ' + (cart.holds.length === 1 ? 'a hold' : cart.holds.length + ' holds') + '</strong>' +
			cart.holds.map(function (x) { return '<span>' + h(x.name + ' ' + x.size + (x.qty > 1 ? ' x' + x.qty : '') + ' for ' + (x.customer || 'customer') + ', held at ' + money(x.price) + (x.qty > 1 ? ' each' : '')) + '</span>'; }).join('') +
			'<span class="muted">Completing this sale clears the hold' + (cart.holds.length === 1 ? '' : 's') + ' in Stock.</span></div>' : '';
		return '<div class="cart-head"><h2>Cart</h2>' +
				'<button type="button" class="link" data-action="clear-cart">Clear</button>' +
				'<button type="button" class="icon-btn only-phone" data-action="close-sheet" aria-label="Close cart">' + ICON.close + '</button></div>' +
			'<ul class="lines">' + lines + '</ul>' + holdNote +
			'<div class="sums">' + sums + '</div>' +
			(catalog.canDiscount && !cart.discount ? '<button type="button" class="link add-discount" data-action="discount">Add a discount</button>' : '') +
			'<button type="button" class="btn btn-primary btn-xl" data-action="charge">Charge ' + money(t.total) + '</button>';
	}

	function renderCart() {
		if (!catalog) { return; }
		var t = totals();
		var el = document.getElementById('cart');
		if (el) { el.innerHTML = cartHTML(t); }
		var bar = document.getElementById('cartbar');
		if (bar) {
			bar.hidden = !t.count || tab !== 'sell';
			bar.innerHTML = '<span>Cart · ' + t.count + (t.count === 1 ? ' item' : ' items') + '</span><strong>' + money(t.total) + '</strong>';
		}
		var sheet = document.getElementById('sheet');
		if (sheet && !sheet.hidden && sheet.getAttribute('data-kind') === 'scan') { renderScanCart(); }
		if (sheet && !sheet.hidden && sheet.getAttribute('data-kind') === 'cart') {
			if (!t.count) { closeSheet(); } else { document.getElementById('sheet-body').innerHTML = cartHTML(t); }
		}
	}

	/* ---------------- Sheets (pop-up panels) ---------------- */

	var lastFocus = null;
	function openSheet(kind, html) {
		var wrap = document.getElementById('sheet');
		lastFocus = document.activeElement;
		wrap.setAttribute('data-kind', kind);
		document.getElementById('sheet-body').innerHTML = html;
		wrap.hidden = false;
		document.body.classList.add('sheet-open');
		var first = wrap.querySelector('[autofocus], .sheet button, .sheet input');
		if (first) { first.focus({ preventScroll: true }); }
	}

	function closeSheet() {
		var wrap = document.getElementById('sheet');
		if (!wrap || wrap.hidden) { return; }
		if (wrap.getAttribute('data-kind') === 'scan') { stopScanner(); }
		wrap.hidden = true;
		document.getElementById('sheet-body').innerHTML = '';
		wrap.removeAttribute('data-kind');
		document.body.classList.remove('sheet-open');
		if (lastFocus && lastFocus.focus) { lastFocus.focus({ preventScroll: true }); }
		if (reloadWhenIdle && !cart.lines.length) { location.reload(); }
	}

	function openPicker(parentId) {
		var g = groups().filter(function (x) { return x.id === parentId; })[0];
		if (!g) { return; }
		var html = '<div class="sheet-head"><h2>' + h(shortName(g.name)) + '</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
			'<p class="muted">Tap an option to add it. Tap again to add another.</p>' +
			'<div class="options">' + g.items.map(function (it) {
				return '<button type="button" class="option" data-action="add" data-id="' + it.id + '">' +
					'<span class="option-name">' + h(it.variant || shortName(it.name)) + '</span>' +
					'<span class="option-meta">' + money(it.price) + ' ' + stockText(it.stock) + '</span>' +
					'<span class="tile-count" data-count="' + it.id + '"></span>' +
				'</button>';
			}).join('') + '</div>' +
			'<button type="button" class="btn btn-block" data-action="close-sheet">Done</button>';
		openSheet('pick', html);
		renderGridCounts();
	}

	function openCart() {
		openSheet('cart', cartHTML(totals()));
	}

	/* ---------------- Stock tab (administrators) ---------------- */

	var stockUI = null;
	function openStock() {
		var pane = document.getElementById('pane-stock');
		if (!pane || !window.PRDStock) { return; }
		if (stockUI) { stockUI.refresh(); return; }
		stockUI = window.PRDStock.mount(pane, {
			api: api,
			css: C.stockCss,
			font: C.font,
			ringUp: ringUpHolds,
			visible: function () { return tab === 'stock'; }
		});
	}

	// A jersey hold picked up at the counter: its jersey goes in the cart, and the sale clears the hold.
	function ringUpHolds(rows) {
		if (!catalog) { toast('Products are still loading. Try again in a moment.', true); return; }
		var missing = rows.filter(function (r) { return !byId[r.variation]; });
		if (missing.length) {
			toast(missing.map(function (r) { return r.name + ' ' + r.size; }).join(', ') + ' is not in the POS list yet. Refresh products from the menu.', true);
			return;
		}
		var added = 0;
		rows.forEach(function (r) {
			if ((cart.holds || []).some(function (x) { return x.id === r.id; })) { return; }
			var line = cart.lines.filter(function (l) { return l.id === r.variation; })[0];
			if (line) { line.qty += r.qty; } else { cart.lines.push({ id: r.variation, qty: r.qty }); }
			cart.holds = (cart.holds || []).concat([{ id: r.id, variation: r.variation, name: r.name, size: r.size, qty: r.qty, price: r.price, customer: r.customer }]);
			added++;
		});
		saveCart();
		tab = 'sell';
		renderTabs();
		renderCart();
		renderGridCounts();
		if (!window.matchMedia('(min-width: 900px)').matches) { openCart(); }
		toast(added ? (added === 1 ? 'Hold added to the cart' : added + ' holds added to the cart') : 'That hold is already in the cart');
	}

	// Holds only stay attached while their jersey is still in the cart.
	function trimHolds() {
		if (!cart.holds || !cart.holds.length) { return; }
		var room = {};
		cart.lines.forEach(function (l) { room[l.id] = l.qty; });
		cart.holds = cart.holds.filter(function (x) {
			if ((room[x.variation] || 0) >= x.qty) { room[x.variation] -= x.qty; return true; }
			return false;
		});
	}

	function openDiscount() {
		var t = totals();
		var html = '<div class="sheet-head"><h2>Discount</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
			'<form id="discount-form" class="stack">' +
				'<label for="disc">Amount off (' + h(catalog.currency) + ')</label>' +
				'<input id="disc" type="text" inputmode="decimal" autocomplete="off" placeholder="0">' +
				'<div class="chips">' + [10, 15, 20].map(function (p) {
					return '<button type="button" class="chip" data-action="disc-pct" data-pct="' + p + '">' + p + '% (' + money(t.subtotal * p / 100) + ')</button>';
				}).join('') + '</div>' +
				'<button type="submit" class="btn btn-primary btn-block">Apply discount</button>' +
			'</form>';
		openSheet('discount', html);
		document.getElementById('discount-form').addEventListener('submit', function (e) {
			e.preventDefault();
			var v = parseFloat(String(document.getElementById('disc').value).replace(/[^0-9.]/g, ''));
			cart.discount = v > 0 ? Math.min(Math.round(v * 100) / 100, t.subtotal) : 0;
			saveCart();
			closeSheet();
			renderCart();
		});
	}

	/* ---------------- Charge ---------------- */

	var charge = null;

	function openCharge() {
		var holdName = cart.holds && cart.holds.length ? (cart.holds[0].customer || '') : '';
		var t = totals();
		if (!t.count) { return; }
		charge = { method: '', total: t.total, quoted: !catalog.taxes, tendered: '' };
		var methods = catalog.methods || {};
		var html = '<div class="sheet-head"><h2>Charge</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
			'<p class="due" id="due">' + money(t.total) + '</p>' +
			'<p class="muted center" id="due-note">' + (catalog.taxes ? (online ? 'Adding sales tax...' : 'Offline: sales tax is added when the sale uploads.') : t.count + (t.count === 1 ? ' item' : ' items')) + '</p>' +
			'<fieldset class="methods"><legend>How did they pay?</legend>' +
				Object.keys(methods).map(function (k) {
					return '<button type="button" class="method" data-action="method" data-method="' + h(k) + '" aria-pressed="false">' + h(methods[k]) + '</button>';
				}).join('') +
			'</fieldset>' +
			'<div class="cash" id="cash" hidden>' +
				'<label for="tendered">Cash received</label>' +
				'<input id="tendered" type="text" inputmode="decimal" autocomplete="off" placeholder="' + h(money(t.total)) + '">' +
				'<div class="chips" id="cash-chips"></div>' +
				'<p class="change" id="change" aria-live="polite"></p>' +
			'</div>' +
			'<details class="customer"' + (holdName ? ' open' : '') + '><summary>Customer details (optional)</summary>' +
				'<div class="stack">' +
					'<label for="c-name">Name</label><input id="c-name" type="text" autocomplete="off" value="' + h(holdName) + '">' +
					'<label for="c-phone">Phone</label><input id="c-phone" type="tel" autocomplete="off">' +
					'<label for="c-email">Email (we email them the receipt)</label><input id="c-email" type="email" autocomplete="off" autocapitalize="off">' +
				'</div>' +
			'</details>' +
			'<button type="button" class="btn btn-primary btn-xl" id="complete" data-action="complete" disabled>Complete sale</button>';
		openSheet('charge', html);
		renderCashChips();
		document.getElementById('tendered').addEventListener('input', function (e) {
			charge.tendered = e.target.value;
			renderChange();
		});
		if (catalog.taxes && online) {
			api('pos/quote', { method: 'POST', body: JSON.stringify(salePayload()) }).then(function (q) {
				if (!charge) { return; }
				charge.total = q.total;
				charge.quoted = true;
				document.getElementById('due').textContent = money(q.total);
				document.getElementById('due-note').textContent = 'Includes ' + money(q.tax) + ' sales tax';
				renderCashChips();
				renderChange();
			}).catch(function () {
				var n = document.getElementById('due-note');
				if (n) { n.textContent = 'Could not get sales tax right now. It is added when the sale uploads.'; }
			});
		}
	}

	function renderCashChips() {
		var el = document.getElementById('cash-chips');
		if (!el || !charge) { return; }
		var due = charge.total;
		var opts = [due];
		[5, 10, 20, 50, 100].forEach(function (step) {
			var v = Math.ceil(due / step) * step;
			if (v > due && opts.indexOf(v) === -1 && opts.length < 4) { opts.push(v); }
		});
		el.innerHTML = opts.map(function (v, i) {
			return '<button type="button" class="chip" data-action="tender" data-amount="' + v + '">' + (i === 0 ? 'Exact ' : '') + money(v) + '</button>';
		}).join('');
	}

	function renderChange() {
		var el = document.getElementById('change');
		if (!el || !charge) { return; }
		var paid = parseFloat(String(charge.tendered).replace(/[^0-9.]/g, ''));
		if (!(paid > 0)) { el.textContent = ''; return; }
		var diff = Math.round((paid - charge.total) * 100) / 100;
		el.innerHTML = diff >= 0 ? 'Change: <strong>' + money(diff) + '</strong>' : '<span class="warn">' + money(-diff) + ' still due</span>';
		updateComplete();
	}

	function updateComplete() {
		var btn = document.getElementById('complete');
		if (!btn || !charge) { return; }
		var ok = !!charge.method;
		if (charge.method === 'cash' && charge.tendered !== '') {
			var paid = parseFloat(String(charge.tendered).replace(/[^0-9.]/g, ''));
			ok = paid >= charge.total - 0.001;
		}
		btn.disabled = !ok;
	}

	function salePayload() {
		var p = {
			items: cart.lines.map(function (l) { return { id: l.id, qty: l.qty }; }),
			discount: cart.discount || 0
		};
		if (cart.holds && cart.holds.length) { p.holds = cart.holds.map(function (x) { return x.id; }); }
		return p;
	}

	function completeSale() {
		var t = totals();
		var p = salePayload();
		p.uuid = uuid();
		p.method = charge.method;
		p.soldAt = Math.floor(Date.now() / 1000);
		p.offline = !online;
		if (charge.method === 'cash' && charge.tendered !== '') {
			p.tendered = parseFloat(String(charge.tendered).replace(/[^0-9.]/g, ''));
		}
		p.customer = {
			name: (document.getElementById('c-name') || {}).value || '',
			phone: (document.getElementById('c-phone') || {}).value || '',
			email: (document.getElementById('c-email') || {}).value || ''
		};
		var entry = {
			uuid: p.uuid,
			payload: p,
			total: charge.total,
			method: (catalog.methods || {})[p.method] || p.method,
			summary: t.lines.map(function (l) { return l.qty + ' x ' + shortName(l.item.name) + (l.item.variant ? ' (' + l.item.variant + ')' : ''); }).join(', '),
			at: Date.now()
		};
		// Save on the device first, so a dropped connection never loses a sale.
		queue.push(entry);
		store.set(KEY.queue, queue);
		t.lines.forEach(function (l) {
			if (l.item.stock !== null && l.item.stock !== undefined) { l.item.stock -= l.qty; }
		});
		store.set(KEY.catalog, catalog);
		var change = p.tendered ? Math.max(0, Math.round((p.tendered - charge.total) * 100) / 100) : null;
		cart = { lines: [], discount: 0 };
		saveCart();
		charge = null;
		doneFor = entry.uuid;
		showDone(entry, change, null);
		renderAll();
		var stillShowing = function () {
			var sheet = document.getElementById('sheet');
			return doneFor === entry.uuid && sheet && !sheet.hidden && sheet.getAttribute('data-kind') === 'done';
		};
		sendSale(entry).then(function (res) {
			if (stillShowing()) {
				showDone(entry, res && res.change !== null && res.change !== undefined ? res.change : change, res);
			} else {
				toast('Sale #' + res.number + ' uploaded');
			}
		}, function () {
			if (stillShowing()) { showDone(entry, change, null); }
		});
	}

	var doneFor = null;

	function showDone(entry, change, res) {
		var waiting = queue.some(function (s) { return s.uuid === entry.uuid; });
		var failed = queue.filter(function (s) { return s.uuid === entry.uuid && s.error; })[0];
		var html = '<div class="done">' +
			'<div class="done-icon ' + (waiting ? 'is-wait' : '') + '">' + (waiting ? ICON.cloud : ICON.check) + '</div>' +
			'<h2>' + (waiting ? (failed ? 'Sale needs attention' : 'Sale saved on this device') : 'Sale complete') + '</h2>' +
			(res ? '<p class="muted">Order #' + h(res.number) + ' · ' + h(res.method) + '</p>' :
				'<p class="muted">' + h(failed ? failed.error : (online ? 'Uploading...' : 'It uploads by itself when you are back online.')) + '</p>') +
			'<p class="done-total">' + money(res ? res.total : entry.total) + '</p>' +
			(change !== null && change !== undefined ? '<p class="done-change">Change due: <strong>' + money(change) + '</strong></p>' : '') +
			(res && res.warnings && res.warnings.length ? '<ul class="warnings">' + res.warnings.map(function (w) { return '<li>' + h(w) + '</li>'; }).join('') + '</ul>' : '') +
			'<button type="button" class="btn btn-primary btn-xl" data-action="new-sale">New sale</button>' +
		'</div>';
		openSheet('done', html);
	}

	/* ---------------- Upload queue ---------------- */

	function sendSale(entry) {
		return api('pos/sale', { method: 'POST', body: JSON.stringify(entry.payload) }).then(function (res) {
			queue = queue.filter(function (s) { return s.uuid !== entry.uuid; });
			store.set(KEY.queue, queue);
			recent.unshift({ number: res.number, total: res.total, method: res.method, summary: entry.summary, at: entry.at });
			recent = recent.slice(0, 50);
			store.set(KEY.recent, recent);
			if (res.stock) {
				Object.keys(res.stock).forEach(function (id) { if (byId[id]) { byId[id].stock = res.stock[id]; } });
				store.set(KEY.catalog, catalog);
			}
			renderStatus();
			renderGrid();
			if (tab === 'sales') { renderSales(); }
			return res;
		}, function (err) {
			if (!err.offline && err.status && err.status >= 400 && err.status < 500 && err.status !== 401 && err.status !== 403) {
				entry.error = err.message;
				store.set(KEY.queue, queue);
			}
			renderStatus();
			throw err;
		});
	}

	function syncQueue() {
		if (syncing || !queue.length || signedOut) { return Promise.resolve(); }
		syncing = true;
		var list = queue.filter(function (s) { return !s.error; });
		var chain = Promise.resolve();
		var stop = false;
		list.forEach(function (entry) {
			chain = chain.then(function () {
				if (stop) { return; }
				return sendSale(entry).catch(function (err) { if (err.offline || err.status === 401 || err.status === 403) { stop = true; } });
			});
		});
		return chain.then(function () {
			syncing = false;
			renderStatus();
			if (tab === 'sales') { renderSales(); }
		});
	}

	/* ---------------- Today's sales ---------------- */

	function renderSales() {
		var pane = document.getElementById('pane-sales');
		if (!pane) { return; }
		var pending = queue.map(function (s) {
			return '<li class="sale is-pending"><div><strong>' + money(s.total) + '</strong> · ' + h(s.method) +
				'<span class="muted block">' + h(s.summary) + '</span>' +
				(s.error ? '<span class="warn block">' + h(s.error) + '</span>' : '') + '</div>' +
				'<div class="sale-side"><span class="badge">' + (s.error ? 'Needs attention' : 'Waiting to upload') + '</span>' +
				(s.error ? '<button type="button" class="link" data-action="retry-sale" data-uuid="' + h(s.uuid) + '">Retry</button> <button type="button" class="link danger" data-action="drop-sale" data-uuid="' + h(s.uuid) + '">Delete</button>' : '') +
				'</div></li>';
		}).join('');
		pane.innerHTML = '<div class="sales-head"><h2>Today</h2>' +
			(queue.length ? '<button type="button" class="btn" data-action="sync">Upload now</button>' : '') +
			'<button type="button" class="btn" data-action="refresh-sales">Refresh</button></div>' +
			(pending ? '<h3>On this device</h3><ul class="sales">' + pending + '</ul>' : '') +
			'<div id="sales-server"><p class="muted pad">' + (online ? 'Loading sales...' : 'Offline. Sales from the store show here when you are back online.') + '</p></div>';
		if (!online) { return; }
		api('pos/sales').then(function (d) {
			var box = document.getElementById('sales-server');
			if (!box) { return; }
			var totalsHtml = Object.keys(d.totals).map(function (k) {
				return '<div class="sum"><span>' + h(k) + '</span><span>' + money(d.totals[k]) + '</span></div>';
			}).join('');
			box.innerHTML = '<div class="sums day-totals">' + totalsHtml +
				'<div class="sum sum-total"><span>Total today (' + d.sales.length + (d.sales.length === 1 ? ' sale' : ' sales') + ')</span><span>' + money(d.total) + '</span></div></div>' +
				(d.sales.length ? '<ul class="sales">' + d.sales.map(function (s) {
					return '<li class="sale"><div><strong>#' + h(s.number) + '</strong> · ' + h(s.time) + ' · ' + h(s.method) +
						'<span class="muted block">' + h(String(s.items).replace(/PRDRWNDN\s*®?\s*/gi, '')) + '</span></div>' +
						'<div class="sale-side"><strong>' + money(s.total) + '</strong><span class="muted">' + h(s.cashier) + '</span></div></li>';
				}).join('') + '</ul>' : '<p class="muted pad">No in-person sales yet today.</p>');
		}).catch(function (e) {
			var box = document.getElementById('sales-server');
			if (box) { box.innerHTML = '<p class="muted pad">' + h(e.offline ? 'Offline right now.' : e.message) + '</p>'; }
		});
	}

	/* ---------------- Menu ---------------- */

	var installEvent = null;
	window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); installEvent = e; });

	/* ---------------- Face ID (passkeys, shared with the shop app) ---------------- */

	var bio = { ok: false, name: 'Face ID' };
	(function () {
		var ua = navigator.userAgent;
		var ios = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		bio.name = ios ? 'Face ID' : /Macintosh/.test(ua) ? 'Touch ID' : /Android/.test(ua) ? 'fingerprint' : 'passkey';
		if (window.PublicKeyCredential && PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable) {
			PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable().then(function (ok) { bio.ok = !!ok; }, function () {});
		}
	})();
	function b64u(buf) { var s = ''; new Uint8Array(buf).forEach(function (b) { s += String.fromCharCode(b); }); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); }
	function unb64u(s) { s = String(s).replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) { s += '='; } var bin = atob(s), out = new Uint8Array(bin.length); for (var i = 0; i < bin.length; i++) { out[i] = bin.charCodeAt(i); } return out.buffer; }

	function faceOn() {
		if (!online) { toast('Turning on ' + bio.name + ' needs the internet.', true); return; }
		api('passkey/register-options', { method: 'POST', body: '{}' }).then(function (o) {
			return navigator.credentials.create({ publicKey: {
				challenge: unb64u(o.challenge), rp: o.rp,
				user: { id: unb64u(o.user.id), name: o.user.name, displayName: o.user.displayName },
				pubKeyCredParams: o.pubKeyCredParams, authenticatorSelection: o.authenticatorSelection,
				attestation: o.attestation, timeout: o.timeout,
				excludeCredentials: (o.excludeCredentials || []).map(function (c) { return { type: 'public-key', id: unb64u(c.id) }; })
			} });
		}).then(function (cred) {
			var r = cred.response;
			var device = /iphone/i.test(navigator.userAgent) ? 'iPhone' : /ipad/i.test(navigator.userAgent) ? 'iPad' : (navigator.platform || '');
			return api('passkey/register', { method: 'POST', body: JSON.stringify({ id: cred.id, clientDataJSON: b64u(r.clientDataJSON), attestationObject: b64u(r.attestationObject), device: 'POS ' + device }) });
		}).then(function (res) {
			C.passkeys = res.count;
			closeSheet();
			toast(bio.name + ' is on. Next time, tap Sign in with ' + bio.name + '.');
		}).catch(function (e) {
			if (e && e.name === 'InvalidStateError') { C.passkeys = Math.max(1, C.passkeys || 0); closeSheet(); toast(bio.name + ' is already on for this device.'); return; }
			if (e && e.name === 'NotAllowedError') { toast(bio.name + ' was cancelled.', true); return; }
			toast((e && e.message) || ('Couldn\'t turn on ' + bio.name + '.'), true);
		});
	}

	function faceOff() {
		if (!window.confirm('Turn off ' + bio.name + ' for your account? It also turns off in the PRDRWNDN shop app. You can turn it on again any time.')) { return; }
		api('passkeys', { method: 'DELETE' }).then(function () {
			C.passkeys = 0;
			closeSheet();
			toast(bio.name + ' is off. Sign in with your password or Google.');
		}, function (e) { toast(e.message || 'Couldn\'t turn it off.', true); });
	}

	function openMenu() {
		var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
		var ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		var install = '';
		if (!standalone) {
			install = installEvent
				? '<button type="button" class="btn btn-block" data-action="install">Install the app on this device</button>'
				: '<div class="note"><strong>Install on this device:</strong> ' + (ios
					? 'open this page in Safari, tap Share, then Add to Home Screen.'
					: 'open your browser menu and choose Install app or Add to Home screen.') + '</div>';
		}
		var html = '<div class="sheet-head"><h2>' + h(C.user) + '</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
			'<div class="stack">' +
				'<button type="button" class="btn btn-block" data-action="refresh-catalog">Refresh products and stock</button>' +
				(queue.length ? '<button type="button" class="btn btn-block" data-action="sync">Upload ' + queue.length + ' waiting ' + (queue.length === 1 ? 'sale' : 'sales') + '</button>' : '') +
				(C.labelsUrl ? '<a class="btn btn-block" href="' + h(C.labelsUrl) + '" target="_blank" rel="noopener">Print labels (opens WordPress)</a>' : '') +
				install +
				(bio.ok ? '<div class="face-row"><div><strong>Sign in with ' + h(bio.name) + '</strong><span class="muted small">' + (C.passkeys ? 'On for your account. Works in the shop app too.' : 'Off. Turn it on to skip the password next time.') + '</span></div>' +
					'<button type="button" class="btn' + (C.passkeys ? '' : ' btn-primary') + '" data-action="' + (C.passkeys ? 'face-off' : 'face-on') + '">' + (C.passkeys ? 'Turn off' : 'Turn on') + '</button></div>' : '') +
				'<a class="btn btn-block" href="' + h(C.logout) + '" data-action="logout">Sign out</a>' +
				'<p class="muted small">App version ' + h(C.build) + (catalog ? ' · products updated ' + new Date(catalog.updated * 1000).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '') + '</p>' +
			'</div>';
		openSheet('menu', html);
	}

	/* ---------------- Camera scanner ---------------- */

	var scanner = null;

	function openScanner() {
		// Sound needs a tap to start on iPhone; opening the scanner is that tap.
		try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); if (audio.resume) { audio.resume(); } } catch (e) { /* no sound */ }
		var html = '<div class="sheet-head"><h2>Scan</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
			'<div class="scanner"><video id="cam" playsinline muted autoplay></video><div class="scan-frame" id="scan-frame" aria-hidden="true"></div></div>' +
			'<div class="scan-result is-idle" id="scan-result" role="status" aria-live="polite"><div class="sr-text"><strong>Point the camera at a barcode</strong><span>New items go straight into the cart. Scanning the same item again asks first.</span></div></div>' +
			'<div class="scan-cart" id="scan-cart"></div>' +
			'<button type="button" class="btn btn-primary btn-block" data-action="scan-done" id="scan-done">Done scanning</button>';
		openSheet('scan', html);
		renderScanCart();
		startScanner(document.getElementById('cam')).catch(function (err) {
			scanShow('bad', '', /denied|NotAllowed/i.test(String(err && (err.name || err.message)))
				? 'Camera access is off'
				: 'The camera could not start', /denied|NotAllowed/i.test(String(err && (err.name || err.message)))
				? 'Allow the camera for this app in your phone settings, then try again.'
				: String(err && err.message ? err.message : err));
		});
	}

	// What the camera just did, in the panel under it (a toast is too easy to miss).
	function scanShow(kind, icon, title, sub, button) {
		var el = document.getElementById('scan-result');
		if (!el) { return; }
		el.className = 'scan-result is-' + kind;
		el.innerHTML = (icon ? '<span class="sr-icon">' + icon + '</span>' : '') +
			'<div class="sr-text"><strong>' + h(title) + '</strong>' + (sub ? '<span>' + h(sub) + '</span>' : '') + '</div>' + (button || '');
		var frame = document.getElementById('scan-frame');
		if (frame && kind !== 'idle') {
			frame.className = 'scan-frame is-' + kind;
			clearTimeout(scanShow.t);
			scanShow.t = setTimeout(function () { if (frame) { frame.className = 'scan-frame'; } }, 900);
		}
	}

	function itemLabel(it) { return shortName(it.name) + (it.variant ? ', ' + it.variant : ''); }

	function scanAdded(it) {
		var n = inCart(it.id);
		var over = it.stock !== null && it.stock !== undefined && n > it.stock;
		scanner.asking = null;
		scanShow(over ? 'warn' : 'ok', ICON.check, 'Added ' + itemLabel(it),
			money(it.price) + ' · ' + n + ' in the cart' + (over ? ' · only ' + Math.max(0, it.stock) + ' in stock' : ''),
			'<button type="button" class="link" data-action="scan-undo" data-id="' + it.id + '">Undo</button>');
		renderScanCart();
	}

	function renderScanCart() {
		var el = document.getElementById('scan-cart');
		if (!el || !catalog) { return; }
		var t = totals();
		el.innerHTML = t.count
			? '<div class="scan-cart-head"><span>In the cart</span><strong>' + t.count + (t.count === 1 ? ' item' : ' items') + ' · ' + money(t.total) + '</strong></div>' +
				'<ul>' + t.lines.map(function (l) { return '<li><span>' + l.qty + ' × ' + h(itemLabel(l.item)) + '</span><span>' + money(l.price * l.qty) + '</span></li>'; }).join('') + '</ul>'
			: '<div class="scan-cart-head"><span class="muted">The cart is empty</span></div>';
		var btn = document.getElementById('scan-done');
		if (btn) { btn.textContent = t.count ? 'Done · ' + t.count + (t.count === 1 ? ' item' : ' items') + ' · ' + money(t.total) : 'Done scanning'; }
	}

	// One read per label: the camera sees the same code many times a second, so it
	// counts once until it has left the camera. Scanning an item that is already in
	// the cart asks first, so a label held up twice doesn't sell two.
	function onScanned(code) {
		var now = Date.now();
		if (!scanner) { return; }
		var same = scanner.last === code && now - scanner.lastAt < 1500;
		scanner.last = code;
		scanner.lastAt = now;
		if (same || scanner.busy) { return; }
		var it = findLocal(code);
		if (it && scanner.added[it.id] && inCart(it.id) > 0) {
			if (scanner.asking !== it.id) {
				scanner.asking = it.id;
				beep(true);
				scanShow('ask', '', itemLabel(it) + ' is already in the cart (' + inCart(it.id) + ')',
					'Scanned the same label again? Only add it if you have another one.',
					'<button type="button" class="btn btn-primary btn-small" data-action="scan-again" data-id="' + it.id + '">Add another</button>');
			}
			return;
		}
		scanner.busy = true;
		handleCode(code, function (res) {
			if (!scanner) { return; }
			scanner.busy = false;
			if (res.ok) {
				scanner.added[res.item.id] = true;
				scanAdded(res.item);
			} else if (!res.pick) {
				scanner.asking = null;
				scanShow('bad', '', res.msg, 'Try again, or type the number in the search box.');
			}
		});
	}

	function loadZXing() {
		if (window.ZXing) { return Promise.resolve(window.ZXing); }
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = C.zxing;
			s.onload = function () { resolve(window.ZXing); };
			s.onerror = function () { reject(new Error('Scanner library did not load')); };
			document.head.appendChild(s);
		});
	}

	function startScanner(video) {
		scanner = { last: '', lastAt: 0, stream: null, timer: null, reader: null, added: {}, asking: null, busy: false };
		var formats = ['upc_a', 'ean_13', 'code_128', 'upc_e', 'ean_8'];
		var useNative = 'BarcodeDetector' in window;
		var nativeCheck = useNative && window.BarcodeDetector.getSupportedFormats
			? window.BarcodeDetector.getSupportedFormats().then(function (f) { return f.indexOf('upc_a') !== -1 || f.indexOf('ean_13') !== -1; }).catch(function () { return false; })
			: Promise.resolve(false);
		return nativeCheck.then(function (native) {
			if (native) {
				var detector = new window.BarcodeDetector({ formats: formats });
				return navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false }).then(function (stream) {
					if (!scanner) { stream.getTracks().forEach(function (t) { t.stop(); }); return; }
					scanner.stream = stream;
					video.srcObject = stream;
					return video.play().then(function () {
						var busy = false;
						scanner.timer = setInterval(function () {
							if (busy || !scanner) { return; }
							busy = true;
							detector.detect(video).then(function (codes) {
								busy = false;
								if (codes && codes[0]) { onScanned(codes[0].rawValue); }
							}).catch(function () { busy = false; });
						}, 150);
					});
				});
			}
			return loadZXing().then(function (ZX) {
				if (!scanner) { return; }
				var hints = new Map();
				hints.set(ZX.DecodeHintType.POSSIBLE_FORMATS, [ZX.BarcodeFormat.UPC_A, ZX.BarcodeFormat.EAN_13, ZX.BarcodeFormat.CODE_128, ZX.BarcodeFormat.UPC_E, ZX.BarcodeFormat.EAN_8]);
				hints.set(ZX.DecodeHintType.TRY_HARDER, true);
				var reader = new ZX.BrowserMultiFormatReader(hints, 250);
				scanner.reader = reader;
				return reader.decodeFromConstraints({ video: { facingMode: 'environment' }, audio: false }, video, function (result) {
					if (result) { onScanned(result.getText()); }
				});
			});
		});
	}

	function stopScanner() {
		if (!scanner) { return; }
		if (scanner.timer) { clearInterval(scanner.timer); }
		if (scanner.reader) { try { scanner.reader.reset(); } catch (e) { /* already stopped */ } }
		if (scanner.stream) { scanner.stream.getTracks().forEach(function (t) { t.stop(); }); }
		scanner = null;
	}

	/* ---------------- Bluetooth / USB scanners ---------------- */
	// These type the code very fast and press Enter. Catch that anywhere on the page.

	var keys = '';
	var keyAt = 0;
	document.addEventListener('keydown', function (e) {
		var t = e.target;
		var typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA') && t.id !== 'q';
		if (typing || e.metaKey || e.ctrlKey || e.altKey) { return; }
		if (t && t.id === 'q') { return; } // The search box handles its own Enter.
		var now = Date.now();
		if (now - keyAt > 80) { keys = ''; }
		keyAt = now;
		if (e.key === 'Enter') {
			if (keys.length >= 4) { e.preventDefault(); handleCode(keys); }
			keys = '';
			return;
		}
		if (e.key && e.key.length === 1) { keys += e.key; }
	});

	/* ---------------- Toast ---------------- */

	var toastTimer;
	function toast(msg, bad) {
		var el = document.getElementById('toast');
		if (!el) { return; }
		el.textContent = msg;
		el.className = 'toast is-on' + (bad ? ' is-bad' : '');
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { el.className = 'toast'; }, bad ? 3200 : 1800);
	}

	/* ---------------- Clicks ---------------- */

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-action]');
		if (!btn) { return; }
		var a = btn.getAttribute('data-action');
		var id = Number(btn.getAttribute('data-id'));
		switch (a) {
			case 'tab':
				tab = btn.getAttribute('data-tab');
				renderTabs();
				renderCart();
				if (tab === 'sales') { renderSales(); }
				if (tab === 'stock') { openStock(); }
				break;
			case 'add':
				if (byId[id]) { addItem(byId[id]); }
				break;
			case 'pick': openPicker(id); break;
			case 'inc': addItem(byId[id]); break;
			case 'dec':
				cart.lines = cart.lines.map(function (l) { if (l.id === id) { l.qty -= 1; } return l; }).filter(function (l) { return l.qty > 0; });
				trimHolds();
				saveCart(); renderCart(); renderGridCounts();
				break;
			case 'clear-cart':
				cart = { lines: [], discount: 0 }; saveCart(); renderCart(); renderGridCounts();
				break;
			case 'open-cart': openCart(); break;
			case 'close-sheet': closeSheet(); break;
			case 'discount': openDiscount(); break;
			case 'disc-pct':
				document.getElementById('disc').value = (Math.round(totals().subtotal * Number(btn.getAttribute('data-pct'))) / 100).toFixed(2);
				break;
			case 'clear-discount': cart.discount = 0; saveCart(); renderCart(); break;
			case 'charge': openCharge(); break;
			case 'method':
				charge.method = btn.getAttribute('data-method');
				Array.prototype.forEach.call(document.querySelectorAll('.method'), function (m) {
					m.setAttribute('aria-pressed', m === btn ? 'true' : 'false');
				});
				document.getElementById('cash').hidden = charge.method !== 'cash';
				if (charge.method === 'cash') { document.getElementById('tendered').focus(); }
				updateComplete();
				break;
			case 'tender':
				charge.tendered = btn.getAttribute('data-amount');
				document.getElementById('tendered').value = charge.tendered;
				renderChange();
				break;
			case 'complete':
				if (!btn.disabled) { btn.disabled = true; completeSale(); }
				break;
			case 'new-sale':
				closeSheet();
				if (reloadWhenIdle) { location.reload(); }
				var q = document.getElementById('q');
				if (q && window.matchMedia('(min-width: 900px)').matches) { q.focus(); }
				break;
			case 'scan': openScanner(); break;
			case 'scan-again':
				if (byId[id] && scanner) { beep(true); addItem(byId[id], 1, true); scanAdded(byId[id]); }
				break;
			case 'scan-undo':
				if (byId[id]) {
					cart.lines = cart.lines.map(function (l) { if (l.id === id) { l.qty -= 1; } return l; }).filter(function (l) { return l.qty > 0; });
					trimHolds();
					saveCart(); renderCart(); renderGridCounts();
					if (scanner && !inCart(id)) { delete scanner.added[id]; }
					if (scanner) { scanner.asking = null; scanner.last = ''; }
					scanShow('idle', '', 'Removed one ' + itemLabel(byId[id]), inCart(id) ? inCart(id) + ' still in the cart' : 'Point the camera at the next barcode.');
					renderScanCart();
				}
				break;
			case 'scan-done':
				closeSheet();
				if (cart.lines.length && !window.matchMedia('(min-width: 900px)').matches) { openCart(); }
				break;
			case 'menu': openMenu(); break;
			case 'face-on': faceOn(); break;
			case 'face-off': faceOff(); break;
			case 'sync':
				if (!queue.length) { toast(online ? 'Everything is uploaded.' : 'Offline right now.'); break; }
				toast('Uploading...');
				syncQueue().then(function () { toast(queue.length ? queue.length + ' still waiting' : 'All sales uploaded'); });
				break;
			case 'refresh-catalog': closeSheet(); loadCatalog(false); break;
			case 'refresh-sales': renderSales(); break;
			case 'retry-sale':
				queue.forEach(function (s) { if (s.uuid === btn.getAttribute('data-uuid')) { delete s.error; } });
				store.set(KEY.queue, queue); syncQueue();
				break;
			case 'drop-sale':
				if (window.confirm('Delete this sale from the device? It will not be uploaded.')) {
					queue = queue.filter(function (s) { return s.uuid !== btn.getAttribute('data-uuid'); });
					store.set(KEY.queue, queue); renderStatus(); renderSales();
				}
				break;
			case 'install':
				if (installEvent) { installEvent.prompt(); installEvent = null; closeSheet(); }
				break;
			case 'logout':
				if (queue.length && !window.confirm(queue.length + ' sale(s) have not uploaded yet. They stay on this device and upload after you sign in again. Sign out?')) {
					e.preventDefault();
				}
				break;
			case 'retry-boot': boot(); break;
		}
	});

	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') { closeSheet(); }
	});

	/* ---------------- Start ---------------- */

	window.addEventListener('online', function () { setOnline(true); });
	window.addEventListener('offline', function () { setOnline(false); });

	if ('serviceWorker' in navigator && C.sw) {
		navigator.serviceWorker.register(C.sw, { scope: C.scope }).catch(function () { /* app still works online */ });
		var hadController = !!navigator.serviceWorker.controller;
		navigator.serviceWorker.addEventListener('controllerchange', function () {
			// A new version was installed: reload now if idle, otherwise after this sale.
			if (!hadController) { hadController = true; return; }
			var sheet = document.getElementById('sheet');
			if (!cart.lines.length && (!sheet || sheet.hidden)) { location.reload(); } else { reloadWhenIdle = true; }
		});
	}

	function boot() {
		indexCatalog();
		if (catalog) { renderAll(); }
		loadCatalog(true).then(function () { syncQueue(); });
	}

	setInterval(function () {
		if (online && queue.length) { syncQueue(); }
	}, 60000);
	setInterval(function () {
		var sheet = document.getElementById('sheet');
		if (online && !cart.lines.length && (!sheet || sheet.hidden)) { loadCatalog(true); }
	}, 10 * 60000);

	boot();
})();
