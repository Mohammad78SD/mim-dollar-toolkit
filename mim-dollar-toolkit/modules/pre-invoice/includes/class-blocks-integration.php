<?php
/**
 * Checkout Block support for the pre-invoice gateway.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class MDT_PI_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'mdt_preinvoice';

	public function initialize() {
		$this->settings = (array) get_option( 'woocommerce_mdt_preinvoice_settings', array() );
	}

	private function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ MDT_PI_Settings::GATEWAY_ID ] ) ? $gateways[ MDT_PI_Settings::GATEWAY_ID ] : new MDT_PI_Gateway();
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'yes' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'mdt-pi-blocks',
			MDT_TK_URL . 'modules/pre-invoice/assets/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			MDT_TK_VERSION,
			true
		);
		return array( 'mdt-pi-blocks' );
	}

	public function get_payment_method_data() {
		$gw = $this->gateway();
		return array(
			'title'       => $gw->get_option( 'title' ),
			'description' => $gw->get_option( 'description' ),
			'button_text' => $gw->get_option( 'order_button_text' ),
			'supports'    => array_values( array_filter( $gw->supports, array( $gw, 'supports' ) ) ),
		);
	}
}
