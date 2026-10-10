<?php
/**
 * Customer email: your pre-invoice.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Email_Customer_Preinvoice extends MDT_PI_Email_Base {

	public function __construct() {
		$this->id             = 'mdt_customer_preinvoice';
		$this->customer_email = true;
		$this->title          = __( 'Pre-invoice (customer)', 'mim-dollar-toolkit' );
		$this->description    = __( 'Sent to the customer when a pre-invoice is issued.', 'mim-dollar-toolkit' );
		$this->template_html  = 'emails/customer-preinvoice.php';
		$this->template_plain = 'emails/plain/customer-preinvoice.php';
		$this->mdt_setup();

		add_action( 'woocommerce_order_status_pending_to_mdt-preinvoice', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed_to_mdt-preinvoice', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();
		$this->customer_email = true;
	}

	public function get_default_subject() {
		return __( 'Your pre-invoice {pi_number} from {site_title}', 'mim-dollar-toolkit' );
	}

	public function get_default_heading() {
		return __( 'Your pre-invoice {pi_number}', 'mim-dollar-toolkit' );
	}
}
