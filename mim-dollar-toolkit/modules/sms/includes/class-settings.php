<?php
/**
 * SMS settings (option mdt_sms_settings, autoload = no because it holds the API key).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Settings {

	const OPTION = 'mdt_sms_settings';

	public static function defaults() {
		$events = array();
		foreach ( array_keys( MDT_SMS_Events::definitions() ) as $key ) {
			$events[ $key ] = array(
				'enabled' => 1,
				'code'    => '',
			);
		}
		return array(
			'provider'       => 'farazsms',
			'api_key'        => '',
			'sender'         => '',
			'admin_numbers'  => '',
			'require_mobile' => 1,
			'events'         => $events,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$out      = array_merge( $defaults, array_intersect_key( $stored, $defaults ) );
		$events   = array();
		foreach ( $defaults['events'] as $key => $def ) {
			$ev            = isset( $stored['events'][ $key ] ) && is_array( $stored['events'][ $key ] ) ? $stored['events'][ $key ] : array();
			$events[ $key ] = array(
				'enabled' => array_key_exists( 'enabled', $ev ) ? (int) ! empty( $ev['enabled'] ) : $def['enabled'],
				'code'    => isset( $ev['code'] ) ? (string) $ev['code'] : '',
			);
		}
		$out['events'] = $events;
		return $out;
	}

	public static function get( $key, $default = '' ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function has_api_key() {
		return '' !== (string) self::get( 'api_key' );
	}

	/**
	 * API key and sender are both set.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return self::has_api_key() && '' !== (string) self::get( 'sender' );
	}

	/**
	 * Valid admin mobiles (E.164).
	 *
	 * @return string[]
	 */
	public static function admin_numbers() {
		return MDT_SMS_Phone::parse_list( (string) self::get( 'admin_numbers' ) );
	}

	public static function require_mobile() {
		return (bool) self::get( 'require_mobile' );
	}

	/**
	 * Sanitize raw (unslashed) POST data. The API key is kept when the field is empty, removed with "clear".
	 *
	 * @param mixed $input    Raw input.
	 * @param array $existing Current settings.
	 * @param int   $invalid  Dropped admin numbers (by reference).
	 * @return array
	 */
	public static function sanitize( $input, $existing, &$invalid = 0 ) {
		$input = is_array( $input ) ? $input : array();
		$out   = array();

		$providers      = MDT_SMS_Providers::labels();
		$provider       = isset( $input['provider'] ) && is_string( $input['provider'] ) ? sanitize_key( $input['provider'] ) : '';
		$out['provider'] = isset( $providers[ $provider ] ) ? $provider : 'farazsms';

		$key = isset( $existing['api_key'] ) ? (string) $existing['api_key'] : '';
		if ( ! empty( $input['api_key_clear'] ) ) {
			$key = '';
		} elseif ( isset( $input['api_key'] ) && is_string( $input['api_key'] ) ) {
			$new = preg_replace( '/[^\x21-\x7E]/', '', $input['api_key'] );
			if ( is_string( $new ) && '' !== $new ) {
				$key = $new;
			}
		}
		$out['api_key'] = $key;

		$sender        = isset( $input['sender'] ) && is_string( $input['sender'] ) ? $input['sender'] : '';
		if ( 'ippanel_edge' === $out['provider'] ) {
			$out['sender'] = MDT_SMS_Phone::normalize_sender( $sender );
		} else {
			// Line numbers are stored as shown in the panel (digits only), e.g. 90008361.
			$digits        = preg_replace( '/\D+/', '', MDT_SMS_Phone::latin_digits( $sender ) );
			$digits        = is_string( $digits ) ? $digits : '';
			$out['sender'] = ( 0 === strpos( trim( $sender ), '+98' ) || 0 === strpos( $digits, '0098' ) ) ? preg_replace( '/^(0098|98)/', '', $digits ) : $digits;
		}

		$admin_raw            = isset( $input['admin_numbers'] ) && is_string( $input['admin_numbers'] ) ? $input['admin_numbers'] : '';
		$out['admin_numbers'] = implode( "\n", MDT_SMS_Phone::parse_list( $admin_raw, $invalid ) );

		$out['require_mobile'] = empty( $input['require_mobile'] ) ? 0 : 1;

		$out['events'] = array();
		foreach ( array_keys( MDT_SMS_Events::definitions() ) as $ev ) {
			$row = isset( $input['events'][ $ev ] ) && is_array( $input['events'][ $ev ] ) ? $input['events'][ $ev ] : array();
			$code = isset( $row['code'] ) && is_string( $row['code'] ) ? sanitize_text_field( $row['code'] ) : '';
			$out['events'][ $ev ] = array(
				'enabled' => empty( $row['enabled'] ) ? 0 : 1,
				'code'    => $code,
			);
		}
		return $out;
	}

	public static function save( array $settings ) {
		update_option( self::OPTION, $settings, false );
	}
}
