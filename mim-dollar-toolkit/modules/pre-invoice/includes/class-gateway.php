<?php
/**
 * "Request pre-invoice" payment gateway (no online payment).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Gateway extends WC_Payment_Gateway {

	const ID = 'mdt_preinvoice';

	/**
	 * Untranslated source defaults (see get_option()). Keep in sync with init_form_fields().
	 */
	const ENGLISH_DEFAULTS = array(
		'title'             => 'Request pre-invoice',
		'description'       => 'No online payment. We will send you a pre-invoice and contact you soon to arrange payment.',
		'order_button_text' => 'Request pre-invoice',
	);

	public function __construct() {
		$this->id                 = self::ID;
		$this->has_fields         = false;
		$this->method_title       = __( 'Pre-invoice', 'mim-dollar-toolkit' );
		$this->method_description = __( 'No online payment: the order is placed as a pre-invoice and the shop contacts the customer to arrange payment.', 'mim-dollar-toolkit' );
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();

		$this->title            = $this->get_option( 'title' );
		$this->description      = $this->get_option( 'description' );
		$this->order_button_text = $this->get_option( 'order_button_text' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'           => array(
				'title'   => __( 'Enable/Disable', 'mim-dollar-toolkit' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable pre-invoice requests', 'mim-dollar-toolkit' ),
				'default' => 'yes',
			),
			'title'             => array(
				'title'       => __( 'Title', 'mim-dollar-toolkit' ),
				'type'        => 'text',
				'description' => __( 'Shown to the customer at checkout.', 'mim-dollar-toolkit' ),
				'default'     => __( 'Request pre-invoice', 'mim-dollar-toolkit' ),
				'desc_tip'    => true,
			),
			'description'       => array(
				'title'   => __( 'Description', 'mim-dollar-toolkit' ),
				'type'    => 'textarea',
				'default' => __( 'No online payment. We will send you a pre-invoice and contact you soon to arrange payment.', 'mim-dollar-toolkit' ),
			),
			'order_button_text' => array(
				'title'   => __( 'Place order button text', 'mim-dollar-toolkit' ),
				'type'    => 'text',
				'default' => __( 'Request pre-invoice', 'mim-dollar-toolkit' ),
			),
		);
	}

	public function get_option( $key, $empty_value = null ) {
		$value = parent::get_option( $key, $empty_value );
		// Fall back to the (translatable) default when a text is empty or still equals the English
		// default that was stored while the site was not yet translated.
		if ( isset( self::ENGLISH_DEFAULTS[ $key ] ) ) {
			if ( '' === $value || null === $value || trim( (string) $value ) === self::ENGLISH_DEFAULTS[ $key ] ) {
				$value = $this->translated_default( $key );
			}
		}
		return $value;
	}

	/**
	 * Translated default of a text field.
	 *
	 * @param string $key Field key.
	 * @return string
	 */
	private function translated_default( $key ) {
		switch ( $key ) {
			case 'description':
				return __( 'No online payment. We will send you a pre-invoice and contact you soon to arrange payment.', 'mim-dollar-toolkit' );
			case 'title':
			case 'order_button_text':
			default:
				return __( 'Request pre-invoice', 'mim-dollar-toolkit' );
		}
	}

	/**
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure' );
		}

		$number = MDT_PI_Number::ensure( $order );
		wc_reduce_stock_levels( $order_id );
		$order = wc_get_order( $order_id ); // Fresh copy after stock/meta writes.

		/* translators: %s: pre-invoice number */
		$order->update_status( 'mdt-preinvoice', sprintf( __( 'Pre-invoice %s issued.', 'mim-dollar-toolkit' ), $number ) );

		if ( WC()->cart ) {
			WC()->cart->empty_cart();
		}

		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}
}
