<?php
/**
 * Customer accounts module: automatic accounts at checkout + login with a mobile OTP.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'WTT_ACC_DIR', WTT_TK_DIR . 'modules/accounts/' );

require_once WTT_ACC_DIR . 'includes/class-settings.php';
require_once WTT_ACC_DIR . 'includes/class-users.php';
require_once WTT_ACC_DIR . 'includes/class-limiter.php';
require_once WTT_ACC_DIR . 'includes/class-otp.php';
require_once WTT_ACC_DIR . 'includes/class-rest.php';
require_once WTT_ACC_DIR . 'includes/class-auto-account.php';
require_once WTT_ACC_DIR . 'includes/class-login-form.php';
require_once WTT_ACC_DIR . 'includes/class-profile.php';
require_once WTT_ACC_DIR . 'includes/class-admin-tab.php';

class WTT_Module_Accounts extends WTT_Module {

	public function id() {
		return 'accounts';
	}

	public function title() {
		return __( 'Customer accounts', 'woo-toman-toolkit' );
	}

	public function description() {
		return __( 'Create a customer account automatically at checkout and let customers log in to My Account with a one-time code sent by SMS (needs the SMS module).', 'woo-toman-toolkit' );
	}

	public function init() {
		WTT_ACC_Auto_Account::init();
		WTT_ACC_REST::init();
		WTT_ACC_Login_Form::init();
		WTT_ACC_Profile::init();

		if ( is_admin() ) {
			WTT_ACC_Admin_Tab::init();
		}
	}

	public function render_settings_tab() {
		WTT_ACC_Admin_Tab::render();
	}

	public function activate() {
		if ( false === get_option( WTT_ACC_Settings::OPTION, false ) ) {
			add_option( WTT_ACC_Settings::OPTION, WTT_ACC_Settings::defaults(), '', 'no' );
		}
	}

	public function uninstall() {
		// User meta (wtt_mobile) is deliberately kept: it is customer data, not plugin configuration.
		delete_option( 'wtt_acc_settings' );
		WTT_Logger::delete( 'accounts' );
	}
}
