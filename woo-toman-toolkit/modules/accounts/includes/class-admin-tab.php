<?php
/**
 * Accounts settings tab (WooCommerce > Toman Toolkit > Customer accounts).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Admin_Tab {

	const TAB = 'accounts';

	public static function init() {
		add_action( 'admin_post_wtt_acc_save', array( __CLASS__, 'handle_save' ) );
	}

	private static function tab_url() {
		return admin_url( 'admin.php?page=wtt-toolkit&tab=' . self::TAB );
	}

	private static function check_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'woo-toman-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	public static function handle_save() {
		self::check_access();
		check_admin_referer( 'wtt_acc_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in WTT_ACC_Settings::sanitize().
		$raw = isset( $_POST['wtt_acc'] ) && is_array( $_POST['wtt_acc'] ) ? wp_unslash( $_POST['wtt_acc'] ) : array();
		WTT_ACC_Settings::save( WTT_ACC_Settings::sanitize( $raw ) );
		set_transient( 'wtt_acc_flash_' . get_current_user_id(), __( 'Settings saved.', 'woo-toman-toolkit' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	private static function checkbox( $name, $values, $label, $desc = '' ) {
		echo '<label><input type="checkbox" name="wtt_acc[' . esc_attr( $name ) . ']" value="1" ' . checked( ! empty( $values[ $name ] ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public static function render() {
		self::check_access();

		$flash_key = 'wtt_acc_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $flash ) . '</p></div>';
		}

		$v = WTT_ACC_Settings::all();
		echo '<h2>' . esc_html__( 'Customer accounts', 'woo-toman-toolkit' ) . '</h2>';

		// SMS dependency status.
		echo '<h3>' . esc_html__( 'SMS dependency', 'woo-toman-toolkit' ) . '</h3>';
		if ( ! WTT_Toolkit::is_enabled( 'sms' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The SMS module is disabled, so login with a code is hidden on My Account. Automatic accounts still work.', 'woo-toman-toolkit' ) . '</p></div>';
		} elseif ( ! WTT_SMS_Settings::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The SMS module is not configured (API key and sender number), so login with a code is hidden on My Account.', 'woo-toman-toolkit' ) . '</p></div>';
		} elseif ( ! WTT_SMS_Events::is_active( 'otp' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The "Login code (OTP)" SMS event is not enabled or has no pattern code yet, so login with a code is hidden on My Account.', 'woo-toman-toolkit' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The SMS module is ready to send login codes.', 'woo-toman-toolkit' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="wtt_acc_save" />';
		wp_nonce_field( 'wtt_acc_save' );

		echo '<h3>' . esc_html__( 'Automatic accounts at checkout', 'woo-toman-toolkit' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Create accounts', 'woo-toman-toolkit' ) . '</th><td>';
		self::checkbox( 'auto_account', $v, __( 'Create or match a customer account for guest orders', 'woo-toman-toolkit' ), __( 'The account is found by the billing mobile, then by the billing email, otherwise it is created and the customer is logged in on the order-received page. Accounts with editor/shop manager/admin rights are never used.', 'woo-toman-toolkit' ) );
		echo '<br />';
		self::checkbox( 'only_preinvoice', $v, __( 'Only for pre-invoice orders', 'woo-toman-toolkit' ) );
		echo '<br />';
		self::checkbox( 'welcome_email', $v, __( 'Send the WooCommerce "new account" email', 'woo-toman-toolkit' ), __( 'Off by default: the customer logs in with a code, no password email is needed.', 'woo-toman-toolkit' ) );
		echo '</td></tr></tbody></table>';

		echo '<h3>' . esc_html__( 'Login with a code (OTP)', 'woo-toman-toolkit' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Options', 'woo-toman-toolkit' ) . '</th><td>';
		self::checkbox( 'otp_enabled', $v, __( 'Show the mobile login form on My Account', 'woo-toman-toolkit' ) );
		echo '<br />';
		self::checkbox( 'otp_register', $v, __( 'Allow registration via OTP', 'woo-toman-toolkit' ), __( 'When the mobile has no account, still send a code and create the account after it is verified.', 'woo-toman-toolkit' ) );
		echo '<br />';
		self::checkbox( 'show_password', $v, __( 'Show the password login (behind a "Log in with password" link)', 'woo-toman-toolkit' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="wtt_acc_code_length">' . esc_html__( 'Code length', 'woo-toman-toolkit' ) . '</label></th><td><select id="wtt_acc_code_length" name="wtt_acc[code_length]">';
		foreach ( array( 5, 6 ) as $n ) {
			echo '<option value="' . esc_attr( (string) $n ) . '" ' . selected( (int) $v['code_length'], $n, false ) . '>' . esc_html( (string) $n ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wtt_acc_expiry">' . esc_html__( 'Code expiry (minutes)', 'woo-toman-toolkit' ) . '</label></th><td><input type="number" min="1" max="30" class="small-text" id="wtt_acc_expiry" name="wtt_acc[expiry]" value="' . esc_attr( (string) $v['expiry'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="wtt_acc_cooldown">' . esc_html__( 'Resend cooldown (seconds)', 'woo-toman-toolkit' ) . '</label></th><td><input type="number" min="30" max="600" class="small-text" id="wtt_acc_cooldown" name="wtt_acc[cooldown]" value="' . esc_attr( (string) $v['cooldown'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Also fixed: 5 wrong attempts per code.', 'woo-toman-toolkit' ) . '</p></td></tr>';
		$num = array(
			'mobile_per_hour'  => array( __( 'Codes per hour per mobile', 'woo-toman-toolkit' ), 100 ),
			'ip_send_per_hour' => array( __( 'Codes per hour per IP address', 'woo-toman-toolkit' ), 1000 ),
			'ip_fail_per_hour' => array( __( 'Failed attempts per hour per IP address', 'woo-toman-toolkit' ), 1000 ),
		);
		foreach ( $num as $name => $def ) {
			echo '<tr><th scope="row"><label for="wtt_acc_' . esc_attr( $name ) . '">' . esc_html( $def[0] ) . '</label></th><td><input type="number" min="1" max="' . esc_attr( (string) $def[1] ) . '" class="small-text" id="wtt_acc_' . esc_attr( $name ) . '" name="wtt_acc[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $v[ $name ] ) . '" /></td></tr>';
		}
		$sources = array(
			'remote_addr'      => __( 'REMOTE_ADDR (default, no proxy)', 'woo-toman-toolkit' ),
			'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)',
			'x-forwarded-for'  => __( 'X-Forwarded-For (leftmost public IP)', 'woo-toman-toolkit' ),
			'x-real-ip'        => 'X-Real-IP',
			'ar-real-ip'       => 'AR-Real-IP (ArvanCloud)',
			'custom'           => __( 'Custom header', 'woo-toman-toolkit' ),
		);
		echo '<tr><th scope="row"><label for="wtt_acc_ip_source">' . esc_html__( 'Client IP source', 'woo-toman-toolkit' ) . '</label></th><td><select id="wtt_acc_ip_source" name="wtt_acc[ip_source]">';
		foreach ( $sources as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['ip_source'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="text" dir="ltr" class="regular-text" id="wtt_acc_ip_custom_header" name="wtt_acc[ip_custom_header]" value="' . esc_attr( (string) $v['ip_custom_header'] ) . '" placeholder="X-My-Client-IP" aria-label="' . esc_attr__( 'Custom header name', 'woo-toman-toolkit' ) . '" />';
		echo '<p class="description">' . esc_html__( 'The address used for the per-IP limits. Only pick a header if the site is really behind that CDN/proxy: otherwise visitors can fake the header and bypass the limits. If the header is missing or invalid, REMOTE_ADDR is used.', 'woo-toman-toolkit' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'woo-toman-toolkit' ) );
		echo '</form>';

		self::render_diagnostics();

		self::render_log();
	}

	/**
	 * Connection diagnostics: what the server sees in this (admin) request.
	 */
	private static function render_diagnostics() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		echo '<h3>' . esc_html__( 'Connection diagnostics', 'woo-toman-toolkit' ) . '</h3>';
		echo '<p class="description" style="max-width:900px">' . esc_html__( 'If REMOTE_ADDR is a CDN or hosting address (the same for every visitor), choose the header above that shows your real IP address. Only pick a header if the site is really behind that CDN, otherwise it can be spoofed.', 'woo-toman-toolkit' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><th scope="row">REMOTE_ADDR</th><td dir="ltr"><code>' . esc_html( '' !== $remote ? $remote : '-' ) . '</code></td></tr>';
		foreach ( array( 'CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'AR-Real-IP', 'True-Client-IP' ) as $name ) {
			$val = WTT_ACC_Limiter::header_value( $name );
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td dir="ltr"><code>' . esc_html( '' !== $val ? substr( $val, 0, 300 ) : '-' ) . '</code></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'IP used by the plugin', 'woo-toman-toolkit' ) . '</th><td dir="ltr"><strong><code>' . esc_html( WTT_ACC_Limiter::client_ip() ) . '</code></strong></td></tr>';
		echo '</tbody></table>';
	}

	private static function render_log() {
		$log = WTT_Logger::all( 'accounts' );
		echo '<h3>' . esc_html__( 'Log', 'woo-toman-toolkit' ) . '</h3>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'woo-toman-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( array_slice( $log, 0, WTT_Logger::MAX ) as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td><td>' . esc_html( WTT_Logger::level_label( $entry['level'] ) ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
