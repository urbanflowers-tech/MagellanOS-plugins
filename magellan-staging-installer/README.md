# Magellan Staging Installer

A single-purpose WordPress shim plugin used **only on internal staging WP sites** to test the MagellanOS auto-install flow before the real Magellan plugin is approved on wordpress.org.

## What it does

WordPress's `POST /wp-json/wp/v2/plugins {slug: "magellan-for-woocommerce"}` REST endpoint looks the slug up against wordpress.org. Until the Magellan plugin is published there, that lookup 404s and our auto-install saga falls back to "manual install needed."

This shim adds a `plugins_api` filter that intercepts the slug lookup for `magellan-for-woocommerce` and returns metadata pointing at the [GitHub release zip](https://github.com/urbanflowers-tech/MagellanOS-plugins/releases) instead. WordPress core then downloads + installs + activates the real plugin from GitHub using its standard `Plugin_Upgrader` machinery — same code path that runs against wordpress.org in production.

## When to use it

- On any internal **staging** WordPress site where you want to test the extension's "Install Magellan Tracking" auto-install end-to-end.
- **Never on production / real merchant sites.** Real merchants get the plugin from wordpress.org directly (once it's approved there); this shim doesn't ship with the real Magellan plugin and isn't distributed to merchants.

## Setup

1. Download `magellan-staging-installer-1.0.0.zip` from the [releases page](https://github.com/urbanflowers-tech/MagellanOS-plugins/releases).
2. Staging WP admin → Plugins → Add New → Upload Plugin → choose the zip → Install Now → Activate.
3. A persistent admin notice appears reminding you the shim is active.

That's it. The next time the MagellanOS backend asks this WP site to install the Magellan plugin, the shim transparently redirects the lookup to GitHub.

## Test flow

After activating the shim:

1. Open the Magellan extension → connect this WC store via Tier-2 OAuth.
2. After WC sync completes, click "Install Magellan Tracking" in the success modal.
3. Approve the WordPress Application Password on WP.
4. Backend POSTs to merchant's WP → WP asks for plugin metadata → shim returns the GitHub URL → WP downloads + installs + activates `magellan-for-woocommerce`.
5. Backend POSTs to the now-installed Magellan plugin's `/wp-json/magellan/v1/configure` → credentials seeded.
6. Place a test order → verified event flows through.

## Removal

Once `magellan-for-woocommerce` is approved on wordpress.org:

1. WP admin → Plugins → find "Magellan Staging Installer" → Deactivate → Delete.
2. The next auto-install call resolves via wp.org directly. No other changes required.

(Leaving the shim active after wp.org approval is harmless — wp.org will return correct metadata for the slug regardless. But removing it eliminates confusion and the admin notice.)

## Updating the target version

When a new release of `magellan-for-woocommerce` ships, edit the three `MAGELLAN_STAGING_TARGET_*` constants at the top of `magellan-staging-installer.php` to point at the new zip URL + version, then re-upload via WP admin (or re-zip and bump the shim itself).
