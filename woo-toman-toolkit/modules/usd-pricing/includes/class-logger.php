<?php
/**
 * USD pricing log: thin wrapper around the shared logger channel "usd-pricing".
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Logger {

	const CHANNEL = 'usd-pricing';

	/**
	 * Add an entry.
	 *
	 * @param string $level   info|warning|error.
	 * @param string $message Message.
	 */
	public static function add( $level, $message ) {
		WTT_Logger::add( self::CHANNEL, $level, $message );
	}

	public static function info( $message ) {
		self::add( 'info', $message );
	}

	public static function warning( $message ) {
		self::add( 'warning', $message );
	}

	public static function error( $message ) {
		self::add( 'error', $message );
	}

	/**
	 * Entries, newest first.
	 *
	 * @return array
	 */
	public static function all() {
		return WTT_Logger::all( self::CHANNEL );
	}

	public static function clear() {
		WTT_Logger::clear( self::CHANNEL );
	}
}
