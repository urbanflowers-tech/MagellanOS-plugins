<?php
/**
 * Admin settings page + credential accessors + auto-install REST endpoint.
 *
 * Provisioning paths:
 *   A) Auto-install — Magellan POSTs to /wp-json/magellan/v1/configure
 *      using a WordPress Application Password (collected during the
 *      OAuth saga). Credentials land in wp_options.
 *   B) Manual — merchant pastes Account ID, plugin fetches the signing
 *      secret via the backend's /bootstrap endpoint using a one-time
 *      install token shown in the Magellan dashboard.
 *
 * @package Magellan
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Magellan_Admin {

	const OPT_ACCOUNT_ID     = 'magellan_account_id';
	const OPT_SIGNING_SECRET = 'magellan_signing_secret';
	const OPT_CONFIGURED_AT  = 'magellan_configured_at';
	const OPT_LAST_EVENT     = 'magellan_last_event_sent';
	const OPT_LAST_ERROR     = 'magellan_last_error';
	const OPT_HEALTH_DATA    = 'magellan_health_data';

	public static function init() {
		add_action( 'admin_menu',     [ __CLASS__, 'add_menu' ] );
		add_action( 'admin_init',     [ __CLASS__, 'register_settings' ] );
		add_action( 'rest_api_init',  [ __CLASS__, 'register_rest_routes' ] );
		add_action( 'admin_notices',  [ __CLASS__, 'maybe_notice' ] );
	}

	// -------------------------------------------------------------
	// Accessors used by every other class
	// -------------------------------------------------------------

	public static function get_account_id(): string {
		return (string) get_option( self::OPT_ACCOUNT_ID, '' );
	}

	public static function get_signing_secret(): string {
		return (string) get_option( self::OPT_SIGNING_SECRET, '' );
	}

	public static function is_configured(): bool {
		return self::get_account_id() !== '' && self::get_signing_secret() !== '';
	}

	public static function record_error( string $msg ): void {
		update_option( self::OPT_LAST_ERROR, [
			'message' => $msg,
			'time'    => time(),
		] );
	}

	// -------------------------------------------------------------
	// REST: /wp-json/magellan/v1/configure  (Path A — auto-install)
	// -------------------------------------------------------------

	public static function register_rest_routes() {
		register_rest_route( 'magellan/v1', '/configure', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'handle_configure' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );

		register_rest_route( 'magellan/v1', '/status', [
			'methods'             => 'GET',
			'callback'            => [ __CLASS__, 'handle_status' ],
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		] );
	}

	public static function handle_configure( WP_REST_Request $req ) {
		$params     = $req->get_json_params();
		$account_id = isset( $params['account_id'] ) ? (string) $params['account_id'] : '';
		$secret     = isset( $params['signing_secret'] ) ? (string) $params['signing_secret'] : '';

		if ( ! preg_match( '/^mgln_(live|test|dev)_[a-z2-7]{14}$/', $account_id ) ) {
			return new WP_Error(
				'magellan_bad_account_id',
				'Invalid Account ID format. Expected mgln_(live|test|dev)_<14 base32 chars>.',
				[ 'status' => 400 ]
			);
		}
		if ( strlen( $secret ) < 32 ) {
			return new WP_Error(
				'magellan_bad_secret',
				'Signing secret too short. Minimum 32 chars.',
				[ 'status' => 400 ]
			);
		}

		update_option( self::OPT_ACCOUNT_ID,     $account_id );
		update_option( self::OPT_SIGNING_SECRET, $secret );
		update_option( self::OPT_CONFIGURED_AT,  time() );

		return new WP_REST_Response( [
			'ok'           => true,
			'account_id'   => $account_id,
			'plugin_ver'   => MAGELLAN_VERSION,
			'wc_ver'       => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wp_ver'       => get_bloginfo( 'version' ),
			'configured_at' => time(),
		], 200 );
	}

	public static function handle_status( WP_REST_Request $req ) {
		return new WP_REST_Response( [
			'configured'      => self::is_configured(),
			'account_id'      => self::get_account_id(),
			'plugin_version'  => MAGELLAN_VERSION,
			'wc_version'      => defined( 'WC_VERSION' ) ? WC_VERSION : null,
			'wp_version'      => get_bloginfo( 'version' ),
			'configured_at'   => (int) get_option( self::OPT_CONFIGURED_AT, 0 ),
			'last_event_at'   => (int) get_option( self::OPT_LAST_EVENT, 0 ),
		], 200 );
	}

	// -------------------------------------------------------------
	// Settings page registration
	// -------------------------------------------------------------

	public static function add_menu() {
		add_submenu_page(
			'woocommerce',
			'Magellan',
			'Magellan',
			'manage_options',
			'magellan',
			[ __CLASS__, 'render_page' ]
		);
	}

	public static function register_settings() {
		register_setting(
			'magellan_settings',
			self::OPT_ACCOUNT_ID,
			[
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					$value = trim( (string) $value );
					if ( preg_match( '/^mgln_(live|test|dev)_[a-z2-7]{14}$/', $value ) ) {
						return $value;
					}
					if ( $value === '' ) {
						return '';
					}
					add_settings_error(
						self::OPT_ACCOUNT_ID,
						'magellan_bad_id',
						'Account ID format invalid. Expected mgln_(live|test|dev)_<14 base32 chars>.',
						'error'
					);
					return get_option( self::OPT_ACCOUNT_ID, '' );
				},
				'default'           => '',
			]
		);
	}

	public static function maybe_notice() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id === 'woocommerce_page_magellan' ) {
			return;
		}
		if ( ! self::is_configured() && current_user_can( 'manage_options' ) ) {
			$url = admin_url( 'admin.php?page=magellan' );
			echo '<div class="notice notice-info is-dismissible"><p><strong>Magellan</strong> &mdash; enter your Account ID to activate the verified attribution pixel. <a href="' . esc_url( $url ) . '">Set it up &rarr;</a></p></div>';
		}
	}

	// -------------------------------------------------------------
	// Settings page render — minimal, one field
	// -------------------------------------------------------------

	public static function render_page() {
		$account_id   = self::get_account_id();
		$has_secret   = self::get_signing_secret() !== '';
		$health       = (array) get_option( self::OPT_HEALTH_DATA, [] );
		$conflicts    = isset( $health['conflicts'] ) && is_array( $health['conflicts'] ) ? $health['conflicts'] : [];
		$last_event   = (int) get_option( self::OPT_LAST_EVENT, 0 );
		$last_error   = (array) get_option( self::OPT_LAST_ERROR, [] );
		$configured_at = (int) get_option( self::OPT_CONFIGURED_AT, 0 );
		?>
		<div class="wrap">
			<h1 style="display:flex;align-items:center;gap:10px;">
				<svg width="22" height="22" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
					<path d="M3 16 L7.5 6 L10 10 L12.5 6 L17 16" stroke="#0d1f3c" stroke-width="2" stroke-linecap="square" stroke-linejoin="miter" fill="none"/>
					<line x1="10" y1="10" x2="10" y2="16" stroke="#0d1f3c" stroke-width="1.5"/>
					<line x1="8" y1="13" x2="12" y2="13" stroke="#0d1f3c" stroke-width="1.5"/>
				</svg>
				Magellan for WooCommerce
			</h1>

			<?php settings_errors(); ?>

			<?php if ( ! self::is_configured() ) : ?>
				<div class="notice notice-warning inline">
					<p>
						Enter your Magellan Account ID to activate the verified attribution pixel.
						<a href="https://app.magellan.app/settings/connections" target="_blank" rel="noopener">Find it in Magellan &rarr; Settings &rarr; Connections</a>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $conflicts ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong>Tracking conflict detected:</strong>
						<?php echo esc_html( $conflicts[0]['plugin'] ?? 'Unknown plugin' ); ?>
						&mdash;
						<?php echo esc_html( $conflicts[0]['note'] ?? 'See Magellan dashboard.' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<?php if ( ! empty( $last_error['message'] ) ) : ?>
				<div class="notice notice-error inline">
					<p>
						<strong>Last error:</strong>
						<?php echo esc_html( $last_error['message'] ); ?>
						(<?php echo esc_html( human_time_diff( (int) $last_error['time'], time() ) ); ?> ago)
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'magellan_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="magellan_account_id">Magellan Account ID</label>
						</th>
						<td>
							<input
								type="text"
								id="magellan_account_id"
								name="<?php echo esc_attr( self::OPT_ACCOUNT_ID ); ?>"
								value="<?php echo esc_attr( $account_id ); ?>"
								class="regular-text code"
								placeholder="mgln_live_xxxxxxxxxxxxxx"
								autocomplete="off"
								spellcheck="false"
							/>
							<p class="description">
								24-character identifier. Find this in
								<a href="https://app.magellan.app/settings/connections" target="_blank" rel="noopener">Magellan &rarr; Settings &rarr; Connections</a>.
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save Account ID' ); ?>
			</form>

			<h2>Status</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Pixel</th>
					<td>
						<?php if ( self::is_configured() ) : ?>
							<span style="color:#057a55;font-weight:600;">&#10003; Active</span>
						<?php elseif ( $account_id !== '' ) : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; Account ID set, signing secret missing &mdash; reconnect from Magellan dashboard</span>
						<?php else : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; Not configured</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( $configured_at ) : ?>
				<tr>
					<th scope="row">Configured</th>
					<td><?php echo esc_html( human_time_diff( $configured_at, time() ) ); ?> ago</td>
				</tr>
				<?php endif; ?>
				<?php if ( $last_event ) : ?>
				<tr>
					<th scope="row">Last verified event sent</th>
					<td><?php echo esc_html( human_time_diff( $last_event, time() ) ); ?> ago</td>
				</tr>
				<?php endif; ?>
				<tr>
					<th scope="row">Action Scheduler</th>
					<td>
						<?php if ( function_exists( 'as_schedule_single_action' ) ) : ?>
							<span style="color:#057a55;font-weight:600;">&#10003; Available</span>
						<?php else : ?>
							<span style="color:#b45309;font-weight:600;">&#9888; Not available &mdash; using WP-Cron fallback</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">HPOS</th>
					<td>
						<?php
						$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
							&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
						echo $hpos_enabled
							? '<span style="color:#057a55;font-weight:600;">&#10003; Enabled</span>'
							: '<span>Disabled</span>';
						?>
					</td>
				</tr>
				<?php if ( ! empty( $health['plugin_status']['events_sent_24h'] ) ) : ?>
				<tr>
					<th scope="row">Events sent (24h)</th>
					<td><?php echo (int) $health['plugin_status']['events_sent_24h']; ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<p class="description" style="margin-top:24px;color:#666;">
				All ad-platform credentials (Meta, Google, TikTok, Klaviyo) live in Magellan, not in WordPress. This plugin sends only verified order events.
			</p>
		</div>
		<?php
	}
}
