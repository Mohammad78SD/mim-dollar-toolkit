<?php
/**
 * Accounts settings tab (WooCommerce > MiM Dollar Toolkit > Customer accounts).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_ACC_Admin_Tab {

	const TAB = 'accounts';

	public static function init() {
		add_action( 'admin_post_mdt_acc_save', array( __CLASS__, 'handle_save' ) );
	}

	private static function tab_url() {
		return admin_url( 'admin.php?page=mdt-toolkit&tab=' . self::TAB );
	}

	private static function check_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	public static function handle_save() {
		self::check_access();
		check_admin_referer( 'mdt_acc_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in MDT_ACC_Settings::sanitize().
		$raw = isset( $_POST['mdt_acc'] ) && is_array( $_POST['mdt_acc'] ) ? wp_unslash( $_POST['mdt_acc'] ) : array();
		MDT_ACC_Settings::save( MDT_ACC_Settings::sanitize( $raw ) );
		set_transient( 'mdt_acc_flash_' . get_current_user_id(), __( 'Settings saved.', 'mim-dollar-toolkit' ), 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	private static function checkbox( $name, $values, $label, $desc = '' ) {
		echo '<label><input type="checkbox" name="mdt_acc[' . esc_attr( $name ) . ']" value="1" ' . checked( ! empty( $values[ $name ] ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		if ( '' !== $desc ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
	}

	public static function render() {
		self::check_access();

		$flash_key = 'mdt_acc_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_string( $flash ) && '' !== $flash ) {
			delete_transient( $flash_key );
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $flash ) . '</p></div>';
		}

		$v = MDT_ACC_Settings::all();
		echo '<h2>' . esc_html__( 'Customer accounts', 'mim-dollar-toolkit' ) . '</h2>';

		// SMS dependency status.
		echo '<h3>' . esc_html__( 'SMS dependency', 'mim-dollar-toolkit' ) . '</h3>';
		if ( ! MDT_Toolkit::is_enabled( 'sms' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The SMS module is disabled, so login with a code is hidden on My Account. Automatic accounts still work.', 'mim-dollar-toolkit' ) . '</p></div>';
		} elseif ( ! MDT_SMS_Settings::is_configured() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The SMS module is not configured (API key and sender number), so login with a code is hidden on My Account.', 'mim-dollar-toolkit' ) . '</p></div>';
		} elseif ( ! MDT_SMS_Events::is_active( 'otp' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The "Login code (OTP)" SMS event is not enabled or has no pattern code yet, so login with a code is hidden on My Account.', 'mim-dollar-toolkit' ) . '</p></div>';
		} else {
			echo '<div class="notice notice-success inline"><p>' . esc_html__( 'The SMS module is ready to send login codes.', 'mim-dollar-toolkit' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_acc_save" />';
		wp_nonce_field( 'mdt_acc_save' );

		echo '<h3>' . esc_html__( 'Automatic accounts at checkout', 'mim-dollar-toolkit' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Create accounts', 'mim-dollar-toolkit' ) . '</th><td>';
		self::checkbox( 'auto_account', $v, __( 'Create or match a customer account for guest orders', 'mim-dollar-toolkit' ), __( 'The account is found by the billing mobile, then by the billing email, otherwise it is created and the customer is logged in on the order-received page. Accounts with editor/shop manager/admin rights are never used.', 'mim-dollar-toolkit' ) );
		echo '<br />';
		self::checkbox( 'only_preinvoice', $v, __( 'Only for pre-invoice orders', 'mim-dollar-toolkit' ) );
		echo '<br />';
		self::checkbox( 'welcome_email', $v, __( 'Send the WooCommerce "new account" email', 'mim-dollar-toolkit' ), __( 'Off by default: the customer logs in with a code, no password email is needed.', 'mim-dollar-toolkit' ) );
		echo '</td></tr></tbody></table>';

		echo '<h3>' . esc_html__( 'Login with a code (OTP)', 'mim-dollar-toolkit' ) . '</h3><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Options', 'mim-dollar-toolkit' ) . '</th><td>';
		self::checkbox( 'otp_enabled', $v, __( 'Show the mobile login form on My Account', 'mim-dollar-toolkit' ) );
		echo '<br />';
		self::checkbox( 'otp_register', $v, __( 'Allow registration via OTP', 'mim-dollar-toolkit' ), __( 'When the mobile has no account, still send a code and create the account after it is verified.', 'mim-dollar-toolkit' ) );
		echo '<br />';
		self::checkbox( 'show_password', $v, __( 'Show the password login (behind a "Log in with password" link)', 'mim-dollar-toolkit' ) );
		echo '</td></tr>';
		echo '<tr><th scope="row"><label for="mdt_acc_code_length">' . esc_html__( 'Code length', 'mim-dollar-toolkit' ) . '</label></th><td><select id="mdt_acc_code_length" name="mdt_acc[code_length]">';
		foreach ( array( 5, 6 ) as $n ) {
			echo '<option value="' . esc_attr( (string) $n ) . '" ' . selected( (int) $v['code_length'], $n, false ) . '>' . esc_html( (string) $n ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="mdt_acc_expiry">' . esc_html__( 'Code expiry (minutes)', 'mim-dollar-toolkit' ) . '</label></th><td><input type="number" min="1" max="30" class="small-text" id="mdt_acc_expiry" name="mdt_acc[expiry]" value="' . esc_attr( (string) $v['expiry'] ) . '" /></td></tr>';
		echo '<tr><th scope="row"><label for="mdt_acc_cooldown">' . esc_html__( 'Resend cooldown (seconds)', 'mim-dollar-toolkit' ) . '</label></th><td><input type="number" min="30" max="600" class="small-text" id="mdt_acc_cooldown" name="mdt_acc[cooldown]" value="' . esc_attr( (string) $v['cooldown'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Also fixed: 5 wrong attempts per code.', 'mim-dollar-toolkit' ) . '</p></td></tr>';
		$num = array(
			'mobile_per_hour'  => array( __( 'Codes per hour per mobile', 'mim-dollar-toolkit' ), 100 ),
			'ip_send_per_hour' => array( __( 'Codes per hour per IP address', 'mim-dollar-toolkit' ), 1000 ),
			'ip_fail_per_hour' => array( __( 'Failed attempts per hour per IP address', 'mim-dollar-toolkit' ), 1000 ),
		);
		foreach ( $num as $name => $def ) {
			echo '<tr><th scope="row"><label for="mdt_acc_' . esc_attr( $name ) . '">' . esc_html( $def[0] ) . '</label></th><td><input type="number" min="1" max="' . esc_attr( (string) $def[1] ) . '" class="small-text" id="mdt_acc_' . esc_attr( $name ) . '" name="mdt_acc[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $v[ $name ] ) . '" /></td></tr>';
		}
		$sources = array(
			'remote_addr'      => __( 'REMOTE_ADDR (default, no proxy)', 'mim-dollar-toolkit' ),
			'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)',
			'x-forwarded-for'  => __( 'X-Forwarded-For (leftmost public IP)', 'mim-dollar-toolkit' ),
			'x-real-ip'        => 'X-Real-IP',
			'ar-real-ip'       => 'AR-Real-IP (ArvanCloud)',
			'custom'           => __( 'Custom header', 'mim-dollar-toolkit' ),
		);
		echo '<tr><th scope="row"><label for="mdt_acc_ip_source">' . esc_html__( 'Client IP source', 'mim-dollar-toolkit' ) . '</label></th><td><select id="mdt_acc_ip_source" name="mdt_acc[ip_source]">';
		foreach ( $sources as $key => $label ) {
			echo '<option value="' . esc_attr( $key ) . '" ' . selected( $v['ip_source'], $key, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="text" dir="ltr" class="regular-text" id="mdt_acc_ip_custom_header" name="mdt_acc[ip_custom_header]" value="' . esc_attr( (string) $v['ip_custom_header'] ) . '" placeholder="X-My-Client-IP" aria-label="' . esc_attr__( 'Custom header name', 'mim-dollar-toolkit' ) . '" />';
		echo '<p class="description">' . esc_html__( 'The address used for the per-IP limits. Only pick a header if the site is really behind that CDN/proxy: otherwise visitors can fake the header and bypass the limits. If the header is missing or invalid, REMOTE_ADDR is used.', 'mim-dollar-toolkit' ) . '</p></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'mim-dollar-toolkit' ) );
		echo '</form>';

		self::render_diagnostics();

		self::render_log();
	}

	/**
	 * Connection diagnostics: what the server sees in this (admin) request.
	 */
	private static function render_diagnostics() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		echo '<h3>' . esc_html__( 'Connection diagnostics', 'mim-dollar-toolkit' ) . '</h3>';
		echo '<p class="description" style="max-width:900px">' . esc_html__( 'If REMOTE_ADDR is a CDN or hosting address (the same for every visitor), choose the header above that shows your real IP address. Only pick a header if the site is really behind that CDN, otherwise it can be spoofed.', 'mim-dollar-toolkit' ) . '</p>';
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		echo '<tr><th scope="row">REMOTE_ADDR</th><td dir="ltr"><code>' . esc_html( '' !== $remote ? $remote : '-' ) . '</code></td></tr>';
		foreach ( array( 'CF-Connecting-IP', 'X-Forwarded-For', 'X-Real-IP', 'AR-Real-IP', 'True-Client-IP' ) as $name ) {
			$val = MDT_ACC_Limiter::header_value( $name );
			echo '<tr><th scope="row">' . esc_html( $name ) . '</th><td dir="ltr"><code>' . esc_html( '' !== $val ? substr( $val, 0, 300 ) : '-' ) . '</code></td></tr>';
		}
		echo '<tr><th scope="row">' . esc_html__( 'IP used by the plugin', 'mim-dollar-toolkit' ) . '</th><td dir="ltr"><strong><code>' . esc_html( MDT_ACC_Limiter::client_ip() ) . '</code></strong></td></tr>';
		echo '</tbody></table>';
	}

	private static function render_log() {
		$log = MDT_Logger::all( 'accounts' );
		echo '<h3>' . esc_html__( 'Log', 'mim-dollar-toolkit' ) . '</h3>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No entries yet.', 'mim-dollar-toolkit' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:900px"><tbody>';
		foreach ( array_slice( $log, 0, MDT_Logger::MAX ) as $entry ) {
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td><td>' . esc_html( MDT_Logger::level_label( $entry['level'] ) ) . '</td><td>' . esc_html( $entry['message'] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
