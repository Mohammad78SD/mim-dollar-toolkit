<?php
/**
 * Customer email: pre-invoice cancelled / expired.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Email_Customer_Preinvoice_Cancelled extends WTT_PI_Email_Base {

	public function __construct() {
		$this->id             = 'wtt_customer_preinvoice_cancelled';
		$this->customer_email = true;
		$this->title          = __( 'Pre-invoice cancelled (customer)', 'woo-toman-toolkit' );
		$this->description    = __( 'Sent to the customer when a pre-invoice is cancelled or expires.', 'woo-toman-toolkit' );
		$this->template_html  = 'emails/customer-preinvoice-cancelled.php';
		$this->template_plain = 'emails/plain/customer-preinvoice-cancelled.php';
		$this->wtt_setup();

		add_action( 'woocommerce_order_status_wtt-preinvoice_to_cancelled', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();
		$this->customer_email = true;
	}

	public function get_default_subject() {
		return __( 'Your pre-invoice {pi_number} from {site_title} has been cancelled', 'woo-toman-toolkit' );
	}

	public function get_default_heading() {
		return __( 'Pre-invoice {pi_number} cancelled', 'woo-toman-toolkit' );
	}
}
