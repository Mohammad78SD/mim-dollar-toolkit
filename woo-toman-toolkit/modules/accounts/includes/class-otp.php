<?php
/**
 * OTP logic: code issuing, storage (HMAC only), verification, rate limits and login.
 * Every method returns a plain array; the REST layer turns it into a response.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_OTP {

	const MAX_ATTEMPTS     = 5;
	const META_VERIFIED    = 'wtt_mobile_verified';

	/**
	 * Can login codes be issued (setting on, SMS module on and configured, otp pattern set)?
	 *
	 * @return bool
	 */
	public static function available() {
		return (bool) WTT_ACC_Settings::get( 'otp_enabled' )
			&& class_exists( 'WTT_SMS_Events' )
			&& WTT_Toolkit::is_enabled( 'sms' )
			&& WTT_SMS_Events::is_active( 'otp' );
	}

	private static function log( $level, $message ) {
		WTT_Logger::add( 'accounts', $level, $message );
	}

	private static function fail( $status, $code, $message, $extra = array() ) {
		return array_merge(
			array(
				'ok'      => false,
				'status'  => $status,
				'code'    => $code,
				'message' => $message,
			),
			$extra
		);
	}

	private static function rate_error( $seconds ) {
		$seconds = max( 1, (int) $seconds );
		if ( $seconds < 120 ) {
			/* translators: %d: number of seconds */
			$text = sprintf( _n( 'Too many requests. Please try again in %d second.', 'Too many requests. Please try again in %d seconds.', $seconds, 'woo-toman-toolkit' ), $seconds );
		} else {
			/* translators: %s: time span, e.g. "5 minutes" */
			$text = sprintf( __( 'Too many requests. Please try again in %s.', 'woo-toman-toolkit' ), human_time_diff( time(), time() + $seconds ) );
		}
		return self::fail( 429, 'wtt_acc_rate_limited', $text, array( 'retry_after' => $seconds ) );
	}

	private static function otp_key( $mobile ) {
		return WTT_ACC_Limiter::key( 'otp', $mobile );
	}

	private static function hash_code( $code, $mobile ) {
		return hash_hmac( 'sha256', $mobile . '|' . $code, wp_salt( 'auth' ) );
	}

	/**
	 * POST /otp/request.
	 *
	 * @param string $raw_mobile Raw mobile.
	 * @return array
	 */
	public static function request( $raw_mobile ) {
		if ( ! self::available() ) {
			return self::fail( 503, 'wtt_acc_unavailable', __( 'Login with a code is currently unavailable.', 'woo-toman-toolkit' ) );
		}
		$mobile = WTT_ACC_Users::normalize_mobile( $raw_mobile );
		if ( '' === $mobile ) {
			return self::fail( 400, 'wtt_acc_bad_mobile', __( 'Please enter a valid Iranian mobile number, for example 09123456789.', 'woo-toman-toolkit' ) );
		}
		$masked   = WTT_ACC_Users::mask( $mobile );
		$ip_key   = WTT_ACC_Limiter::key( 'rip', WTT_ACC_Limiter::client_ip() );
		$mob_key  = WTT_ACC_Limiter::key( 'rmo', $mobile );
		$cool_key = WTT_ACC_Limiter::key( 'cd', $mobile );
		$cooldown = (int) WTT_ACC_Settings::get( 'cooldown' );

		if ( ! WTT_ACC_Limiter::lock( 'otp|' . $mobile ) ) {
			return self::rate_error( 3 );
		}
		try {
			// Rate limits: identical behaviour whether or not an account exists.
			$cd = get_transient( $cool_key );
			if ( is_numeric( $cd ) && (int) $cd + $cooldown > time() ) {
				self::log( 'warning', sprintf( 'OTP request for %s rejected: cooldown.', $masked ) );
				return self::rate_error( (int) $cd + $cooldown - time() );
			}
			$m = WTT_ACC_Limiter::status( $mob_key );
			if ( $m['count'] >= (int) WTT_ACC_Settings::get( 'mobile_per_hour' ) ) {
				self::log( 'warning', sprintf( 'OTP request for %s rejected: hourly limit per mobile.', $masked ) );
				return self::rate_error( $m['reset'] );
			}
			$i = WTT_ACC_Limiter::status( $ip_key );
			if ( $i['count'] >= (int) WTT_ACC_Settings::get( 'ip_send_per_hour' ) ) {
				self::log( 'warning', sprintf( 'OTP request for %s rejected: hourly limit per IP.', $masked ) );
				return self::rate_error( $i['reset'] );
			}
			WTT_ACC_Limiter::hit( $mob_key, HOUR_IN_SECONDS );
			WTT_ACC_Limiter::hit( $ip_key, HOUR_IN_SECONDS );
			set_transient( $cool_key, time(), $cooldown );
			self::log( 'info', sprintf( 'OTP requested for %s.', $masked ) );

			$generic = array(
				'ok'          => true,
				'status'      => 200,
				'message'     => __( 'If this number can receive SMS, a login code has been sent.', 'woo-toman-toolkit' ),
				'cooldown'    => $cooldown,
				'code_length' => (int) WTT_ACC_Settings::get( 'code_length' ),
			);

			// Decide silently whether to send. The response never reveals the outcome.
			$users = WTT_ACC_Users::find_by_mobile( $mobile );
			if ( ! $users ) {
				$users = WTT_ACC_Users::find_by_billing_phone( $mobile );
			}
			if ( count( $users ) > 1 ) {
				self::log( 'warning', sprintf( 'OTP for %s not sent: several accounts share this mobile.', $masked ) );
				return $generic;
			}
			if ( 1 === count( $users ) ) {
				if ( WTT_ACC_Users::is_privileged( $users[0] ) ) {
					self::log( 'warning', sprintf( 'OTP for %s blocked: privileged account.', $masked ) );
					return $generic;
				}
			} elseif ( ! WTT_ACC_Settings::get( 'otp_register' ) ) {
				self::log( 'info', sprintf( 'OTP for %s not sent: no account and registration via OTP is off.', $masked ) );
				return $generic;
			}

			$len  = (int) WTT_ACC_Settings::get( 'code_length' );
			$code = str_pad( (string) random_int( 0, (int) pow( 10, $len ) - 1 ), $len, '0', STR_PAD_LEFT );
			$ttl  = (int) WTT_ACC_Settings::get( 'expiry' ) * MINUTE_IN_SECONDS;
			set_transient(
				self::otp_key( $mobile ),
				array(
					'hash'     => self::hash_code( $code, $mobile ),
					'exp'      => time() + $ttl,
					'attempts' => 0,
				),
				$ttl
			);

			$res = WTT_SMS_Sender::send_now( 'otp', $mobile, array( 'code' => $code ) );
			unset( $code );
			if ( empty( $res['ok'] ) ) {
				delete_transient( self::otp_key( $mobile ) );
				self::log( 'error', sprintf( 'OTP SMS to %s failed: %s', $masked, isset( $res['error'] ) ? (string) $res['error'] : 'unknown' ) );
				return self::fail( 500, 'wtt_acc_send_failed', __( 'The code could not be sent. Please try again later.', 'woo-toman-toolkit' ) );
			}
			self::log( 'info', sprintf( 'OTP SMS sent to %s.', $masked ) );
			return $generic;
		} finally {
			WTT_ACC_Limiter::unlock( 'otp|' . $mobile );
		}
	}

	/**
	 * POST /otp/verify.
	 *
	 * @param string $raw_mobile Raw mobile.
	 * @param string $raw_code   Raw code.
	 * @param string $redirect   Requested redirect.
	 * @return array
	 */
	public static function verify( $raw_mobile, $raw_code, $redirect = '' ) {
		if ( ! self::available() ) {
			return self::fail( 503, 'wtt_acc_unavailable', __( 'Login with a code is currently unavailable.', 'woo-toman-toolkit' ) );
		}
		$mobile = WTT_ACC_Users::normalize_mobile( $raw_mobile );
		$code   = preg_replace( '/\D+/', '', WTT_SMS_Phone::latin_digits( (string) $raw_code ) );
		if ( '' === $mobile || ! is_string( $code ) || '' === $code || strlen( $code ) > 10 ) {
			return self::fail( 400, 'wtt_acc_wrong_code', __( 'The code is incorrect.', 'woo-toman-toolkit' ) );
		}
		$masked  = WTT_ACC_Users::mask( $mobile );
		$fip_key = WTT_ACC_Limiter::key( 'fip', WTT_ACC_Limiter::client_ip() );

		$f = WTT_ACC_Limiter::status( $fip_key );
		if ( $f['count'] >= (int) WTT_ACC_Settings::get( 'ip_fail_per_hour' ) ) {
			self::log( 'warning', sprintf( 'OTP verify for %s rejected: too many failures from this IP.', $masked ) );
			return self::rate_error( $f['reset'] );
		}
		if ( ! WTT_ACC_Limiter::lock( 'otp|' . $mobile ) ) {
			return self::rate_error( 3 );
		}
		try {
			$key  = self::otp_key( $mobile );
			$data = get_transient( $key );
			if ( ! is_array( $data ) || ! isset( $data['hash'], $data['exp'], $data['attempts'] ) || (int) $data['exp'] < time() ) {
				delete_transient( $key );
				self::log( 'warning', sprintf( 'OTP verify for %s failed: no valid code.', $masked ) );
				return self::fail( 400, 'wtt_acc_expired', __( 'The code has expired or was not requested. Please request a new code.', 'woo-toman-toolkit' ) );
			}
			if ( (int) $data['attempts'] >= self::MAX_ATTEMPTS ) {
				delete_transient( $key );
				return self::fail( 400, 'wtt_acc_locked', __( 'Too many wrong attempts. Please request a new code.', 'woo-toman-toolkit' ) );
			}
			if ( ! hash_equals( (string) $data['hash'], self::hash_code( $code, $mobile ) ) ) {
				$data['attempts'] = (int) $data['attempts'] + 1;
				WTT_ACC_Limiter::hit( $fip_key, HOUR_IN_SECONDS );
				self::log( 'warning', sprintf( 'OTP verify for %s failed: wrong code (attempt %d).', $masked, $data['attempts'] ) );
				if ( $data['attempts'] >= self::MAX_ATTEMPTS ) {
					delete_transient( $key );
					return self::fail( 400, 'wtt_acc_locked', __( 'Too many wrong attempts. Please request a new code.', 'woo-toman-toolkit' ) );
				}
				set_transient( $key, $data, max( 1, (int) $data['exp'] - time() ) );
				return self::fail( 400, 'wtt_acc_wrong_code', __( 'The code is incorrect.', 'woo-toman-toolkit' ) );
			}
			delete_transient( $key ); // One use only.

			$refuse = self::fail( 400, 'wtt_acc_refused', __( 'Login with a code is not possible for this account. Please use your password or contact us.', 'woo-toman-toolkit' ) );
			$users  = WTT_ACC_Users::find_by_mobile( $mobile );
			if ( ! $users ) {
				$users = WTT_ACC_Users::find_by_billing_phone( $mobile );
			}
			if ( count( $users ) > 1 ) {
				self::log( 'warning', sprintf( 'OTP login for %s refused: several accounts share this mobile.', $masked ) );
				return $refuse;
			}
			if ( 1 === count( $users ) ) {
				$user = $users[0];
				if ( WTT_ACC_Users::is_privileged( $user ) ) {
					self::log( 'warning', sprintf( 'OTP login for %s blocked: privileged account.', $masked ) );
					return $refuse;
				}
				if ( '' === (string) get_user_meta( $user->ID, WTT_ACC_Users::META_MOBILE, true ) ) {
					update_user_meta( $user->ID, WTT_ACC_Users::META_MOBILE, $mobile );
				}
			} else {
				if ( ! WTT_ACC_Settings::get( 'otp_register' ) ) {
					return $refuse;
				}
				$new_id = WTT_ACC_Users::create_customer( $mobile, '' );
				if ( is_wp_error( $new_id ) ) {
					self::log( 'error', sprintf( 'OTP registration for %s failed: %s', $masked, $new_id->get_error_message() ) );
					return self::fail( 500, 'wtt_acc_register_failed', __( 'The account could not be created. Please try again later.', 'woo-toman-toolkit' ) );
				}
				$user = get_userdata( $new_id );
				if ( ! $user instanceof WP_User ) {
					return $refuse;
				}
				update_user_meta( $user->ID, self::META_VERIFIED, '1' );
				self::log( 'info', sprintf( 'Account #%d created via OTP for %s.', $user->ID, $masked ) );
			}

			// The mobile was not proven before (e.g. auto-created at checkout by a stranger): end every
			// existing session so nobody who got in earlier stays logged in, then mark the mobile as proven.
			if ( '1' !== (string) get_user_meta( $user->ID, self::META_VERIFIED, true ) ) {
				WP_Session_Tokens::get_instance( $user->ID )->destroy_all();
				update_user_meta( $user->ID, self::META_VERIFIED, '1' );
				self::log( 'warning', sprintf( 'Mobile %s verified for user #%d for the first time: all earlier sessions destroyed.', $masked, $user->ID ) );
			}
			wc_set_customer_auth_cookie( $user->ID ); // Remember = 14 days: the owner proved possession of the mobile.
			// Same hook core fires after a password login, so last-login plugins and WooCommerce keep working.
			do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals
			do_action( 'wtt_acc_otp_login', $user->ID );
			self::log( 'info', sprintf( 'OTP login verified for %s (user #%d).', $masked, $user->ID ) );

			$default = wc_get_page_permalink( 'myaccount' );
			$target  = '' !== (string) $redirect ? wp_validate_redirect( esc_url_raw( $redirect ), $default ) : $default;
			return array(
				'ok'       => true,
				'status'   => 200,
				'redirect' => $target,
			);
		} finally {
			WTT_ACC_Limiter::unlock( 'otp|' . $mobile );
		}
	}
}
