<?php
/**
 * Phone number helpers (Iranian mobile numbers, E.164).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Phone {

	/**
	 * Persian/Arabic-Indic digits to Latin, drop whitespace and bidi/ZWNJ marks.
	 *
	 * @param string $raw Raw input.
	 * @return string
	 */
	private static function clean( $raw ) {
		$map = array(
			'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
			'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
			'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
			'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
		);
		$s   = strtr( (string) $raw, $map );
		$s   = preg_replace( '/[\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]+/u', '', $s );
		return is_string( $s ) ? $s : '';
	}

	/**
	 * Convert Persian/Arabic-Indic digits to Latin and drop ZWNJ/bidi marks only (no other changes).
	 * Used to pre-process checkout fields before WooCommerce validates them.
	 *
	 * @param string $value Raw input.
	 * @return string
	 */
	public static function latin_digits( $value ) {
		$value = (string) $value;
		$s     = strtr(
			$value,
			array(
				'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
				'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
				'٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
				'٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
			)
		);
		$s     = preg_replace( '/[\x{200C}\x{200E}\x{200F}\x{202A}-\x{202E}]+/u', '', $s );
		return is_string( $s ) ? $s : $value;
	}

	/**
	 * Normalize a mobile number to E.164 when it looks Iranian; otherwise return the bare digits
	 * (which then fails is_valid_mobile()).
	 *
	 * @param string $raw Raw number.
	 * @return string
	 */
	public static function normalize( $raw ) {
		$s    = self::clean( $raw );
		$plus = ( 0 === strpos( $s, '+' ) );
		$d    = preg_replace( '/\D+/', '', $s );
		if ( '' === $d ) {
			return '';
		}
		if ( $plus ) {
			return '+' . $d;
		}
		if ( 0 === strpos( $d, '0098' ) ) {
			return '+98' . substr( $d, 4 );
		}
		if ( 0 === strpos( $d, '98' ) && 12 === strlen( $d ) ) {
			return '+' . $d;
		}
		if ( 0 === strpos( $d, '09' ) && 11 === strlen( $d ) ) {
			return '+98' . substr( $d, 1 );
		}
		if ( 0 === strpos( $d, '9' ) && 10 === strlen( $d ) ) {
			return '+98' . $d;
		}
		return $d;
	}

	/**
	 * Normalize the sender (service line) number: 3000505 / 983000505 / +983000505 => +983000505.
	 *
	 * @param string $raw Raw number.
	 * @return string
	 */
	public static function normalize_sender( $raw ) {
		$s    = self::clean( $raw );
		$plus = ( 0 === strpos( $s, '+' ) );
		$d    = preg_replace( '/\D+/', '', $s );
		if ( '' === $d ) {
			return '';
		}
		if ( $plus ) {
			return '+' . $d;
		}
		if ( 0 === strpos( $d, '0098' ) ) {
			return '+' . substr( $d, 2 );
		}
		if ( 0 === strpos( $d, '98' ) ) {
			return '+' . $d;
		}
		return '+98' . ltrim( $d, '0' );
	}

	/**
	 * Is this an E.164 Iranian mobile number?
	 *
	 * @param string $e164 Normalized number.
	 * @return bool
	 */
	public static function is_valid_mobile( $e164 ) {
		return 1 === preg_match( '/^\+989\d{9}$/D', (string) $e164 );
	}

	/**
	 * Human-friendly local form 09xxxxxxxxx (or the input when not an Iranian mobile).
	 *
	 * @param string $e164 Normalized number.
	 * @return string
	 */
	public static function local( $e164 ) {
		return self::is_valid_mobile( $e164 ) ? '0' . substr( $e164, 3 ) : (string) $e164;
	}

	/**
	 * Mask the middle digits for notes and logs: 0912***4567.
	 *
	 * @param string $number Any number.
	 * @return string
	 */
	public static function mask( $number ) {
		$n = self::local( self::normalize( $number ) );
		$n = ltrim( $n, '+' );
		if ( strlen( $n ) < 8 ) {
			return '***';
		}
		return substr( $n, 0, 4 ) . '***' . substr( $n, -4 );
	}

	/**
	 * Parse a comma/newline separated list into unique valid E.164 mobiles.
	 *
	 * @param string $text Raw list.
	 * @param int    $invalid Number of entries dropped (by reference).
	 * @return string[]
	 */
	public static function parse_list( $text, &$invalid = 0 ) {
		$invalid = 0;
		$out     = array();
		$parts   = preg_split( '/[\r\n,;،؛]+/u', (string) $text, -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $parts as $part ) {
			$n = self::normalize( $part );
			if ( self::is_valid_mobile( $n ) ) {
				$out[ $n ] = $n;
			} else {
				++$invalid;
			}
		}
		return array_values( $out );
	}
}
