<?php
/**
 * Self-hosted update checker.
 *
 * The plugin isn't on wordpress.org, so WordPress has no update source for
 * it and installed stores would never see "update available". This class
 * fills that gap by pointing WP's native update system at the GitHub
 * releases of urbanflowers-tech/MagellanOS-plugins:
 *
 *   - `pre_set_site_transient_update_plugins` — when WP builds its update
 *     list (twice daily + on the Plugins screen), inject our plugin's
 *     update info if a newer `vX.Y.Z` release exists. WP then shows the
 *     native "update available" notice, the one-click Update button, and
 *     honors the per-plugin auto-update toggle. The update DOWNLOADS the
 *     new zip server-side and OVERWRITES the existing build in place
 *     (keeps settings — does not run uninstall.php).
 *   - `plugins_api` — supply the "View version details" popup info, but
 *     ONLY when an update is actually available (so it doesn't collide
 *     with the staging-installer shim, which also answers plugins_api for
 *     this slug during fresh installs).
 *
 * The GitHub releases query is cached in a transient (12h) to respect the
 * unauthenticated GitHub API rate limit (60/hr per server IP) — a store
 * makes ~2 calls/day. Fails closed: if GitHub is unreachable, no update is
 * injected and the plugin keeps working.
 *
 * NOTE: once the plugin is approved on wordpress.org, REMOVE this class —
 * wp.org becomes the update source and a self-hosted updater pointing
 * elsewhere violates the directory guidelines.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Updater {

	const GITHUB_OWNER     = 'urbanflowers-tech';
	const GITHUB_REPO      = 'MagellanOS-plugins';
	const CACHE_KEY        = 'magellan_latest_release';
	const CACHE_TTL        = 12 * HOUR_IN_SECONDS;
	const ASSET_PREFIX     = 'magellan-for-woocommerce-'; // <prefix><version>.zip
	const PLUGIN_HOMEPAGE  = 'https://magellan.app';
	// WP version tested up to — keep in sync with readme.txt "Tested up to".
	// (Not a plugin-header field, so it can't be derived from get_file_data.)
	const TESTED_UP_TO     = '6.8';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', [ __CLASS__, 'inject_update' ] );
		// Priority 99 (runs late) so that on a staging site where the
		// staging-installer shim ALSO hooks plugins_api for this slug, our
		// update-details object is the one returned when an update exists.
		add_filter( 'plugins_api', [ __CLASS__, 'plugin_info' ], 99, 3 );
		// Drop the cached release lookup after any plugin upgrade completes,
		// so the next check reflects the just-installed version. (Note: this
		// does NOT fire on the Plugins-screen "Check Again" link — that path
		// rebuilds the transient via our inject_update filter directly, and
		// the 12h cache TTL bounds staleness regardless.)
		add_action( 'upgrader_process_complete', [ __CLASS__, 'flush_cache' ] );
	}

	/** WP plugin file id, e.g. magellan-for-woocommerce/magellan-for-woocommerce.php */
	private static function plugin_basename(): string {
		return plugin_basename( MAGELLAN_PLUGIN_FILE );
	}

	// -----------------------------------------------------------------
	// Update transient injection
	// -----------------------------------------------------------------

	/**
	 * @param mixed $transient The update_plugins transient (stdClass) — or
	 *                         non-object on the first pass; return as-is then.
	 * @return mixed
	 */
	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = self::get_latest_release();
		if ( ! $latest ) {
			return $transient;
		}
		// Only inject when the release is strictly newer than what's running.
		if ( version_compare( MAGELLAN_VERSION, $latest['version'], '>=' ) ) {
			return $transient;
		}

		$file = self::plugin_basename();
		$obj  = (object) [
			'slug'         => dirname( $file ),
			'plugin'       => $file,
			'new_version'  => $latest['version'],
			'url'          => self::PLUGIN_HOMEPAGE,
			'package'      => $latest['zip'],
			'tested'       => $latest['tested'],
			'requires'     => $latest['requires'],
			'requires_php' => $latest['requires_php'],
		];

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = [];
		}
		$transient->response[ $file ] = $obj;

		// Remove any stale "no update" entry for our plugin.
		if ( isset( $transient->no_update[ $file ] ) ) {
			unset( $transient->no_update[ $file ] );
		}

		return $transient;
	}

	// -----------------------------------------------------------------
	// "View details" popup
	// -----------------------------------------------------------------

	/**
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || dirname( self::plugin_basename() ) !== $args->slug ) {
			return $result;
		}
		$latest = self::get_latest_release();
		// Only claim plugins_api when we actually have a newer release to
		// describe — otherwise let the request fall through (e.g. to the
		// staging-installer shim during a fresh install).
		if ( ! $latest || version_compare( MAGELLAN_VERSION, $latest['version'], '>=' ) ) {
			return $result;
		}

		return (object) [
			'name'          => 'Magellan for WooCommerce',
			'slug'          => $args->slug,
			'version'       => $latest['version'],
			'author'        => '<a href="' . esc_url( self::PLUGIN_HOMEPAGE ) . '">Magellan</a>',
			'homepage'      => self::PLUGIN_HOMEPAGE,
			'requires'      => $latest['requires'],
			'tested'        => $latest['tested'],
			'requires_php'  => $latest['requires_php'],
			'download_link' => $latest['zip'],
			'trunk'         => $latest['zip'],
			'sections'      => [
				'description' => 'First-party attribution pixel for Magellan.',
				'changelog'   => $latest['changelog'] !== '' ? wp_kses_post( $latest['changelog'] ) : 'See the release notes on GitHub.',
			],
		];
	}

	// -----------------------------------------------------------------
	// GitHub releases query (cached)
	// -----------------------------------------------------------------

	public static function flush_cache(): void {
		delete_transient( self::CACHE_KEY );
	}

	/**
	 * Resolve the latest plugin release from GitHub. Returns an array:
	 *   [ version, zip, tested, requires, requires_php, changelog ]
	 * or null on any failure / no matching release.
	 *
	 * Picks the highest `vX.Y.Z` tag (semver) — explicitly ignoring the
	 * `staging-installer-*` tags that live in the same repo so the shim
	 * release is never offered as a plugin update.
	 */
	private static function get_latest_release(): ?array {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		// Cache an explicit "miss" too (stored as the string 'none') so a
		// failing/empty query doesn't hammer GitHub every page load.
		if ( 'none' === $cached ) {
			return null;
		}

		// per_page=100 so plugin releases can't be pushed off the first page
		// by interleaved staging-installer releases in the same repo.
		$url      = sprintf( 'https://api.github.com/repos/%s/%s/releases?per_page=100', self::GITHUB_OWNER, self::GITHUB_REPO );
		$response = wp_remote_get( $url, [
			'timeout' => 8,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'MagellanWooPlugin/' . MAGELLAN_VERSION,
			],
		] );

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}

		$releases = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $releases ) ) {
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}

		$best = null; // [ version, release ]
		foreach ( $releases as $rel ) {
			if ( ! is_array( $rel ) || ! empty( $rel['draft'] ) || ! empty( $rel['prerelease'] ) ) {
				continue;
			}
			$tag = isset( $rel['tag_name'] ) ? (string) $rel['tag_name'] : '';
			// Plugin releases only: tag exactly `vX.Y.Z`. Excludes
			// `staging-installer-vX.Y.Z` and anything else.
			if ( ! preg_match( '/^v(\d+\.\d+\.\d+)$/', $tag, $m ) ) {
				continue;
			}
			$version = $m[1];
			if ( null === $best || version_compare( $version, $best[0], '>' ) ) {
				$best = [ $version, $rel ];
			}
		}

		if ( null === $best ) {
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}

		[ $version, $rel ] = $best;

		// Find the plugin zip asset for that version.
		$zip = '';
		$assets = isset( $rel['assets'] ) && is_array( $rel['assets'] ) ? $rel['assets'] : [];
		foreach ( $assets as $asset ) {
			$name = isset( $asset['name'] ) ? (string) $asset['name'] : '';
			if ( $name === self::ASSET_PREFIX . $version . '.zip' && ! empty( $asset['browser_download_url'] ) ) {
				$zip = (string) $asset['browser_download_url'];
				break;
			}
		}
		if ( '' === $zip ) {
			// Release exists but no matching zip asset — don't offer a broken update.
			set_transient( self::CACHE_KEY, 'none', self::CACHE_TTL );
			return null;
		}

		// Derive compatibility fields from the running plugin's own header so
		// they never drift from the source of truth. `Tested up to` is a
		// readme.txt field (not a plugin-header field), so it stays a const.
		$headers = get_file_data( MAGELLAN_PLUGIN_FILE, [
			'RequiresWP'  => 'Requires at least',
			'RequiresPHP' => 'Requires PHP',
		] );

		$result = [
			'version'      => $version,
			'zip'          => $zip,
			'tested'       => self::TESTED_UP_TO,
			'requires'     => $headers['RequiresWP'] !== '' ? $headers['RequiresWP'] : '6.0',
			'requires_php' => $headers['RequiresPHP'] !== '' ? $headers['RequiresPHP'] : '8.0',
			'changelog'    => isset( $rel['body'] ) ? (string) $rel['body'] : '',
		];
		set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
		return $result;
	}
}
