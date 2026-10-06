<?php
/**
 * Shared ring-buffer logger, one channel per module, stored in an option.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_Logger {

	const MAX = 100;

	/**
	 * Option name per channel. Legacy names are kept so existing logs survive.
	 *
	 * @param string $channel Channel id.
	 * @return string
	 */
	public static function option_name( $channel ) {
		$legacy = array( 'usd-pricing' => 'wtt_usd_log' );
		if ( isset( $legacy[ $channel ] ) ) {
			return $legacy[ $channel ];
		}
		return 'wtt_tk_log_' . str_replace( '-', '_', sanitize_key( $channel ) );
	}

	/**
	 * Translated label of a log level.
	 *
	 * @param string $level info|warning|error.
	 * @return string
	 */
	public static function level_label( $level ) {
		switch ( $level ) {
			case 'error':
				return __( 'Error', 'woo-toman-toolkit' );
			case 'warning':
				return __( 'Warning', 'woo-toman-toolkit' );
			case 'info':
				return __( 'Info', 'woo-toman-toolkit' );
		}
		return (string) $level;
	}

	/**
	 * Add an entry.
	 *
	 * @param string $channel Channel id.
	 * @param string $level   info|warning|error.
	 * @param string $message Message.
	 */
	public static function add( $channel, $level, $message ) {
		$option = self::option_name( $channel );
		$log    = get_option( $option, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'    => time(),
			'level'   => in_array( $level, array( 'info', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => wp_strip_all_tags( (string) $message ),
		);
		if ( count( $log ) > self::MAX ) {
			$log = array_slice( $log, -self::MAX );
		}
		update_option( $option, $log, false );
	}

	/**
	 * Entries, newest first.
	 *
	 * @param string $channel Channel id.
	 * @return array
	 */
	public static function all( $channel ) {
		$log = get_option( self::option_name( $channel ), array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/**
	 * Empty a channel.
	 *
	 * @param string $channel Channel id.
	 */
	public static function clear( $channel ) {
		update_option( self::option_name( $channel ), array(), false );
	}

	/**
	 * Delete a channel's storage (uninstall).
	 *
	 * @param string $channel Channel id.
	 */
	public static function delete( $channel ) {
		delete_option( self::option_name( $channel ) );
	}
}
