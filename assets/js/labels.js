/* PRDRWNDN POS: label printing screen. */
(function () {
	'use strict';

	var cfg = window.PRDRWNDN_POS || {};
	var DPI = 203;
	var SIZES = {
		'2.25x1.25': { page: [2.25, 1.25] },
		'2x1': { page: [2, 1] },
		'4x6': { page: [4, 6] },
		'4x6-8': { page: [4, 6], tile: [2, 1.5], cols: 2, rows: 4 },
		'4x6-12': { page: [4, 6], tile: [2, 1], cols: 2, rows: 6 }
	};
	var SHEET_PAD = 8; // dots kept clear inside each cut-out label, so a slightly off cut does not touch the print

	function layout(key) {
		var l = SIZES[key] || SIZES['2.25x1.25'];
		return {
			page: l.page,
			tile: l.tile || l.page,
			cols: l.cols || 1,
			rows: l.rows || 1,
			perPage: (l.cols || 1) * (l.rows || 1)
		};
	}
	var $ = function (id) { return document.getElementById(id); };

	var rowsEl = $('prdpos-rows');
	var sizeEl = $('prdpos-size');
	var priceEl = $('prdpos-price');
	var previewEl = $('prdpos-preview');
	var previewNote = $('prdpos-preview-note');
	var printerEl = $('prdpos-printer');
	var statusEl = $('prdpos-qz-status');
	var printBtn = $('prdpos-print');
	var totalEl = $('prdpos-total');
	var previewId = null;

	var items = (cfg.items || []).map(function (it) {
		it.checked = false;
		it.qty = 1;
		return it;
	});

	/* ---------------- Label drawing ---------------- */

	function labelName(name) {
		var n = String(name || '').replace(/PRDRWNDN\s*®?\s*/i, '').trim();
		return n || String(name || '');
	}

	function setFont(ctx, px, bold) {
		ctx.font = (bold ? 'bold ' : '') + px + 'px Helvetica, Arial, sans-serif';
	}

	// Shrink text until it fits, then cut it with an ellipsis if it still does not.
	function fitText(ctx, text, maxW, px, minPx, bold) {
		var size = px;
		setFont(ctx, size, bold);
		while (size > minPx && ctx.measureText(text).width > maxW) {
			size -= 1;
			setFont(ctx, size, bold);
		}
		var out = text;
		if (ctx.measureText(out).width > maxW) {
			while (out.length > 1 && ctx.measureText(out + '…').width > maxW) {
				out = out.slice(0, -1);
			}
			out = out.trim() + '…';
		}
		return { text: out, size: size };
	}

	// UPC-A: guard bars run longer, digits sit under the bars (1 99874 89194 1).
	var QUIET = 9;
	function drawUPC(ctx, item, top, bottom, W) {
		var mods = item.modules || '';
		var long = item.long || '';
		var num = String(item.barcode || '');
		var mw = Math.max(1, Math.min(4, Math.floor((W - 8) / mods.length)));
		var x0 = Math.round((W - mw * mods.length) / 2);
		var ext = 5 * mw;
		var normalH = Math.max(24, bottom - top - Math.round(9.5 * mw));
		ctx.fillStyle = '#000';
		for (var i = 0; i < mods.length; i++) {
			if (mods.charAt(i) === '1') {
				ctx.fillRect(x0 + i * mw, top, mw, normalH + (long.charAt(i) === '1' ? ext : 0));
			}
		}
		if (num.length === 12) {
			var digitTop = top + normalH + Math.round(1.2 * mw);
			var big = Math.round(9.6 * mw);
			var small = Math.round(7.4 * mw);
			ctx.textAlign = 'center';
			var put = function (moduleX, ch, size) {
				setFont(ctx, size, true);
				ctx.fillText(ch, x0 + Math.round(moduleX * mw), digitTop + (size === small ? Math.round(1.5 * mw) : 0));
			};
			put(QUIET / 2, num.charAt(0), small);
			for (var k = 0; k < 5; k++) {
				put(QUIET + 10 + 7 * k + 3.5, num.charAt(1 + k), big);
				put(QUIET + 50 + 7 * k + 3.5, num.charAt(6 + k), big);
			}
			put(QUIET + 95 + QUIET / 2, num.charAt(11), small);
			ctx.textAlign = 'left';
		}
		return mw;
	}

	// Pure black and white pixels print cleanest on a thermal printer.
	function toOneBit(ctx, W, H) {
		var img = ctx.getImageData(0, 0, W, H);
		var d = img.data;
		for (var p = 0; p < d.length; p += 4) {
			var lum = 0.299 * d[p] + 0.587 * d[p + 1] + 0.114 * d[p + 2];
			var v = lum < 150 ? 0 : 255;
			d[p] = d[p + 1] = d[p + 2] = v;
			d[p + 3] = 255;
		}
		ctx.putImageData(img, 0, 0);
	}

	function renderLabel(item, dims, showPrice, canvas) {
		var W = Math.round(dims[0] * DPI);
		var H = Math.round(dims[1] * DPI);
		canvas = canvas || document.createElement('canvas');
		canvas.width = W;
		canvas.height = H;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, W, H);
		ctx.fillStyle = '#000';
		ctx.textBaseline = 'top';

		// Layout is designed for 2.25 x 1.25 in and scaled for other sizes.
		var s = Math.min(H / 254, W / 457);
		var contentH = Math.round(254 * s);
		var y0 = Math.round((H - contentH) / 2);
		var m = Math.round(10 * s);
		var px = function (n) { return Math.max(10, Math.round(n * s)); };

		// Top row: brand on the left, price on the right.
		var priceW = 0;
		if (showPrice && item.price) {
			setFont(ctx, px(32), true);
			priceW = ctx.measureText(item.price).width;
			ctx.textAlign = 'right';
			ctx.fillText(item.price, W - m, y0 + m - Math.round(2 * s));
		}
		ctx.textAlign = 'left';
		setFont(ctx, px(18), true);
		ctx.fillText(cfg.brand || 'PRDRWNDN', m, y0 + m + Math.round(2 * s));

		// Product name and option (scent, color, size).
		var textW = W - 2 * m;
		var nameTop = y0 + m + Math.round(32 * s);
		var name = fitText(ctx, labelName(item.name), textW, px(26), px(16), true);
		setFont(ctx, name.size, true);
		ctx.fillText(name.text, m, nameTop);

		var lineTop = nameTop + Math.round(30 * s);
		var skuW = 0;
		if (item.sku) {
			setFont(ctx, px(16), true);
			skuW = ctx.measureText(item.sku).width;
			ctx.textAlign = 'right';
			ctx.fillText(item.sku, W - m, lineTop + Math.round(4 * s));
			ctx.textAlign = 'left';
		}
		if (item.variant) {
			var v = fitText(ctx, item.variant, textW - (skuW ? skuW + Math.round(12 * s) : 0), px(22), px(14), false);
			setFont(ctx, v.size, false);
			ctx.fillText(v.text, m, lineTop);
		}
		var barTop = y0 + Math.round((item.variant || item.sku ? 100 : 78) * s);
		var mw = drawUPC(ctx, item, barTop, y0 + contentH - Math.round(6 * s), W);

		toOneBit(ctx, W, H);
		return { canvas: canvas, moduleWidth: mw, width: dims[0], height: dims[1] };
	}

	// Several labels on one page, with dashed cut lines between them.
	function renderSheet(list, lay, showPrice, canvas) {
		var W = Math.round(lay.page[0] * DPI);
		var H = Math.round(lay.page[1] * DPI);
		canvas = canvas || document.createElement('canvas');
		canvas.width = W;
		canvas.height = H;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#fff';
		ctx.fillRect(0, 0, W, H);
		var tw = Math.round(lay.tile[0] * DPI);
		var th = Math.round(lay.tile[1] * DPI);
		var inner = [(tw - 2 * SHEET_PAD) / DPI, (th - 2 * SHEET_PAD) / DPI];
		list.forEach(function (item, n) {
			var col = n % lay.cols;
			var row = Math.floor(n / lay.cols);
			var r = renderLabel(item, inner, showPrice);
			ctx.drawImage(r.canvas, col * tw + SHEET_PAD, row * th + SHEET_PAD);
		});
		ctx.strokeStyle = '#000';
		ctx.lineWidth = 2;
		ctx.setLineDash([10, 10]);
		ctx.beginPath();
		for (var c = 1; c < lay.cols; c++) { ctx.moveTo(c * tw, 0); ctx.lineTo(c * tw, H); }
		for (var rr = 1; rr < lay.rows; rr++) { ctx.moveTo(0, rr * th); ctx.lineTo(W, rr * th); }
		ctx.stroke();
		ctx.setLineDash([]);
		return { canvas: canvas, width: lay.page[0], height: lay.page[1] };
	}

	// What to send to the printer: one image per label (with copies) or one per sheet.
	function printJobs(sel, key, showPrice) {
		var lay = layout(key);
		if (lay.perPage === 1) {
			return sel.map(function (it) {
				return { canvas: renderLabel(it, lay.page, showPrice).canvas, copies: it.qty, name: it.sku || it.barcode };
			});
		}
		var all = [];
		sel.forEach(function (it) { for (var i = 0; i < it.qty; i++) { all.push(it); } });
		var jobs = [];
		for (var p = 0; p < all.length; p += lay.perPage) {
			var chunk = all.slice(p, p + lay.perPage);
			jobs.push({ canvas: renderSheet(chunk, lay, showPrice).canvas, copies: 1, name: 'sheet ' + (jobs.length + 1) });
		}
		return jobs;
	}

	/* ---------------- Table ---------------- */

	function el(tag, attrs, text) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'className') { e.className = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		if (text !== undefined && text !== null) { e.textContent = text; }
		return e;
	}

	function buildRows() {
		rowsEl.innerHTML = '';
		items.forEach(function (it) {
			var ok = !!it.modules;
			var tr = el('tr', { 'data-id': it.id, className: ok ? '' : 'is-disabled' });

			var th = el('th', { className: 'check-column', scope: 'row' });
			var cb = el('input', { type: 'checkbox', id: 'prdpos-cb-' + it.id });
			cb.disabled = !ok;
			cb.addEventListener('change', function () {
				it.checked = cb.checked;
				qty.disabled = !cb.checked;
				if (cb.checked) { previewId = it.id; }
				refresh();
			});
			th.appendChild(cb);
			tr.appendChild(th);

			var tdName = el('td');
			var lab = el('label', { 'for': 'prdpos-cb-' + it.id });
			lab.appendChild(el('strong', null, labelName(it.name)));
			if (it.variant) { lab.appendChild(el('span', { className: 'prdpos-muted' }, it.variant)); }
			tdName.appendChild(lab);
			tdName.addEventListener('click', function () { if (ok) { previewId = it.id; drawPreview(); } });
			tr.appendChild(tdName);

			var tdCode = el('td', { className: 'prdpos-code' }, it.sku || '-');
			tdCode.appendChild(el('span', { className: 'prdpos-muted' }, String(it.barcode || '').replace(/^(\d)(\d{5})(\d{5})(\d)$/, '$1 $2 $3 $4')));
			tr.appendChild(tdCode);
			tr.appendChild(el('td', { className: 'num' }, it.price || ''));
			tr.appendChild(el('td', { className: 'num' }, it.stock === null || it.stock === undefined ? '-' : String(it.stock)));

			var tdQty = el('td', { className: 'num' });
			var qty = el('input', { type: 'number', min: '0', max: '999', step: '1', value: String(it.qty), 'aria-label': 'Labels for ' + labelName(it.name) + (it.variant ? ' ' + it.variant : '') });
			qty.className = 'small-text';
			qty.disabled = true;
			qty.addEventListener('input', function () {
				it.qty = Math.max(0, Math.min(999, parseInt(qty.value, 10) || 0));
				refresh(true);
			});
			tdQty.appendChild(qty);
			tr.appendChild(tdQty);

			it._cb = cb;
			it._qty = qty;
			it._tr = tr;
			rowsEl.appendChild(tr);
		});
	}

	function selected() {
		return items.filter(function (it) { return it.checked && it.modules && it.qty > 0; });
	}

	function refresh(skipPreview) {
		var sel = selected();
		var count = sel.reduce(function (n, it) { return n + it.qty; }, 0);
		var lay = layout(sizeEl.value);
		var sheets = lay.perPage > 1 ? Math.ceil(count / lay.perPage) : 0;
		totalEl.textContent = count ? count + (count === 1 ? ' label' : ' labels') + ' for ' + sel.length + (sel.length === 1 ? ' product' : ' products') +
			(sheets ? ' on ' + sheets + (sheets === 1 ? ' sheet' : ' sheets') : '') : '';
		printBtn.disabled = !(count && printerEl.value);
		if (!skipPreview) { drawPreview(); }
	}

	function drawPreview() {
		var it = items.filter(function (x) { return x.id === previewId && x.modules; })[0] ||
			selected()[0] || items.filter(function (x) { return x.modules; })[0];
		if (!it) {
			previewNote.textContent = 'Add SKUs to your products to see a label here.';
			return;
		}
		var lay = layout(sizeEl.value);
		if (lay.perPage > 1) {
			var sel = selected();
			var list = [];
			sel.forEach(function (x) { for (var i = 0; i < x.qty && list.length < lay.perPage; i++) { list.push(x); } });
			if (!list.length) { for (var k = 0; k < lay.perPage; k++) { list.push(it); } }
			renderSheet(list, lay, priceEl.checked, previewEl);
			previewNote.textContent = 'First sheet: ' + lay.perPage + ' labels of ' + lay.tile[0] + ' x ' + lay.tile[1] + ' in on a 4 x 6 in label. Cut along the dashed lines.';
			return;
		}
		var r = renderLabel(it, lay.page, priceEl.checked, previewEl);
		previewNote.textContent = 'Preview at ' + r.width + ' x ' + r.height + ' in, ' + DPI + ' dpi. ' +
			(it.kind === 'upc' ? 'Real UPC.' : 'In-store number.');
	}

	$('prdpos-filter').addEventListener('input', function (e) {
		var q = e.target.value.toLowerCase().trim();
		items.forEach(function (it) {
			var hay = (it.name + ' ' + it.variant + ' ' + it.sku + ' ' + (it.barcode || '')).toLowerCase();
			it._tr.hidden = q && hay.indexOf(q) === -1;
		});
	});

	$('prdpos-all').addEventListener('change', function (e) {
		items.forEach(function (it) {
			if (!it.modules || it._tr.hidden) { return; }
			it.checked = e.target.checked;
			it._cb.checked = it.checked;
			it._qty.disabled = !it.checked;
		});
		refresh();
	});

	$('prdpos-qty-stock').addEventListener('click', function () {
		var any = items.some(function (it) { return it.checked; });
		items.forEach(function (it) {
			if (!it.modules || it._tr.hidden) { return; }
			if (!any && (it.stock || 0) > 0) {
				it.checked = true;
				it._cb.checked = true;
				it._qty.disabled = false;
			}
			if (it.checked) {
				it.qty = Math.max(0, it.stock || 0);
				it._qty.value = String(it.qty);
			}
		});
		refresh();
	});

	$('prdpos-clear').addEventListener('click', function () {
		items.forEach(function (it) {
			it.checked = false;
			it.qty = 1;
			it._cb.checked = false;
			it._qty.value = '1';
			it._qty.disabled = true;
		});
		$('prdpos-all').checked = false;
		refresh();
	});

	sizeEl.addEventListener('change', function () { refresh(); });
	priceEl.addEventListener('change', function () { drawPreview(); });

	/* ---------------- QZ Tray ---------------- */

	function api(path, opts) {
		opts = opts || {};
		opts.credentials = 'same-origin';
		opts.headers = Object.assign({ 'X-WP-Nonce': cfg.nonce }, opts.headers || {});
		// Sites without pretty links use ?rest_route=..., so a second "?" must become "&".
		var url = cfg.rest.indexOf('?') !== -1 ? cfg.rest + path.replace('?', '&') : cfg.rest + path;
		return fetch(url, opts).then(function (r) {
			return r.json().then(function (body) {
				if (!r.ok) { throw new Error(body && body.message ? body.message : 'Request failed'); }
				return body;
			});
		});
	}

	function setStatus(text, kind) {
		statusEl.textContent = text;
		statusEl.className = 'prdpos-status' + (kind ? ' is-' + kind : '');
	}

	function setupSecurity() {
		if (!window.qz) { return; }
		qz.security.setCertificatePromise(function (resolve) {
			if (!cfg.qzReady) { resolve(); return; }
			api('qz/certificate').then(function (d) { resolve(d.certificate || ''); }).catch(function () { resolve(); });
		});
		qz.security.setSignatureAlgorithm('SHA512');
		qz.security.setSignaturePromise(function (toSign) {
			return function (resolve) {
				if (!cfg.qzReady) { resolve(); return; }
				api('qz/sign', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ request: toSign })
				}).then(function (d) { resolve(d.signature || ''); }).catch(function () { resolve(); });
			};
		});
	}

	function remembered() {
		try { return window.localStorage.getItem('prdpos_printer') || ''; } catch (e) { return ''; }
	}

	function remember(name) {
		try { window.localStorage.setItem('prdpos_printer', name); } catch (e) { /* private mode */ }
	}

	function pickPrinter(list, fallback) {
		var saved = remembered();
		if (saved && list.indexOf(saved) !== -1) { return saved; }
		var hint = String(cfg.printer || '').toLowerCase();
		var match = list.filter(function (p) {
			var l = p.toLowerCase();
			return (hint && l.indexOf(hint) !== -1) || l.indexOf('ctp800') !== -1 || l.indexOf('label') !== -1;
		})[0];
		return match || fallback || list[0] || '';
	}

	function connect() {
		if (!window.qz) {
			setStatus('QZ Tray library did not load. Reload the page.', 'error');
			return Promise.resolve();
		}
		setStatus('Connecting to QZ Tray...');
		var start = qz.websocket.isActive() ? Promise.resolve() : qz.websocket.connect({ retries: 1, delay: 1 });
		return start.then(function () {
			return Promise.all([qz.printers.find(), qz.printers.getDefault().catch(function () { return ''; })]);
		}).then(function (res) {
			var list = Array.isArray(res[0]) ? res[0] : [res[0]];
			printerEl.innerHTML = '';
			list.forEach(function (p) { printerEl.appendChild(el('option', { value: p }, p)); });
			printerEl.value = pickPrinter(list, res[1]);
			printerEl.disabled = false;
			$('prdpos-connect').textContent = 'Reconnect';
			setStatus('Connected to QZ Tray.' + (cfg.qzReady ? ' Silent printing is on.' : ''), 'ok');
			refresh(true);
		}).catch(function (err) {
			setStatus('Could not reach QZ Tray. Make sure it is open on this computer (green icon in the menu bar), then try again. ' + (err && err.message ? '(' + err.message + ')' : ''), 'error');
		});
	}

	printerEl.addEventListener('change', function () { remember(printerEl.value); refresh(true); });
	$('prdpos-connect').addEventListener('click', connect);

	printBtn.addEventListener('click', function () {
		var sel = selected();
		var printer = printerEl.value;
		if (!sel.length || !printer) { return; }
		remember(printer);
		printBtn.disabled = true;
		var page = layout(sizeEl.value).page;
		var jobs = printJobs(sel, sizeEl.value, priceEl.checked);
		var done = 0;
		var chain = Promise.resolve();
		jobs.forEach(function (job) {
			chain = chain.then(function () {
				setStatus('Printing ' + job.name + ' (' + (done + 1) + ' of ' + jobs.length + ')...');
				var config = qz.configs.create(printer, {
					size: { width: page[0], height: page[1] },
					units: 'in',
					density: DPI,
					colorType: 'blackwhite',
					interpolation: 'nearest-neighbor',
					margins: 0,
					scaleContent: true,
					copies: job.copies,
					jobName: 'PRDRWNDN ' + job.name
				});
				return qz.print(config, [{
					type: 'pixel',
					format: 'image',
					flavor: 'base64',
					data: job.canvas.toDataURL('image/png').split(',')[1]
				}]).then(function () { done++; });
			});
		});
		chain.then(function () {
			setStatus('Sent ' + totalEl.textContent + ' to ' + printer + '.', 'ok');
		}).catch(function (err) {
			setStatus('Printing stopped: ' + (err && err.message ? err.message : err), 'error');
		}).then(function () {
			refresh(true);
		});
	});

	/* ---------------- Browser printing (no QZ Tray) ---------------- */

	$('prdpos-browser-print').addEventListener('click', function () {
		var sel = selected();
		if (!sel.length) {
			setStatus('Select at least one product first.', 'error');
			return;
		}
		var page = layout(sizeEl.value).page;
		var w = page[0] + 'in';
		var h = page[1] + 'in';
		var frame = document.createElement('iframe');
		frame.setAttribute('aria-hidden', 'true');
		frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;';
		document.body.appendChild(frame);
		var doc = frame.contentWindow.document;
		doc.open();
		doc.write('<!doctype html><html><head><title>Labels</title><style>@page{size:' + w + ' ' + h + ';margin:0}html,body{margin:0;padding:0}img{display:block;width:' + w + ';height:' + h + ';page-break-after:always;image-rendering:pixelated}</style></head><body></body></html>');
		doc.close();
		var imgs = [];
		printJobs(sel, sizeEl.value, priceEl.checked).forEach(function (job) {
			var url = job.canvas.toDataURL('image/png');
			for (var i = 0; i < job.copies; i++) {
				var img = doc.createElement('img');
				img.src = url;
				img.alt = job.name;
				doc.body.appendChild(img);
				imgs.push(img);
			}
		});
		Promise.all(imgs.map(function (img) {
			return img.complete ? Promise.resolve() : new Promise(function (r) { img.onload = r; img.onerror = r; });
		})).then(function () {
			frame.contentWindow.focus();
			frame.contentWindow.print();
			setTimeout(function () { frame.remove(); }, 60000);
		});
	});

	/* ---------------- Scan test ---------------- */

	$('prdpos-scan-form').addEventListener('submit', function (e) {
		e.preventDefault();
		var input = $('prdpos-scan');
		var code = input.value.trim();
		var out = $('prdpos-scan-result');
		if (!code) { return; }
		out.textContent = 'Looking up ' + code + '...';
		api('lookup?code=' + encodeURIComponent(code)).then(function (p) {
			out.innerHTML = '';
			var box = el('div', { className: 'prdpos-found' });
			box.appendChild(el('strong', null, labelName(p.name) + (p.variant ? ' (' + p.variant + ')' : '')));
			var meta = (p.sku ? 'SKU ' + p.sku + ' | ' : '') + (p.barcode_kind === 'upc' ? 'UPC ' : 'Barcode ') + p.barcode + ' | ' + (p.price_text || '') +
				(p.stock === null || p.stock === undefined ? '' : ' | ' + p.stock + ' in stock');
			box.appendChild(el('span', null, meta));
			out.appendChild(box);
			var row = items.filter(function (it) { return it.id === p.id; })[0];
			if (row && row._tr) {
				row._tr.classList.add('is-flash');
				row._tr.scrollIntoView({ block: 'nearest' });
				setTimeout(function () { row._tr.classList.remove('is-flash'); }, 1600);
				if (row.modules) { previewId = row.id; drawPreview(); }
			}
		}).catch(function (err) {
			out.innerHTML = '';
			out.appendChild(el('div', { className: 'prdpos-notfound' }, (err && err.message ? err.message : 'Not found') + ' (' + code + ')'));
		}).then(function () {
			input.value = '';
			input.focus();
		});
	});

	/* ---------------- Start ---------------- */

	buildRows();
	refresh();
	setupSecurity();
	if (window.qz) {
		// Connect quietly if QZ Tray is already running; otherwise wait for the button.
		qz.websocket.connect({ retries: 0 }).then(function () { return connect(); }).catch(function () {
			setStatus('QZ Tray is not running on this computer. Open it, then click Connect.');
		});
	}
})();
