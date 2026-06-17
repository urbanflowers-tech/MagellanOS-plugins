<?php
/**
 * Frontend pixel — enqueues magellan-pixel.js on every page,
 * checkout-email-capture snippet on checkout only.
 *
 * Performance:
 *   - Core pixel: under 2KB minified
 *   - async + defer, footer only
 *   - No measurable Core Web Vitals regression
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Pixel {

	public static function init() {
		add_action( 'wp_enqueue_scripts', [ __CLASS__, 'enqueue' ] );
		add_action( 'wp_head', [ __CLASS__, 'inject_account_meta' ], 1 );
	}

	/**
	 * Inject a tiny meta tag with the account ID — read by the JS pixel.
	 * Keeps the pixel file static (cacheable) while still being account-aware.
	 */
	public static function inject_account_meta() {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}

		// Allow consent management plugins to disable tracking
		$enabled = apply_filters( 'magellan_tracking_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		$account_id = Magellan_Admin::get_account_id();
		echo "\n<meta name=\"magellan-account\" content=\"" . esc_attr( $account_id ) . "\">\n";
		// Endpoint for cart-email REST capture (same-origin POST)
		echo '<meta name="magellan-rest" content="' . esc_attr( rest_url( 'magellan/v1/cart-email' ) ) . "\">\n";
		// Endpoint for anonymous cart-state capture (magellan-cart.js).
		// Distinct from magellan-rest so the cart listener targets /cart
		// (email optional) and the checkout listener keeps /cart-email.
		echo '<meta name="magellan-rest-cart" content="' . esc_attr( rest_url( 'magellan/v1/cart' ) ) . "\">\n";
	}

	public static function enqueue() {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}

		$enabled = apply_filters( 'magellan_tracking_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		// Core pixel — every page
		wp_enqueue_script(
			'magellan-pixel',
			MAGELLAN_PLUGIN_URL . 'assets/magellan-pixel.js',
			[],
			MAGELLAN_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// Cart-state listener — every front-end page (add-to-cart fires on
		// shop / product / archive, not just the cart page). Depends on
		// magellan-pixel for window.Magellan.getCookie().
		wp_enqueue_script(
			'magellan-cart',
			MAGELLAN_PLUGIN_URL . 'assets/magellan-cart.js',
			[ 'magellan-pixel' ],
			MAGELLAN_VERSION,
			[
				'in_footer' => true,
				'strategy'  => 'defer',
			]
		);

		// Checkout email capture — checkout pages only
		if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ) {
			wp_enqueue_script(
				'magellan-checkout',
				MAGELLAN_PLUGIN_URL . 'assets/magellan-checkout.js',
				[ 'magellan-pixel' ],
				MAGELLAN_VERSION,
				[
					'in_footer' => true,
					'strategy'  => 'defer',
				]
			);
		}
	}
}
