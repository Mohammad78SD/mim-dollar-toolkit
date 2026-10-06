<?php
/**
 * USD Pricing tab of the toolkit admin page (WooCommerce > Toman Toolkit).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Admin_Page {

	const SLUG  = 'wtt-toolkit';
	const TAB   = 'usd-pricing';
	const GROUP = 'wtt_usd_settings_group';

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_' . self::GROUP, array( __CLASS__, 'capability' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );

		$actions = array( 'fetch_now', 'sync_now', 'test', 'accept_rate', 'clear_log', 'dismiss' );
		foreach ( $actions as $action ) {
			add_action( 'admin_post_wtt_usd_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
	}

	public static function capability() {
		return 'manage_woocommerce';
	}

	public static function register_settings() {
		register_setting(
			self::GROUP,
			WTT_USD_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'WTT_USD_Settings', 'sanitize' ),
				'default'           => WTT_USD_Settings::defaults(),
			)
		);
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		if ( ! isset( $_GET['tab'] ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		wp_enqueue_style( 'wtt-usd-admin', WTT_USD_URL . 'assets/admin.css', array(), WTT_USD_VERSION );
	}

	/* ---------------------------------------------------------------- helpers */

	private static function page_url() {
		return admin_url( 'admin.php?page=' . self::SLUG . '&tab=' . self::TAB );
	}

	private static function flash( $type, $message ) {
		set_transient( 'wtt_usd_flash_' . get_current_user_id(), array( $type, $message ), 5 * MINUTE_IN_SECONDS );
	}

	private static function redirect() {
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * Capability + nonce check for admin-post handlers.
	 *
	 * @param string $action Action / nonce name.
	 */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-toman-toolkit' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	private static function fmt_rate( $rate ) {
		$rate = (float) $rate;
		if ( $rate <= 0 ) {
			return '&mdash;';
		}
		return esc_html( number_format_i18n( $rate, $rate >= 1000 ? 0 : 2 ) );
	}

	private static function fmt_time( $timestamp ) {
		$timestamp = (int) $timestamp;
		if ( ! $timestamp ) {
			return esc_html__( 'Never', 'woo-toman-toolkit' );
		}
		$date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp );
		/* translators: %s: human readable time difference */
		$ago = sprintf( __( '%s ago', 'woo-toman-toolkit' ), human_time_diff( $timestamp, time() ) );
		return esc_html( $date . ' (' . $ago . ')' );
	}

	private static function action_form( $action, $label, $primary = false ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wtt_usd_' . esc_attr( $action ) . '" />';
		wp_nonce_field( 'wtt_usd_' . $action );
		submit_button( $label, $primary ? 'primary' : 'secondary', 'submit', false );
		echo '</form>';
	}

	/* --------------------------------------------------------------- handlers */

	public static function handle_fetch_now() {
		self::guard( 'wtt_usd_fetch_now' );
		$result = WTT_USD_Rate_Manager::fetch_and_update();
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
		} elseif ( (float) $result['pending_rejected_rate'] > 0 ) {
			self::flash( 'warning', __( 'The new rate differs too much from the previous one and was held back. Review it below.', 'woo-toman-toolkit' ) );
		} else {
			self::flash( 'success', __( 'Rate fetched.', 'woo-toman-toolkit' ) );
		}
		self::redirect();
	}

	public static function handle_sync_now() {
		self::guard( 'wtt_usd_sync_now' );
		if ( WTT_USD_Price_Sync::start_full_sync() ) {
			self::flash( 'success', __( 'Price sync started. Prices are updated in the background.', 'woo-toman-toolkit' ) );
		} else {
			self::flash( 'error', __( 'Cannot sync prices: no exchange rate is available yet.', 'woo-toman-toolkit' ) );
		}
		self::redirect();
	}

	public static function handle_test() {
		self::guard( 'wtt_usd_test' );
		set_transient( 'wtt_usd_test_' . get_current_user_id(), WTT_USD_Rate_Manager::query_providers(), 5 * MINUTE_IN_SECONDS );
		self::redirect();
	}

	public static function handle_accept_rate() {
		self::guard( 'wtt_usd_accept_rate' );
		if ( WTT_USD_Rate_Manager::accept_pending() ) {
			self::flash( 'success', __( 'Rate accepted; prices are being updated.', 'woo-toman-toolkit' ) );
		} else {
			self::flash( 'error', __( 'There is no pending rate to accept.', 'woo-toman-toolkit' ) );
		}
		self::redirect();
	}

	public static function handle_clear_log() {
		self::guard( 'wtt_usd_clear_log' );
		WTT_USD_Logger::clear();
		self::flash( 'success', __( 'Log cleared.', 'woo-toman-toolkit' ) );
		self::redirect();
	}

	public static function handle_dismiss() {
		self::guard( 'wtt_usd_dismiss' );
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$state = WTT_USD_Rate_Manager::get_state();
		$sig   = array(
			'fail'    => (string) $state['fail_since'],
			'pending' => (string) $state['pending_rejected_rate'],
		);
		if ( isset( $sig[ $type ] ) ) {
			$dismissed = get_option( 'wtt_usd_dismissed', array() );
			if ( ! is_array( $dismissed ) ) {
				$dismissed = array();
			}
			$dismissed[ $type ] = $sig[ $type ];
			update_option( 'wtt_usd_dismissed', $dismissed, false );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : self::page_url() );
		exit;
	}

	/* ---------------------------------------------------------------- notices */

	public static function notices() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$state     = WTT_USD_Rate_Manager::get_state();
		$dismissed = get_option( 'wtt_usd_dismissed', array() );
		if ( ! is_array( $dismissed ) ) {
			$dismissed = array();
		}

		if ( (int) $state['fail_count'] > 3 && ( ! isset( $dismissed['fail'] ) || (string) $state['fail_since'] !== (string) $dismissed['fail'] ) ) {
			echo '<div class="notice notice-error wtt-usd-notice"><p><strong>' . esc_html__( 'USD Pricing:', 'woo-toman-toolkit' ) . '</strong> ';
			/* translators: %d: number of consecutive failures */
			echo esc_html( sprintf( __( 'fetching the exchange rate has failed %d times in a row. The last good rate is still in use.', 'woo-toman-toolkit' ), (int) $state['fail_count'] ) );
			echo ' <a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Open settings', 'woo-toman-toolkit' ) . '</a> | ';
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wtt_usd_dismiss&type=fail' ), 'wtt_usd_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'woo-toman-toolkit' ) . '</a></p></div>';
		}

		$pending = (float) $state['pending_rejected_rate'];
		if ( $pending > 0 && ( ! isset( $dismissed['pending'] ) || (string) $state['pending_rejected_rate'] !== (string) $dismissed['pending'] ) ) {
			echo '<div class="notice notice-warning wtt-usd-notice"><p><strong>' . esc_html__( 'USD Pricing:', 'woo-toman-toolkit' ) . '</strong> ';
			/* translators: 1: new rate, 2: current rate */
			echo esc_html( sprintf( __( 'a new rate of %1$s was rejected because it differs too much from the current rate (%2$s).', 'woo-toman-toolkit' ), number_format_i18n( $pending, 0 ), number_format_i18n( (float) $state['raw_rate'], 0 ) ) );
			echo '</p><p>';
			self::action_form( 'accept_rate', __( 'Accept this rate anyway', 'woo-toman-toolkit' ), true );
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wtt_usd_dismiss&type=pending' ), 'wtt_usd_dismiss' ) ) . '">' . esc_html__( 'Dismiss', 'woo-toman-toolkit' ) . '</a></p></div>';
		}
	}

	/* ------------------------------------------------------------------- page */

	/**
	 * Tab content. Capability is checked by the toolkit admin page; wrap/heading are rendered there.
	 */
	public static function render_tab() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'woo-toman-toolkit' ), '', array( 'response' => 403 ) );
		}

		$flash_key = 'wtt_usd_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_array( $flash ) && 2 === count( $flash ) ) {
			delete_transient( $flash_key );
			$class = in_array( $flash[0], array( 'success', 'error', 'warning' ), true ) ? $flash[0] : 'info';
			echo '<div class="notice notice-' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $flash[1] ) . '</p></div>';
		}
		settings_errors();

		self::render_status();
		self::render_test_results();
		self::render_settings_form();
		self::render_log();
	}

	private static function render_status() {
		$state     = WTT_USD_Rate_Manager::get_state();
		$settings  = WTT_USD_Settings::all();
		$next      = wp_next_scheduled( WTT_USD_Plugin::CRON_HOOK );
		$providers = WTT_USD_Rate_Providers::get_all();

		echo '<h2>' . esc_html__( 'Status', 'woo-toman-toolkit' ) . '</h2>';
		echo '<table class="widefat striped wtt-usd-status" style="max-width:800px"><tbody>';

		self::row( __( 'Raw rate (Toman per USDT)', 'woo-toman-toolkit' ), self::fmt_rate( $state['raw_rate'] ) );
		$eff = self::fmt_rate( $state['effective_rate'] );
		if ( ! empty( $settings['manual_rate_enabled'] ) ) {
			$eff .= ' <em>' . esc_html__( '(manual override)', 'woo-toman-toolkit' ) . '</em>';
		}
		self::row( __( 'Effective rate', 'woo-toman-toolkit' ), $eff );

		$sources = '';
		if ( empty( $state['sources'] ) ) {
			$sources = '&mdash;';
		} else {
			foreach ( $state['sources'] as $name => $value ) {
				$label    = isset( $providers[ $name ] ) ? $providers[ $name ]->get_label() : $name;
				$sources .= '<div><strong>' . esc_html( $label ) . ':</strong> ' . ( is_numeric( $value ) ? self::fmt_rate( $value ) : '<span class="wtt-usd-level-error">' . esc_html( (string) $value ) . '</span>' ) . '</div>';
			}
		}
		self::row( __( 'Last fetch results', 'woo-toman-toolkit' ), $sources );

		self::row( __( 'Last rate update', 'woo-toman-toolkit' ), self::fmt_time( $state['fetched_at'] ) );
		self::row( __( 'Last fetch attempt', 'woo-toman-toolkit' ), self::fmt_time( $state['last_attempt_at'] ) );
		self::row( __( 'Last price sync', 'woo-toman-toolkit' ), self::fmt_time( $state['last_synced_at'] ) . ' &middot; ' . self::fmt_rate( $state['last_synced_rate'] ) );
		self::row( __( 'Store currency', 'woo-toman-toolkit' ), esc_html( get_woocommerce_currency() ) . ( 'IRR' === get_woocommerce_currency() ? ' <em>' . esc_html__( '(Toman prices are multiplied by 10)', 'woo-toman-toolkit' ) . '</em>' : '' ) );
		self::row( __( 'Products with USD price', 'woo-toman-toolkit' ), esc_html( number_format_i18n( WTT_USD_Price_Sync::count_usd_products() ) ) );
		$next_html = esc_html__( 'Not scheduled', 'woo-toman-toolkit' );
		if ( $next ) {
			$next_html = esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next ) );
			$now       = time();
			if ( $next > $now ) {
				/* translators: %s: human readable time difference */
				$next_html .= ' ' . esc_html( sprintf( __( '(in %s)', 'woo-toman-toolkit' ), human_time_diff( $now, $next ) ) );
			} elseif ( $now - $next > 5 * MINUTE_IN_SECONDS ) {
				/* translators: %s: human readable time difference */
				$next_html .= ' <span class="wtt-usd-level-error">' . esc_html( sprintf( __( 'overdue by %s — WP-Cron does not seem to be running; add the cPanel cron job below', 'woo-toman-toolkit' ), human_time_diff( $next, $now ) ) ) . '</span>';
			}
		}
		self::row( __( 'Next scheduled fetch', 'woo-toman-toolkit' ), $next_html );

		if ( (float) $state['pending_rejected_rate'] > 0 ) {
			self::row( __( 'Pending rejected rate', 'woo-toman-toolkit' ), self::fmt_rate( $state['pending_rejected_rate'] ) );
		}
		echo '</tbody></table>';

		echo '<p class="wtt-usd-actions">';
		self::action_form( 'fetch_now', __( 'Fetch rate now', 'woo-toman-toolkit' ), true );
		self::action_form( 'sync_now', __( 'Sync prices now', 'woo-toman-toolkit' ) );
		self::action_form( 'test', __( 'Test providers', 'woo-toman-toolkit' ) );
		if ( (float) $state['pending_rejected_rate'] > 0 ) {
			self::action_form( 'accept_rate', __( 'Accept this rate anyway', 'woo-toman-toolkit' ) );
		}
		echo '</p>';

		echo '<p class="description">';
		echo esc_html__( 'Tip: WP-Cron only runs when the site gets visits. For reliable updates, add this cron job in cPanel (every 5 minutes):', 'woo-toman-toolkit' );
		echo '<br /><code>' . esc_html( 'wget -q -O - ' . site_url( 'wp-cron.php?doing_wp_cron' ) . ' >/dev/null 2>&1' ) . '</code></p>';
	}

	/**
	 * @param string $label Label (plain text).
	 * @param string $html  Pre-escaped HTML.
	 */
	private static function row( $label, $html ) {
		echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $html ) . '</td></tr>';
	}

	private static function render_test_results() {
		$key     = 'wtt_usd_test_' . get_current_user_id();
		$results = get_transient( $key );
		if ( ! is_array( $results ) ) {
			return;
		}
		delete_transient( $key );
		$providers = WTT_USD_Rate_Providers::get_all();

		echo '<h2>' . esc_html__( 'Provider test (not saved)', 'woo-toman-toolkit' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:800px"><tbody>';
		foreach ( $results as $name => $value ) {
			$label = isset( $providers[ $name ] ) ? $providers[ $name ]->get_label() : $name;
			$html  = is_numeric( $value ) ? self::fmt_rate( $value ) : '<span class="wtt-usd-level-error">' . esc_html( (string) $value ) . '</span>';
			echo '<tr><th scope="row" style="width:260px">' . esc_html( $label ) . '</th><td>' . wp_kses_post( $html ) . '</td></tr>';
		}
		if ( empty( $results ) ) {
			echo '<tr><td>' . esc_html__( 'No providers enabled.', 'woo-toman-toolkit' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_settings_form() {
		$s        = WTT_USD_Settings::all();
		$opt      = WTT_USD_Settings::OPTION;
		$provider = WTT_USD_Rate_Providers::get_all();

		echo '<h2>' . esc_html__( 'Settings', 'woo-toman-toolkit' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::GROUP );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Providers.
		echo '<tr><th scope="row">' . esc_html__( 'Rate providers', 'woo-toman-toolkit' ) . '</th><td>';
		foreach ( $provider as $name => $p ) {
			echo '<label style="display:block"><input type="checkbox" name="' . esc_attr( $opt ) . '[providers][]" value="' . esc_attr( $name ) . '" ' . checked( in_array( $name, (array) $s['providers'], true ), true, false ) . ' /> ' . esc_html( $p->get_label() ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'The median of all successful providers is used.', 'woo-toman-toolkit' ) . '</p></td></tr>';

		// Interval.
		echo '<tr><th scope="row"><label for="wtt-interval">' . esc_html__( 'Update interval', 'woo-toman-toolkit' ) . '</label></th><td>';
		echo '<select id="wtt-interval" name="' . esc_attr( $opt ) . '[update_interval_minutes]">';
		foreach ( WTT_USD_Settings::interval_choices() as $m ) {
			/* translators: %d: minutes */
			echo '<option value="' . esc_attr( $m ) . '" ' . selected( (int) $s['update_interval_minutes'], $m, false ) . '>' . esc_html( sprintf( __( '%d minutes', 'woo-toman-toolkit' ), $m ) ) . '</option>';
		}
		echo '</select></td></tr>';

		// Markup.
		echo '<tr><th scope="row"><label for="wtt-markup">' . esc_html__( 'Markup (%)', 'woo-toman-toolkit' ) . '</label></th><td>';
		echo '<input type="number" id="wtt-markup" min="0" max="100" step="0.01" name="' . esc_attr( $opt ) . '[markup_percent]" value="' . esc_attr( $s['markup_percent'] ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Added on top of the raw rate (0-100).', 'woo-toman-toolkit' ) . '</p></td></tr>';

		// Rate rounding.
		echo '<tr><th scope="row"><label for="wtt-rate-round">' . esc_html__( 'Rate rounding step (Toman)', 'woo-toman-toolkit' ) . '</label></th><td>';
		echo '<select id="wtt-rate-round" name="' . esc_attr( $opt ) . '[rate_round_step]">';
		foreach ( WTT_USD_Settings::rate_round_choices() as $v ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( (int) $s['rate_round_step'], $v, false ) . '>' . esc_html( number_format_i18n( $v ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'The rate is rounded up to a multiple of this value.', 'woo-toman-toolkit' ) . '</p></td></tr>';

		// Price rounding.
		echo '<tr><th scope="row"><label for="wtt-price-round">' . esc_html__( 'Price rounding step (Toman)', 'woo-toman-toolkit' ) . '</label></th><td>';
		echo '<select id="wtt-price-round" name="' . esc_attr( $opt ) . '[price_round_step]">';
		foreach ( WTT_USD_Settings::price_round_choices() as $v ) {
			echo '<option value="' . esc_attr( $v ) . '" ' . selected( (int) $s['price_round_step'], $v, false ) . '>' . esc_html( number_format_i18n( $v ) ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Final product prices are rounded up to a multiple of this value (1 = no extra rounding).', 'woo-toman-toolkit' ) . '</p></td></tr>';

		// Max change.
		echo '<tr><th scope="row"><label for="wtt-maxchange">' . esc_html__( 'Max rate change (%)', 'woo-toman-toolkit' ) . '</label></th><td>';
		echo '<input type="number" id="wtt-maxchange" min="0.1" max="100" step="0.1" name="' . esc_attr( $opt ) . '[max_change_percent]" value="' . esc_attr( $s['max_change_percent'] ) . '" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'A fetched rate that differs from the last good rate by more than this is held back until you accept it.', 'woo-toman-toolkit' ) . '</p></td></tr>';

		// Manual override.
		echo '<tr><th scope="row">' . esc_html__( 'Manual rate override', 'woo-toman-toolkit' ) . '</th><td>';
		echo '<label><input type="checkbox" name="' . esc_attr( $opt ) . '[manual_rate_enabled]" value="1" ' . checked( ! empty( $s['manual_rate_enabled'] ), true, false ) . ' /> ' . esc_html__( 'Use the manual rate below (no markup or rounding is applied)', 'woo-toman-toolkit' ) . '</label><br />';
		echo '<input type="number" min="0" step="1" name="' . esc_attr( $opt ) . '[manual_rate]" value="' . esc_attr( $s['manual_rate'] ) . '" class="regular-text" /></td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'woo-toman-toolkit' ) );
		echo '</form>';
	}

	private static function render_log() {
		$log = WTT_USD_Logger::all();
		echo '<h2>' . esc_html__( 'Log', 'woo-toman-toolkit' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th style="width:200px">' . esc_html__( 'Time', 'woo-toman-toolkit' ) . '</th><th style="width:90px">' . esc_html__( 'Level', 'woo-toman-toolkit' ) . '</th><th>' . esc_html__( 'Message', 'woo-toman-toolkit' ) . '</th></tr></thead><tbody>';
		if ( empty( $log ) ) {
			echo '<tr><td colspan="3">' . esc_html__( 'No log entries yet.', 'woo-toman-toolkit' ) . '</td></tr>';
		}
		foreach ( $log as $entry ) {
			$level = isset( $entry['level'] ) ? sanitize_key( $entry['level'] ) : 'info';
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td>';
			echo '<td class="wtt-usd-level-' . esc_attr( $level ) . '">' . esc_html( WTT_Logger::level_label( $level ) ) . '</td>';
			echo '<td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
		if ( ! empty( $log ) ) {
			echo '<p>';
			self::action_form( 'clear_log', __( 'Clear log', 'woo-toman-toolkit' ) );
			echo '</p>';
		}
	}
}
