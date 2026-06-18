<?php
/**
 * Cart tracking and abandonment.
 *
 * Three responsibilities:
 *   1. Register REST endpoint POST /wp-json/magellan/v1/cart-email
 *      called by checkout JS when email is typed.
 *   2. Hook into WC cart events server-side (add_to_cart, etc.) as
 *      a backup mechanism — Blocks checkout doesn't always fire JS.
 *   3. Webhook reliability backup — every 5 minutes, scan for orders
 *      with verified events not yet sent.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Cart {

	const TRANSIENT_RATE_LIMIT = 'mgln_cart_rl_';
	const RATE_LIMIT_PER_MIN   = 10;

	public static function init() {
		add_action( 'rest_api_init', [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'magellan_sync_check', [ __CLASS__, 'sync_check' ] );
	}

	// -----------------------------------------------------------
	// REST endpoint — receives hashed email from checkout JS
	// -----------------------------------------------------------

	public static function register_rest_routes() {
		// Checkout email capture (email REQUIRED) — called by checkout JS
		// when the shopper types their email.
		register_rest_route( 'magellan/v1', '/cart-email', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_cart_email' ],
			'permission_callback' => '__return_true',
		] );

		// Anonymous cart-state capture (email OPTIONAL) — called by the
		// cart-change listener (magellan-cart.js) on add-to-cart / qty /
		// remove, before the shopper has provided an email. Identity is
		// stitched on later when the same cart_token reaches /cart-email
		// at checkout. Enables real abandoned-cart tracking.
		register_rest_route( 'magellan/v1', '/cart', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_cart_snapshot' ],
			'permission_callback' => '__return_true',
		] );
	}

	/** Checkout email path — email required. */
	public static function handle_cart_email( WP_REST_Request $req ) {
		return self::handle_cart_event( $req, true );
	}

	/** Anonymous cart-state path — email optional. */
	public static function handle_cart_snapshot( WP_REST_Request $req ) {
		return self::handle_cart_event( $req, false );
	}

	/**
	 * Shared handler for both cart routes. Validates, builds the signed
	 * payload, and forwards to Magellan.
	 *
	 * @param bool $require_email When true (checkout path) a valid
	 *                            email_hash must be present; when false
	 *                            (cart-state path) it is optional and the
	 *                            cart is recorded anonymously.
	 */
	private static function handle_cart_event( WP_REST_Request $req, bool $require_email ) {
		if ( ! Magellan_Admin::is_configured() ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'not_configured' ], 200 );
		}

		// Rate limit per IP — 10/min, with a SEPARATE budget per route so the
		// high-volume anonymous /cart traffic can't starve the higher-value
		// checkout /cart-email capture (priority inversion).
		$ip  = self::client_ip();
		$key = self::TRANSIENT_RATE_LIMIT . ( $require_email ? 'email_' : 'cart_' ) . md5( $ip );
		$cnt = (int) get_transient( $key );
		if ( $cnt >= self::RATE_LIMIT_PER_MIN ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'rate_limited' ], 429 );
		}
		set_transient( $key, $cnt + 1, MINUTE_IN_SECONDS );

		$params = $req->get_json_params();

		// Validate cart_token format (always required — it's the dedup key).
		$cart_token = isset( $params['cart_token'] ) ? (string) $params['cart_token'] : '';
		if ( ! preg_match( '/^cart_[a-z0-9_]{8,64}$/i', $cart_token ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_token' ], 400 );
		}

		// Email hash: required on the checkout path, optional+validated-if-
		// present on the anonymous cart-state path.
		$identity   = is_array( $params['identity'] ?? null ) ? $params['identity'] : [];
		$email_raw  = isset( $identity['email_hash'] ) ? (string) $identity['email_hash'] : '';
		$has_email  = $email_raw !== '';
		$email_hash = null;
		if ( $has_email ) {
			if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $email_raw ) ) {
				return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_hash' ], 400 );
			}
			$email_hash = $email_raw;
		} elseif ( $require_email ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_hash' ], 400 );
		}

		// Whitelist attribution + click IDs to prevent payload abuse
		$attribution = is_array( $params['attribution'] ?? null ) ? $params['attribution'] : [];
		$click_ids   = is_array( $attribution['click_ids'] ?? null ) ? $attribution['click_ids'] : [];
		$allowed_cid = [ 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid', 'twclid' ];
		$click_clean = [];
		foreach ( $allowed_cid as $k ) {
			if ( isset( $click_ids[ $k ] ) && is_string( $click_ids[ $k ] ) ) {
				$click_clean[ $k ] = substr( sanitize_text_field( $click_ids[ $k ] ), 0, 500 );
			}
		}

		$payload = [
			'cart_token' => $cart_token,
			'occurred_at' => isset( $params['occurred_at'] ) ? sanitize_text_field( (string) $params['occurred_at'] ) : gmdate( 'c' ),
			'identity' => [
				'email_hash' => $email_hash,
				'phone_hash' => null,
			],
			'attribution' => [
				'first_touch'     => self::sanitize_touch( $attribution['first_touch'] ?? null ),
				'last_paid_touch' => self::sanitize_touch( $attribution['last_paid_touch'] ?? null ),
				'click_ids'       => $click_clean,
				'session_count'   => isset( $attribution['session_count'] ) ? (int) $attribution['session_count'] : 1,
			],
			'cart' => self::current_cart_snapshot(),
			'consent_state' => apply_filters( 'magellan_consent_state', 'granted', null ),
		];

		// Forward to Magellan (signed). Same backend route for both — the
		// backend treats email as optional.
		Magellan_Sender::send_cart_email( $payload );

		return new WP_REST_Response( [ 'ok' => true ], 200 );
	}

	private static function sanitize_touch( $touch ): ?array {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$allowed = [ 'source', 'medium', 'campaign', 'content', 'term', 'occurred_at', 'landing_url', 'referrer' ];
		$out = [];
		foreach ( $allowed as $k ) {
			if ( isset( $touch[ $k ] ) && is_string( $touch[ $k ] ) ) {
				$out[ $k ] = substr( sanitize_text_field( $touch[ $k ] ), 0, 500 );
			}
		}
		return $out ?: null;
	}

	private static function current_cart_snapshot(): array {
		// In a custom REST request (the cart-state route fires site-wide,
		// not just on checkout), WC()->cart is not auto-initialized.
		// wc_load_cart() loads the cart + session for the current request
		// from the visitor's session cookie, so the snapshot reflects the
		// real cart on any page. No-op when the cart is already loaded.
		if ( function_exists( 'WC' ) && function_exists( 'wc_load_cart' ) && ( ! WC()->cart ) ) {
			wc_load_cart();
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return [ 'items' => [], 'subtotal' => 0.0, 'currency' => get_woocommerce_currency() ];
		}
		$items = [];
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$items[] = [
				'sku'        => $product && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				'product_id' => (int) ( $item['product_id'] ?? 0 ),
				'quantity'   => (int) ( $item['quantity'] ?? 0 ),
			];
		}
		return [
			'items'    => $items,
			'subtotal' => (float) WC()->cart->get_subtotal(),
			'currency' => get_woocommerce_currency(),
		];
	}

	// -----------------------------------------------------------
	// Webhook reliability backup
	// Every 5 min, find orders that should have sent a verified
	// event but haven't, and re-schedule.
	// -----------------------------------------------------------

	public static function sync_check() {
		if ( ! Magellan_Admin::is_configured() ) {
			return;
		}

		$orders = wc_get_orders( [
			'status'       => [ 'wc-processing', 'wc-completed' ],
			'date_created' => '>' . ( time() - 2 * HOUR_IN_SECONDS ),
			'limit'        => 25,
			'return'       => 'objects',
			'meta_query'   => [
				[
					'key'     => '_mgln_event_sent',
					'compare' => 'NOT EXISTS',
				],
			],
		] );

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			// Re-trigger the send via the normal path
			Magellan_Sender::schedule_send( $order->get_id() );
		}
	}

	private static function client_ip(): string {
		$candidates = [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ];
		foreach ( $candidates as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}
}
