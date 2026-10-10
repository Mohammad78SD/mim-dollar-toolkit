<?php
/**
 * Locale-aware formatting helpers (Jalali + Persian digits only for the fa locale).
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Format {

	/**
	 * Persian digits when fa locale, otherwise unchanged.
	 */
	public static function digits( $str ) {
		return mdt_is_fa_locale() ? mdt_fa_digits( $str ) : (string) $str;
	}

	public static function date( $ts ) {
		$ts = (int) $ts;
		if ( ! $ts ) {
			return '';
		}
		if ( mdt_is_fa_locale() ) {
			return mdt_fa_digits( mdt_jdate( 'Y/m/d', $ts ) );
		}
		return wp_date( get_option( 'date_format' ), $ts );
	}

	public static function datetime( $ts ) {
		$ts = (int) $ts;
		if ( ! $ts ) {
			return '';
		}
		if ( mdt_is_fa_locale() ) {
			return mdt_fa_digits( mdt_jdate( 'Y/m/d H:i', $ts ) );
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
	}

	/**
	 * Plain-text (escaped) money string with currency symbol.
	 *
	 * @param float  $amount   Amount.
	 * @param string $currency Currency code.
	 * @return string
	 */
	public static function money( $amount, $currency = '' ) {
		$decimals = wc_get_price_decimals();
		$dec_sep  = wc_get_price_decimal_separator();
		$thou_sep = wc_get_price_thousand_separator();
		$str      = number_format( (float) $amount, $decimals, '.', ',' );
		if ( mdt_is_fa_locale() ) {
			$str = strtr( $str, array( ',' => '٬', '.' => '٫' ) );
			$str = mdt_fa_digits( $str );
		} else {
			$str = strtr( $str, array( ',' => '|', '.' => $dec_sep ) );
			$str = str_replace( '|', $thou_sep, $str );
		}
		$symbol = html_entity_decode( get_woocommerce_currency_symbol( $currency ), ENT_QUOTES, 'UTF-8' );
		return esc_html( $str . ' ' . $symbol );
	}

	/**
	 * Total in Persian words (fa locale + IRT/IRR only), else ''.
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public static function total_in_words( $order ) {
		if ( ! mdt_is_fa_locale() ) {
			return '';
		}
		$currency = $order->get_currency();
		$total    = (float) $order->get_total();
		if ( 'IRT' === $currency ) {
			return mdt_number_to_fa_words( (int) round( $total ) ) . ' تومان';
		}
		if ( 'IRR' === $currency ) {
			return mdt_number_to_fa_words( (int) round( $total ) ) . ' ریال';
		}
		return '';
	}
}
