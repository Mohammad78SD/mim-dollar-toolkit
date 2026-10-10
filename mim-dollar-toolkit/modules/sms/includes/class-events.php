<?php
/**
 * SMS events: definitions, triggers and pattern variables.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Events {

	public static function init() {
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
	}

	/**
	 * Event definitions: key => array( label, to (customer|admin), vars (name => description), suggested ).
	 *
	 * @return array
	 */
	public static function definitions() {
		$store = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		return array(
			'customer_preinvoice' => array(
				'label'     => __( 'Pre-invoice issued (to customer)', 'mim-dollar-toolkit' ),
				'to'        => 'customer',
				'trigger'   => __( 'Sent to the billing mobile when an order enters the "Pre-invoice sent" status.', 'mim-dollar-toolkit' ),
				'vars'      => array(
					'name'   => __( 'customer first name', 'mim-dollar-toolkit' ),
					'number' => __( 'pre-invoice number', 'mim-dollar-toolkit' ),
					'total'  => __( 'order total (digits only, the unit is part of your pattern text)', 'mim-dollar-toolkit' ),
					'valid'  => __( 'valid-until date (Jalali)', 'mim-dollar-toolkit' ),
				),
				'suggested' => str_replace( '{url}', function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/' ), str_replace( '%s', $store, /* translators: %s: store name (inserted literally; the other %-tokens are SMS pattern variables). */ __( 'Dear %name%, pre-invoice %number% for %total% Toman has been issued. Valid until %valid%. View it in your account: {url} - %s', 'mim-dollar-toolkit' ) ) ),
				'note'      => __( 'Links must be written as plain text inside the pattern; FarazSMS does not accept a link as a variable.', 'mim-dollar-toolkit' ),
			),
			'admin_new_preinvoice' => array(
				'label'     => __( 'New pre-invoice (to admins)', 'mim-dollar-toolkit' ),
				'to'        => 'admin',
				'trigger'   => __( 'Sent to every admin mobile when an order enters the "Pre-invoice sent" status.', 'mim-dollar-toolkit' ),
				'vars'      => array(
					'number' => __( 'pre-invoice number', 'mim-dollar-toolkit' ),
					'name'   => __( 'customer full name', 'mim-dollar-toolkit' ),
					'phone'  => __( 'customer phone', 'mim-dollar-toolkit' ),
					'total'  => __( 'order total (digits only)', 'mim-dollar-toolkit' ),
				),
				'suggested' => __( 'New pre-invoice request %number% from %name% (%phone%) for %total% Toman', 'mim-dollar-toolkit' ),
			),
			'customer_paid'       => array(
				'label'     => __( 'Payment confirmed (to customer)', 'mim-dollar-toolkit' ),
				'to'        => 'customer',
				'trigger'   => __( 'Sent once when a pre-invoice order moves to Processing or Completed.', 'mim-dollar-toolkit' ),
				'vars'      => array(
					'name'   => __( 'customer first name', 'mim-dollar-toolkit' ),
					'number' => __( 'pre-invoice number', 'mim-dollar-toolkit' ),
				),
				'suggested' => str_replace( '%s', $store, /* translators: %s: store name (inserted literally; the other %-tokens are SMS pattern variables). */ __( 'Dear %name%, the payment for pre-invoice %number% is confirmed. Thank you for your purchase - %s', 'mim-dollar-toolkit' ) ),
			),
			'customer_cancelled'  => array(
				'label'     => __( 'Pre-invoice cancelled (to customer)', 'mim-dollar-toolkit' ),
				'to'        => 'customer',
				'trigger'   => __( 'Sent when a pre-invoice order goes from "Pre-invoice sent" to Cancelled (e.g. expired).', 'mim-dollar-toolkit' ),
				'vars'      => array(
					'name'   => __( 'customer first name', 'mim-dollar-toolkit' ),
					'number' => __( 'pre-invoice number', 'mim-dollar-toolkit' ),
				),
				'suggested' => __( 'Dear %name%, pre-invoice %number% has expired and was cancelled. Contact us to have it issued again.', 'mim-dollar-toolkit' ),
			),
			'otp'                 => array(
				'label'     => __( 'Login code (OTP)', 'mim-dollar-toolkit' ),
				'to'        => 'otp',
				'trigger'   => __( 'Sent right away (not queued) when a customer asks for a login code on the My Account page. Not tied to an order.', 'mim-dollar-toolkit' ),
				'vars'      => array(
					'code' => __( 'the one-time login code', 'mim-dollar-toolkit' ),
				),
				'suggested' => str_replace( '%s', $store, /* translators: %s: store name (inserted literally; the other %-tokens are SMS pattern variables). */ __( 'Your %s login code: %code%', 'mim-dollar-toolkit' ) ),
			),
		);
	}

	/**
	 * Are the pre-invoice module and its classes available (events 1-4 depend on them)?
	 *
	 * @return bool
	 */
	public static function preinvoice_active() {
		return MDT_Toolkit::is_enabled( 'pre-invoice' ) && class_exists( 'MDT_PI_Page' ) && class_exists( 'MDT_PI_Number' );
	}

	/**
	 * Enabled, has a pattern code, module configured and pre-invoice active.
	 *
	 * @param string $event Event key.
	 * @return bool
	 */
	public static function is_active( $event ) {
		$defs = self::definitions();
		if ( ! isset( $defs[ $event ] ) || ! MDT_SMS_Settings::is_configured() ) {
			return false;
		}
		// Order events need the pre-invoice module; the login code (otp) does not.
		if ( 'otp' !== $event && ! self::preinvoice_active() ) {
			return false;
		}
		$ev = MDT_SMS_Settings::get( 'events' );
		return ! empty( $ev[ $event ]['enabled'] ) && '' !== (string) $ev[ $event ]['code'];
	}

	/**
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	public static function is_preinvoice_order( $order ) {
		$gateway = class_exists( 'MDT_PI_Settings' ) ? MDT_PI_Settings::GATEWAY_ID : 'mdt_preinvoice';
		return $order instanceof WC_Order && $gateway === $order->get_payment_method();
	}

	/**
	 * woocommerce_order_status_changed.
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Old status (no prefix).
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! self::is_preinvoice_order( $order ) || ! self::preinvoice_active() || ! MDT_SMS_Settings::is_configured() ) {
			return;
		}
		$status = class_exists( 'MDT_PI_Order_Status' ) ? MDT_PI_Order_Status::STATUS : 'mdt-preinvoice';

		if ( $status === $to && in_array( $from, array( 'pending', 'failed' ), true ) ) {
			MDT_SMS_Sender::queue( 'customer_preinvoice', $order, 'customer' );
			foreach ( array_keys( MDT_SMS_Settings::admin_numbers() ) as $i ) {
				MDT_SMS_Sender::queue( 'admin_new_preinvoice', $order, 'admin:' . $i );
			}
		} elseif ( in_array( $to, array( 'processing', 'completed' ), true ) && ! in_array( $from, array( 'processing', 'completed' ), true ) ) {
			if ( 'yes' !== $order->get_meta( '_mdt_sms_paid_sent' ) && self::is_active( 'customer_paid' ) ) {
				$order->update_meta_data( '_mdt_sms_paid_sent', 'yes' );
				$order->save_meta_data();
				MDT_SMS_Sender::queue( 'customer_paid', $order, 'customer' );
			}
		} elseif ( 'cancelled' === $to && $status === $from ) {
			MDT_SMS_Sender::queue( 'customer_cancelled', $order, 'customer' );
		}
	}

	/**
	 * Pattern variables for an event.
	 *
	 * @param string   $event Event key.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function params( $event, $order ) {
		$first = trim( $order->get_billing_first_name() );
		$last  = trim( $order->get_billing_last_name() );
		$full  = trim( $first . ' ' . $last );
		$name  = '' !== $first ? $first : ( '' !== $last ? $last : __( 'Customer', 'mim-dollar-toolkit' ) );

		if ( class_exists( 'MDT_PI_Number' ) ) {
			MDT_PI_Number::ensure( $order );
		}
		$number = (string) $order->get_meta( '_mdt_pi_number' );
		$total  = number_format( (float) $order->get_total(), 0, '.', ',' );

		switch ( $event ) {
			case 'customer_preinvoice':
				$valid  = (int) $order->get_meta( '_mdt_pi_valid_until' );
				$params = array(
					'name'   => $name,
					'number' => $number,
					'total'  => $total,
					'valid'  => $valid ? mdt_jdate( 'Y/m/d', $valid ) : '',
				);
				break;
			case 'admin_new_preinvoice':
				$params = array(
					'number' => $number,
					'name'   => '' !== $full ? $full : $name,
					'phone'  => MDT_SMS_Phone::local( MDT_SMS_Phone::normalize( $order->get_billing_phone() ) ),
					'total'  => $total,
				);
				break;
			default:
				$params = array(
					'name'   => $name,
					'number' => $number,
				);
		}
		$params = apply_filters( 'mdt_sms_event_params', $params, $event, $order );
		return is_array( $params ) ? $params : array();
	}

	/**
	 * Sample variables for the test button.
	 *
	 * @param string $event Event key.
	 * @param string $phone Test mobile (local form).
	 * @return array
	 */
	public static function sample_params( $event, $phone ) {
		$all  = array(
			'name'   => 'تست',
			'number' => 'PI-1405-0000',
			'total'  => '1,000,000',
			'valid'  => mdt_jdate( 'Y/m/d', time() + 2 * DAY_IN_SECONDS ),
			'phone'  => $phone,
			'code'   => '12345',
		);
		$defs = self::definitions();
		return isset( $defs[ $event ] ) ? array_intersect_key( $all, $defs[ $event ]['vars'] ) : array();
	}
}
