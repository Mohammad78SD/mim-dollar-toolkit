<?php
/**
 * Registers the pre-invoice emails with WooCommerce.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Emails {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register' ) );
	}

	public static function register( $emails ) {
		$dir = MDT_TK_DIR . 'modules/pre-invoice/includes/';
		require_once $dir . 'class-email-base.php';
		require_once $dir . 'class-email-customer-preinvoice.php';
		require_once $dir . 'class-email-admin-new-preinvoice.php';
		require_once $dir . 'class-email-customer-cancelled.php';

		$emails['MDT_PI_Email_Customer_Preinvoice']           = new MDT_PI_Email_Customer_Preinvoice();
		$emails['MDT_PI_Email_Admin_New_Preinvoice']          = new MDT_PI_Email_Admin_New_Preinvoice();
		$emails['MDT_PI_Email_Customer_Preinvoice_Cancelled'] = new MDT_PI_Email_Customer_Preinvoice_Cancelled();
		return $emails;
	}
}
