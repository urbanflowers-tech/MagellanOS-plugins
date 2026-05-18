/*!
 * Magellan first-party pixel v2.2
 * Captures UTM parameters, click IDs, and referral source.
 * Stores in first-party cookie (_mgln). 6-month TTL.
 * Designed for no measurable Core Web Vitals regression.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || typeof document === 'undefined') return;

	var COOKIE = '_mgln';
	var TTL_MS = 180 * 24 * 60 * 60 * 1000; // 180 days

	var CLICK_IDS = ['fbclid','gclid','gbraid','wbraid','ttclid','msclkid','twclid'];
	var UTM_KEYS  = ['utm_source','utm_medium','utm_campaign','utm_content','utm_term'];

	function readCookie(name) {
		var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + name + '=([^;]+)'));
		return m ? m[1] : null;
	}

	function writeCookie(name, value) {
		var d = new Date(Date.now() + TTL_MS).toUTCString();
		var domain = '';
		// Use registrable domain to share cookie across www and apex
		var host = location.hostname;
		var parts = host.split('.');
		if (parts.length >= 2 && host !== 'localhost') {
			domain = '; domain=.' + parts.slice(-2).join('.');
		}
		document.cookie = name + '=' + value + '; expires=' + d + '; path=/; SameSite=Lax' + domain;
	}

	function decodeCookie(raw) {
		try {
			return JSON.parse(decodeURIComponent(atob(raw)));
		} catch (e) {
			return null;
		}
	}

	function encodeCookie(obj) {
		try {
			return btoa(encodeURIComponent(JSON.stringify(obj)));
		} catch (e) {
			return null;
		}
	}

	function getParam(qs, key) {
		var v = qs.get(key);
		return v ? String(v).substring(0, 200) : null;
	}

	function classifySource(utmSource, utmMedium, referrer) {
		// Returns simple normalized source label for the last-touch.
		var src = (utmSource || '').toLowerCase();
		var med = (utmMedium || '').toLowerCase();

		if (src.indexOf('facebook') >= 0 || src.indexOf('meta') >= 0 || src === 'fb' || src === 'ig' || src === 'instagram') return 'meta';
		if (src.indexOf('google') >= 0) return med === 'cpc' || med === 'paid' || med === 'ppc' ? 'google_paid' : 'google';
		if (src.indexOf('tiktok') >= 0) return 'tiktok';
		if (src.indexOf('klaviyo') >= 0 || med === 'email') return 'email';
		if (src.indexOf('bing') >= 0 || src.indexOf('microsoft') >= 0) return 'bing';
		if (src.indexOf('pinterest') >= 0) return 'pinterest';

		// No UTM — classify by referrer
		if (!referrer) return 'direct';
		var r = referrer.toLowerCase();
		if (r.indexOf('facebook.com') >= 0 || r.indexOf('instagram.com') >= 0 || r.indexOf('l.facebook') >= 0) return 'social_meta';
		if (r.indexOf('google.') >= 0) return 'organic_google';
		if (r.indexOf('bing.') >= 0) return 'organic_bing';
		if (r.indexOf('tiktok.com') >= 0) return 'social_tiktok';
		return 'referral';
	}

	function isPaidMedium(medium) {
		if (!medium) return false;
		var m = medium.toLowerCase();
		return m === 'cpc' || m === 'ppc' || m === 'paid' || m === 'paid_social' || m === 'paidsearch' || m === 'display';
	}

	// ---------------------------------------------------------
	// Read or initialize the cookie
	// ---------------------------------------------------------

	var raw = readCookie(COOKIE);
	var data = raw ? decodeCookie(raw) : null;
	var now = Math.floor(Date.now() / 1000);

	if (!data || typeof data !== 'object') {
		data = {
			// First touch — set once, never overwritten by direct traffic
			fs: null, fm: null, fc: null, fct: null, ft_kw: null,
			ft: now,            // first-seen timestamp
			furl: null,         // first landing URL
			fref: null,         // first referrer

			// Last paid touch — refreshed only when new paid signal arrives
			lsrc: null, lmed: null, lcamp: null, lcon: null, lterm: null,
			lts: null,          // last-paid timestamp

			// Click IDs — latest seen
			cids: {},

			// Session tracking
			sc: 0,              // session count
			lseen: 0            // last-seen timestamp (any session)
		};
	}

	// ---------------------------------------------------------
	// Parse current URL for UTM + click IDs + referrer
	// ---------------------------------------------------------

	var qs = new URLSearchParams(location.search);

	var hasUtm = false;
	var utm = {};
	for (var i = 0; i < UTM_KEYS.length; i++) {
		var k = UTM_KEYS[i];
		var v = getParam(qs, k);
		if (v) { utm[k] = v; hasUtm = true; }
	}

	var newClickIds = {};
	for (var j = 0; j < CLICK_IDS.length; j++) {
		var ck = CLICK_IDS[j];
		var cv = getParam(qs, ck);
		if (cv) newClickIds[ck] = cv;
	}
	var hasClickId = Object.keys(newClickIds).length > 0;

	var referrer = document.referrer || '';

	// ---------------------------------------------------------
	// Session counting — new session if last seen > 30 minutes
	// ---------------------------------------------------------

	var SESSION_GAP = 30 * 60; // 30 minutes
	if (!data.lseen || (now - data.lseen) > SESSION_GAP) {
		data.sc = (data.sc || 0) + 1;
	}
	data.lseen = now;

	// ---------------------------------------------------------
	// First-touch — set once
	// ---------------------------------------------------------

	if (!data.fs && (hasUtm || hasClickId || referrer)) {
		data.fs = utm.utm_source || classifySource(null, null, referrer);
		data.fm = utm.utm_medium || null;
		data.fc = utm.utm_campaign || null;
		data.fct = utm.utm_content || null;
		data.ft_kw = utm.utm_term || null;
		data.ft = now;
		data.furl = String(location.href).substring(0, 500);
		data.fref = referrer ? referrer.substring(0, 300) : null;
	}

	// ---------------------------------------------------------
	// Last paid touch — refresh ONLY on paid signal
	// (Avoids overwriting last paid with direct/organic.)
	// ---------------------------------------------------------

	var isPaid = isPaidMedium(utm.utm_medium) || hasClickId;
	if (isPaid) {
		data.lsrc  = utm.utm_source   || classifySource(utm.utm_source, utm.utm_medium, referrer);
		data.lmed  = utm.utm_medium   || 'cpc';
		data.lcamp = utm.utm_campaign || null;
		data.lcon  = utm.utm_content  || null;
		data.lterm = utm.utm_term     || null;
		data.lts   = now;
	}

	// ---------------------------------------------------------
	// Click IDs — merge, latest wins per ID
	// ---------------------------------------------------------

	if (hasClickId) {
		data.cids = data.cids || {};
		for (var ckey in newClickIds) {
			if (Object.prototype.hasOwnProperty.call(newClickIds, ckey)) {
				data.cids[ckey] = newClickIds[ckey];
			}
		}
	}

	// ---------------------------------------------------------
	// Write back
	// ---------------------------------------------------------

	var encoded = encodeCookie(data);
	if (encoded) {
		writeCookie(COOKIE, encoded);
	}

	// Expose minimal API for other scripts (checkout email capture)
	window.Magellan = {
		v: '2.2.0',
		getCookie: function () { return data; }
	};
})();
