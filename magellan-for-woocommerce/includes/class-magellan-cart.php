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
		register_rest_route( 'magellan/v1', '/cart-email', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_cart_email' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function handle_cart_email( WP_REST_Request $req ) {
		if ( ! Magellan_Admin::is_configured() ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'not_configured' ], 200 );
		}

		// Rate limit per IP — 10/min
		$ip  = self::client_ip();
		$key = self::TRANSIENT_RATE_LIMIT . md5( $ip );
		$cnt = (int) get_transient( $key );
		if ( $cnt >= self::RATE_LIMIT_PER_MIN ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'rate_limited' ], 429 );
		}
		set_transient( $key, $cnt + 1, MINUTE_IN_SECONDS );

		$params = $req->get_json_params();

		// Validate cart_token format
		$cart_token = isset( $params['cart_token'] ) ? (string) $params['cart_token'] : '';
		if ( ! preg_match( '/^cart_[a-z0-9_]{8,64}$/i', $cart_token ) ) {
			return new WP_REST_Response( [ 'ok' => false, 'reason' => 'bad_token' ], 400 );
		}

		// Validate email_hash format
		$identity   = is_array( $params['identity'] ?? null ) ? $params['identity'] : [];
		$email_hash = isset( $identity['email_hash'] ) ? (string) $identity['email_hash'] : '';
		if ( ! preg_match( '/^sha256:[a-f0-9]{64}$/', $email_hash ) ) {
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

		// Forward to Magellan (signed)
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
