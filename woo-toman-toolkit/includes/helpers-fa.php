<?php
/**
 * Persian helpers: digits, number to words, locale check.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wtt_fa_digits' ) ) {
	/**
	 * Convert Latin digits to Persian digits.
	 *
	 * @param string|int|float $str Input.
	 * @return string
	 */
	function wtt_fa_digits( $str ) {
		return strtr(
			(string) $str,
			array(
				'0' => '۰',
				'1' => '۱',
				'2' => '۲',
				'3' => '۳',
				'4' => '۴',
				'5' => '۵',
				'6' => '۶',
				'7' => '۷',
				'8' => '۸',
				'9' => '۹',
			)
		);
	}
}

if ( ! function_exists( 'wtt_is_fa_locale' ) ) {
	/**
	 * Whether the current locale is Persian.
	 *
	 * @return bool
	 */
	function wtt_is_fa_locale() {
		$locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		return 0 === strpos( (string) $locale, 'fa' );
	}
}

if ( ! function_exists( 'wtt_number_to_fa_words' ) ) {
	/**
	 * Integer to Persian words (supports up to the trillions, and beyond to 10^18 - 1).
	 *
	 * @param int|string $number Integer.
	 * @return string
	 */
	function wtt_number_to_fa_words( $number ) {
		$number = (int) $number;
		if ( 0 === $number ) {
			return 'صفر';
		}
		if ( $number < 0 ) {
			return 'منفی ' . wtt_number_to_fa_words( -$number );
		}

		$ones     = array( '', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده', 'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده' );
		$tens     = array( '', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود' );
		$hundreds = array( '', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد' );
		$scales   = array( '', 'هزار', 'میلیون', 'میلیارد', 'تریلیون', 'کوادریلیون', 'کوینتیلیون' );

		$groups = array();
		while ( $number > 0 ) {
			$groups[] = $number % 1000;
			$number   = intdiv( $number, 1000 );
		}

		$parts = array();
		for ( $i = count( $groups ) - 1; $i >= 0; $i-- ) {
			$g = $groups[ $i ];
			if ( 0 === $g ) {
				continue;
			}
			$words = array();
			if ( $g >= 100 ) {
				$words[] = $hundreds[ intdiv( $g, 100 ) ];
				$g       = $g % 100;
			}
			if ( $g >= 20 ) {
				$words[] = $tens[ intdiv( $g, 10 ) ];
				$g       = $g % 10;
			}
			if ( $g > 0 ) {
				$words[] = $ones[ $g ];
			}
			$text = implode( ' و ', $words );
			if ( $i > 0 ) {
				$text .= ' ' . $scales[ $i ];
			}
			$parts[] = $text;
		}

		return implode( ' و ', $parts );
	}
}
