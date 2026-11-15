/*!
 * Pshyk Boutique — progressive enhancement, no dependencies.
 * The store plugin's <select data-pr-custom-quantity> stays the single source of truth:
 * chips only set its value and fire the same events, so its price/bottle scripts and the server rules keep working.
 * Without JS customers use the original select.
 */
(function () {
	'use strict';

	var cfg = window.pshykBoutique || { currency: '₴', i18n: {} };
	var t = cfg.i18n || {};
	var reduceMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	function ready(fn) {
		if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fn); } else { fn(); }
	}

	function num(v) {
		var n = parseFloat(String(v == null ? '' : v).replace(',', '.'));
		return isFinite(n) ? n : 0;
	}

	function money(v) {
		var rounded = Math.round(v * 100) / 100;
		var whole = Math.abs(rounded % 1) < 0.001;
		var s = rounded.toLocaleString('uk-UA', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
		return s + ' ' + (cfg.currency || '₴');
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null) { n.textContent = text; }
		return n;
	}

	function fire(node, type) {
		node.dispatchEvent(new Event(type, { bubbles: true }));
	}

	/* ------------------------------------------------------------ buy box */

	function standardTiers(values) {
		return values[0] >= 3 ? [3, 5, 10, 15, 20] : [1, 2, 3, 4, 5, 10, 15, 20];
	}

	function enhanceBuyBox(select) {
		if (select.dataset.pbEnhanced) { return; }
		var wrap = select.closest('.quantity-select');
		var form = select.closest('form.cart');
		if (!wrap || !form) { return; }
		select.dataset.pbEnhanced = '1';

		var perMl = num(wrap.getAttribute('data-price') || wrap.getAttribute('data-base-price'));
		var regularPerMl = num(wrap.getAttribute('data-regular-price')) || perMl;
		var values = Array.prototype.map.call(select.options, function (o) { return parseInt(o.value, 10); }).filter(function (v) { return v > 0; });
		if (!values.length || !(perMl > 0)) { return; }
		var max = Math.max.apply(null, values);
		var missing = standardTiers(values).filter(function (v) { return v > max; });

		var uid = 'pb-vol-' + Math.random().toString(36).slice(2, 8);
		var fieldset = el('fieldset', 'pb-vol');
		var legend = el('legend', 'pb-vol__legend');
		legend.appendChild(el('span', 'pb-vol__legend-text', 'Оберіть обʼєм'));
		legend.appendChild(el('span', 'pb-vol__per', money(perMl) + ' ' + (t.perMl || 'за 1 мл')));
		fieldset.appendChild(legend);
		var list = el('div', 'pb-vol__list');
		fieldset.appendChild(list);

		values.forEach(function (ml) {
			var id = uid + '-' + ml;
			var input = el('input', 'pb-vol__input');
			input.type = 'radio';
			input.name = uid;
			input.id = id;
			input.value = String(ml);
			input.checked = String(ml) === String(select.value);
			var label = el('label', 'pb-vol__chip');
			label.htmlFor = id;
			label.appendChild(el('span', 'pb-vol__ml', ml + ' ' + (t.ml || 'мл')));
			label.appendChild(el('span', 'pb-vol__price', money(perMl * ml)));
			list.appendChild(input);
			list.appendChild(label);
			input.addEventListener('change', function () {
				if (!input.checked) { return; }
				select.value = input.value;
				fire(select, 'input');
				fire(select, 'change');
			});
		});

		missing.forEach(function (ml) {
			var id = uid + '-' + ml;
			var input = el('input', 'pb-vol__input');
			input.type = 'radio';
			input.name = uid;
			input.id = id;
			input.disabled = true;
			var label = el('label', 'pb-vol__chip pb-vol__chip--off');
			label.htmlFor = id;
			label.appendChild(el('span', 'pb-vol__ml', ml + ' ' + (t.ml || 'мл')));
			label.appendChild(el('span', 'pb-vol__price', 'немає'));
			list.appendChild(input);
			list.appendChild(label);
		});
		if (missing.length) {
			fieldset.appendChild(el('p', 'pb-vol__hint', 'Більші обʼєми зараз недоступні: аромату залишилось небагато.'));
		}

		wrap.parentNode.insertBefore(fieldset, wrap);
		wrap.classList.add('pb-sr-only');
		select.setAttribute('tabindex', '-1');
		select.setAttribute('aria-hidden', 'true');
		form.classList.add('pb-enhanced');

		/* WPC «Додати красивий флакон»: show it between the volume and the total (same as its «before button» position). */
		var bottles = document.querySelector('.woobt-wrap');
		var total = form.querySelector('[data-pb-total]');
		var button = form.querySelector('.single_add_to_cart_button');
		/* Order inside the form: volume → optional bottle → total → button. */
		if (total && button && button.parentNode) {
			button.parentNode.insertBefore(total, button);
		}
		if (bottles && total && bottles.nextElementSibling !== total) {
			form.insertBefore(bottles, total);
			bottles.classList.add('pb-bottles-inline');
		}

		function bottleLines() {
			var lines = [];
			document.querySelectorAll('.woobt-product-together').forEach(function (item) {
				var cb = item.querySelector('.woobt-checkbox');
				if (!cb || !cb.checked || item.offsetParent === null) { return; }
				var qty = num(item.getAttribute('data-qty')) || 1;
				lines.push({ name: item.getAttribute('data-name') || 'Флакон', price: num(item.getAttribute('data-price')) * qty, qty: qty });
			});
			return lines;
		}

		/* The store's tara script hides every bottle for small volumes; then hide our frame too. */
		function syncBottleFrame() {
			if (!bottles || !form.contains(bottles)) { return; }
			bottles.classList.remove('pb-bottles-empty');
			var any = Array.prototype.some.call(bottles.querySelectorAll('.woobt-product-together'), function (i) { return i.getClientRects().length > 0; });
			bottles.classList.toggle('pb-bottles-empty', !any);
		}

		function render() {
			syncBottleFrame();
			var ml = parseInt(select.value, 10) || values[0];
			list.querySelectorAll('.pb-vol__input').forEach(function (i) { if (!i.disabled) { i.checked = i.value === String(ml); } });
			if (!total) { return; }
			var frag = perMl * ml;
			var regular = regularPerMl * ml;
			var lines = bottleLines();
			var sum = frag + lines.reduce(function (a, l) { return a + l.price; }, 0);
			total.hidden = false;
			total.innerHTML = '';
			var dl = el('dl', 'pb-total__list');
			function row(label, value, cls, old) {
				var r = el('div', 'pb-total__row' + (cls ? ' ' + cls : ''));
				r.appendChild(el('dt', null, label));
				var dd = el('dd');
				if (old) { var del = el('del', 'pb-total__old', money(old)); del.setAttribute('aria-label', 'Без знижки ' + money(old)); dd.appendChild(del); dd.appendChild(document.createTextNode(' ')); }
				dd.appendChild(document.createTextNode(money(value)));
				r.appendChild(dd);
				dl.appendChild(r);
			}
			row((t.fragrance || 'Аромат') + ', ' + ml + ' ' + (t.ml || 'мл'), frag, '', regular > frag + 0.001 ? regular : 0);
			lines.forEach(function (l) { row(l.name + (l.qty > 1 ? ' × ' + l.qty : ''), l.price, 'pb-total__row--extra'); });
			row(t.total || 'Разом', sum, 'pb-total__row--sum');
			total.appendChild(dl);
			total.classList.remove('pb-pulse');
			if (!reduceMotion) { void total.offsetWidth; total.classList.add('pb-pulse'); }
			if (button) { button.setAttribute('aria-describedby', total.id || (total.id = uid + '-total')); }
			updateSticky(ml, sum);
		}

		select.addEventListener('change', render);
		document.addEventListener('change', function (e) {
			if (e.target && e.target.classList && e.target.classList.contains('woobt-checkbox')) { setTimeout(render, 30); }
		});
		/* The store's tara script toggles bottles after the select changes; re-read once it is done. */
		select.addEventListener('change', function () { setTimeout(render, 80); });
		render();
		setupSticky(form, button, fieldset);
		guardRepeatClicks(form, button);
	}

	/* ------------------------------------------------- repeated-click guard */

	function guardRepeatClicks(form, button) {
		if (!button) { return; }
		var busyUntil = 0;
		var label = button.textContent;
		form.addEventListener('submit', function (e) {
			var now = Date.now();
			if (now < busyUntil) {
				e.preventDefault();
				e.stopImmediatePropagation();
				return;
			}
			busyUntil = now + 4000;
			button.setAttribute('aria-busy', 'true');
			button.classList.add('pb-is-busy');
		}, true);
		function done() {
			busyUntil = Date.now() + 600;
			button.removeAttribute('aria-busy');
			button.classList.remove('pb-is-busy');
			button.classList.add('pb-is-added');
			button.textContent = 'Додано в кошик ✓';
			setTimeout(function () { button.classList.remove('pb-is-added'); button.textContent = label; }, 2200);
		}
		if (window.jQuery) {
			window.jQuery(document.body).on('added_to_cart', done);
			window.jQuery(document.body).on('ajax_request_not_sent.adding_to_cart wc_cart_button_updated', function () { busyUntil = 0; button.classList.remove('pb-is-busy'); button.removeAttribute('aria-busy'); });
		}
	}

	/* --------------------------------------------------------- sticky bar */

	var sticky = null;
	var stickySum = null;

	var lastSticky = null;

	function updateSticky(ml, sum) {
		if (ml != null) { lastSticky = [ml, sum]; }
		if (stickySum && lastSticky) { stickySum.textContent = lastSticky[0] + ' ' + (t.ml || 'мл') + ' · ' + money(lastSticky[1]); }
	}

	function setupSticky(form, button, chooser) {
		sticky = document.querySelector('[data-pb-sticky]');
		if (!sticky || !button || !('IntersectionObserver' in window)) { return; }
		stickySum = sticky.querySelector('[data-pb-sticky-sum]');
		updateSticky();
		var stickyBtn = sticky.querySelector('[data-pb-sticky-btn]');
		var mq = window.matchMedia('(max-width: 768px)');
		var toolbar = document.querySelector('.wd-toolbar, .storefront-handheld-footer-bar');
		var pos = 'visible'; /* where the main button is: visible | above (scrolled past) | below (not reached yet) */
		var footerVisible = false;
		var typing = false;
		var shown = false;
		var hideTimer = 0;

		function toolbarHeight() {
			if (!toolbar) { return 0; }
			var cs = window.getComputedStyle(toolbar);
			return cs.display === 'none' || cs.visibility === 'hidden' ? 0 : toolbar.getBoundingClientRect().height;
		}

		function sync() {
			var show = mq.matches && pos !== 'visible' && !footerVisible && !typing;
			document.documentElement.style.setProperty('--pb-toolbar-h', toolbarHeight() + 'px');
			stickyBtn.textContent = pos === 'above' ? (t.addToCart || 'Додати в кошик') : 'Обрати об\u02bcєм';
			if (show === shown) { return; }
			shown = show;
			clearTimeout(hideTimer);
			if (show) {
				sticky.hidden = false;
				void sticky.offsetWidth; /* commit the off-screen position so the slide-in transition runs */
				sticky.classList.add('is-on');
				document.body.classList.add('pb-sticky-on');
			} else {
				sticky.classList.remove('is-on');
				document.body.classList.remove('pb-sticky-on');
				hideTimer = setTimeout(function () { if (!shown) { sticky.hidden = true; } }, reduceMotion ? 0 : 300);
			}
		}

		/* Scroll + rAF instead of IntersectionObserver: a fast fling can move the button from below the
		   viewport to above it in one frame, which an observer never reports. */
		var frame = 0;
		function measure() {
			frame = 0;
			var r = button.getBoundingClientRect();
			var next = r.bottom < 0 ? 'above' : (r.top > window.innerHeight ? 'below' : 'visible');
			if (next !== pos) { pos = next; sync(); }
		}
		function schedule() { if (!frame) { frame = requestAnimationFrame(measure); } }
		window.addEventListener('scroll', schedule, { passive: true });
		window.addEventListener('resize', schedule, { passive: true });
		pos = '';
		measure();

		var footer = document.querySelector('.footer-container, .wd-footer, .site-footer, body > footer');
		if (footer) {
			new IntersectionObserver(function (entries) { footerVisible = entries[0].isIntersecting; sync(); }).observe(footer);
		}
		document.addEventListener('focusin', function (e) {
			typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) && e.target.type !== 'radio' && e.target.type !== 'checkbox' && !sticky.contains(e.target);
			sync();
		});
		document.addEventListener('focusout', function () { typing = false; setTimeout(sync, 50); });
		if (mq.addEventListener) { mq.addEventListener('change', sync); }

		stickyBtn.addEventListener('click', function () {
			if (pos === 'above') {
				button.click();
				return;
			}
			var target = chooser || form;
			target.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
			var checked = target.querySelector('input:checked') || target.querySelector('input, select, button');
			if (checked) { setTimeout(function () { checked.focus({ preventScroll: true }); }, reduceMotion ? 0 : 350); }
		});
	}

	/* ------------------------------------------------------ section reveal */

	function reveal() {
		var nodes = document.querySelectorAll('[data-pb-reveal]');
		if (!nodes.length || reduceMotion || !('IntersectionObserver' in window)) { return; }
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting) { e.target.classList.add('is-in'); io.unobserve(e.target); }
			});
		}, { rootMargin: '0px 0px -10% 0px' });
		nodes.forEach(function (n) {
			/* Only sections that start below the fold get the hidden state — nothing visible ever blinks. */
			if (n.getBoundingClientRect().top > window.innerHeight) {
				n.classList.add('pb-reveal');
				io.observe(n);
			}
		});
	}

	/* --------------------------------------- untranslated back-in-stock button */

	function translateNotify() {
		document.querySelectorAll('.wt-notify-btn').forEach(function (b) {
			if (/notify me/i.test(b.textContent)) {
				b.childNodes.forEach(function (n) { if (n.nodeType === 3 && /notify/i.test(n.nodeValue)) { n.nodeValue = t.notify || 'Повідомити про надходження'; } });
			}
			if (/notify me/i.test(b.getAttribute('aria-label') || '')) { b.setAttribute('aria-label', t.notifyLong || 'Повідомити, коли аромат знову буде в наявності'); }
		});
	}

	ready(function () {
		document.querySelectorAll('select[data-pr-custom-quantity]').forEach(enhanceBuyBox);
		reveal();
		translateNotify();
	});
})();
