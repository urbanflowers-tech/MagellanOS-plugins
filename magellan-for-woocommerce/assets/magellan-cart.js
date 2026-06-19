/*!
 * Magellan cart-state listener v2.4.3
 * Fires on cart changes (add / update qty / remove) across classic and
 * Blocks WooCommerce, via the underlying cart-mutation network request
 * (so it works even when cart fragments are disabled), plus once on page
 * load and on tab re-show, and POSTs an anonymous cart
 * snapshot to Magellan (keyed by cart_token, no email). Identity is
 * stitched on later when the shopper enters an email at checkout. Enables
 * abandoned-cart tracking without waiting for checkout.
 *
 * The cart contents are read from WooCommerce's own Store API
 * (/wp-json/wc/store/v1/cart) — REST-safe and present even when cart
 * fragments are disabled — rather than relying on the woocommerce_cart_hash
 * cookie (set only by the fragments script) or a server-side wc_load_cart()
 * read (which fatals in the custom REST context on some stores).
 *
 * No WC JS dependency is declared (no jQuery / block-data dep) — the
 * listener feature-detects at runtime so it degrades gracefully on any
 * theme or page.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || typeof document === 'undefined') return;

	var meta = document.querySelector('meta[name="magellan-rest-cart"]');
	if (!meta) return;
	var REST_URL = meta.getAttribute('content');
	if (!REST_URL) return;

	// WooCommerce Store API cart, on the same wp-json root as our route
	// (preserves non-default REST prefixes). Authoritative, REST-safe source.
	var STORE_CART_URL = REST_URL.replace(/magellan\/v1\/cart$/, 'wc/store/v1/cart');

	var DEBOUNCE_MS = 900;
	var HASH_KEY    = '_mgln_cart_hash_sent'; // localStorage: last cart hash we POSTed
	var TOKEN_KEY   = '_mgln_cart_token';

	// Guarded localStorage access — a throwing getter/setter (storage
	// disabled / quota) must degrade to a cache-miss, never abort the
	// listener. The unguarded read used to sit in the debounced callback,
	// so a throw there killed all future capture.
	function lsGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
	function lsSet(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }

	// --- cart_token: reuse the exact scheme magellan-checkout.js uses, so
	// the anonymous cart and the eventual checkout-email share one token.
	// Also mirror it into a cookie so the server can read it at order
	// creation (Magellan_Tracker stamps it onto the order → the verified
	// order_placed event carries it → backend marks the cart 'converted').
	function getCartToken() {
		var existing = lsGet(TOKEN_KEY);
		var token = existing;
		if (!token) {
			token = 'cart_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10);
			lsSet(TOKEN_KEY, token);
		}
		writeCartTokenCookie(token);
		return token;
	}

	function writeCartTokenCookie(token) {
		try {
			// 30-day, lax, path=/ so the checkout POST carries it server-side.
			var d = new Date(Date.now() + 30 * 24 * 60 * 60 * 1000).toUTCString();
			document.cookie = TOKEN_KEY + '=' + token + '; expires=' + d + '; path=/; SameSite=Lax';
		} catch (e) {}
	}

	function readCookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
		return m ? m[1] : null;
	}

	// WooCommerce maintains a `woocommerce_cart_hash` cookie that changes
	// whenever cart contents change. We use it to suppress no-op events
	// (e.g. wc_fragments_refreshed fires on every page load): only POST
	// when the hash actually differs from the last one we sent. An empty
	// cart clears the cookie — represent that as the string 'empty' so a
	// cart-emptied transition still sends once.
	function currentCartHash() {
		return readCookie('woocommerce_cart_hash') || 'empty';
	}

	// Read the current cart from WooCommerce's own Store API. REST-safe,
	// authoritative, and present even when cart fragments are disabled.
	// Resolves to a normalized {items, subtotal, currency, count} or null on
	// any failure (caller then falls back to the cookie-hash signature, and
	// the server falls back to its own snapshot).
	function readStoreCart() {
		return fetch(STORE_CART_URL, {
			method: 'GET',
			headers: { 'Accept': 'application/json' },
			credentials: 'same-origin'
		}).then(function (r) {
			return (r && r.ok) ? r.json() : null;
		}).then(function (c) {
			if (!c || typeof c !== 'object') return null;
			var totals = c.totals || {};
			var minor  = (totals.currency_minor_unit != null) ? totals.currency_minor_unit : 2;
			var div    = Math.pow(10, minor) || 1;
			// total_items = line-item subtotal (pre shipping/fees), matching
			// the old server-side WC()->cart->get_subtotal().
			var raw = (totals.total_items != null) ? totals.total_items
				: (totals.total_price != null ? totals.total_price : '0');
			var items = (c.items || []).map(function (it) {
				return {
					sku: it && it.sku ? String(it.sku) : '',
					product_id: it && it.id ? (it.id | 0) : 0,
					quantity: it && it.quantity ? (it.quantity | 0) : 0
				};
			});
			return {
				items: items,
				subtotal: (parseInt(raw, 10) || 0) / div,
				currency: totals.currency_code || '',
				count: c.items_count || 0
			};
		}).catch(function () { return null; });
	}

	// Stable signature of the cart contents — suppresses no-op sends without
	// depending on the woocommerce_cart_hash cookie (absent when fragments are
	// off). 'empty' for a cart with no items.
	function cartSignature(store) {
		if (store) {
			if (!store.count) return 'empty';
			return store.count + ':' + Math.round(store.subtotal * 100) + ':' +
				store.items.map(function (i) { return i.product_id + 'x' + i.quantity; }).join(',');
		}
		return currentCartHash(); // fallback to the WC cookie
	}

	function buildPayload(store) {
		var pixel = window.Magellan && window.Magellan.getCookie ? window.Magellan.getCookie() : {};
		var payload = {
			cart_token: getCartToken(),
			occurred_at: new Date().toISOString(),
			// No identity — anonymous. Backend stitches identity later via
			// the same cart_token at checkout.
			identity: { email_hash: null, phone_hash: null },
			attribution: {
				first_touch: pixel.fs ? {
					source: pixel.fs, medium: pixel.fm, campaign: pixel.fc,
					occurred_at: pixel.ft ? new Date(pixel.ft * 1000).toISOString() : null
				} : null,
				last_paid_touch: pixel.lsrc ? {
					source: pixel.lsrc, medium: pixel.lmed, campaign: pixel.lcamp,
					occurred_at: pixel.lts ? new Date(pixel.lts * 1000).toISOString() : null
				} : null,
				click_ids: pixel.cids || {},
				session_count: pixel.sc || 1
			},
			consent_state: 'granted'
		};
		// Browser-sourced cart snapshot (Store API). The server sanitizes it
		// and prefers it over its own wc_load_cart() read.
		if (store) {
			payload.cart = { items: store.items, subtotal: store.subtotal, currency: store.currency };
		}
		return payload;
	}

	var inFlight = false;
	var pending  = false; // a cart change arrived while a POST was in flight
	function sendSnapshot() {
		readStoreCart().then(function (store) {
			var sig  = cartSignature(store);
			var prev = lsGet(HASH_KEY);

			// Never record a still-empty cart we've never sent anything for —
			// otherwise an ordinary page view with no cart would INSERT a
			// phantom 0-item 'active' cart that the janitor flips to abandoned.
			if (sig === 'empty' && (prev === null || prev === 'empty')) return;

			// Skip if cart unchanged since our last successful send. Primary
			// noise filter — without it, a send on every page view would trip
			// the server's 10/min rate limit.
			if (prev === sig) return;

			if (inFlight) { pending = true; return; }
			inFlight = true;

			try {
				fetch(REST_URL, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify(buildPayload(store)),
					credentials: 'same-origin',
					keepalive: true
				}).then(function (res) {
					inFlight = false;
					// Only remember the signature on a successful (2xx) send so
					// a failure retries on the next event.
					if (res && res.ok) lsSet(HASH_KEY, sig);
					flushPending();
				}).catch(function () { inFlight = false; flushPending(); });
			} catch (e) { inFlight = false; flushPending(); }
		});
	}

	// If a distinct cart change landed mid-flight, send the latest state now.
	// Re-reading the cart makes this a no-op if nothing actually changed.
	function flushPending() {
		if (pending) { pending = false; sendSnapshot(); }
	}

	var debounceTimer = null;
	function onCartChanged() {
		if (debounceTimer) clearTimeout(debounceTimer);
		// Debounce: cart events fire in bursts (add-to-cart triggers several)
		// and the fragments/cookie settle slightly after the event. Reading
		// the hash AFTER the debounce avoids racing the server.
		debounceTimer = setTimeout(sendSnapshot, DEBOUNCE_MS);
	}

	// --- MODE A: classic WooCommerce (jQuery events on document.body) ---
	// These require jQuery, which WC loads on classic cart/shop/product
	// pages. Feature-detect so we never hard-depend on it.
	if (window.jQuery) {
		try {
			window.jQuery(document.body).on(
				'added_to_cart removed_from_cart updated_cart_totals updated_wc_div wc_fragments_refreshed wc_cart_emptied',
				onCartChanged
			);
		} catch (e) { /* ignore */ }
	}

	// --- MODE B: WooCommerce Blocks (native CustomEvents on body) ---
	// Dispatched with the wc-blocks_ prefix; WC also forwards the legacy
	// jQuery events into these, so this covers both worlds without jQuery.
	if (document.body && document.body.addEventListener) {
		['wc-blocks_added_to_cart', 'wc-blocks_removed_from_cart'].forEach(function (evt) {
			document.body.addEventListener(evt, onCartChanged);
		});
	}

	// --- MODE C: network interception (theme-agnostic) ---
	// MODE A/B only fire when WooCommerce dispatches its JS events, which needs
	// the cart-fragments script (classic) or the Cart/Checkout blocks. MANY
	// stores disable fragments — then add-to-cart fires NOTHING, and the cart
	// is only ever captured on the next page load (the listener below). To
	// capture the moment the cart actually changes, watch for the underlying
	// cart-mutation request (classic ?wc-ajax / ?add-to-cart, or the Store API)
	// and snapshot once it completes. isCartMutation() excludes our own POST
	// and the Store-API GET our snapshot makes, so this never recurses.
	function isCartMutation(url, method) {
		if (!url) return false;
		var u = String(url);
		if (u.indexOf('magellan/v1/cart') !== -1) return false; // our own endpoint
		var m = (method ? String(method) : 'GET').toUpperCase();
		if (m !== 'GET' && /\/wc\/store\/v\d+\/cart\/(add-item|update-item|remove-item|items)/.test(u)) return true;
		if (/[?&]wc-ajax=(add_to_cart|update_cart|remove_from_cart)/.test(u)) return true;
		if (/[?&]add-to-cart=\d/.test(u)) return true;
		return false;
	}

	// fetch — wrapped transparently; we only attach a passive post-completion
	// hook and always return the original promise. Guarded so we can never
	// break the page's own fetch calls.
	if (typeof window.fetch === 'function') {
		var _mglnFetch = window.fetch;
		window.fetch = function (input, init) {
			var p = _mglnFetch.apply(this, arguments);
			try {
				var url = typeof input === 'string' ? input : (input && input.url) || '';
				var method = (init && init.method) || (input && input.method) || 'GET';
				if (isCartMutation(url, method)) { p.then(function () { onCartChanged(); }, function () {}); }
			} catch (e) { /* ignore */ }
			return p;
		};
	}

	// XMLHttpRequest — same coverage for jQuery/$.ajax-based add-to-cart.
	try {
		var _mglnOpen = XMLHttpRequest.prototype.open;
		var _mglnSend = XMLHttpRequest.prototype.send;
		XMLHttpRequest.prototype.open = function (method, url) {
			try { this._mglnCartMut = isCartMutation(url, method); } catch (e) {}
			return _mglnOpen.apply(this, arguments);
		};
		XMLHttpRequest.prototype.send = function () {
			try {
				if (this._mglnCartMut) {
					this.addEventListener('loadend', function () { onCartChanged(); });
				}
			} catch (e) {}
			return _mglnSend.apply(this, arguments);
		};
	} catch (e) { /* ignore */ }

	// --- MODE D: re-check when the tab becomes visible / is restored ---
	// Catches a cart changed in another tab and back/forward-cache restores.
	if (document.addEventListener) {
		document.addEventListener('visibilitychange', function () {
			if (!document.hidden) { onCartChanged(); }
		});
	}
	if (window.addEventListener) {
		window.addEventListener('pageshow', function () { onCartChanged(); });
	}

	// --- Initial capture on load ---
	// The listeners above only fire on in-page AJAX cart mutations. A cart
	// populated on a previous page (the common case: add-to-cart that
	// navigates, or a returning session) would otherwise never be captured
	// until the next in-page change. Fire once on load to snapshot the current
	// cart. Deduped by signature, so it's a no-op when nothing changed.
	if (document.readyState === 'complete' || document.readyState === 'interactive') {
		onCartChanged();
	} else {
		document.addEventListener('DOMContentLoaded', onCartChanged);
	}
})();
