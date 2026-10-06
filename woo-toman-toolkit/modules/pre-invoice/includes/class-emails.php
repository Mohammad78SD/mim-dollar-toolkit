<?php
/**
 * Registers the pre-invoice emails with WooCommerce.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Emails {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ) {
		$dir = WTT_TK_DIR . 'modules/pre-invoice/includes/';
		require_once $dir . 'class-email-base.php';
		require_once $dir . 'class-email-customer-preinvoice.php';
		require_once $dir . 'class-email-admin-new-preinvoice.php';
		require_once $dir . 'class-email-customer-cancelled.php';

		$emails['WTT_PI_Email_Customer_Preinvoice']           = new WTT_PI_Email_Customer_Preinvoice();
		$emails['WTT_PI_Email_Admin_New_Preinvoice']          = new WTT_PI_Email_Admin_New_Preinvoice();
		$emails['WTT_PI_Email_Customer_Preinvoice_Cancelled'] = new WTT_PI_Email_Customer_Preinvoice_Cancelled();
		return $emails;
	}
}
