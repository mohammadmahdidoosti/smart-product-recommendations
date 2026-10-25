/* Smart Product Recommendations — front-end loader (no dependencies). */
(function () {
	'use strict';

	var cfg = window.SPR || {};
	if (!cfg.rest) {
		return;
	}

	var VIEWED = 'spr_viewed';
	var ATTR = 'spr_attr';
	var queue = [];

	/* ---------- cookies ---------- */
	function getCookie(name) {
		var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
		return m ? decodeURIComponent(m[1]) : '';
	}
	function setCookie(name, value, days) {
		var d = new Date();
		d.setTime(d.getTime() + days * 864e5);
		document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + d.toUTCString() +
			'; path=' + (cfg.path || '/') + '; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}
	function viewed() {
		return getCookie(VIEWED).split(',').map(function (v) { return parseInt(v, 10); })
			.filter(function (v) { return v > 0; });
	}

	/* ---------- analytics ---------- */
	function track(ev) {
		queue.push(ev);
	}
	function flush(useBeacon) {
		if (!queue.length) {
			return;
		}
		var body = JSON.stringify({ events: queue.splice(0, 20) });
		var url = cfg.rest + 'track';
		if (useBeacon && navigator.sendBeacon) {
			navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
			return;
		}
		try {
			fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: body, keepalive: true, credentials: 'same-origin' });
		} catch (e) { /* ignore */ }
	}
	if (!cfg.preview) {
		setInterval(function () { flush(false); }, 5000);
	}
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			flush(true);
		}
	});
	window.addEventListener('pagehide', function () { flush(true); });

	function remember(pid, placement) {
		var pairs = getCookie(ATTR).split('|').filter(function (p) {
			return p && p.split(':')[0] !== String(pid);
		});
		pairs.unshift(pid + ':' + placement);
		setCookie(ATTR, pairs.slice(0, 20).join('|'), 30);
	}

	/* ---------- recently viewed + view counter ---------- */
	var seen = viewed();
	if (cfg.product && !cfg.preview) {
		var next = [cfg.product].concat(seen.filter(function (id) { return id !== cfg.product; })).slice(0, 20);
		setCookie(VIEWED, next.join(','), 60);
		track({ t: 'view', id: cfg.product });
	}

	/* ---------- carousel ---------- */
	function initCarousel(block) {
		var list = block.querySelector('.spr-list');
		var prev = block.querySelector('.spr-prev');
		var next = block.querySelector('.spr-next');
		if (!list || !prev || !next) {
			return;
		}
		var rtl = getComputedStyle(list).direction === 'rtl';
		function step(dir) {
			var amount = Math.max(list.clientWidth * 0.8, 200) * dir * (rtl ? -1 : 1);
			list.scrollBy({ left: amount, behavior: 'smooth' });
		}
		function update() {
			var max = list.scrollWidth - list.clientWidth;
			var pos = Math.abs(list.scrollLeft);
			prev.disabled = pos <= 2;
			next.disabled = pos >= max - 2;
			block.classList.toggle('spr-no-scroll', max <= 2);
		}
		prev.addEventListener('click', function () { step(-1); });
		next.addEventListener('click', function () { step(1); });
		list.addEventListener('scroll', update, { passive: true });
		window.addEventListener('resize', update);
		update();
	}

	/* ---------- bundle: bought together ---------- */
	function money(n) {
		var m = cfg.money || {};
		var d = parseInt(m.decimals || 0, 10);
		var parts = Math.abs(n).toFixed(d).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, m.thou == null ? ',' : m.thou);
		var num = parts.join(m.dec || '.');
		var sym = m.symbol || '';
		switch (m.pos) {
			case 'left': return sym + num;
			case 'left_space': return sym + '\u00a0' + num;
			case 'right': return num + sym;
			default: return num + '\u00a0' + sym;
		}
	}
	function t(key) {
		return (cfg.i18n && cfg.i18n[key]) || '';
	}

	function initBundle(block) {
		var items = Array.prototype.slice.call(block.querySelectorAll('.spr-bundle-item'));
		var totalEl = block.querySelector('.spr-sum-total');
		var btn = block.querySelector('.spr-add-all');
		var msg = block.querySelector('.spr-bundle-msg');
		if (!items.length || !btn) {
			return;
		}
		function selected() {
			return items.filter(function (li) { return li.querySelector('input').checked; });
		}
		function update() {
			var sel = selected();
			var sum = sel.reduce(function (a, li) { return a + parseFloat(li.getAttribute('data-price') || '0'); }, 0);
			items.forEach(function (li) { li.classList.toggle('is-off', !li.querySelector('input').checked); });
			totalEl.textContent = money(sum);
			btn.disabled = !sel.length;
			btn.textContent = sel.length === 1 ? btn.getAttribute('data-label-one') : btn.getAttribute('data-label').replace('%d', sel.length.toLocaleString(document.documentElement.lang || undefined));
		}
		items.forEach(function (li) { li.querySelector('input').addEventListener('change', update); });

		btn.addEventListener('click', function () {
			var sel = selected();
			if (!sel.length) {
				msg.textContent = t('none');
				return;
			}
			if (cfg.preview) {
				return;
			}
			var placement = block.getAttribute('data-spr-placement');
			var label = btn.textContent;
			btn.disabled = true;
			btn.classList.add('is-busy');
			btn.textContent = t('adding');
			msg.textContent = '';

			var ok = 0, failed = 0, last = null;
			// Sequential: WooCommerce's cart session is not safe for parallel writes.
			sel.reduce(function (p, li) {
				return p.then(function () {
					var pid = li.getAttribute('data-pid');
					var body = new URLSearchParams({ product_id: pid, quantity: 1 });
					return fetch(cfg.wcAjax, { method: 'POST', credentials: 'same-origin', body: body })
						.then(function (r) { return r.json(); })
						.then(function (res) {
							if (res && !res.error) {
								ok++;
								last = res;
								if (!li.classList.contains('is-main')) {
									remember(pid, placement);
								}
							} else {
								failed++;
							}
						})
						.catch(function () { failed++; });
				});
			}, Promise.resolve()).then(function () {
				btn.classList.remove('is-busy');
				btn.textContent = label;
				btn.disabled = false;
				if (ok) {
					track({ t: 'click', p: placement });
					flush(true);
					msg.innerHTML = '';
					msg.appendChild(document.createTextNode(t('added').replace('%d', ok.toLocaleString(document.documentElement.lang || undefined)) + ' '));
					var a = document.createElement('a');
					a.href = cfg.cartUrl;
					a.textContent = t('viewCart');
					msg.appendChild(a);
					block.classList.add('is-added');
					// Refresh mini-cart / cart fragments in themes that listen to WooCommerce events.
					if (window.jQuery && last) {
						window.jQuery(document.body).trigger('added_to_cart', [last.fragments, last.cart_hash, window.jQuery(btn)]);
					}
				}
				if (failed) {
					msg.appendChild(document.createTextNode((ok ? ' ' : '') + t('failed')));
				}
			});
		});
		update();
	}

	function initBlock(block) {
		if (block.classList.contains('spr-layout-carousel')) {
			initCarousel(block);
		}
		if (block.classList.contains('spr-bundle')) {
			initBundle(block);
		}
	}
	window.SPRInit = function (root) {
		Array.prototype.forEach.call((root || document).querySelectorAll('.spr-block'), initBlock);
	};
	if (cfg.preview) {
		return; // Admin live preview: rendering only, no tracking or loading.
	}

	/* ---------- impressions & clicks ---------- */
	var io = 'IntersectionObserver' in window ? new IntersectionObserver(function (entries) {
		entries.forEach(function (e) {
			if (e.isIntersecting) {
				track({ t: 'imp', p: e.target.getAttribute('data-spr-placement') });
				io.unobserve(e.target);
			}
		});
	}, { threshold: 0.3 }) : null;

	document.addEventListener('click', function (e) {
		var el = e.target.closest ? e.target.closest('.spr-block [data-spr-pid]') : null;
		if (!el) {
			return;
		}
		var block = el.closest('.spr-block');
		var placement = block.getAttribute('data-spr-placement');
		remember(el.getAttribute('data-spr-pid'), placement);
		track({ t: 'click', p: placement });
		flush(true);
	}, true);

	/* ---------- load blocks ---------- */
	function load(slots) {
		var blocks = {};
		slots.forEach(function (s, i) {
			var d = s.dataset;
			blocks['b' + i] = {
				source: d.sprSource,
				placement: d.sprPlacement,
				limit: parseInt(d.sprLimit || '8', 10),
				title: d.sprTitle || '',
				product: parseInt(d.sprProduct || '0', 10),
				items: d.sprItems || '',
				layout: d.sprLayout || '',
				columns: d.sprColumns ? parseInt(d.sprColumns, 10) : undefined
			};
		});

		var headers = { 'Content-Type': 'application/json' };
		if (cfg.nonce) {
			headers['X-WP-Nonce'] = cfg.nonce;
		}

		fetch(cfg.rest + 'render', {
			method: 'POST',
			headers: headers,
			credentials: 'same-origin',
			body: JSON.stringify({ blocks: blocks, viewed: seen })
		}).then(function (r) {
			if (!r.ok) {
				throw new Error('HTTP ' + r.status);
			}
			return r.json();
		}).then(function (res) {
			slots.forEach(function (s, i) {
				var html = res && res.blocks ? res.blocks['b' + i] : '';
				if (!html) {
					s.parentNode && s.parentNode.removeChild(s);
					return;
				}
				s.innerHTML = html;
				s.classList.remove('spr-loading');
				s.removeAttribute('aria-busy');
				var block = s.querySelector('.spr-block');
				if (block) {
					initBlock(block);
					if (io) {
						io.observe(block);
					}
				}
			});
			document.dispatchEvent(new CustomEvent('spr:loaded'));
		}).catch(function () {
			slots.forEach(function (s) { s.parentNode && s.parentNode.removeChild(s); });
		});
	}

	function start() {
		var slots = Array.prototype.slice.call(document.querySelectorAll('.spr-slot.spr-loading'));
		if (!slots.length) {
			return;
		}
		// Defer the request until the first block is close to the viewport.
		if ('IntersectionObserver' in window) {
			var started = false;
			var obs = new IntersectionObserver(function (entries) {
				if (started || !entries.some(function (e) { return e.isIntersecting; })) {
					return;
				}
				started = true;
				obs.disconnect();
				load(slots);
			}, { rootMargin: '800px 0px' });
			slots.forEach(function (s) { obs.observe(s); });
		} else {
			load(slots);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
