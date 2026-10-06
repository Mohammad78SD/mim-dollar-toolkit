<?php
/**
 * User helpers: mobile meta, privileged check, creation of customers.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Users {

	const META_MOBILE      = 'wtt_mobile';
	const META_PLACEHOLDER = 'wtt_placeholder_email';

	/**
	 * Raw input to the stored 09xxxxxxxxx form ('' when it is not an Iranian mobile).
	 *
	 * @param string $raw Raw number (Persian digits allowed).
	 * @return string
	 */
	public static function normalize_mobile( $raw ) {
		if ( ! is_string( $raw ) || '' === $raw || strlen( $raw ) > 64 ) {
			return '';
		}
		$e164 = WTT_SMS_Phone::normalize( $raw );
		return WTT_SMS_Phone::is_valid_mobile( $e164 ) ? WTT_SMS_Phone::local( $e164 ) : '';
	}

	/**
	 * E.164 of a stored mobile.
	 *
	 * @param string $mobile 09xxxxxxxxx.
	 * @return string
	 */
	public static function e164( $mobile ) {
		return WTT_SMS_Phone::normalize( $mobile );
	}

	/**
	 * Mask for logs.
	 *
	 * @param string $mobile Any form.
	 * @return string
	 */
	public static function mask( $mobile ) {
		return WTT_SMS_Phone::mask( $mobile );
	}

	/**
	 * Anyone who is more than a customer/subscriber: never OTP-logged-in, never attached to orders.
	 *
	 * @param WP_User|int $user User.
	 * @return bool
	 */
	public static function is_privileged( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user instanceof WP_User ) {
			return true;
		}
		return user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_woocommerce' ) || user_can( $user, 'manage_options' );
	}

	/**
	 * Users whose wtt_mobile equals the mobile (at most 2 returned, enough to detect ambiguity).
	 *
	 * @param string $mobile 09xxxxxxxxx.
	 * @return WP_User[]
	 */
	public static function find_by_mobile( $mobile ) {
		if ( '' === $mobile ) {
			return array();
		}
		$users = get_users(
			array(
				'meta_key'   => self::META_MOBILE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $mobile, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/**
	 * Fallback for OTP login: the WooCommerce billing_phone meta, only when exactly one user matches.
	 *
	 * @param string $mobile 09xxxxxxxxx.
	 * @return WP_User[]
	 */
	public static function find_by_billing_phone( $mobile ) {
		if ( '' === $mobile ) {
			return array();
		}
		$users = get_users(
			array(
				'meta_key'   => 'billing_phone', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => $mobile, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'number'     => 2,
				'orderby'    => 'ID',
				'order'      => 'ASC',
			)
		);
		return is_array( $users ) ? $users : array();
	}

	/**
	 * Is the mobile used by a user other than $except_id?
	 *
	 * @param string $mobile    09xxxxxxxxx.
	 * @param int    $except_id User to ignore.
	 * @return bool
	 */
	public static function mobile_taken( $mobile, $except_id = 0 ) {
		foreach ( self::find_by_mobile( $mobile ) as $u ) {
			if ( (int) $u->ID !== (int) $except_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Placeholder email for accounts created without a real one.
	 *
	 * @param string $mobile 09xxxxxxxxx.
	 * @return string
	 */
	public static function placeholder_email( $mobile ) {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = is_string( $host ) && '' !== $host ? strtolower( $host ) : 'localhost';
		return sanitize_email( $mobile . '@noemail.' . $host );
	}

	/**
	 * Unique username: the mobile, or mobile-NNNN when taken.
	 *
	 * @param string $mobile 09xxxxxxxxx.
	 * @return string
	 */
	private static function username( $mobile ) {
		$name = $mobile;
		$i    = 0;
		while ( username_exists( $name ) && $i < 10 ) {
			$name = $mobile . '-' . wp_rand( 1000, 9999 );
			++$i;
		}
		return $name;
	}

	/**
	 * Create a WooCommerce customer for a mobile. The WooCommerce "new account" email is suppressed unless the
	 * "send welcome email" setting is on.
	 *
	 * @param string        $mobile 09xxxxxxxxx.
	 * @param string        $email  Real email or '' (a placeholder is used then).
	 * @param string        $first  First name.
	 * @param string        $last   Last name.
	 * @param WC_Order|null $order  Order whose addresses are copied to the customer.
	 * @return int|WP_Error User id.
	 */
	public static function create_customer( $mobile, $email, $first = '', $last = '', $order = null ) {
		$email       = sanitize_email( (string) $email );
		$placeholder = false;
		if ( '' === $email || ! is_email( $email ) || email_exists( $email ) ) {
			$email       = self::placeholder_email( $mobile );
			$placeholder = true;
			if ( ! is_email( $email ) || email_exists( $email ) ) {
				return new WP_Error( 'wtt_acc_email', 'no usable email address' );
			}
		}

		$suppress = ! WTT_ACC_Settings::get( 'welcome_email' );
		if ( $suppress ) {
			add_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 999 );
		}
		$args = array();
		if ( '' !== $first ) {
			$args['first_name'] = $first;
		}
		if ( '' !== $last ) {
			$args['last_name'] = $last;
		}
		$user_id = wc_create_new_customer( $email, self::username( $mobile ), wp_generate_password( 24 ), $args );
		if ( $suppress ) {
			remove_filter( 'woocommerce_email_enabled_customer_new_account', '__return_false', 999 );
		}
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}
		$user_id = (int) $user_id;

		update_user_meta( $user_id, self::META_MOBILE, $mobile );
		update_user_meta( $user_id, WTT_ACC_OTP::META_VERIFIED, '0' ); // Proven only by a login code.
		if ( $placeholder ) {
			update_user_meta( $user_id, self::META_PLACEHOLDER, 1 );
		}
		$display = trim( $first . ' ' . $last );
		if ( '' !== $display ) {
			wp_update_user(
				array(
					'ID'           => $user_id,
					'display_name' => $display,
				)
			);
		}

		$customer = new WC_Customer( $user_id );
		if ( $order instanceof WC_Order ) {
			foreach ( array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country' ) as $f ) {
				$get = 'get_billing_' . $f;
				$set = 'set_billing_' . $f;
				$customer->$set( $order->$get() );
				$get = 'get_shipping_' . $f;
				$set = 'set_shipping_' . $f;
				$customer->$set( $order->$get() );
			}
			$customer->set_shipping_phone( $order->get_shipping_phone() );
		}
		$customer->set_billing_phone( $mobile );
		if ( ! $placeholder ) {
			$customer->set_billing_email( $email );
		}
		$customer->save();

		return $user_id;
	}
}
