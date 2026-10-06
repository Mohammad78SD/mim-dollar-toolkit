<?php
/**
 * Checkout: require a valid Iranian mobile number as billing phone (classic checkout and Store API / Blocks).
 *
 * Classic: billing_phone is made required through woocommerce_billing_fields and validated in
 * woocommerce_after_checkout_validation. Blocks: the phone field's required state is a WooCommerce
 * setting we do not touch; instead the number is validated server side in
 * woocommerce_store_api_checkout_update_order_from_request (an empty phone is invalid as well).
 * A valid number is saved on the order as 09xxxxxxxxx.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_SMS_Checkout {

	public static function init() {
		// Persian/Arabic digits to Latin before WooCommerce validates phone/postcode (always, independent of require-mobile).
		foreach ( array( 'billing_phone', 'shipping_phone', 'billing_postcode', 'shipping_postcode' ) as $key ) {
			add_filter( 'woocommerce_process_checkout_field_' . $key, array( 'WTT_SMS_Phone', 'latin_digits' ), 5 );
		}
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'normalize_store_api_request' ), 5, 3 );
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'require_field' ), 20 );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'normalize_classic' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_blocks' ), 10, 2 );
	}

	private static function message() {
		return __( 'Please enter a valid Iranian mobile number as your phone number, for example 09123456789.', 'woo-toman-toolkit' );
	}

	/**
	 * Store API: convert digits in phone/postcode of billing_address/shipping_address. Runs on rest_pre_dispatch,
	 * i.e. before WP_REST_Server::dispatch() validates args (the address schema checks the phone with
	 * WC_Validation::is_phone). Batch requests are handled on the outer /batch request, before the
	 * sub-requests are built and validated.
	 *
	 * @param mixed           $result  Pre-dispatch result (passed through).
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function normalize_store_api_request( $result, $server, $request ) {
		unset( $server );
		if ( ! $request instanceof WP_REST_Request || 0 !== strpos( $request->get_route(), '/wc/store/' ) ) {
			return $result;
		}
		self::normalize_request_params( $request );
		if ( '/wc/store/v1/batch' === untrailingslashit( $request->get_route() ) ) {
			$requests = $request->get_param( 'requests' );
			if ( is_array( $requests ) ) {
				foreach ( $requests as $i => $sub ) {
					if ( is_array( $sub ) && isset( $sub['body'] ) && is_array( $sub['body'] ) ) {
						$requests[ $i ]['body'] = self::normalize_addresses( $sub['body'] );
					}
				}
				$request->set_param( 'requests', $requests );
			}
		}
		return $result;
	}

	private static function normalize_request_params( $request ) {
		foreach ( array( 'billing_address', 'shipping_address' ) as $key ) {
			$address = $request->get_param( $key );
			if ( is_array( $address ) ) {
				$request->set_param( $key, self::normalize_addresses( array( $key => $address ) )[ $key ] );
			}
		}
	}

	private static function normalize_addresses( array $params ) {
		foreach ( array( 'billing_address', 'shipping_address' ) as $key ) {
			if ( isset( $params[ $key ] ) && is_array( $params[ $key ] ) ) {
				foreach ( array( 'phone', 'postcode' ) as $field ) {
					if ( isset( $params[ $key ][ $field ] ) && is_string( $params[ $key ][ $field ] ) ) {
						$params[ $key ][ $field ] = WTT_SMS_Phone::latin_digits( $params[ $key ][ $field ] );
					}
				}
			}
		}
		return $params;
	}

	public static function require_field( $fields ) {
		if ( WTT_SMS_Settings::require_mobile() && isset( $fields['billing_phone'] ) ) {
			$fields['billing_phone']['required'] = true;
		}
		return $fields;
	}

	/**
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 */
	public static function validate_classic( $data, $errors ) {
		if ( ! WTT_SMS_Settings::require_mobile() || ! is_array( $data ) || ! array_key_exists( 'billing_phone', $data ) ) {
			return;
		}
		$normalized = WTT_SMS_Phone::normalize( $data['billing_phone'] );
		// An empty phone is already reported by WooCommerce (the field is made required above).
		if ( '' !== $normalized && ! WTT_SMS_Phone::is_valid_mobile( $normalized ) ) {
			$errors->add( 'wtt_sms_invalid_phone', self::message(), array( 'id' => 'billing_phone' ) );
		}
	}

	/**
	 * @param WC_Order $order Order being created (classic checkout).
	 * @param array    $data  Posted data.
	 */
	public static function normalize_classic( $order, $data ) {
		unset( $data );
		self::normalize_order( $order );
	}

	/**
	 * @param WC_Order         $order   Order.
	 * @param \WP_REST_Request $request Request.
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the phone is invalid.
	 */
	public static function validate_blocks( $order, $request = null ) {
		unset( $request );
		if ( ! WTT_SMS_Settings::require_mobile() || ! $order instanceof WC_Order ) {
			return;
		}
		if ( ! WTT_SMS_Phone::is_valid_mobile( WTT_SMS_Phone::normalize( $order->get_billing_phone() ) ) ) {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'wtt_sms_invalid_phone', self::message(), 400 );
			}
			return;
		}
		self::normalize_order( $order );
	}

	private static function normalize_order( $order ) {
		if ( ! WTT_SMS_Settings::require_mobile() || ! $order instanceof WC_Order ) {
			return;
		}
		$e164 = WTT_SMS_Phone::normalize( $order->get_billing_phone() );
		if ( WTT_SMS_Phone::is_valid_mobile( $e164 ) ) {
			$order->set_billing_phone( WTT_SMS_Phone::local( $e164 ) );
		}
	}
}
