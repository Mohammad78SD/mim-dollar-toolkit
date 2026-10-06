<?php
/**
 * Accounts settings (option wtt_acc_settings, autoload = no).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Settings {

	const OPTION = 'wtt_acc_settings';

	public static function defaults() {
		return array(
			'auto_account'     => 1,
			'only_preinvoice'  => 1,
			'welcome_email'    => 0,
			'otp_enabled'      => 1,
			'otp_register'     => 1,
			'show_password'    => 1,
			'code_length'      => 5,
			'expiry'           => 5,
			'cooldown'         => 60,
			'mobile_per_hour'  => 5,
			'ip_send_per_hour' => 10,
			'ip_fail_per_hour' => 20,
			'ip_source'        => 'remote_addr',
			'ip_custom_header' => '',
		);
	}

	/**
	 * Allowed values of the ip_source setting.
	 *
	 * @return string[]
	 */
	public static function ip_sources() {
		return array( 'remote_addr', 'cf-connecting-ip', 'x-forwarded-for', 'x-real-ip', 'ar-real-ip', 'custom' );
	}

	/**
	 * Header name safe to turn into a $_SERVER key ('' when invalid).
	 *
	 * @param mixed $name Raw header name.
	 * @return string
	 */
	public static function clean_header_name( $name ) {
		$name = is_string( $name ) ? trim( $name ) : '';
		return preg_match( '/^[A-Za-z0-9-]{1,64}$/D', $name ) ? $name : '';
	}

	/**
	 * Settings merged with defaults and clamped.
	 *
	 * @return array
	 */
	public static function all() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		foreach ( array( 'auto_account', 'only_preinvoice', 'welcome_email', 'otp_enabled', 'otp_register', 'show_password' ) as $k ) {
			$out[ $k ] = empty( $out[ $k ] ) ? 0 : 1;
		}
		$out['code_length'] = 6 === (int) $out['code_length'] ? 6 : 5;
		$out['expiry']      = max( 1, min( 30, (int) $out['expiry'] ) );
		$out['cooldown']    = max( 30, min( 600, (int) $out['cooldown'] ) );
		$out['mobile_per_hour']   = max( 1, min( 100, (int) $out['mobile_per_hour'] ) );
		$out['ip_send_per_hour']  = max( 1, min( 1000, (int) $out['ip_send_per_hour'] ) );
		$out['ip_fail_per_hour']  = max( 1, min( 1000, (int) $out['ip_fail_per_hour'] ) );
		$out['ip_source']         = in_array( $out['ip_source'], self::ip_sources(), true ) ? $out['ip_source'] : 'remote_addr';
		$out['ip_custom_header']  = self::clean_header_name( $out['ip_custom_header'] );
		return $out;
	}

	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : '';
	}

	/**
	 * Sanitize raw (unslashed) POST data.
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();
		foreach ( array( 'auto_account', 'only_preinvoice', 'welcome_email', 'otp_enabled', 'otp_register', 'show_password' ) as $k ) {
			$out[ $k ] = empty( $input[ $k ] ) ? 0 : 1;
		}
		$out['code_length'] = ( isset( $input['code_length'] ) && 6 === (int) $input['code_length'] ) ? 6 : 5;
		$out['expiry']      = max( 1, min( 30, isset( $input['expiry'] ) ? absint( $input['expiry'] ) : 5 ) );
		$out['cooldown']    = max( 30, min( 600, isset( $input['cooldown'] ) ? absint( $input['cooldown'] ) : 60 ) );
		$out['mobile_per_hour']  = max( 1, min( 100, isset( $input['mobile_per_hour'] ) ? absint( $input['mobile_per_hour'] ) : 5 ) );
		$out['ip_send_per_hour'] = max( 1, min( 1000, isset( $input['ip_send_per_hour'] ) ? absint( $input['ip_send_per_hour'] ) : 10 ) );
		$out['ip_fail_per_hour'] = max( 1, min( 1000, isset( $input['ip_fail_per_hour'] ) ? absint( $input['ip_fail_per_hour'] ) : 20 ) );
		$src                     = isset( $input['ip_source'] ) && is_string( $input['ip_source'] ) ? $input['ip_source'] : 'remote_addr';
		$out['ip_source']        = in_array( $src, self::ip_sources(), true ) ? $src : 'remote_addr';
		$out['ip_custom_header'] = self::clean_header_name( isset( $input['ip_custom_header'] ) ? sanitize_text_field( $input['ip_custom_header'] ) : '' );
		if ( 'custom' === $out['ip_source'] && '' === $out['ip_custom_header'] ) {
			$out['ip_source'] = 'remote_addr';
		}
		return $out;
	}

	public static function save( array $settings ) {
		update_option( self::OPTION, $settings, false );
	}
}
