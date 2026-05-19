<?php
/**
 * Plugin Name:       Magellan Staging Installer
 * Plugin URI:        https://github.com/urbanflowers-tech/MagellanOS-plugins
 * Description:       Staging-only helper. Redirects WordPress's wp.org slug lookup for <code>magellan-for-woocommerce</code> to the GitHub release zip, so the MagellanOS extension's auto-install flow works end-to-end before the real Magellan plugin is approved on wordpress.org. Deactivate + delete once the real plugin is on wordpress.org.
 * Version:           1.0.1
 * Author:            Magellan
 * Author URI:        https://magellan.app
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.0
 *
 * @package MagellanStagingInstaller
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Slug we're intercepting + the GitHub release zip to serve in its place.
 *
 * Bump these whenever a new magellan-for-woocommerce release ships and you
 * want the staging install to pull the newer version. (The shim itself does
 * not need to be re-released for that — just edit + re-upload.)
 */
const MAGELLAN_STAGING_TARGET_SLUG    = 'magellan-for-woocommerce';
const MAGELLAN_STAGING_TARGET_VERSION = '2.2.3';
const MAGELLAN_STAGING_TARGET_ZIP     = 'https://github.com/urbanflowers-tech/MagellanOS-plugins/releases/download/v2.2.3/magellan-for-woocommerce-2.2.3.zip';

/**
 * Intercept `plugins_api('plugin_information', { slug })` lookups. When the
 * slug matches our target, return a fake-but-valid info object so WordPress
 * core's `Plugin_Upgrader::install()` can download + extract + activate the
 * real plugin from our GitHub release URL instead of (failing to) reach
 * wp.org.
 *
 * After wp.org approves the real plugin, deactivate + delete this staging
 * installer. The same auto-install code path then works against wp.org for
 * real, with zero changes anywhere else.
 *
 * Pattern reference: WP_REST_Plugins_Controller::get_plugin_data() →
 * plugins_api() → applies this filter before falling through to the
 * wp.org HTTP request.
 */
add_filter(
	'plugins_api',
	function ( $result, $action, $args ) {
		if ( $action !== 'plugin_information' ) {
			return $result;
		}
		if ( ! is_object( $args ) || empty( $args->slug ) ) {
			return $result;
		}
		if ( $args->slug !== MAGELLAN_STAGING_TARGET_SLUG ) {
			return $result;
		}

		return (object) [
			'name'          => 'Magellan for WooCommerce',
			'slug'          => MAGELLAN_STAGING_TARGET_SLUG,
			'version'       => MAGELLAN_STAGING_TARGET_VERSION,
			'author'        => '<a href="https://magellan.app">Magellan</a>',
			'homepage'      => 'https://magellan.app',
			'requires'      => '6.0',
			'tested'        => '6.8',
			'requires_php'  => '8.0',
			'download_link' => MAGELLAN_STAGING_TARGET_ZIP,
			'trunk'         => MAGELLAN_STAGING_TARGET_ZIP,
			'sections'      => [
				'description' => 'First-party attribution pixel for Magellan. This is a staging-side redirect — the real plugin is hosted at the GitHub release URL until the wordpress.org submission is approved.',
			],
		];
	},
	10,
	3
);

/**
 * Persistent admin notice — so nobody forgets this shim is on the site,
 * and so it's obvious during code review / handoff that the install
 * source is GitHub, not wp.org.
 */
add_action(
	'admin_notices',
	function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>';
		echo '<strong>Magellan Staging Installer</strong> is active. ';
		echo 'WordPress plugin lookups for <code>' . esc_html( MAGELLAN_STAGING_TARGET_SLUG ) . '</code> are being redirected to a GitHub release zip ';
		echo '(<code>v' . esc_html( MAGELLAN_STAGING_TARGET_VERSION ) . '</code>). ';
		echo '<strong>Deactivate + delete</strong> once the real Magellan plugin is approved on wordpress.org.';
		echo '</p></div>';
	}
);
