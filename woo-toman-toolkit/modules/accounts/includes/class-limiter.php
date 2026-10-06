<?php
/**
 * Rate limiting (transients) and a small DB-level lock to serialise OTP operations per mobile.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_Limiter {

	/**
	 * Transient name for a bucket.
	 *
	 * @param string $bucket Bucket type.
	 * @param string $id     Identifier (hashed here).
	 * @return string
	 */
	public static function key( $bucket, $id ) {
		return 'wtt_acc_' . $bucket . '_' . hash( 'sha256', (string) $id );
	}

	/**
	 * Hits in the current window and seconds until the window ends.
	 *
	 * @param string $key Transient name.
	 * @return array {count:int, reset:int}
	 */
	public static function status( $key ) {
		$d = get_transient( $key );
		if ( is_array( $d ) && isset( $d['count'], $d['until'] ) && (int) $d['until'] > time() ) {
			return array(
				'count' => (int) $d['count'],
				'reset' => (int) $d['until'] - time(),
			);
		}
		return array(
			'count' => 0,
			'reset' => 0,
		);
	}

	/**
	 * Register a hit.
	 *
	 * @param string $key    Transient name.
	 * @param int    $window Window in seconds (applies when a new window starts).
	 * @return int New count.
	 */
	public static function hit( $key, $window ) {
		$d = get_transient( $key );
		if ( is_array( $d ) && isset( $d['count'], $d['until'] ) && (int) $d['until'] > time() ) {
			$d['count'] = (int) $d['count'] + 1;
		} else {
			$d = array(
				'count' => 1,
				'until' => time() + (int) $window,
			);
		}
		set_transient( $key, $d, max( 1, (int) $d['until'] - time() ) );
		return (int) $d['count'];
	}

	/**
	 * Take an exclusive lock (atomic INSERT IGNORE on a wp_options row; stale after 15 s).
	 *
	 * @param string $name Lock id (hashed here).
	 * @return bool
	 */
	public static function lock( $name ) {
		global $wpdb;
		$opt = 'wtt_acc_lock_' . substr( hash( 'sha256', (string) $name ), 0, 40 );
		$now = (string) time();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$ok = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $opt, $now ) );
		if ( 1 === (int) $ok ) {
			return true;
		}
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $opt ) );
		if ( null !== $old && ( time() - (int) $old ) > 15 ) {
			// Steal a stale lock atomically (only one caller can match the old value).
			$stolen = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $now, $opt, $old ) );
			return 1 === (int) $stolen;
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery
		return false;
	}

	public static function unlock( $name ) {
		global $wpdb;
		$opt = 'wtt_acc_lock_' . substr( hash( 'sha256', (string) $name ), 0, 40 );
		$wpdb->delete( $wpdb->options, array( 'option_name' => $opt ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * $_SERVER key for a header name.
	 *
	 * @param string $name Header name, e.g. X-Real-IP.
	 * @return string
	 */
	public static function server_key( $name ) {
		return 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
	}

	/**
	 * Raw value of a request header ('' when absent).
	 *
	 * @param string $name Header name.
	 * @return string
	 */
	public static function header_value( $name ) {
		$key = self::server_key( $name );
		return isset( $_SERVER[ $key ] ) && is_string( $_SERVER[ $key ] ) ? trim( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/**
	 * Validated IP from a header value, or '' (X-Forwarded-For: leftmost valid public IP).
	 *
	 * @param string $value  Header value.
	 * @param bool   $is_xff Comma separated list?
	 * @return string
	 */
	private static function ip_from_value( $value, $is_xff ) {
		if ( '' === $value || strlen( $value ) > 1024 ) {
			return '';
		}
		$parts = $is_xff ? explode( ',', $value ) : array( $value );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( false === filter_var( $part, FILTER_VALIDATE_IP ) ) {
				continue;
			}
			if ( ! $is_xff || false !== filter_var( $part, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return $part;
			}
		}
		return '';
	}

	/**
	 * Client IP according to the ip_source setting (falls back to REMOTE_ADDR when the header is missing or
	 * invalid). The wtt_acc_client_ip filter is applied last.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$ip     = $remote;
		$source = WTT_ACC_Settings::get( 'ip_source' );
		$header = 'custom' === $source ? WTT_ACC_Settings::get( 'ip_custom_header' ) : ( 'remote_addr' === $source ? '' : $source );
		if ( '' !== $header ) {
			$found = self::ip_from_value( self::header_value( $header ), 'x-forwarded-for' === strtolower( $header ) );
			if ( '' !== $found ) {
				$ip = $found;
			}
		}
		$ip = apply_filters( 'wtt_acc_client_ip', $ip );
		$ip = is_string( $ip ) ? trim( $ip ) : '';
		return false !== filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}
}
