<?php
/**
 * SMS module (pattern SMS through FarazSMS / IPPanel).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'WTT_SMS_DIR', WTT_TK_DIR . 'modules/sms/' );

require_once WTT_SMS_DIR . 'includes/class-phone.php';
require_once WTT_SMS_DIR . 'includes/class-settings.php';
require_once WTT_SMS_DIR . 'includes/class-provider.php';
require_once WTT_SMS_DIR . 'includes/class-provider-ippanel-edge.php';
require_once WTT_SMS_DIR . 'includes/class-provider-farazsms.php';
require_once WTT_SMS_DIR . 'includes/class-events.php';
require_once WTT_SMS_DIR . 'includes/class-sender.php';
require_once WTT_SMS_DIR . 'includes/class-checkout.php';
require_once WTT_SMS_DIR . 'includes/class-admin-tab.php';

class WTT_Module_SMS extends WTT_Module {

	public function id() {
		return 'sms';
	}

	public function title() {
		return __( 'SMS', 'woo-toman-toolkit' );
	}

	public function description() {
		return __( 'Send pattern SMS notifications (pre-invoice issued, paid, cancelled, login codes) to customers and admins through FarazSMS, and require a valid Iranian mobile number at checkout.', 'woo-toman-toolkit' );
	}

	public function init() {
		WTT_SMS_Events::init();
		WTT_SMS_Sender::init();
		WTT_SMS_Checkout::init();

		if ( is_admin() ) {
			WTT_SMS_Admin_Tab::init();
		}
	}

	public function render_settings_tab() {
		WTT_SMS_Admin_Tab::render();
	}

	public function activate() {
		if ( false === get_option( WTT_SMS_Settings::OPTION, false ) ) {
			// Autoload "no": the option holds the API key.
			add_option( WTT_SMS_Settings::OPTION, WTT_SMS_Settings::defaults(), '', 'no' );
		}
	}

	public function deactivate() {
		// Pending messages cannot run while the module is off; drop them (settings and logs are kept).
		if ( function_exists( 'as_unschedule_all_actions' ) && function_exists( 'as_has_scheduled_action' ) ) {
			as_unschedule_all_actions( WTT_SMS_Sender::HOOK, null, WTT_SMS_Sender::GROUP );
		}
		wp_clear_scheduled_hook( WTT_SMS_Sender::HOOK );
	}

	public function uninstall() {
		delete_option( WTT_SMS_Settings::OPTION );
		WTT_Logger::delete( 'sms' );
	}
}
