<?php
/**
 * Settings storage with defaults and sanitization.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Settings {

	const OPTION = 'wtt_usd_settings';

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'providers'               => array( 'bitpin', 'nobitex', 'wallex' ),
			'update_interval_minutes' => 60,
			'markup_percent'          => 0,
			'rate_round_step'         => 1000,
			'price_round_step'        => 1,
			'max_change_percent'      => 10,
			'manual_rate_enabled'     => false,
			'manual_rate'             => 0,
		);
	}

	public static function interval_choices() {
		return array( 15, 30, 60, 120, 180, 360 );
	}

	public static function rate_round_choices() {
		return array( 1, 100, 1000, 10000 );
	}

	public static function price_round_choices() {
		return array( 1, 1000, 10000, 100000 );
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Programmatic partial update (sanitized).
	 *
	 * @param array $partial Values to change.
	 */
	public static function update( array $partial ) {
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $partial ) ) );
	}

	/**
	 * Create the option with defaults if missing.
	 */
	public static function ensure_defaults() {
		if ( false === get_option( self::OPTION, false ) ) {
			add_option( self::OPTION, self::defaults() );
		}
	}

	/**
	 * Sanitize callback (idempotent).
	 *
	 * @param mixed $input Raw input.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			$input = array();
		}
		$out = array();

		$known     = array_keys( WTT_USD_Rate_Providers::get_all() );
		$providers = array();
		if ( isset( $input['providers'] ) && is_array( $input['providers'] ) ) {
			foreach ( $input['providers'] as $name ) {
				$name = sanitize_key( $name );
				if ( in_array( $name, $known, true ) ) {
					$providers[] = $name;
				}
			}
		} elseif ( ! isset( $input['providers'] ) ) {
			$providers = $defaults['providers'];
		}
		$providers = array_values( array_unique( $providers ) );
		if ( empty( $providers ) ) {
			$providers = $defaults['providers'];
		}
		$out['providers'] = $providers;

		$out['update_interval_minutes'] = self::choice( $input, 'update_interval_minutes', self::interval_choices(), $defaults );
		$out['rate_round_step']         = self::choice( $input, 'rate_round_step', self::rate_round_choices(), $defaults );
		$out['price_round_step']        = self::choice( $input, 'price_round_step', self::price_round_choices(), $defaults );

		$out['markup_percent']     = self::number( $input, 'markup_percent', 0, 100, $defaults );
		$out['max_change_percent'] = self::number( $input, 'max_change_percent', 0.1, 100, $defaults );

		$out['manual_rate_enabled'] = ! empty( $input['manual_rate_enabled'] );
		$manual                     = isset( $input['manual_rate'] ) ? (float) wc_format_decimal( $input['manual_rate'] ) : 0;
		$out['manual_rate']         = $manual > 0 ? $manual : 0;
		if ( $out['manual_rate_enabled'] && $out['manual_rate'] <= 0 ) {
			$out['manual_rate_enabled'] = false;
		}

		return $out;
	}

	private static function choice( $input, $key, $choices, $defaults ) {
		$val = isset( $input[ $key ] ) ? absint( $input[ $key ] ) : 0;
		return in_array( $val, $choices, true ) ? $val : $defaults[ $key ];
	}

	private static function number( $input, $key, $min, $max, $defaults ) {
		if ( ! isset( $input[ $key ] ) || '' === $input[ $key ] || ! is_numeric( wc_format_decimal( $input[ $key ] ) ) ) {
			return $defaults[ $key ];
		}
		$val = (float) wc_format_decimal( $input[ $key ] );
		return max( $min, min( $max, $val ) );
	}
}
