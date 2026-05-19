# MagellanOS Plugins

Companion plugins for the MagellanOS Truth Layer — first-party conversion
verification for e-commerce platforms.

## Plugins

| Plugin | Platform | Version | Status |
|---|---|---|---|
| [magellan-for-woocommerce](./magellan-for-woocommerce/) | WooCommerce (WordPress 6.0+, WC 7.0+) | 2.2.3 | wordpress.org submission-ready |
| [magellan-staging-installer](./magellan-staging-installer/) | WordPress 6.0+ — **internal staging only** | 1.0.1 | Active (shim until wp.org approves the main plugin) |

## How it fits

These plugins are the **WP/store side** of Truth Layer Track B. They:

1. Hold an account-scoped HMAC signing secret (issued by MagellanOS)
2. Hook into platform purchase / cart / identity events
3. HMAC-sign the payload (`X-Magellan-Signature: t=<unix>,v1=<hex>`) and POST
   to the MagellanOS `pixel-ingest` Edge Function
4. Receive verified-attribution and per-event Tracking Health back

Backend contract is defined in the Truth Layer Backend spec — see
`/Users/dev/magellan-specs/` and the MagellanOS `supabase/functions/pixel-ingest/`
implementation.

## Installation

Two paths supported:

- **Auto-install (preferred):** from the MagellanOS Chrome extension's
  Connect modal → after connecting a WC store via Tier-2 OAuth, the modal
  offers "Install Magellan Tracking" which kicks off the WP Application
  Password saga. MagellanOS pushes the plugin into the store via the WP
  REST API and configures it with the account ID + signing secret.
- **Manual:** upload the plugin zip via WP admin → Plugins → Add New, then
  configure account ID + signing secret in Settings → Magellan.

## License

GPL-2.0+
