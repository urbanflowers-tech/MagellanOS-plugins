<?php
/**
 * Plugin Name:       Magellan for WooCommerce
 * Plugin URI:        https://magellan.app
 * Description:       First-party attribution pixel for Magellan. Captures verified purchase data and sends it to Magellan for cross-platform attribution and overclaim detection.
 * Version:           2.2.0
 * Author:            Magellan
 * Author URI:        https://magellan.app
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Text Domain:       magellan-for-woocommerce
 * WC requires at least: 7.0
 * WC tested up to:   9.x
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------

define( 'MAGELLAN_VERSION',            '2.2.0' );
define( 'MAGELLAN_PLUGIN_FILE',        __FILE__ );
define( 'MAGELLAN_PLUGIN_DIR',         plugin_dir_path( __FILE__ ) );
define( 'MAGELLAN_PLUGIN_URL',         plugin_dir_url( __FILE__ ) );

// API base — overridable via wp-config.php for staging/test environments.
if ( ! defined( 'MAGELLAN_API_BASE' ) ) {
	define( 'MAGELLAN_API_BASE', 'https://api.magellan.app/v1/pixel' );
}

define( 'MAGELLAN_ENDPOINT_EVENT',     MAGELLAN_API_BASE . '/event' );
define( 'MAGELLAN_ENDPOINT_CART',      MAGELLAN_API_BASE . '/cart-email' );
define( 'MAGELLAN_ENDPOINT_IDENTS',    MAGELLAN_API_BASE . '/identities' );
define( 'MAGELLAN_ENDPOINT_HEALTH',    MAGELLAN_API_BASE . '/health' );
define( 'MAGELLAN_ENDPOINT_BOOTSTRAP', MAGELLAN_API_BASE . '/bootstrap' );

// ---------------------------------------------------------------------
// HPOS compatibility declaration (must run before WooCommerce inits)
// ---------------------------------------------------------------------

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				MAGELLAN_PLUGIN_FILE,
				true
			);
		}
	}
);

// ---------------------------------------------------------------------
// Boot — only after WooCommerce loads
// ---------------------------------------------------------------------

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p><strong>Magellan for WooCommerce</strong> requires WooCommerce to be installed and active.</p></div>';
				}
			);
			return;
		}

		$includes = [
			'class-magellan-admin',
			'class-magellan-identity',
			'class-magellan-pixel',
			'class-magellan-tracker',
			'class-magellan-sender',
			'class-magellan-cart',
			'class-magellan-health',
		];

		foreach ( $includes as $file ) {
			require_once MAGELLAN_PLUGIN_DIR . "includes/{$file}.php";
		}

		Magellan_Admin::init();
		Magellan_Identity::init();
		Magellan_Pixel::init();
		Magellan_Tracker::init();
		Magellan_Sender::init();
		Magellan_Cart::init();
		Magellan_Health::init();
	}
);

// ---------------------------------------------------------------------
// Activation — schedule historical identity sync
// ---------------------------------------------------------------------

register_activation_hook(
	__FILE__,
	function () {
		// Run the historical sync 60 seconds after activation so
		// the rest of the plugin has time to register hooks.
		if ( ! wp_next_scheduled( 'magellan_historical_identity_sync' ) ) {
			wp_schedule_single_event( time() + 60, 'magellan_historical_identity_sync' );
		}

		// Daily housekeeping
		if ( ! wp_next_scheduled( 'magellan_daily_cleanup' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'magellan_daily_cleanup' );
		}

		// Webhook reliability backup — every 5 minutes
		if ( ! wp_next_scheduled( 'magellan_sync_check' ) ) {
			wp_schedule_event( time() + 300, 'magellan_5min', 'magellan_sync_check' );
		}

		// Daily health report
		if ( ! wp_next_scheduled( 'magellan_health_check' ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:05' ), 'daily', 'magellan_health_check' );
		}
	}
);

// Custom 5-minute cron schedule
add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules['magellan_5min'] ) ) {
			$schedules['magellan_5min'] = [
				'interval' => 300,
				'display'  => 'Every 5 minutes (Magellan)',
			];
		}
		return $schedules;
	}
);

// ---------------------------------------------------------------------
// Deactivation — clean up scheduled events but keep stored data
// ---------------------------------------------------------------------

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'magellan_historical_identity_sync' );
		wp_clear_scheduled_hook( 'magellan_daily_cleanup' );
		wp_clear_scheduled_hook( 'magellan_sync_check' );
		wp_clear_scheduled_hook( 'magellan_health_check' );
	}
);

// ---------------------------------------------------------------------
// Uninstall — handled in uninstall.php (not in deactivate)
// ---------------------------------------------------------------------
