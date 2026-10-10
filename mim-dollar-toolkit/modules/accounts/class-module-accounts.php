<?php
/**
 * Customer accounts module: automatic accounts at checkout + login with a mobile OTP.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

define( 'MDT_ACC_DIR', MDT_TK_DIR . 'modules/accounts/' );

require_once MDT_ACC_DIR . 'includes/class-settings.php';
require_once MDT_ACC_DIR . 'includes/class-users.php';
require_once MDT_ACC_DIR . 'includes/class-limiter.php';
require_once MDT_ACC_DIR . 'includes/class-otp.php';
require_once MDT_ACC_DIR . 'includes/class-rest.php';
require_once MDT_ACC_DIR . 'includes/class-auto-account.php';
require_once MDT_ACC_DIR . 'includes/class-login-form.php';
require_once MDT_ACC_DIR . 'includes/class-profile.php';
require_once MDT_ACC_DIR . 'includes/class-admin-tab.php';

class MDT_Module_Accounts extends MDT_Module {

	public function id() {
		return 'accounts';
	}

	public function title() {
		return __( 'Customer accounts', 'mim-dollar-toolkit' );
	}

	public function description() {
		return __( 'Create a customer account automatically at checkout and let customers log in to My Account with a one-time code sent by SMS (needs the SMS module).', 'mim-dollar-toolkit' );
	}

	public function init() {
		MDT_ACC_Auto_Account::init();
		MDT_ACC_REST::init();
		MDT_ACC_Login_Form::init();
		MDT_ACC_Profile::init();

		if ( is_admin() ) {
			MDT_ACC_Admin_Tab::init();
		}
	}

	public function render_settings_tab() {
		MDT_ACC_Admin_Tab::render();
	}

	public function activate() {
		if ( false === get_option( MDT_ACC_Settings::OPTION, false ) ) {
			add_option( MDT_ACC_Settings::OPTION, MDT_ACC_Settings::defaults(), '', 'no' );
		}
	}

	public function uninstall() {
		// User meta (mdt_mobile) is deliberately kept: it is customer data, not plugin configuration.
		delete_option( 'mdt_acc_settings' );
		MDT_Logger::delete( 'accounts' );
	}
}
