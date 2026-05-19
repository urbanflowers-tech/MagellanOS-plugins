=== Magellan for WooCommerce ===
Contributors: magellanapp
Tags: woocommerce, analytics, attribution, pixel, conversion tracking
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 2.2.2
WC requires at least: 7.0
WC tested up to: 9.x
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

First-party attribution pixel for Magellan. Captures verified purchase data and sends it to Magellan for cross-platform attribution and overclaim detection.

== Description ==

**Magellan for WooCommerce** is the truth layer between your store and your ad platforms.

Every ad platform — Meta, Google, TikTok — claims credit for your sales. Most of the time they overclaim. A store showing ROAS 4x in Meta Ads Manager may be running at 2.8x in reality. Without verified first-party data, you cannot know.

This plugin gives Magellan the ground truth it needs to tell you what actually happened.

**What this plugin does:**

* Captures UTM parameters and click IDs (fbclid, gclid, ttclid, and more) when a visitor arrives at your store
* Tracks the customer journey from first visit to purchase — across sessions and devices
* Hashes customer email and phone server-side (SHA-256) before any data leaves WordPress
* Stamps verified attribution data onto every WooCommerce order
* Sends one signed verified event per order to Magellan's API
* Reports tracking health (conflicting plugins, consent state, checkout type)

**What this plugin does NOT do:**

* It does not talk to Meta, Google, TikTok, or Klaviyo directly
* It does not store any ad-platform API credentials on your WordPress server
* It does not slow your store down — the frontend pixel is under 2KB, async + deferred

All ad-platform Conversions API sends, attribution analysis, and overclaim detection live in Magellan's backend.

**One field. That's all.**

The settings page asks for one thing: your Magellan Account ID. Everything else is auto-configured.

**Requirements:**

* WordPress 6.0+
* WooCommerce 7.0+
* PHP 8.0+
* A Magellan account ([magellan.app](https://magellan.app))

== Installation ==

= Auto-install (preferred) =

1. Connect WooCommerce in your Magellan dashboard at app.magellan.app
2. Approve the consent screen (one click, two scopes: WooCommerce REST + WordPress Application Password)
3. Magellan installs and configures this plugin automatically
4. Done — verified attribution starts immediately

= Manual install =

1. Upload the plugin folder to `/wp-content/plugins/` or install via WordPress.org
2. Activate through the **Plugins** menu
3. Go to **WooCommerce → Magellan**
4. Paste your Magellan Account ID (format: `mgln_live_xxxxxxxxxxxxxx`)
5. Save

== Frequently Asked Questions ==

= Does this plugin slow down my store? =

No. The frontend pixel is under 2KB, loaded asynchronously and deferred in the footer. All verified-event sending happens server-side in background jobs via Action Scheduler. The customer's thank-you page never waits for an external API call.

= What data is sent to Magellan? =

For each completed order: order total, items, hashed email, hashed phone, UTM parameters, click IDs, session count, and first-touch attribution. Raw email and phone never leave WordPress — only SHA-256 hashes.

= Does this conflict with Meta Pixel, Google Tag Manager, or other tracking plugins? =

The plugin detects common tracking plugins (PixelYourSite, Meta Pixel, Google Site Kit, GTM4WP) and surfaces conflicts in your Magellan dashboard. Verified event IDs are designed to deduplicate against your existing pixel events on Meta and Google.

= Is this GDPR compliant? =

The plugin honors a `magellan_tracking_enabled` filter for integration with your consent management plugin. When tracking is disabled, the pixel does not load and no events are sent. The plugin also exposes WordPress privacy erasure hooks so customer data can be removed on request.

= What happens to data when I uninstall? =

Plugin options (account ID, signing secret) are removed. Order metadata stamped by the plugin (`_mgln_*`) is preserved so reinstalling restores historical attribution.

== Screenshots ==

1. Settings page — one field, Account ID only
2. Magellan dashboard showing Platform Reported vs Magellan Verified ROAS
3. Tracking Health report in Magellan Data department
4. Customer lifetime journey map built from plugin attribution data

== External services ==

This plugin connects to **Magellan's API** (default: https://api.magellan.app) to deliver verified attribution data for your WooCommerce orders. The destination is configurable via the `MAGELLAN_API_BASE` constant in `wp-config.php` or set automatically during the Magellan dashboard install flow.

**What data is sent, when, and why:**

* **Order events** — sent when a WooCommerce order moves to `processing` or `completed` status (delayed ~10 seconds via Action Scheduler). Payload includes: order ID, order number, currency, totals (subtotal, shipping, tax, discount, total), line items (SKU, name, quantity, unit price, line total), the order's hashed customer email (SHA-256, never raw), hashed phone (SHA-256, country-aware E.164 normalization, never raw), attribution touches (UTM source/medium/campaign, click IDs such as fbclid/gclid/ttclid, session count, first-touch landing URL, referrer), customer IP address, and User-Agent. Purpose: Magellan cross-references this against what Meta / Google / TikTok / Klaviyo claim, then surfaces overclaim and lets the Conversions API send only the verified events.
* **Refund / cancellation events** — sent when WooCommerce records a refund or cancels an order that previously sent a verified event. Payload includes refund amount + reason (or cancellation flag), original event ID, hashed identity, currency, and total. Purpose: keep ad-platform conversion counts honest as refunds happen.
* **Cart email capture** — when a shopper types an email into the WooCommerce checkout, the plugin's frontend JS hashes the email (SHA-256, client-side) and POSTs `{cart_token, identity.email_hash, attribution, cart}` to a local plugin REST endpoint, which signs and forwards to Magellan. Rate-limited to 10 requests per IP per minute. Purpose: abandoned-cart attribution and remarketing tied to verified opt-in.
* **Identity batch** — once on plugin activation (and on demand thereafter), the plugin sends a batched list of historical customer identities: hashed email + hashed phone + first/last seen timestamp + order count + external customer ID. Sent in chunks of 500. Purpose: backfill cross-store identity matching for already-completed orders.
* **Tracking Health report** — once per day (and immediately on plugin activation / deactivation of other plugins) the plugin sends a non-sensitive site profile: WordPress / WooCommerce / PHP versions, HPOS status, checkout type, active theme name, site locale, multisite flag, site URL, count of events sent in the last 24h, a list of conflicting tracking plugins detected by file path, and the slug of any consent or cache plugin detected. Purpose: warn the operator when a duplicate Meta Pixel or GTM container would otherwise cause double-counting.

**What this plugin does NOT send:**

* Raw email addresses or phone numbers (always SHA-256 hashed first)
* Payment method details, card numbers, or any financial credentials
* Shopper passwords, session cookies, or login tokens
* Product images or descriptions
* Comments, reviews, or any blog/CMS content
* Customer addresses
* WordPress user accounts other than their email hash (used as a stable customer identifier)

**Magellan privacy + terms:**

* Privacy policy: https://magellan.app/privacy
* Terms of service: https://magellan.app/terms

Use of this plugin requires a Magellan account. Site operators are responsible for disclosing this data flow in their own site's privacy policy and obtaining any consent required by their jurisdiction (GDPR, CCPA, PDPA, etc.). The plugin respects the `magellan_tracking_enabled` filter so consent-management plugins can suppress all data flow when a visitor has not consented.

== Privacy and GDPR ==

This plugin is designed to be compatible with consent-management workflows and PII-minimization policies:

* **No raw PII leaves WordPress.** Email and phone are SHA-256 hashed (with country-aware E.164 normalization for phone) before any transmission. Magellan stores only the hashes.
* **No browser fingerprinting.** The plugin's first-party pixel sets a single cookie (`_mgln`, 180-day TTL, SameSite=Lax) carrying UTM parameters, click IDs (fbclid, gclid, gbraid, wbraid, ttclid, msclkid, twclid), referrer, and session count. No canvas fingerprinting, no device enumeration, no third-party cookies.
* **Consent integration.** Apply the `magellan_tracking_enabled` filter (return `false` from your consent-management plugin) to suppress the pixel and skip all server-side event sending until consent is granted. Apply the `magellan_consent_state` filter to record consent state (`granted` / `denied` / `unknown`) on each outbound event.
* **Right to erasure.** The plugin honors WordPress's `Personal_Data_Eraser` hooks. Hashed identity data tied to a specific order is removed via the standard WP privacy tools.
* **Data residency.** Magellan's API runs in regions disclosed at https://magellan.app/data-residency.

== Changelog ==

= 2.2.2 =
* Added: LICENSE.txt (GPL-2.0+) bundled in plugin distribution.
* Added: `External services` and `Privacy and GDPR` sections in readme — full disclosure of what data is sent to Magellan's API, when, and why.
* Improved: all user-facing strings wrapped in `__()` / `esc_html__()` and `load_plugin_textdomain` registered. Plugin is now translation-ready.
* Fixed: pixel JS no longer hard-codes plugin version (was reporting stale `2.2.0`); reads from the enqueued script's WP-injected `?ver=` parameter instead.
* Internal: WordPress.org coding standards review pass — output escaping audit, nonce / capability check audit, sanitize/escape symmetry on all REST and admin-post handlers.

= 2.2.1 =

= 2.2.1 =
* **Fix (CRITICAL):** signing secret was passed to HMAC as its base64 string instead of the decoded raw bytes. Every signed request 401'd against the backend. The plugin now base64-decodes the stored secret to bytes before signing. This matches the backend's `account_signing_secrets.secret_b64` → raw-bytes-as-HMAC-key contract.
* **Fix:** `MAGELLAN_API_BASE` now resolves from priority chain: `wp-config.php` constant → `magellan_api_base` option → default. `handle_configure` (Path A auto-install) persists `api_base` if the backend sends it. Trailing slashes are stripped on persist.
* **New:** Path B manual-install bootstrap. Admin settings page now shows "Install with token" form when the signing secret is not yet stored. Plugin POSTs `{account_id, install_token}` to `MAGELLAN_API_BASE/bootstrap`, stores the returned signing secret + api_base, and surfaces typed error messages (unknown_token, expired_token, already_consumed, etc.). Path A auto-install remains the preferred flow.
* **New:** "API base" row in the admin Status section so operators can verify which backend the plugin is talking to (and whether it came from the wp-config constant).

= 2.2.0 =
* Aligned with Magellan Truth Layer Backend Spec v1.0
* **Fix:** verified event now fires on `woocommerce_order_status_processing` (not on `_created`). Avoids events for orders that never confirm (PromptPay timeouts, declined bank transfers).
* **New:** refund events — `woocommerce_order_refunded` fires a negative conversion event to Magellan.
* **New:** multi-currency support — payload carries source currency; backend handles base conversion.
* **New:** HMAC-SHA256 signing on every outbound request (Stripe-style `t=<ts>,v1=<hex>`).
* **New:** two-credential auto-install via WooCommerce REST + WordPress Application Password.
* Verified event payload restructured with nested `identity`, `attribution`, `context` objects.

= 2.1.0 =
* First-party pixel with UTM and click ID capture (fbclid, gclid, gbraid, wbraid, ttclid, msclkid, twclid)
* Server-side order attribution using WooCommerce PHP hooks
* Identity resolution with country-aware E.164 phone normalization
* Two-phase historical identity sync — registered customers and guest checkout emails
* Cart tracking and checkout email capture
* Tracking health reporting with conflict detection
* HPOS compatible
* WooCommerce Blocks compatible

== Upgrade Notice ==

= 2.2.2 =
WordPress.org submission-ready release. Adds LICENSE.txt, full External Services and Privacy disclosure, and translation-ready strings. No behavior change vs 2.2.1.

= 2.2.1 =
CRITICAL fix: HMAC signing now matches backend contract (base64-decode the signing secret before signing). 2.2.0 installs cannot deliver verified events — upgrade required.

= 2.2.0 =
Aligns with Magellan backend Truth Layer v1.0. Fixes a critical issue where verified events were fired for orders that subsequently failed. Adds refund handling, multi-currency, and HMAC-signed requests.
