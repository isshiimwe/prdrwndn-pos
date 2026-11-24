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
		cloud: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 18h10a4 4 0 0 0 .5-8A6 6 0 0 0 6 9.5 4.3 4.3 0 0 0 7 18z"/><path d="M12 11v5M9.5 13.5L12 16l2.5-2.5"/></svg>',
		print: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 9V4h10v5M7 17H5a1 1 0 0 1-1-1v-6a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1v6a1 1 0 0 1-1 1h-2M7 14h10v6H7z"/></svg>',
		download: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11M7.5 10.5L12 15l4.5-4.5M5 19h14"/></svg>',
		mail: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16v12H4z"/><path d="M4.5 6.5L12 13l7.5-6.5"/></svg>',
		wa: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.6a8.4 8.4 0 0 0-7.3 12.6L3.6 20.4l4.3-1.1A8.4 8.4 0 1 0 12 3.6z"/><path d="M9.3 8.6c.3-.6.6-.6.9-.6l.5.1 1 2.1-.6.9c.4.9 1.4 1.9 2.3 2.3l.9-.6 2.1 1c0 .7-.3 1.5-1.2 1.7-.8.2-2.3 0-4-1.5-1.6-1.4-2.3-3-2.2-3.9 0-.6.1-1.1.3-1.5z" stroke-width="1.4"/></svg>'
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

	// Jersey holds in the cart are charged what they were held at (their deal or discount stays with them) and sit out the deals.
	function heldUnits() {
		var room = {}, out = { qty: {}, off: 0 };
		cart.lines.forEach(function (l) { room[l.id] = (room[l.id] || 0) + l.qty; });
		(cart.holds || []).forEach(function (x) {
			if ((room[x.variation] || 0) < x.qty) { return; }
			room[x.variation] -= x.qty;
			out.qty[x.variation] = (out.qty[x.variation] || 0) + x.qty;
			var it = byId[x.variation];
			out.off += Math.max(0, (it ? Number(it.price) || 0 : 0) - (Number(x.price) || 0)) * x.qty;
		});
		out.off = Math.round(out.off * 100) / 100;
		return out;
	}

	function totals() {
		var lines = cartLines();
		var subtotal = lines.reduce(function (a, l) { return a + l.price * l.qty; }, 0);
		var held = heldUnits();
		var discounts = dealDiscounts(lines.map(function (l) {
			return { item: l.item, qty: l.qty - (held.qty[l.item.id] || 0), price: l.price, cats: l.cats };
		}).filter(function (l) { return l.qty > 0; }));
		if (held.off > 0) { discounts.push({ label: 'Held price', amount: held.off }); }
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
					'<button type="button" class="tab" data-action="tab" data-tab="sales">Sales</button>' +
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
				'<section class="pane pane-sales" id="pane-sales" aria-label="Sales" hidden></section>' +
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
			(res && res.id ? '<div class="done-receipt" id="done-receipt" data-id="' + res.id + '"><p class="muted small">Getting the receipt...</p></div>' :
				'<p class="muted small">Receipt options show here once the sale uploads. It is also in the Sales tab.</p>') +
			'<button type="button" class="btn btn-primary btn-xl" data-action="new-sale">New sale</button>' +
		'</div>';
		openSheet('done', html);
		if (res && res.id) { fillDoneReceipt(res.id, entry.payload && entry.payload.customer); }
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

	/* ---------------- Receipts ---------------- */

	var receipts = {};
	var canShareFiles = (function () {
		try { return !!(navigator.canShare && window.File && navigator.canShare({ files: [new File(['x'], 'x.pdf', { type: 'application/pdf' })] })); } catch (e) { return false; }
	})();
	var touch = window.matchMedia('(pointer: coarse)').matches;

	function getReceipt(id, fresh) {
		if (!fresh && receipts[id]) { return Promise.resolve(receipts[id]); }
		return api('pos/receipt/' + id).then(function (r) { receipts[id] = r; return r; });
	}

	function receiptCss(css) {
		if (document.getElementById('rcpt-css')) { return; }
		var st = document.createElement('style');
		st.id = 'rcpt-css';
		st.textContent = css;
		document.head.appendChild(st);
	}

	function receiptActions(r, prefill) {
		prefill = prefill || {};
		var id = r.id;
		var email = r.email || prefill.email || '';
		var phone = r.phone || prefill.phone || '';
		return '<div class="rcpt-actions">' +
			'<div class="rcpt-grid">' +
				'<button type="button" class="rbtn" data-action="rcpt-print" data-id="' + id + '">' + ICON.print + '<span>Print</span></button>' +
				'<button type="button" class="rbtn" data-action="rcpt-pdf" data-id="' + id + '">' + ICON.download + '<span>PDF</span></button>' +
				'<button type="button" class="rbtn" data-action="rcpt-form" data-form="email" data-id="' + id + '">' + ICON.mail + '<span>Email</span></button>' +
				'<button type="button" class="rbtn" data-action="rcpt-form" data-form="wa" data-id="' + id + '">' + ICON.wa + '<span>WhatsApp</span></button>' +
			'</div>' +
			'<div class="rcpt-more">' +
				'<button type="button" class="link" data-action="rcpt-label" data-id="' + id + '">Label printer (4x6)</button>' +
				(canShareFiles ? '<button type="button" class="link" data-action="rcpt-share" data-id="' + id + '">Share PDF</button>' : '') +
			'</div>' +
			'<form class="rcpt-form" data-form="email" data-id="' + id + '" hidden>' +
				'<label for="re-' + id + '">Customer email</label>' +
				'<div class="rcpt-row"><input id="re-' + id + '" name="to" type="email" autocomplete="off" autocapitalize="off" spellcheck="false" value="' + h(email) + '" required><button type="submit" class="btn btn-primary">Send</button></div>' +
			'</form>' +
			'<form class="rcpt-form" data-form="wa" data-id="' + id + '" hidden>' +
				'<label for="rw-' + id + '">Customer WhatsApp number</label>' +
				'<div class="rcpt-row"><input id="rw-' + id + '" name="to" type="tel" autocomplete="off" value="' + h(phone) + '" placeholder="Empty: pick a contact"><button type="submit" class="btn btn-primary">Open WhatsApp</button></div>' +
				'<p class="muted small">US numbers work without +1. Other countries start with + and the country code, like +250 for Rwanda.</p>' +
			'</form>' +
			'<p class="rcpt-msg" id="rcpt-msg-' + id + '" role="status" aria-live="polite"></p>' +
		'</div>';
	}

	function receiptSay(id, text, bad) {
		var el = document.getElementById('rcpt-msg-' + id);
		if (el) { el.textContent = text; el.className = 'rcpt-msg' + (bad ? ' is-bad' : ' is-ok'); }
		else if (text) { toast(text, bad); }
	}

	function openReceipt(id) {
		openSheet('receipt', '<div class="sheet-head"><h2>Receipt</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div><p class="muted pad">Loading the receipt...</p>');
		getReceipt(id).then(function (r) {
			var wrap = document.getElementById('sheet');
			if (!wrap || wrap.hidden || wrap.getAttribute('data-kind') !== 'receipt') { return; }
			receiptCss(r.css);
			document.getElementById('sheet-body').innerHTML =
				'<div class="sheet-head"><h2>Receipt #' + h(r.number) + '</h2><button type="button" class="icon-btn" data-action="close-sheet" aria-label="Close">' + ICON.close + '</button></div>' +
				receiptActions(r) +
				'<div class="rcpt-paper">' + r.html + '</div>';
		}).catch(function (e) {
			var body = document.getElementById('sheet-body');
			if (body) { body.querySelector('.pad').textContent = e.offline ? 'Offline right now. Receipts open when you are back online.' : e.message; }
		});
	}

	function fillDoneReceipt(id, prefill) {
		getReceipt(id).then(function (r) {
			var box = document.getElementById('done-receipt');
			if (!box || Number(box.getAttribute('data-id')) !== id) { return; }
			receiptCss(r.css);
			box.innerHTML = '<p class="done-label">Receipt</p>' + receiptActions(r, prefill) +
				(prefill && prefill.email ? '<p class="muted small">The store also emails a receipt to ' + h(prefill.email) + '.</p>' : '');
		}).catch(function () {
			var box = document.getElementById('done-receipt');
			if (box) { box.innerHTML = '<p class="muted small">The receipt is in the Sales tab.</p>'; }
		});
	}

	function printReceipt(id) {
		getReceipt(id).then(function (r) {
			receiptCss(r.css);
			var pr = document.getElementById('print-root');
			if (!pr) { pr = document.createElement('div'); pr.id = 'print-root'; document.body.appendChild(pr); }
			pr.innerHTML = r.html;
			document.body.classList.add('printing-receipt');
			var done = function () { document.body.classList.remove('printing-receipt'); window.removeEventListener('afterprint', done); };
			window.addEventListener('afterprint', done);
			setTimeout(function () { window.print(); setTimeout(done, 1500); }, 60);
		}).catch(function (e) { receiptSay(id, e.message, true); });
	}

	function receiptPdf(r) {
		return fetch(r.download, { credentials: 'same-origin', cache: 'no-store' }).then(function (res) {
			if (!res.ok) { throw new Error('Could not make the PDF (' + res.status + ').'); }
			return res.blob();
		}, function () { throw new Error('No connection.'); });
	}

	function saveFile(blob, name, title) {
		if (touch && canShareFiles && window.File) {
			var file = new File([blob], name, { type: blob.type || 'application/octet-stream' });
			if (navigator.canShare({ files: [file] })) {
				return navigator.share({ files: [file], title: title || name }).catch(function (e) { if (e && e.name !== 'AbortError') { throw e; } });
			}
		}
		var url = URL.createObjectURL(blob);
		var a = document.createElement('a');
		a.href = url;
		a.download = name;
		document.body.appendChild(a);
		a.click();
		setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 4000);
		return Promise.resolve();
	}

	function downloadReceipt(id) {
		receiptSay(id, 'Making the PDF...');
		getReceipt(id).then(function (r) {
			return receiptPdf(r).then(function (blob) { return saveFile(blob, r.filename, 'Receipt #' + r.number); });
		}).then(function () { receiptSay(id, ''); }).catch(function (e) { receiptSay(id, e.message, true); });
	}

	function shareReceipt(id) {
		getReceipt(id).then(function (r) {
			return receiptPdf(r).then(function (blob) {
				var file = new File([blob], r.filename, { type: 'application/pdf' });
				return navigator.share({ files: [file], title: 'Receipt #' + r.number, text: 'PRDRWNDN receipt #' + r.number });
			});
		}).catch(function (e) { if (e && e.name !== 'AbortError') { receiptSay(id, e.message, true); } });
	}

	function emailReceipt(id, to, btn) {
		btn.disabled = true;
		receiptSay(id, 'Sending...');
		api('pos/receipt/' + id + '/email', { method: 'POST', body: JSON.stringify({ email: to }) }).then(function (res) {
			if (receipts[id]) { receipts[id].email = res.to; }
			receiptSay(id, 'Receipt emailed to ' + res.to + '.');
		}).catch(function (e) {
			receiptSay(id, e.offline ? 'Offline right now. Try again when you are back online.' : e.message, true);
		}).then(function () { btn.disabled = false; });
	}

	function waNumber(raw) {
		var s = String(raw || '').trim();
		var digits = s.replace(/\D/g, '');
		if (!digits) { return ''; }
		if (s.charAt(0) === '+' || s.indexOf('00') === 0) { return s.indexOf('00') === 0 ? digits.slice(2) : digits; }
		if (digits.length === 10) { return '1' + digits; }
		return digits;
	}

	function whatsappReceipt(id, to) {
		getReceipt(id).then(function (r) {
			var n = waNumber(to);
			window.open('https://wa.me/' + n + '?text=' + encodeURIComponent(r.text), '_blank', 'noopener');
			receiptSay(id, n ? 'WhatsApp opened with the receipt for +' + n + '.' : 'WhatsApp opened. Pick the customer, then send.');
		}).catch(function (e) { receiptSay(id, e.message, true); });
	}

	/* Label printer (CTP800BD) through QZ Tray on the Mac. */
	var qzSetup = false;
	function loadScript(src) {
		return new Promise(function (resolve, reject) {
			var s = document.createElement('script');
			s.src = src;
			s.onload = resolve;
			s.onerror = function () { reject(new Error('Could not load QZ Tray.')); };
			document.head.appendChild(s);
		});
	}
	function qzPrinter() {
		return (window.qz ? Promise.resolve() : loadScript(C.qz)).then(function () {
			if (!qzSetup) {
				qz.security.setCertificatePromise(function (resolve) {
					if (!C.qzSign) { resolve(); return; }
					api('qz/certificate').then(function (d) { resolve(d.certificate || ''); }).catch(function () { resolve(); });
				});
				qz.security.setSignatureAlgorithm('SHA512');
				qz.security.setSignaturePromise(function (toSign) {
					return function (resolve) {
						if (!C.qzSign) { resolve(); return; }
						api('qz/sign', { method: 'POST', body: JSON.stringify({ request: toSign }) }).then(function (d) { resolve(d.signature || ''); }).catch(function () { resolve(); });
					};
				});
				qzSetup = true;
			}
			return qz.websocket.isActive() ? null : qz.websocket.connect({ retries: 1, delay: 1 });
		}).then(function () {
			return Promise.all([qz.printers.find(), qz.printers.getDefault().catch(function () { return ''; })]);
		}).then(function (res) {
			var list = Array.isArray(res[0]) ? res[0] : [res[0]];
			var saved = '';
			try { saved = localStorage.getItem('prdpos_printer') || ''; } catch (e) { /* private mode */ }
			if (saved && list.indexOf(saved) !== -1) { return saved; }
			var hint = String(C.printer || '').toLowerCase();
			var match = list.filter(function (p) {
				var l = String(p).toLowerCase();
				return (hint && l.indexOf(hint) !== -1) || l.indexOf('ctp800') !== -1 || l.indexOf('label') !== -1;
			})[0];
			var pick = match || res[1] || list[0] || '';
			if (!pick) { throw new Error('No printer found.'); }
			return pick;
		});
	}
	function labelReceipt(id) {
		receiptSay(id, 'Connecting to the label printer...');
		var printer;
		qzPrinter().then(function (p) {
			printer = p;
			return getReceipt(id);
		}).then(function (r) {
			return receiptPdf(r).then(function (blob) {
				return new Promise(function (resolve, reject) {
					var fr = new FileReader();
					fr.onload = function () { resolve({ r: r, b64: String(fr.result).split(',')[1] }); };
					fr.onerror = reject;
					fr.readAsDataURL(blob);
				});
			});
		}).then(function (x) {
			var config = qz.configs.create(printer, { size: { width: 4, height: 6 }, units: 'in', colorType: 'blackwhite', margins: 0, scaleContent: true, jobName: 'PRDRWNDN receipt #' + x.r.number });
			return qz.print(config, [{ type: 'pixel', format: 'pdf', flavor: 'base64', data: x.b64 }]);
		}).then(function () {
			receiptSay(id, 'Sent to ' + printer + '.');
		}).catch(function (e) {
			var msg = e && e.message ? e.message : String(e);
			receiptSay(id, /QZ|websocket|connect|Unable to establish/i.test(msg) || !window.qz ?
				'Could not reach the label printer. Use the POS on the Mac with QZ Tray open (green icon in the menu bar), or use Print.' :
				'Printing stopped: ' + msg, true);
		});
	}

	document.addEventListener('submit', function (e) {
		var f = e.target;
		if (!f.classList || !f.classList.contains('rcpt-form')) { return; }
		e.preventDefault();
		var id = Number(f.getAttribute('data-id'));
		var to = f.querySelector('input').value.trim();
		if (f.getAttribute('data-form') === 'email') {
			emailReceipt(id, to, f.querySelector('button'));
		} else {
			whatsappReceipt(id, to);
		}
	});

	/* ---------------- Sales history ---------------- */

	var hist = { preset: 'today', from: '', to: '', method: '', cashier: '', q: '', page: 1, rows: [], data: null, seq: 0 };
	var PRESETS = [['today', 'Today'], ['yesterday', 'Yesterday'], ['7', '7 days'], ['30', '30 days'], ['month', 'This month'], ['custom', 'Pick dates']];

	function two(n) { return (n < 10 ? '0' : '') + n; }
	function ymd(d) { return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()); }
	function histRange() {
		var now = new Date(), d = new Date(now), to = ymd(now), from = to;
		if (hist.preset === 'yesterday') { d.setDate(d.getDate() - 1); from = to = ymd(d); }
		else if (hist.preset === '7') { d.setDate(d.getDate() - 6); from = ymd(d); }
		else if (hist.preset === '30') { d.setDate(d.getDate() - 29); from = ymd(d); }
		else if (hist.preset === 'month') { from = ymd(new Date(now.getFullYear(), now.getMonth(), 1)); }
		else if (hist.preset === 'custom') { from = hist.from || to; to = hist.to || from; }
		return [from, to];
	}
	function histLabel() {
		var p = PRESETS.filter(function (x) { return x[0] === hist.preset; })[0];
		if (hist.preset === 'custom') { var r = histRange(); return r[0] === r[1] ? r[0] : r[0] + ' to ' + r[1]; }
		return p ? (hist.preset === '7' || hist.preset === '30' ? 'Last ' + p[1] : p[1]) : '';
	}
	function histQuery(page, per) {
		var r = histRange();
		var q = (hist.preset === 'custom' ? 'from=' + r[0] + '&to=' + r[1] : 'range=' + hist.preset) + '&page=' + page + '&per_page=' + per;
		if (hist.method) { q += '&method=' + encodeURIComponent(hist.method); }
		if (hist.cashier) { q += '&cashier=' + encodeURIComponent(hist.cashier); }
		if (hist.q) { q += '&q=' + encodeURIComponent(hist.q); }
		return q;
	}

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
		var r = histRange();
		var methods = (catalog && catalog.methods) || {};
		pane.innerHTML = '<div class="sales-head"><h2>Sales</h2>' +
			(queue.length ? '<button type="button" class="btn btn-small" data-action="sync">Upload now</button>' : '') +
			'<button type="button" class="btn btn-small" data-action="hist-csv">' + ICON.download + ' Download</button>' +
			'<button type="button" class="btn btn-small" data-action="refresh-sales">Refresh</button></div>' +
			'<div class="hist-filters">' +
				'<div class="chips hist-presets" role="group" aria-label="Dates">' + PRESETS.map(function (p) {
					return '<button type="button" class="chip" data-action="hist-preset" data-preset="' + p[0] + '" aria-pressed="' + (hist.preset === p[0]) + '">' + h(p[1]) + '</button>';
				}).join('') + '</div>' +
				'<div class="hist-range"' + (hist.preset === 'custom' ? '' : ' hidden') + '>' +
					'<label>From <input type="date" id="h-from" value="' + h(r[0]) + '"></label>' +
					'<label>To <input type="date" id="h-to" value="' + h(r[1]) + '"></label>' +
				'</div>' +
				'<div class="hist-row">' +
					'<label class="sr" for="h-q">Search sales</label><input id="h-q" type="search" placeholder="Search order #, customer or item" value="' + h(hist.q) + '">' +
					'<label class="sr" for="h-method">Payment</label><select id="h-method"><option value="">All payments</option>' + Object.keys(methods).map(function (k) {
						return '<option value="' + h(k) + '"' + (hist.method === k ? ' selected' : '') + '>' + h(methods[k]) + '</option>';
					}).join('') + '</select>' +
					(C.manager ? '<label class="sr" for="h-cashier">Cashier</label><select id="h-cashier"><option value="">Everyone</option></select>' : '') +
				'</div>' +
			'</div>' +
			(pending ? '<h3>On this device</h3><ul class="sales">' + pending + '</ul>' : '') +
			'<div id="sales-server"><p class="muted pad">' + (online ? 'Loading sales...' : 'Offline. Sales from the store show here when you are back online.') + '</p></div>';

		var reload = function () { hist.page = 1; loadHistory(); };
		var qt;
		document.getElementById('h-q').addEventListener('input', function (e) { hist.q = e.target.value.trim(); clearTimeout(qt); qt = setTimeout(reload, 350); });
		document.getElementById('h-method').addEventListener('change', function (e) { hist.method = e.target.value; reload(); });
		var hc = document.getElementById('h-cashier');
		if (hc) { hc.addEventListener('change', function (e) { hist.cashier = e.target.value; reload(); }); }
		['h-from', 'h-to'].forEach(function (fid) {
			document.getElementById(fid).addEventListener('change', function () {
				hist.from = document.getElementById('h-from').value;
				hist.to = document.getElementById('h-to').value;
				if (hist.from && hist.to && hist.to < hist.from) { var t = hist.from; hist.from = hist.to; hist.to = t; }
				reload();
			});
		});
		if (online) { loadHistory(); }
	}

	function loadHistory(more) {
		var box = document.getElementById('sales-server');
		if (!box) { return; }
		var seq = ++hist.seq;
		if (!more) { hist.page = 1; hist.rows = []; }
		api('pos/history?' + histQuery(hist.page, 50)).then(function (d) {
			if (seq !== hist.seq) { return; }
			hist.data = d;
			hist.rows = more ? hist.rows.concat(d.sales) : d.sales;
			var hc = document.getElementById('h-cashier');
			if (hc && d.cashiers) {
				var cur = hist.cashier;
				hc.innerHTML = '<option value="">Everyone</option>' + d.cashiers.map(function (c) {
					return '<option value="' + c.id + '"' + (String(c.id) === String(cur) ? ' selected' : '') + '>' + h(c.name) + '</option>';
				}).join('');
			}
			renderHistory();
		}).catch(function (e) {
			if (seq !== hist.seq) { return; }
			box.innerHTML = '<p class="muted pad">' + h(e.offline ? 'Offline right now.' : e.message) + '</p>';
		});
	}

	function renderHistory() {
		var box = document.getElementById('sales-server');
		var d = hist.data;
		if (!box || !d) { return; }
		var oneDay = d.from === d.to;
		if (hist.preset !== 'custom') { hist.from = d.from; hist.to = d.to; }
		var totalsHtml = Object.keys(d.totals).map(function (k) {
			return '<div class="sum"><span>' + h(k) + '</span><span>' + money(d.totals[k]) + '</span></div>';
		}).join('');
		box.innerHTML = '<div class="sums day-totals">' + totalsHtml +
			'<div class="sum sum-total"><span>' + h(histLabel()) + ' (' + d.count + (d.count === 1 ? ' sale' : ' sales') + ', ' + d.units + (d.units === 1 ? ' item' : ' items') + ')</span><span>' + money(d.total) + '</span></div></div>' +
			(!C.manager ? '<p class="muted small">Showing the sales you rang up.</p>' : '') +
			(hist.rows.length ? '<ul class="sales">' + hist.rows.map(function (s) {
				var when = oneDay ? s.time : s.day + ', ' + s.time;
				var flag = !s.counts ? '<span class="badge badge-bad">' + h(s.statusLabel) + '</span>' : (s.refunded > 0 ? '<span class="badge">Refunded ' + money(s.refunded) + '</span>' : '');
				return '<li><button type="button" class="sale sale-btn' + (!s.counts ? ' is-void' : '') + '" data-action="open-receipt" data-id="' + s.id + '">' +
					'<span class="sale-main"><strong>#' + h(s.number) + '</strong> · ' + h(when) + ' · ' + h(s.method) +
						(s.customer ? ' · ' + h(s.customer) : '') +
						'<span class="muted block">' + h(s.items) + '</span>' + flag + '</span>' +
					'<span class="sale-side"><strong>' + money(s.total) + '</strong><span class="muted">' + h(s.cashier) + '</span></span>' +
				'</button></li>';
			}).join('') + '</ul>' +
			(d.page < d.pages ? '<button type="button" class="btn btn-block" data-action="hist-more">Show more (' + (d.found - hist.rows.length) + ' left)</button>' : '') :
			'<p class="muted pad">No in-person sales ' + (hist.q || hist.method || hist.cashier ? 'match these filters.' : (hist.preset === 'today' ? 'yet today.' : 'in this period.')) + '</p>');
	}

	function csvCell(v) {
		var s = String(v === null || v === undefined ? '' : v);
		if (/^[=+\-@\t\r]/.test(s)) { s = "'" + s; } // Keep spreadsheets from running it as a formula.
		return /[",\n\r]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
	}

	function downloadHistory(btn) {
		btn.disabled = true;
		toast('Getting all sales...');
		var all = [];
		var page = 1;
		var step = function () {
			return api('pos/history?' + histQuery(page, 1000)).then(function (d) {
				all = all.concat(d.sales);
				if (d.page < d.pages) { page++; return step(); }
				return d;
			});
		};
		step().then(function (d) {
			var head = ['Order', 'Date', 'Time', 'Cashier', 'Customer', 'Phone', 'Email', 'Items', 'Units', 'Subtotal', 'Discounts', 'Tax', 'Total', 'Refunded', 'Net', 'Payment', 'Status'];
			var lines = [head.join(',')].concat(all.map(function (s) {
				return [s.number, s.date, s.time, s.cashier, s.customer, s.phone, s.email, s.items, s.units, s.subtotal.toFixed(2), s.discount.toFixed(2), s.tax.toFixed(2), s.total.toFixed(2), s.refunded.toFixed(2), s.net.toFixed(2), s.method, s.statusLabel].map(csvCell).join(',');
			}));
			var name = 'prdrwndn-pos-sales-' + d.from + (d.to !== d.from ? '-to-' + d.to : '') + '.csv';
			var blob = new Blob(['﻿' + lines.join('\r\n') + '\r\n'], { type: 'text/csv;charset=utf-8' });
			return saveFile(blob, name, 'POS sales ' + d.from + (d.to !== d.from ? ' to ' + d.to : ''));
		}).then(function () {
			toast(all.length + (all.length === 1 ? ' sale' : ' sales') + ' downloaded');
		}).catch(function (e) {
			toast(e.offline ? 'Offline right now.' : e.message, true);
		}).then(function () { btn.disabled = false; });
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
			case 'refresh-sales': receipts = {}; renderSales(); break;
			case 'hist-preset':
				hist.preset = btn.getAttribute('data-preset');
				if (hist.preset === 'custom' && !hist.from) { hist.from = hist.to = (hist.data && hist.data.today) || ymd(new Date()); }
				renderSales();
				break;
			case 'hist-more': hist.page++; loadHistory(true); break;
			case 'hist-csv': downloadHistory(btn); break;
			case 'open-receipt': openReceipt(id); break;
			case 'rcpt-print': printReceipt(id); break;
			case 'rcpt-pdf': downloadReceipt(id); break;
			case 'rcpt-share': shareReceipt(id); break;
			case 'rcpt-label': labelReceipt(id); break;
			case 'rcpt-form':
				Array.prototype.forEach.call(btn.closest('.rcpt-actions').querySelectorAll('.rcpt-form'), function (f) {
					var show = f.getAttribute('data-form') === btn.getAttribute('data-form') && f.hidden;
					f.hidden = !show;
					if (show) { var inp = f.querySelector('input'); inp.focus({ preventScroll: true }); }
				});
				break;
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
