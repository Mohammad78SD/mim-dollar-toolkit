<?php
/**
 * Checkout Block support for the pre-invoice gateway.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

class WTT_PI_Blocks_Integration extends AbstractPaymentMethodType {

	protected $name = 'wtt_preinvoice';

	public function initialize() {
		$this->settings = (array) get_option( 'woocommerce_wtt_preinvoice_settings', array() );
	}

	private function gateway() {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		return isset( $gateways[ WTT_PI_Settings::GATEWAY_ID ] ) ? $gateways[ WTT_PI_Settings::GATEWAY_ID ] : new WTT_PI_Gateway();
	}

	public function is_active() {
		return 'yes' === $this->get_setting( 'enabled', 'yes' );
	}

	public function get_payment_method_script_handles() {
		wp_register_script(
			'wtt-pi-blocks',
			WTT_TK_URL . 'modules/pre-invoice/assets/blocks.js',
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			WTT_TK_VERSION,
			true
		);
		return array( 'wtt-pi-blocks' );
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
