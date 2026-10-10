<?php
/**
 * Admin email: new pre-invoice request.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Email_Admin_New_Preinvoice extends MDT_PI_Email_Base {

	public function __construct() {
		$this->id             = 'mdt_admin_new_preinvoice';
		$this->title          = __( 'New pre-invoice request (admin)', 'mim-dollar-toolkit' );
		$this->description    = __( 'Sent to the shop when a customer requests a pre-invoice.', 'mim-dollar-toolkit' );
		$this->template_html  = 'emails/admin-new-preinvoice.php';
		$this->template_plain = 'emails/plain/admin-new-preinvoice.php';
		$this->mdt_setup();

		add_action( 'woocommerce_order_status_pending_to_mdt-preinvoice', array( $this, 'trigger' ), 10, 2 );
		add_action( 'woocommerce_order_status_failed_to_mdt-preinvoice', array( $this, 'trigger' ), 10, 2 );
		parent::__construct();
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	protected function set_recipient_for( $order ) {
		// Recipient comes from the email settings.
		$this->recipient = $this->get_option( 'recipient', get_option( 'admin_email' ) );
	}

	public function get_default_subject() {
		return __( 'New pre-invoice request {pi_number} — {customer_name}', 'mim-dollar-toolkit' );
	}

	public function get_default_heading() {
		return __( 'New pre-invoice request {pi_number}', 'mim-dollar-toolkit' );
	}

	public function init_form_fields() {
		parent::init_form_fields();
		$fields = array(
			'recipient' => array(
				'title'       => __( 'Recipient(s)', 'mim-dollar-toolkit' ),
				'type'        => 'text',
				/* translators: %s: admin email */
				'description' => sprintf( __( 'Comma-separated. Defaults to %s.', 'mim-dollar-toolkit' ), '<code>' . esc_html( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => get_option( 'admin_email' ),
				'desc_tip'    => false,
			),
		);
		// Put the recipient right after the enabled toggle.
		$this->form_fields = array_merge(
			array_slice( $this->form_fields, 0, 1, true ),
			$fields,
			array_slice( $this->form_fields, 1, null, true )
		);
	}
}
