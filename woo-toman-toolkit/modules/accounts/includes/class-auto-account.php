<?php
/**
 * Automatic customer account at checkout (guest orders only), classic checkout and Store API.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Auto_Account {

	/**
	 * True only while WooCommerce's own checkout handler (classic or Store API) is running this request.
	 *
	 * @var bool
	 */
	private static $in_checkout = false;

	public static function init() {
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'mark_checkout' ), 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mark_checkout' ), 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_classic' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api' ), 20, 1 );
	}

	public static function mark_checkout() {
		self::$in_checkout = true;
	}

	/**
	 * @param int           $order_id Order id.
	 * @param array         $posted   Posted data.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_classic( $order_id, $posted = array(), $order = null ) {
		unset( $posted );
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		self::process( $order );
	}

	/**
	 * @param WC_Order $order Order.
	 */
	public static function on_store_api( $order ) {
		self::process( $order );
	}

	private static function log( $level, $message ) {
		WTT_Logger::add( 'accounts', $level, $message );
	}

	/**
	 * Is the current request the checkout request itself (never cron, CLI or an unrelated admin/REST call)?
	 *
	 * @return bool
	 */
	private static function is_checkout_request() {
		if ( ! self::$in_checkout || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return false;
		}
		return true;
	}

	/**
	 * @param WC_Order|false $order Order.
	 */
	private static function process( $order ) {
		$in_checkout       = self::is_checkout_request();
		self::$in_checkout = false;
		if ( ! $order instanceof WC_Order || ! WTT_ACC_Settings::get( 'auto_account' ) ) {
			return;
		}

		if ( $order->get_customer_id() ) {
			self::handle_logged_in( $order );
			return;
		}
		$gateway = class_exists( 'WTT_PI_Settings' ) ? WTT_PI_Settings::GATEWAY_ID : 'wtt_preinvoice';
		if ( WTT_ACC_Settings::get( 'only_preinvoice' ) && $gateway !== $order->get_payment_method() ) {
			return;
		}

		$mobile = WTT_ACC_Users::normalize_mobile( (string) $order->get_billing_phone() );
		if ( '' === $mobile ) {
			return;
		}
		$masked = WTT_ACC_Users::mask( $mobile );
		$lock   = 'auto|' . $mobile;
		if ( ! WTT_ACC_Limiter::lock( $lock ) ) {
			self::log( 'warning', sprintf( 'Order #%d: auto-account skipped (busy) for %s.', $order->get_id(), $masked ) );
			return;
		}
		try {
			// 1. Existing account with this mobile.
			$users = WTT_ACC_Users::find_by_mobile( $mobile );
			if ( count( $users ) > 1 ) {
				self::log( 'warning', sprintf( 'Order #%d left as guest: several accounts share %s.', $order->get_id(), $masked ) );
				return;
			}
			if ( 1 === count( $users ) ) {
				self::attach( $order, $users[0], __( 'Attached to existing account #%d (matched mobile)', 'woo-toman-toolkit' ), $masked );
				return;
			}

			// 2. Existing account with this billing email.
			$email = sanitize_email( (string) $order->get_billing_email() );
			if ( '' !== $email && is_email( $email ) ) {
				$by_email = get_user_by( 'email', $email );
				if ( $by_email instanceof WP_User ) {
					self::attach( $order, $by_email, __( 'Attached to existing account #%d (matched email)', 'woo-toman-toolkit' ), $masked );
					return;
				}
			}

			// 3. Create a customer.
			$user_id = WTT_ACC_Users::create_customer(
				$mobile,
				$email,
				trim( (string) $order->get_billing_first_name() ),
				trim( (string) $order->get_billing_last_name() ),
				$order
			);
			if ( is_wp_error( $user_id ) ) {
				self::log( 'error', sprintf( 'Order #%d: account creation for %s failed: %s', $order->get_id(), $masked, $user_id->get_error_message() ) );
				return;
			}
			$order->set_customer_id( $user_id );
			/* translators: %d: user id */
			$order->add_order_note( sprintf( __( 'Customer account #%d was created automatically.', 'woo-toman-toolkit' ), $user_id ) );
			$order->save();
			self::log( 'info', sprintf( 'Order #%d: account #%d created for %s.', $order->get_id(), $user_id, $masked ) );

			// Log in ONLY the account we just created, only inside the checkout request, only for a guest.
			if ( $in_checkout && ! is_user_logged_in() ) {
				// Session cookie only (not "remember me"): the buyer may be a stranger using someone else's mobile.
				// Same steps as wc_set_customer_auth_cookie() otherwise, so the WooCommerce session/cart carries over.
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id, false );
				if ( WC()->session && is_callable( array( WC()->session, 'init_session_cookie' ) ) ) {
					WC()->session->init_session_cookie();
				}
				self::log( 'info', sprintf( 'Order #%d: customer #%d logged in after checkout.', $order->get_id(), $user_id ) );
			}
		} finally {
			WTT_ACC_Limiter::unlock( $lock );
		}
	}

	/**
	 * Attach a guest order to an existing account (never logs in, never touches privileged users).
	 *
	 * @param WC_Order $order  Order.
	 * @param WP_User  $user   User.
	 * @param string   $note   Order note format with %d.
	 * @param string   $masked Masked mobile for the log.
	 */
	private static function attach( $order, $user, $note, $masked ) {
		if ( WTT_ACC_Users::is_privileged( $user ) ) {
			self::log( 'warning', sprintf( 'Order #%d left as guest: matching account is privileged (%s).', $order->get_id(), $masked ) );
			return;
		}
		$order->set_customer_id( (int) $user->ID );
		$order->add_order_note( sprintf( $note, (int) $user->ID ) );
		$order->save();
		self::log( 'info', sprintf( 'Order #%d attached to account #%d (%s).', $order->get_id(), $user->ID, $masked ) );
	}

	/**
	 * A logged-in customer checked out: remember the mobile if the account has none (never overwrite),
	 * and replace a placeholder email by the real one (the user is authenticated here).
	 *
	 * @param WC_Order $order Order.
	 */
	private static function handle_logged_in( $order ) {
		$user = get_userdata( (int) $order->get_customer_id() );
		if ( ! $user instanceof WP_User || get_current_user_id() !== (int) $user->ID || WTT_ACC_Users::is_privileged( $user ) ) {
			return;
		}
		$mobile = WTT_ACC_Users::normalize_mobile( (string) $order->get_billing_phone() );
		if ( '' !== $mobile && '' === (string) get_user_meta( $user->ID, WTT_ACC_Users::META_MOBILE, true ) && ! WTT_ACC_Users::mobile_taken( $mobile, $user->ID ) ) {
			update_user_meta( $user->ID, WTT_ACC_Users::META_MOBILE, $mobile );
			self::log( 'info', sprintf( 'Account #%d: mobile %s saved from checkout.', $user->ID, WTT_ACC_Users::mask( $mobile ) ) );
		}
		if ( get_user_meta( $user->ID, WTT_ACC_Users::META_PLACEHOLDER, true ) ) {
			$email = sanitize_email( (string) $order->get_billing_email() );
			if ( '' !== $email && is_email( $email ) && ! email_exists( $email ) ) {
				$res = wp_update_user(
					array(
						'ID'         => $user->ID,
						'user_email' => $email,
					)
				);
				if ( ! is_wp_error( $res ) ) {
					delete_user_meta( $user->ID, WTT_ACC_Users::META_PLACEHOLDER );
					self::log( 'info', sprintf( 'Account #%d: placeholder email replaced.', $user->ID ) );
				}
			}
		}
	}
}
