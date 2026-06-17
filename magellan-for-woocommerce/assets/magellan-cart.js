/*!
 * Magellan cart-state listener v2.3
 * Fires on cart changes (add / update qty / remove) across classic and
 * Blocks WooCommerce, and POSTs an anonymous cart snapshot to Magellan
 * (keyed by cart_token, no email). Identity is stitched on later when
 * the shopper enters an email at checkout. Enables abandoned-cart
 * tracking without waiting for checkout.
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

	var DEBOUNCE_MS = 900;
	var HASH_KEY    = '_mgln_cart_hash_sent'; // localStorage: last cart hash we POSTed
	var TOKEN_KEY   = '_mgln_cart_token';

	// --- cart_token: reuse the exact scheme magellan-checkout.js uses, so
	// the anonymous cart and the eventual checkout-email share one token.
	function getCartToken() {
		var existing = localStorage.getItem(TOKEN_KEY);
		if (existing) return existing;
		var token = 'cart_' + Date.now().toString(36) + '_' + Math.random().toString(36).substring(2, 10);
		try { localStorage.setItem(TOKEN_KEY, token); } catch (e) {}
		return token;
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

	function buildPayload() {
		var pixel = window.Magellan && window.Magellan.getCookie ? window.Magellan.getCookie() : {};
		return {
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
	}

	var inFlight = false;
	function sendSnapshot() {
		var hash = currentCartHash();
		// Skip if cart unchanged since our last successful send. This is the
		// primary noise filter — without it, wc_fragments_refreshed on every
		// page view would trip the server's 10/min rate limit.
		if (localStorage.getItem(HASH_KEY) === hash) return;
		if (inFlight) return;
		inFlight = true;

		try {
			fetch(REST_URL, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(buildPayload()),
				credentials: 'same-origin',
				keepalive: true
			}).then(function (res) {
				inFlight = false;
				// Only remember the hash on a successful (2xx) send so a
				// failure retries on the next event.
				if (res && res.ok) {
					try { localStorage.setItem(HASH_KEY, hash); } catch (e) {}
				}
			}).catch(function () { inFlight = false; });
		} catch (e) { inFlight = false; }
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
})();
