<?php
/**
 * SMS settings tab (WooCommerce > MiM Dollar Toolkit > SMS).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Admin_Tab {

	const TAB = 'sms';

	public static function init() {
		add_action( 'admin_post_mdt_sms_save', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_mdt_sms_test', array( __CLASS__, 'handle_test' ) );
		add_action( 'admin_post_mdt_sms_check', array( __CLASS__, 'handle_check' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	private static function tab_url() {
		return admin_url( 'admin.php?page=mdt-toolkit&tab=' . self::TAB );
	}

	private static function flash( $type, $message ) {
		set_transient(
			'mdt_sms_flash_' . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => (string) $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	private static function check_access() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mim-dollar-toolkit' ), '', array( 'response' => 403 ) );
		}
	}

	public static function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, 'mdt-toolkit' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check.
		if ( ! isset( $_GET['tab'] ) || self::TAB !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}
		wp_enqueue_script( 'mdt-sms-admin', MDT_TK_URL . 'modules/sms/assets/admin.js', array(), MDT_TK_VERSION, true );
		wp_localize_script( 'mdt-sms-admin', 'mdtSmsAdmin', array( 'copied' => __( 'Copied', 'mim-dollar-toolkit' ) ) );
	}

	public static function handle_save() {
		self::check_access();
		check_admin_referer( 'mdt_sms_save' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in MDT_SMS_Settings::sanitize().
		$raw     = isset( $_POST['mdt_sms'] ) && is_array( $_POST['mdt_sms'] ) ? wp_unslash( $_POST['mdt_sms'] ) : array();
		$invalid = 0;
		MDT_SMS_Settings::save( MDT_SMS_Settings::sanitize( $raw, MDT_SMS_Settings::all(), $invalid ) );
		$msg = __( 'Settings saved.', 'mim-dollar-toolkit' );
		if ( $invalid > 0 ) {
			/* translators: %d: number of ignored numbers */
			$msg .= ' ' . sprintf( _n( '%d admin number was not a valid Iranian mobile and was ignored.', '%d admin numbers were not valid Iranian mobiles and were ignored.', $invalid, 'mim-dollar-toolkit' ), $invalid );
		}
		self::flash( 'success', $msg );
		wp_safe_redirect( self::tab_url() );
		exit;
	}

	public static function handle_test() {
		self::check_access();
		check_admin_referer( 'mdt_sms_test' );
		$event  = isset( $_POST['mdt_sms_event'] ) && is_string( $_POST['mdt_sms_event'] ) ? sanitize_key( wp_unslash( $_POST['mdt_sms_event'] ) ) : '';
		$mobile = isset( $_POST['mdt_sms_mobile'] ) && is_string( $_POST['mdt_sms_mobile'] ) ? sanitize_text_field( wp_unslash( $_POST['mdt_sms_mobile'] ) ) : '';
		$res    = MDT_SMS_Sender::send_test( $event, $mobile );
		self::flash( $res['ok'] ? 'success' : 'error', $res['message'] );
		wp_safe_redirect( self::tab_url() . '#mdt-sms-test' );
		exit;
	}

	public static function handle_check() {
		self::check_access();
		check_admin_referer( 'mdt_sms_check' );
		$provider = MDT_SMS_Providers::make();
		if ( ! MDT_SMS_Settings::has_api_key() ) {
			self::flash( 'error', __( 'Save the API key first.', 'mim-dollar-toolkit' ) );
		} elseif ( ! $provider || ! method_exists( $provider, 'check' ) ) {
			self::flash( 'error', __( 'The selected provider does not support a connection check.', 'mim-dollar-toolkit' ) );
		} else {
			$res = $provider->check();
			set_transient( 'mdt_sms_check_' . get_current_user_id(), is_array( $res ) ? $res : array( 'ok' => false, 'error' => 'invalid response' ), 5 * MINUTE_IN_SECONDS );
		}
		wp_safe_redirect( self::tab_url() . '#mdt-sms-check' );
		exit;
	}

	private static function render_scalars( $data ) {
		if ( is_scalar( $data ) ) {
			return '<code>' . esc_html( is_bool( $data ) ? ( $data ? 'true' : 'false' ) : (string) $data ) . '</code>';
		}
		if ( is_array( $data ) ) {
			$scalars = array_filter( $data, 'is_scalar' );
			if ( 1 === count( $data ) && 1 === count( $scalars ) ) {
				return '<code>' . esc_html( (string) reset( $scalars ) ) . '</code>';
			}
			$parts = array();
			foreach ( $data as $k => $v ) {
				if ( is_scalar( $v ) ) {
					$parts[] = esc_html( (string) $k ) . ': <code>' . esc_html( is_bool( $v ) ? ( $v ? 'true' : 'false' ) : (string) $v ) . '</code>';
				}
			}
			return implode( ' &middot; ', $parts );
		}
		return '';
	}

	private static function render_check_result() {
		$key = 'mdt_sms_check_' . get_current_user_id();
		$res = get_transient( $key );
		if ( ! is_array( $res ) ) {
			return;
		}
		delete_transient( $key );
		if ( empty( $res['ok'] ) ) {
			$err = isset( $res['error'] ) && '' !== (string) $res['error'] ? (string) $res['error'] : __( 'Unknown error', 'mim-dollar-toolkit' );
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Connection failed:', 'mim-dollar-toolkit' ) . ' ' . esc_html( $err ) . '</p></div>';
			return;
		}
		echo '<div class="notice notice-success inline"><p><strong>' . esc_html__( 'Connection OK.', 'mim-dollar-toolkit' ) . '</strong></p>';
		$bal = self::render_scalars( isset( $res['balance'] ) ? $res['balance'] : null );
		echo '<p>' . esc_html__( 'Balance:', 'mim-dollar-toolkit' ) . ' ' . ( '' !== $bal ? $bal : '-' ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render_scalars().
		$lines = isset( $res['lines'] ) && is_array( $res['lines'] ) ? $res['lines'] : array();
		echo '<p>' . esc_html__( 'Accessible sender lines:', 'mim-dollar-toolkit' ) . ' ';
		if ( $lines ) {
			echo implode( ', ', array_map( function ( $l ) { return '<code>' . esc_html( (string) $l ) . '</code>'; }, $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped per item.
		} else {
			echo '-';
		}
		echo '</p></div>';
	}

	public static function render() {
		self::check_access();

		$flash_key = 'mdt_sms_flash_' . get_current_user_id();
		$flash     = get_transient( $flash_key );
		if ( is_array( $flash ) && isset( $flash['message'] ) ) {
			delete_transient( $flash_key );
			$type = 'error' === $flash['type'] ? 'notice-error' : 'notice-success';
			echo '<div class="notice ' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
		}

		$values = MDT_SMS_Settings::all();
		$defs   = MDT_SMS_Events::definitions();

		echo '<h2>' . esc_html__( 'SMS', 'mim-dollar-toolkit' ) . '</h2>';

		if ( ! MDT_SMS_Events::preinvoice_active() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'The Pre-invoice module is disabled, so the four order-related SMS events below are inactive. Enable it on the Modules tab.', 'mim-dollar-toolkit' ) . '</p></div>';
		}
		if ( ! MDT_SMS_Settings::is_configured() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'No SMS is sent until the API key and the sender number are saved.', 'mim-dollar-toolkit' ) . '</p></div>';
		}

		self::render_help();

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_sms_save" />';
		wp_nonce_field( 'mdt_sms_save' );

		echo '<h3>' . esc_html__( 'Provider', 'mim-dollar-toolkit' ) . '</h3><table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row"><label for="mdt_sms_provider">' . esc_html__( 'Provider', 'mim-dollar-toolkit' ) . '</label></th><td><select id="mdt_sms_provider" name="mdt_sms[provider]">';
		foreach ( MDT_SMS_Providers::labels() as $id => $label ) {
			echo '<option value="' . esc_attr( $id ) . '" ' . selected( $values['provider'], $id, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></td></tr>';

		$has_key = MDT_SMS_Settings::has_api_key();
		echo '<tr><th scope="row"><label for="mdt_sms_api_key">' . esc_html__( 'API key', 'mim-dollar-toolkit' ) . '</label></th><td>';
		echo '<input type="password" class="regular-text" autocomplete="new-password" id="mdt_sms_api_key" name="mdt_sms[api_key]" value="" placeholder="' . esc_attr( $has_key ? '•••••• ' . __( '(saved)', 'mim-dollar-toolkit' ) : '' ) . '" />';
		if ( $has_key ) {
			echo '<br /><label><input type="checkbox" name="mdt_sms[api_key_clear]" value="1" /> ' . esc_html__( 'Remove the saved API key', 'mim-dollar-toolkit' ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Leave empty to keep the saved key. The key is never shown again.', 'mim-dollar-toolkit' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mdt_sms_sender">' . esc_html__( 'Sender number', 'mim-dollar-toolkit' ) . '</label></th><td>';
		echo '<input type="text" dir="ltr" class="regular-text" id="mdt_sms_sender" name="mdt_sms[sender]" value="' . esc_attr( (string) $values['sender'] ) . '" />';
		echo '<p class="description">' . esc_html__( 'Your FarazSMS sender line, e.g. 90008361 (Panel → Lines)', 'mim-dollar-toolkit' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="mdt_sms_admin_numbers">' . esc_html__( 'Admin mobile numbers', 'mim-dollar-toolkit' ) . '</label></th><td>';
		echo '<textarea dir="ltr" class="large-text" rows="3" id="mdt_sms_admin_numbers" name="mdt_sms[admin_numbers]">' . esc_textarea( (string) $values['admin_numbers'] ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One per line or comma separated. They receive the "New pre-invoice" SMS.', 'mim-dollar-toolkit' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Checkout', 'mim-dollar-toolkit' ) . '</th><td>';
		echo '<label><input type="checkbox" name="mdt_sms[require_mobile]" value="1" ' . checked( ! empty( $values['require_mobile'] ), true, false ) . ' /> ' . esc_html__( 'Require a valid Iranian mobile number at checkout', 'mim-dollar-toolkit' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Classic checkout: the phone field becomes required and is validated. Block checkout: the phone is validated when the order is placed (an empty phone is rejected too). A valid number is saved as 09xxxxxxxxx.', 'mim-dollar-toolkit' ) . '</p></td></tr>';
		echo '</tbody></table>';

		echo '<h3>' . esc_html__( 'Events', 'mim-dollar-toolkit' ) . '</h3>';
		foreach ( $defs as $key => $def ) {
			$ev = $values['events'][ $key ];
			echo '<h4>' . esc_html( $def['label'] ) . '</h4>';
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th scope="row">' . esc_html__( 'Enabled', 'mim-dollar-toolkit' ) . '</th><td><label><input type="checkbox" name="mdt_sms[events][' . esc_attr( $key ) . '][enabled]" value="1" ' . checked( ! empty( $ev['enabled'] ), true, false ) . ' /> ' . esc_html( $def['trigger'] ) . '</label></td></tr>';
			echo '<tr><th scope="row"><label for="mdt_sms_code_' . esc_attr( $key ) . '">' . esc_html__( 'Pattern code', 'mim-dollar-toolkit' ) . '</label></th><td><input type="text" dir="ltr" class="regular-text" id="mdt_sms_code_' . esc_attr( $key ) . '" name="mdt_sms[events][' . esc_attr( $key ) . '][code]" value="' . esc_attr( (string) $ev['code'] ) . '" /></td></tr>';
			echo '<tr><th scope="row">' . esc_html__( 'Variables', 'mim-dollar-toolkit' ) . '</th><td><ul style="margin:0">';
			foreach ( $def['vars'] as $var => $desc ) {
				echo '<li><code>%' . esc_html( $var ) . '%</code> &mdash; ' . esc_html( $desc ) . '</li>';
			}
			echo '</ul></td></tr>';
			echo '<tr><th scope="row"><label for="mdt_sms_sug_' . esc_attr( $key ) . '">' . esc_html__( 'Suggested pattern text', 'mim-dollar-toolkit' ) . '</label></th><td>';
			echo '<textarea readonly class="large-text" rows="2" id="mdt_sms_sug_' . esc_attr( $key ) . '">' . esc_textarea( $def['suggested'] ) . '</textarea>';
			echo '<p><button type="button" class="button mdt-sms-copy" data-target="mdt_sms_sug_' . esc_attr( $key ) . '">' . esc_html__( 'Copy', 'mim-dollar-toolkit' ) . '</button></p>';
			if ( ! empty( $def['note'] ) ) {
				echo '<p class="description">' . esc_html( $def['note'] ) . '</p>';
			}
			echo '</td></tr>';
			echo '</tbody></table>';
		}
		submit_button( __( 'Save settings', 'mim-dollar-toolkit' ) );
		echo '</form>';

		// Test form.
		echo '<h3 id="mdt-sms-test">' . esc_html__( 'Send test SMS', 'mim-dollar-toolkit' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_sms_test" />';
		wp_nonce_field( 'mdt_sms_test' );
		echo '<p><label for="mdt_sms_event">' . esc_html__( 'Event', 'mim-dollar-toolkit' ) . '</label> <select id="mdt_sms_event" name="mdt_sms_event">';
		foreach ( $defs as $key => $def ) {
			echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $def['label'] ) . '</option>';
		}
		echo '</select> <label for="mdt_sms_mobile">' . esc_html__( 'Mobile', 'mim-dollar-toolkit' ) . '</label> <input type="text" dir="ltr" id="mdt_sms_mobile" name="mdt_sms_mobile" placeholder="09123456789" /> ';
		echo '<button type="submit" class="button">' . esc_html__( 'Send test SMS', 'mim-dollar-toolkit' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Sends the selected event\'s pattern with sample values using the saved settings. Save your settings first.', 'mim-dollar-toolkit' ) . '</p>';
		echo '</form>';

		// Connection check.
		echo '<h3 id="mdt-sms-check">' . esc_html__( 'Connection', 'mim-dollar-toolkit' ) . '</h3>';
		self::render_check_result();
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="mdt_sms_check" />';
		wp_nonce_field( 'mdt_sms_check' );
		echo '<p><button type="submit" class="button">' . esc_html__( 'Check connection', 'mim-dollar-toolkit' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Checks the saved API key by reading the account balance and sender lines. No SMS is sent.', 'mim-dollar-toolkit' ) . '</p>';
		echo '</form>';

		self::render_log();
	}

	private static function render_help() {
		echo '<div class="notice notice-info inline" style="padding:8px 14px"><p><strong>' . esc_html__( 'How to set up FarazSMS', 'mim-dollar-toolkit' ) . '</strong></p><ol>';
		echo '<li>' . esc_html__( 'In the FarazSMS user panel create an API key (Api-Key) and paste it above.', 'mim-dollar-toolkit' ) . '</li>';
		echo '<li>' . esc_html__( 'Enter your sender (service line) number.', 'mim-dollar-toolkit' ) . '</li>';
		echo '<li>' . esc_html__( 'In the panel create a pattern for each event, using the suggested text and exactly the variable names listed for it. Variable names must match exactly.', 'mim-dollar-toolkit' ) . '</li>';
		echo '<li>' . esc_html__( 'Patterns must be approved by FarazSMS before they work, which usually takes a few hours.', 'mim-dollar-toolkit' ) . '</li>';
		echo '<li>' . esc_html__( 'Write links as plain text inside the pattern, not as variables: FarazSMS rejects a link passed as a variable.', 'mim-dollar-toolkit' ) . '</li>';
		echo '<li>' . esc_html__( 'Paste each approved pattern code into its event, save, then try the test SMS.', 'mim-dollar-toolkit' ) . '</li>';
		echo '</ol></div>';
	}

	private static function render_log() {
		$log = MDT_Logger::all( 'sms' );
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
