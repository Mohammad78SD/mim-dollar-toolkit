<?php
/**
 * Jalali (Solar Hijri) date helpers.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wtt_gregorian_to_jalali' ) ) {
	/**
	 * Convert a Gregorian date to Jalali.
	 *
	 * @param int $gy Gregorian year.
	 * @param int $gm Gregorian month (1-12).
	 * @param int $gd Gregorian day.
	 * @return int[] array( year, month, day ).
	 */
	function wtt_gregorian_to_jalali( $gy, $gm, $gd ) {
		$gy = (int) $gy;
		$gm = (int) $gm;
		$gd = (int) $gd;

		$g_d_m = array( 0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334 );
		$gy2   = ( $gm > 2 ) ? ( $gy + 1 ) : $gy;
		$days  = 355666 + ( 365 * $gy ) + intdiv( $gy2 + 3, 4 ) - intdiv( $gy2 + 99, 100 ) + intdiv( $gy2 + 399, 400 ) + $gd + $g_d_m[ $gm - 1 ];
		$jy    = -1595 + ( 33 * intdiv( $days, 12053 ) );
		$days  = $days % 12053;
		$jy   += 4 * intdiv( $days, 1461 );
		$days  = $days % 1461;
		if ( $days > 365 ) {
			$jy   += intdiv( $days - 1, 365 );
			$days  = ( $days - 1 ) % 365;
		}
		if ( $days < 186 ) {
			$jm = 1 + intdiv( $days, 31 );
			$jd = 1 + ( $days % 31 );
		} else {
			$jm = 7 + intdiv( $days - 186, 30 );
			$jd = 1 + ( ( $days - 186 ) % 30 );
		}
		return array( $jy, $jm, $jd );
	}
}

if ( ! function_exists( 'wtt_jalali_month_names' ) ) {
	/**
	 * Persian month names, index 1-12.
	 *
	 * @return string[]
	 */
	function wtt_jalali_month_names() {
		return array( 1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند' );
	}
}

if ( ! function_exists( 'wtt_jdate' ) ) {
	/**
	 * Format a timestamp as a Jalali date in the site timezone. Latin digits; wrap with wtt_fa_digits() if needed.
	 *
	 * Supported: Y y m n d j H i s F l, and backslash escaping.
	 *
	 * @param string   $format    Format string.
	 * @param int|null $timestamp Unix timestamp (default now).
	 * @return string
	 */
	function wtt_jdate( $format = 'Y/m/d', $timestamp = null ) {
		if ( null === $timestamp ) {
			$timestamp = time();
		}
		$timestamp = (int) $timestamp;

		// Gregorian parts in the site timezone.
		$parts = explode( ' ', wp_date( 'Y n j H i s w', $timestamp ) );
		list( $jy, $jm, $jd ) = wtt_gregorian_to_jalali( (int) $parts[0], (int) $parts[1], (int) $parts[2] );

		$months   = wtt_jalali_month_names();
		$weekdays = array( 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه' );

		$map = array(
			'Y' => (string) $jy,
			'y' => substr( (string) $jy, -2 ),
			'm' => sprintf( '%02d', $jm ),
			'n' => (string) $jm,
			'd' => sprintf( '%02d', $jd ),
			'j' => (string) $jd,
			'H' => $parts[3],
			'i' => $parts[4],
			's' => $parts[5],
			'F' => $months[ $jm ],
			'l' => $weekdays[ (int) $parts[6] ],
		);

		$out = '';
		$len = strlen( $format );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $format[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$out .= $format[ ++$i ];
			} elseif ( isset( $map[ $c ] ) ) {
				$out .= $map[ $c ];
			} else {
				$out .= $c;
			}
		}
		return $out;
	}
}
