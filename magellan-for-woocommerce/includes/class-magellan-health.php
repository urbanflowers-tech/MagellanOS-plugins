<?php
/**
 * Tracking Health reporting.
 *
 * Daily report (and on-demand when plugins change) to Magellan describing:
 *   - WP / WooCommerce / PHP versions
 *   - HPOS status, checkout type, active theme, locale
 *   - Magellan plugin status (version, events sent, failures)
 *   - Conflicting tracking plugins
 *   - Consent management plugin detected
 *   - Cache plugin detected
 *
 * Conflicts trigger immediate ad-hoc reports.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Health {

	const CONFLICT_PLUGINS = [
		'pixelyoursite/pixelyoursite.php'                   => [ 'name' => 'PixelYourSite', 'severity' => 'warning' ],
		'pixelyoursite-pro/pixelyoursite-pro.php'           => [ 'name' => 'PixelYourSite Pro', 'severity' => 'warning' ],
		'facebook-for-woocommerce/facebook-for-woocommerce.php' => [ 'name' => 'Facebook for WooCommerce', 'severity' => 'warning' ],
		'official-facebook-pixel/facebook-pixel.php'        => [ 'name' => 'Meta Pixel (Official)', 'severity' => 'warning' ],
		'google-listings-and-ads/google-listings-and-ads.php' => [ 'name' => 'Google Listings & Ads', 'severity' => 'info' ],
		'google-site-kit/google-site-kit.php'               => [ 'name' => 'Site Kit by Google', 'severity' => 'info' ],
		'gtm4wp/gtm4wp.php'                                 => [ 'name' => 'GTM4WP', 'severity' => 'warning' ],
		'metorik-helper/metorik-helper.php'                 => [ 'name' => 'Metorik Helper', 'severity' => 'info' ],
	];

	public static function init() {
		add_action( 'magellan_health_check',  [ __CLASS__, 'run' ] );
		add_action( 'activated_plugin',       [ __CLASS__, 'on_plugin_change' ] );
		add_action( 'deactivated_plugin',     [ __CLASS__, 'on_plugin_change' ] );
	}

	public static function run() {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}

		$report = self::collect();
		update_option( Magellan_Admin::OPT_HEALTH_DATA, $report );

		// Send via signed dispatch — non-blocking.
		// CRITICAL: must base64-decode the stored secret to raw bytes
		// before HMAC. See class-magellan-admin.php::get_signing_secret_bytes
		// and the matching backend at supabase/functions/_shared/truth-layer/signature.ts.
		$account_id    = Magellan_Admin::get_account_id();
		$signing_bytes = Magellan_Admin::get_signing_secret_bytes();
		if ( $account_id === '' || $signing_bytes === '' ) {
			return;
		}

		$body      = wp_json_encode( $report );
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, $signing_bytes );

		wp_remote_post( MAGELLAN_ENDPOINT_HEALTH, [
			'body'      => $body,
			'headers'   => [
				'Content-Type'              => 'application/json',
				'X-Magellan-Account'        => $account_id,
				'X-Magellan-Signature'      => 't=' . $timestamp . ',v1=' . $signature,
				'X-Magellan-Plugin-Version' => MAGELLAN_VERSION,
			],
			'blocking'  => false,
			'timeout'   => 5,
		] );
	}

	public static function on_plugin_change() {
		// Re-run health within 10 seconds of any plugin activation/deactivation
		if ( ! wp_next_scheduled( 'magellan_health_check' ) ) {
			wp_schedule_single_event( time() + 10, 'magellan_health_check' );
		}
	}

	public static function collect(): array {
		$counters       = (array) get_option( 'magellan_counters', [] );
		$today          = gmdate( 'Y-m-d' );
		$events_sent    = (int) ( $counters[ $today ]['events_sent_24h'] ?? 0 );
		$last_event_at  = (int) get_option( Magellan_Admin::OPT_LAST_EVENT, 0 );
		$configured_at  = (int) get_option( Magellan_Admin::OPT_CONFIGURED_AT, 0 );

		// Conflicts
		$conflicts     = [];
		$active_plugins = (array) get_option( 'active_plugins', [] );
		// Network-active plugins (multisite)
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', [] );
			$active_plugins = array_merge( $active_plugins, array_keys( $network ) );
		}

		foreach ( self::CONFLICT_PLUGINS as $file => $info ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$conflicts[] = [
					'type'     => 'duplicate_pixel_risk',
					'plugin'   => $info['name'],
					'file'     => $file,
					'severity' => $info['severity'],
					'note'     => $info['name'] . ' is active. Confirm event_id deduplication or disable overlapping CAPI sending in ' . $info['name'] . ' to avoid potential double-counting.',
				];
			}
		}

		// Consent plugin detection
		$consent_plugin = null;
		$consent_signatures = [
			'complianz-gdpr/complianz-gpdr.php'                  => 'complianz',
			'complianz-terms-conditions/complianz-terms.php'     => 'complianz',
			'cookie-notice/cookie-notice.php'                    => 'cookie-notice',
			'cookiebot/cookiebot.php'                            => 'cookiebot',
			'wp-gdpr-compliance/wp-gdpr-compliance.php'          => 'wp-gdpr',
			'gdpr-cookie-compliance/moove-gdpr.php'              => 'moove-gdpr',
		];
		foreach ( $consent_signatures as $file => $slug ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$consent_plugin = $slug;
				break;
			}
		}

		// Cache plugin detection
		$cache_plugin = null;
		$cache_signatures = [
			'wp-rocket/wp-rocket.php'             => 'wp-rocket',
			'w3-total-cache/w3-total-cache.php'   => 'w3tc',
			'wp-super-cache/wp-cache.php'         => 'wp-super-cache',
			'litespeed-cache/litespeed-cache.php' => 'litespeed',
			'nitropack/main.php'                  => 'nitropack',
		];
		foreach ( $cache_signatures as $file => $slug ) {
			if ( in_array( $file, $active_plugins, true ) ) {
				$cache_plugin = $slug;
				break;
			}
		}

		// HPOS detection
		$hpos = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		// Checkout type
		$checkout_type = 'classic';
		if ( function_exists( 'has_block' ) ) {
			$checkout_page = get_post( wc_get_page_id( 'checkout' ) );
			if ( $checkout_page && has_block( 'woocommerce/checkout', $checkout_page ) ) {
				$checkout_type = 'blocks';
			}
		}

		return [
			'report_id'   => 'health_' . gmdate( 'Ymd_His' ) . '_' . substr( bin2hex( random_bytes( 4 ) ), 0, 8 ),
			'occurred_at' => gmdate( 'c' ),
			'platform'    => 'woocommerce',

			'environment' => [
				'wp_version'   => get_bloginfo( 'version' ),
				'woo_version'  => defined( 'WC_VERSION' ) ? WC_VERSION : null,
				'php_version'  => PHP_VERSION,
				'hpos_enabled' => (bool) $hpos,
				'checkout_type' => $checkout_type,
				'site_locale'   => get_locale(),
				'active_theme'  => wp_get_theme()->get( 'Name' ),
				'site_url'      => home_url(),
				'multisite'     => is_multisite(),
			],

			'magellan_plugin' => [
				'version'             => MAGELLAN_VERSION,
				'installed_at'        => $configured_at ? gmdate( 'c', $configured_at ) : null,
				'active'              => true,
				'last_event_sent_at'  => $last_event_at ? gmdate( 'c', $last_event_at ) : null,
				'events_sent_24h'     => $events_sent,
				'action_scheduler'    => function_exists( 'as_schedule_single_action' ),
			],

			'conflicts'      => $conflicts,
			'consent_plugin' => $consent_plugin ? [ 'detected' => $consent_plugin ] : null,
			'cache_plugin'   => $cache_plugin   ? [ 'detected' => $cache_plugin ]   : null,
		];
	}
}
