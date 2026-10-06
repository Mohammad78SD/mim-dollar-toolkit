<?php
/**
 * Pre-invoice settings: field definitions, defaults, sanitization, placeholders.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Settings {

	const OPTION     = 'wtt_pi_settings';
	const GATEWAY_ID = 'wtt_preinvoice';

	/**
	 * Field definitions in display order: key => array( type, label, desc, group ).
	 * Types: text, email, url, textarea, number, checkbox, media.
	 *
	 * @return array
	 */
	public static function fields() {
		$ph = __( 'Placeholders: {pi_number}, {valid_until}, {site_title}, {shop_phone}, {customer_name}.', 'woo-toman-toolkit' );
		return array(
			'shop_name'         => array( 'text', __( 'Shop name', 'woo-toman-toolkit' ), '', 'shop' ),
			'logo_id'           => array( 'media', __( 'Logo', 'woo-toman-toolkit' ), '', 'shop' ),
			'address'           => array( 'textarea', __( 'Address', 'woo-toman-toolkit' ), '', 'shop' ),
			'phone'             => array( 'text', __( 'Phone', 'woo-toman-toolkit' ), '', 'shop' ),
			'email'             => array( 'email', __( 'Email', 'woo-toman-toolkit' ), '', 'shop' ),
			'website'           => array( 'url', __( 'Website', 'woo-toman-toolkit' ), '', 'shop' ),
			'national_id'       => array( 'text', __( 'National ID', 'woo-toman-toolkit' ), '', 'shop' ),
			'economic_code'     => array( 'text', __( 'Economic code', 'woo-toman-toolkit' ), '', 'shop' ),
			'registration_no'   => array( 'text', __( 'Registration number', 'woo-toman-toolkit' ), '', 'shop' ),
			'signature_id'      => array( 'media', __( 'Signature / stamp image', 'woo-toman-toolkit' ), '', 'shop' ),
			'validity_hours'    => array( 'number', __( 'Pre-invoice validity (hours)', 'woo-toman-toolkit' ), __( 'Also used as the step for the "Extend validity" order action.', 'woo-toman-toolkit' ), 'flow' ),
			'auto_cancel_days'  => array( 'number', __( 'Auto-cancel after (days)', 'woo-toman-toolkit' ), __( '0 = never. Stock is restored when an order is cancelled.', 'woo-toman-toolkit' ), 'flow' ),
			'number_prefix'     => array( 'text', __( 'Number prefix', 'woo-toman-toolkit' ), __( 'Numbers look like PREFIX + Jalali year + sequence, e.g. PI-1405-0001.', 'woo-toman-toolkit' ), 'flow' ),
			'only_gateway'      => array( 'checkbox', __( 'Only offer pre-invoice at checkout', 'woo-toman-toolkit' ), __( 'Hide all other payment methods on the storefront.', 'woo-toman-toolkit' ), 'flow' ),
			'show_images'       => array( 'checkbox', __( 'Show product images on the pre-invoice page', 'woo-toman-toolkit' ), '', 'flow' ),
			'cart_button_text'  => array( 'text', __( 'Cart "Proceed to checkout" button text', 'woo-toman-toolkit' ), __( 'Empty = WooCommerce default.', 'woo-toman-toolkit' ), 'texts' ),
			'checkout_notice'   => array( 'textarea', __( 'Checkout notice (classic checkout, above order review)', 'woo-toman-toolkit' ), '', 'texts' ),
			'thankyou_message'  => array( 'textarea', __( 'Thank-you page message', 'woo-toman-toolkit' ), $ph, 'texts' ),
			'email_intro'       => array( 'textarea', __( 'Email intro text', 'woo-toman-toolkit' ), $ph, 'texts' ),
			'payment_message'   => array( 'textarea', __( 'Payment / contact message', 'woo-toman-toolkit' ), $ph, 'texts' ),
			'footer_note'       => array( 'textarea', __( 'Footer note (e.g. bank details, terms)', 'woo-toman-toolkit' ), $ph, 'texts' ),
			'disclaimer'        => array( 'textarea', __( 'Validity disclaimer', 'woo-toman-toolkit' ), $ph, 'texts' ),
		);
	}

	/**
	 * Text keys where an empty stored value falls back to the (translatable) default.
	 *
	 * @return string[]
	 */
	private static function fallback_keys() {
		return array( 'shop_name', 'number_prefix', 'thankyou_message', 'email_intro', 'payment_message', 'disclaimer' );
	}

	public static function defaults() {
		return array(
			'shop_name'        => (string) get_option( 'blogname' ),
			'logo_id'          => 0,
			'address'          => '',
			'phone'            => '',
			'email'            => '',
			'website'          => '',
			'national_id'      => '',
			'economic_code'    => '',
			'registration_no'  => '',
			'signature_id'     => 0,
			'validity_hours'   => 48,
			'auto_cancel_days' => 3,
			'number_prefix'    => 'PI-',
			'only_gateway'     => 1,
			'show_images'      => 1,
			'cart_button_text' => __( 'Continue to request pre-invoice', 'woo-toman-toolkit' ),
			'checkout_notice'  => '',
			'thankyou_message' => __( 'Your pre-invoice {pi_number} has been issued and emailed to you. Our team will contact you as soon as possible to arrange payment.', 'woo-toman-toolkit' ),
			'email_intro'      => __( 'Thank you for your request. Your pre-invoice is below.', 'woo-toman-toolkit' ),
			'payment_message'  => __( 'Our team will contact you shortly to arrange payment.', 'woo-toman-toolkit' ),
			'footer_note'      => '',
			'disclaimer'       => __( 'Prices are valid until {valid_until} due to exchange rate changes.', 'woo-toman-toolkit' ),
		);
	}

	/**
	 * The untranslated (English) source of the default texts. Stored values that equal one of these
	 * were saved while the site was English (or never customised) and are swapped for the current
	 * locale's translated default at runtime. Keep in sync with defaults().
	 *
	 * @return array
	 */
	public static function english_defaults() {
		return array(
			'cart_button_text' => 'Continue to request pre-invoice',
			'thankyou_message' => 'Your pre-invoice {pi_number} has been issued and emailed to you. Our team will contact you as soon as possible to arrange payment.',
			'email_intro'      => 'Thank you for your request. Your pre-invoice is below.',
			'payment_message'  => 'Our team will contact you shortly to arrange payment.',
			'disclaimer'       => 'Prices are valid until {valid_until} due to exchange rate changes.',
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$defaults = self::defaults();
		$out      = array_merge( $defaults, $stored );
		foreach ( self::fallback_keys() as $key ) {
			if ( '' === trim( (string) $out[ $key ] ) ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}
		foreach ( self::english_defaults() as $key => $english ) {
			if ( trim( (string) $out[ $key ] ) === $english ) {
				$out[ $key ] = $defaults[ $key ];
			}
		}
		return $out;
	}

	public static function get( $key, $default = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Sanitize a raw (unslashed) array of settings.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( self::fields() as $key => $def ) {
			$raw = isset( $input[ $key ] ) ? $input[ $key ] : '';
			if ( is_array( $raw ) ) {
				$raw = '';
			}
			switch ( $def[0] ) {
				case 'checkbox':
					$out[ $key ] = empty( $input[ $key ] ) ? 0 : 1;
					break;
				case 'number':
					$out[ $key ] = max( 0, absint( $raw ) );
					if ( 'validity_hours' === $key && $out[ $key ] < 1 ) {
						$out[ $key ] = 48;
					}
					break;
				case 'media':
					$out[ $key ] = absint( $raw );
					break;
				case 'email':
					$out[ $key ] = sanitize_email( $raw );
					break;
				case 'url':
					$out[ $key ] = esc_url_raw( $raw );
					break;
				case 'textarea':
					$out[ $key ] = wp_kses_post( $raw );
					break;
				default:
					$out[ $key ] = sanitize_text_field( $raw );
			}
		}
		return $out;
	}

	/**
	 * Replace placeholders in a setting text. Returns HTML that is safe to echo
	 * (template passed through wp_kses_post, values escaped).
	 *
	 * @param string        $text  Template.
	 * @param WC_Order|null $order Order.
	 * @return string
	 */
	/**
	 * "Items reserved until X" sentence (escaped, localized digits); empty when no expiry.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function reserved_sentence( $order ) {
		$until = $order instanceof WC_Order ? WTT_PI_Orders::reserved_until( $order ) : null;
		if ( ! $until ) {
			return '';
		}
		/* translators: %s: date/time */
		return sprintf( __( 'Your items are reserved until %s.', 'woo-toman-toolkit' ), WTT_PI_Format::datetime( $until ) );
	}

	public static function replace( $text, $order = null ) {
		$text   = wp_kses_post( (string) $text );
		$values = array(
			'{site_title}' => (string) get_option( 'blogname' ),
			'{shop_phone}' => (string) self::get( 'phone' ),
		);
		if ( $order instanceof WC_Order ) {
			$valid                   = (int) $order->get_meta( '_wtt_pi_valid_until' );
			$values['{pi_number}']     = (string) $order->get_meta( '_wtt_pi_number' );
			$values['{valid_until}']   = $valid ? WTT_PI_Format::datetime( $valid ) : '';
			$values['{customer_name}'] = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		} else {
			$values['{pi_number}']     = '';
			$values['{valid_until}']   = '';
			$values['{customer_name}'] = '';
		}
		$values = array_map( 'esc_html', $values );
		$values = array_map( array( 'WTT_PI_Format', 'digits' ), $values );
		return nl2br( strtr( $text, $values ) );
	}
}
