/* Smart Product Recommendations — admin. */
(function () {
	'use strict';
	var A = window.SPRAdmin || {};
	var T = A.i18n || {};
	var lang = document.documentElement.lang || undefined;

	function $(sel, root) { return (root || document).querySelector(sel); }
	function $$(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}
	function fmt(str) {
		var args = Array.prototype.slice.call(arguments, 1), i = 0;
		return String(str).replace(/%(\d\$)?s/g, function (m, pos) {
			return pos ? args[parseInt(pos, 10) - 1] : args[i++];
		});
	}
	function post(action, data) {
		var body = data instanceof FormData ? data : new URLSearchParams(data || {});
		body.append('action', action);
		body.append('nonce', A.nonce);
		return fetch(A.ajax, { method: 'POST', credentials: 'same-origin', body: body }).then(function (r) { return r.json(); });
	}

	/* ---------- toast ---------- */
	var toastEl = $('.spr-toast'), toastTimer;
	function toast(msg, isError) {
		if (!toastEl) { return; }
		toastEl.textContent = msg;
		toastEl.classList.toggle('is-error', !!isError);
		toastEl.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { toastEl.hidden = true; }, 2600);
	}

	/* ---------- index status (header pill, overview card, banner) ---------- */
	var ticking = false, lastStatus = null;
	function renderStatus(s) {
		var pill = $('[data-spr-pill]');
		if (pill) {
			pill.classList.toggle('is-done', s.status === 'done');
			pill.classList.toggle('is-running', s.status === 'running');
			$('[data-spr-pill-text]').textContent = s.status === 'running' && s.phase === 'relations'
				? T.running + ' ' + s.pct_i18n + '%'
				: (T[s.status] || s.status);
		}
		var banner = $('[data-spr-changed]');
		if (banner) { banner.hidden = !(s.changed && s.status !== 'running'); }
		$$('[data-spr-rebuild]').forEach(function (b) { b.disabled = s.status === 'running'; });

		var card = $('[data-spr-index]');
		if (!card) { return; }
		$('[data-spr-state]', card).textContent = T[s.status] || s.status;
		$('[data-spr-phase]', card).textContent = s.status === 'running' ? (T[s.phase] || '') : '';
		var prog = $('[data-spr-progress]', card);
		var indeterminate = s.status === 'running' && s.phase !== 'relations';
		prog.classList.toggle('is-indeterminate', indeterminate);
		prog.setAttribute('aria-valuenow', String(Math.round(s.pct)));
		$('[data-spr-bar]', card).style.inlineSize = (indeterminate ? 30 : s.pct) + '%';

		var meta = {
			progress: s.status === 'running' && s.phase === 'relations' ? fmt(T.progress, s.processed_i18n, s.total_i18n) : '',
			built: s.ended ? fmt(T.built, s.ended) : '',
			count: s.status === 'done' ? fmt(T.rel_count, s.relations_i18n) : '',
			next: s.next ? fmt(T.next, s.next) : ''
		};
		Object.keys(meta).forEach(function (k) {
			var li = $('[data-spr-meta="' + k + '"]', card);
			li.textContent = meta[k];
			li.hidden = !meta[k];
		});
		$('[data-spr-cancel]', card).disabled = s.status !== 'running';
	}
	function poll() {
		post('spr_status').then(function (r) {
			if (!r.success) { return; }
			var s = r.data;
			renderStatus(s);
			if (lastStatus === 'running' && s.status === 'done') {
				loadPreview(); // Fresh index → fresh preview.
			}
			lastStatus = s.status;
			if (s.status === 'running' && !ticking) {
				// Drive the build from the browser while this page is open.
				ticking = true;
				post('spr_tick').then(function (x) { ticking = false; if (x.success) { renderStatus(x.data); } })
					.catch(function () { ticking = false; });
			}
		}).catch(function () {});
	}
	poll();
	setInterval(poll, 4000);

	$$('[data-spr-rebuild]').forEach(function (b) {
		b.addEventListener('click', function () {
			if (window.confirm(T.confirm)) {
				post('spr_rebuild').then(function (r) { if (r.success) { renderStatus(r.data); lastStatus = 'running'; poll(); } });
			}
		});
	});
	$$('[data-spr-cancel]').forEach(function (b) {
		b.addEventListener('click', function () {
			post('spr_cancel').then(function (r) { if (r.success) { renderStatus(r.data); } });
		});
	});

	/* ---------- forms: live outputs, dirty state, AJAX save ---------- */
	function numberOut(input) {
		var step = parseFloat(input.step || '1');
		var digits = step < 0.1 ? 2 : (step < 1 ? 1 : 0);
		return Number(input.value).toLocaleString(lang, { minimumFractionDigits: digits, maximumFractionDigits: digits });
	}

	$$('[data-spr-form]').forEach(function (form) {
		var bar = $('[data-spr-savebar]', form);
		var label = $('[data-spr-dirty]', form);
		var initial = new URLSearchParams(new FormData(form)).toString();

		function dirty() {
			var now = new URLSearchParams(new FormData(form)).toString() !== initial;
			bar.classList.toggle('is-dirty', now);
			label.textContent = now ? T.unsaved : T.allSaved;
			return now;
		}
		form.addEventListener('input', function (e) {
			if (e.target.type === 'range') {
				var out = e.target.closest('.spr-slider').querySelector('[data-spr-out]');
				if (out) { out.textContent = numberOut(e.target); }
			}
			dirty();
		});
		form.addEventListener('change', dirty);

		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var btn = $('button[type="submit"]', form);
			btn.disabled = true;
			var fd = new FormData(form);
			fd.delete('option_page'); fd.delete('action'); fd.delete('_wpnonce'); fd.delete('_wp_http_referer');
			post('spr_save', fd).then(function (r) {
				btn.disabled = false;
				if (!r.success) { throw new Error('save'); }
				initial = new URLSearchParams(new FormData(form)).toString();
				dirty();
				toast(T.saved);
				poll();
			}).catch(function () {
				btn.disabled = false;
				toast(T.saveFail, true);
			});
		});

		window.addEventListener('beforeunload', function (e) {
			if (dirty()) { e.preventDefault(); e.returnValue = ''; }
		});
		$$('input[type="range"]', form).forEach(function (r) {
			var out = r.closest('.spr-slider').querySelector('[data-spr-out]');
			if (out) { out.textContent = numberOut(r); }
		});
	});

	/* ---------- placements: dim fields of switched-off locations ---------- */
	$$('.spr-place').forEach(function (row) {
		var cb = $('input[type="checkbox"]', row);
		cb.addEventListener('change', function () { row.classList.toggle('is-on', cb.checked); });
	});
	$$('[data-spr-filter]').forEach(function (input) {
		var select = $(input.getAttribute('data-spr-filter'));
		input.addEventListener('input', function () {
			var q = input.value.trim().toLowerCase();
			$$('option', select).forEach(function (o) { o.hidden = q && o.textContent.toLowerCase().indexOf(q) === -1 && !o.selected; });
		});
	});

	/* ---------- appearance: swatches + live preview ---------- */
	var color = $('#spr-accent');
	function syncSwatches() {
		if (!color) { return; }
		$$('.spr-swatch').forEach(function (s) { s.classList.toggle('is-active', s.getAttribute('data-color').toLowerCase() === color.value.toLowerCase()); });
		var hex = $('[data-spr-hex]');
		if (hex) { hex.textContent = color.value.toUpperCase(); }
	}
	$$('.spr-swatch').forEach(function (s) {
		s.addEventListener('click', function () {
			color.value = s.getAttribute('data-color');
			color.dispatchEvent(new Event('input', { bubbles: true }));
			color.dispatchEvent(new Event('change', { bubbles: true }));
		});
	});
	syncSwatches();

	var preview = $('[data-spr-preview]');
	var canvas = $('[data-spr-canvas]');
	function fit() {
		if (!canvas) { return; }
		var sizer = canvas.parentNode;
		var avail = sizer.clientWidth;
		var w = canvas.offsetWidth;
		var scale = Math.min(1, avail / w);
		canvas.style.transform = scale < 1 ? 'scale(' + scale + ')' : '';
		// Mobile canvas fits unscaled; center it.
		canvas.style.marginInlineStart = scale < 1 ? '0' : Math.max(0, (avail - w) / 2) + 'px';
		sizer.style.blockSize = Math.ceil(canvas.offsetHeight * scale) + 'px';
	}
	window.addEventListener('resize', fit);
	var previewTimer, previewReq = 0;
	function loadPreview() {
		if (!preview) { return; }
		var form = preview.closest('form');
		var fd = new FormData(form);
		fd.delete('option_page'); fd.delete('action'); fd.delete('_wpnonce'); fd.delete('_wp_http_referer');
		fd.append('q', $('[data-spr-preview-q]').value.trim());
		var id = ++previewReq;
		canvas.classList.add('is-loading');
		post('spr_preview_block', fd).then(function (r) {
			if (id !== previewReq) { return; }
			canvas.classList.remove('is-loading');
			var name = $('[data-spr-preview-name]');
			if (!r.success) {
				canvas.innerHTML = '<p class="spr-empty">' + esc(r.data === 'not_found' ? T.notFound : T.noPreview) + '</p>';
				name.textContent = '';
				fit();
				return;
			}
			canvas.innerHTML = r.data.html || '<p class="spr-empty">' + esc(T.none) + '</p>';
			name.textContent = r.data.name;
			if (window.SPRInit) { window.SPRInit(canvas); }
			fit();
		}).catch(function () { canvas.classList.remove('is-loading'); });
	}
	function setVars() {
		var radius = $('#spr-radius');
		$$('.spr-block', canvas).forEach(function (b) {
			if (color) { b.style.setProperty('--spr-accent', color.value); }
			if (radius) { b.style.setProperty('--spr-radius', radius.value + 'px'); }
		});
	}
	if (preview) {
		var form = preview.closest('form');
		form.addEventListener('input', function (e) {
			if (e.target === color || e.target.id === 'spr-radius') {
				syncSwatches();
				setVars(); // Instant; no round-trip needed for color and corners.
				return;
			}
			clearTimeout(previewTimer);
			previewTimer = setTimeout(loadPreview, 250);
		});
		form.addEventListener('change', function (e) {
			if (e.target.type === 'checkbox' || e.target.type === 'radio') {
				clearTimeout(previewTimer);
				previewTimer = setTimeout(loadPreview, 50);
			}
		});
		$$('input[name="spr_device"]').forEach(function (r) {
			r.addEventListener('change', function () {
				$('[data-spr-frame]').classList.toggle('is-mobile', r.value === 'mobile' && r.checked);
				fit();
			});
		});
		var q = $('[data-spr-preview-q]'), qTimer;
		q.addEventListener('input', function () { clearTimeout(qTimer); qTimer = setTimeout(loadPreview, 450); });
		q.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadPreview(); } });
		loadPreview();
	}

	/* ---------- matching: product inspector ---------- */
	var inspectBtn = $('[data-spr-inspect]');
	if (inspectBtn) {
		var out = $('[data-spr-inspect-out]');
		var list = function (title, rows) {
			var h = '<h3>' + esc(title) + '</h3>';
			if (!rows.length) { return h + '<p class="spr-empty">' + esc(T.none) + '</p>'; }
			h += '<ul class="spr-matches">';
			rows.forEach(function (r) {
				h += '<li class="spr-match">' + (r.img ? '<img src="' + esc(r.img) + '" alt="" loading="lazy">' : '<span class="spr-noimg"></span>') +
					'<div><a href="' + esc(r.url) + '" target="_blank" rel="noopener">' + esc(r.name) + '</a>' +
					'<small>' + esc(r.price) + (r.stock ? '' : ' · <span class="is-oos">' + esc(T.stockOut) + '</span>') + '</small></div>' +
					'<span class="spr-strength">' + esc(r.label) + '<i style="--w:' + r.pct + '%"></i></span></li>';
			});
			return h + '</ul>';
		};
		var run = function () {
			var q = $('[data-spr-q]').value.trim();
			if (!q) { return; }
			out.innerHTML = '<div class="spr-skeleton"><span></span></div>';
			post('spr_preview', { q: q }).then(function (r) {
				if (!r.success) { out.innerHTML = '<p class="spr-empty">' + esc(r.data) + '</p>'; return; }
				var p = r.data.product;
				out.innerHTML = '<div class="spr-inspect-head">' + (p.img ? '<img src="' + esc(p.img) + '" alt="">' : '') + '<div><strong>' + esc(p.name) + '</strong><span>#' + p.id + ' · ' + esc(p.price) + '</span></div></div>' +
					list(T.bought, r.data.bought) + list(T.similar, r.data.similar);
			});
		};
		inspectBtn.addEventListener('click', run);
		$('[data-spr-q]').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); run(); } });
	}

	/* ---------- shortcode builder ---------- */
	var builder = $('[data-spr-builder]');
	if (builder) {
		var code = $('[data-spr-code]', builder);
		var build = function () {
			var src = $('input[name="b_source"]:checked', builder).value;
			var parts = ['source="' + src + '"'];
			var title = $('#b-title').value.trim().replace(/"/g, '');
			var limit = $('#b-limit').value, cols = $('#b-cols').value;
			var layout = $('input[name="b_layout"]:checked', builder).value;
			var pidField = $('[data-spr-pid-field]', builder);
			pidField.hidden = !(src === 'similar' || src === 'bought');
			var pid = $('#b-pid').value;
			if (!pidField.hidden && pid) { parts.push('product_id="' + parseInt(pid, 10) + '"'); }
			if (title) { parts.push('title="' + title + '"'); }
			if (limit && limit !== '8') { parts.push('limit="' + parseInt(limit, 10) + '"'); }
			if (layout) { parts.push('layout="' + layout + '"'); }
			if (cols && cols !== '4') { parts.push('columns="' + parseInt(cols, 10) + '"'); }
			code.textContent = '[spr_recommendations ' + parts.join(' ') + ']';
		};
		builder.addEventListener('input', build);
		builder.addEventListener('change', build);
		$('[data-spr-copy]', builder).addEventListener('click', function () {
			var text = code.textContent;
			(navigator.clipboard ? navigator.clipboard.writeText(text) : Promise.reject()).catch(function () {
				var ta = document.createElement('textarea');
				ta.value = text; document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
			}).then(function () { toast(T.copied); });
		});
		build();
	}
})();
