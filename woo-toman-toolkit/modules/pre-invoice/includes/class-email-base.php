<?php
/**
 * Shared behaviour of the pre-invoice emails.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

abstract class WTT_PI_Email_Base extends WC_Email {

	/**
	 * Common setup; subclasses set id/title/templates first.
	 */
	protected function wtt_setup() {
		$this->template_base = WTT_TK_DIR . 'modules/pre-invoice/templates/';
		$this->placeholders  = array(
			'{site_title}'    => $this->get_blogname(),
			'{pi_number}'     => '',
			'{customer_name}' => '',
			'{valid_until}'   => '',
			'{shop_phone}'    => '',
		);
	}

	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}
		if ( is_a( $order, 'WC_Order' ) ) {
			WTT_PI_Number::ensure( $order );
			$this->object = $order;
			$this->set_recipient_for( $order );
			$valid                                = (int) $order->get_meta( '_wtt_pi_valid_until' );
			$this->placeholders['{pi_number}']     = (string) $order->get_meta( '_wtt_pi_number' );
			$this->placeholders['{customer_name}'] = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
			$this->placeholders['{valid_until}']   = WTT_PI_Format::datetime( $valid );
			$this->placeholders['{shop_phone}']    = (string) WTT_PI_Settings::get( 'phone' );
		}

		if ( $this->is_enabled() && $this->get_recipient() && $this->object ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	/**
	 * Set $this->recipient for the order (customer emails use the billing email).
	 *
	 * @param WC_Order $order Order.
	 */
	protected function set_recipient_for( $order ) {
		$this->recipient = $order->get_billing_email();
	}

	protected function template_args( $plain ) {
		return array(
			'order'              => $this->object,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => ! $this->is_customer_email(),
			'plain_text'         => $plain,
			'email'              => $this,
			'pi_number'          => (string) $this->object->get_meta( '_wtt_pi_number' ),
			'pi_url'             => WTT_PI_Page::url( $this->object ),
			'issued'             => WTT_PI_Format::date( (int) $this->object->get_meta( '_wtt_pi_issued_at' ) ),
			'valid_until'        => WTT_PI_Format::datetime( (int) $this->object->get_meta( '_wtt_pi_valid_until' ) ),
			'words'              => WTT_PI_Format::total_in_words( $this->object ),
			'settings'           => WTT_PI_Settings::all(),
		);
	}

	public function get_content_html() {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), 'woo-toman-toolkit/', $this->template_base );
	}

	public function get_content_plain() {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), 'woo-toman-toolkit/', $this->template_base );
	}

	public function get_default_additional_content() {
		return '';
	}
}
