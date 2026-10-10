<?php
/**
 * Pre-invoice numbering and per-order issue data.
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_PI_Number {

	const COUNTER = 'mdt_pi_counter';

	/**
	 * Next number for a timestamp (consumes the counter).
	 *
	 * @param int $ts Issue timestamp.
	 * @return string
	 */
	public static function next( $ts = 0 ) {
		global $wpdb;
		$ts   = $ts ? (int) $ts : time();
		$year = (int) mdt_jdate( 'Y', $ts );

		$locked = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', 'mdt_pi_counter' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		// Read straight from the DB to bypass the object cache.
		$raw     = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::COUNTER ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counter = $raw ? maybe_unserialize( $raw ) : array();
		if ( ! is_array( $counter ) || ! isset( $counter['year'], $counter['seq'] ) || (int) $counter['year'] !== $year ) {
			$counter = array(
				'year' => $year,
				'seq'  => 0,
			);
		}
		$counter['seq'] = (int) $counter['seq'] + 1;
		update_option( self::COUNTER, $counter, false );
		wp_cache_delete( self::COUNTER, 'options' );
		if ( $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'mdt_pi_counter' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		$number = (string) MDT_PI_Settings::get( 'number_prefix' ) . $year . '-' . str_pad( (string) $counter['seq'], 4, '0', STR_PAD_LEFT );
		return (string) apply_filters( 'mdt_pi_number', $number, $year, (int) $counter['seq'] );
	}

	/**
	 * Make sure the order has a number, issue time and validity. Idempotent.
	 *
	 * @param WC_Order $order Order.
	 * @return string The number.
	 */
	public static function ensure( $order ) {
		$number = (string) $order->get_meta( '_mdt_pi_number' );
		if ( '' !== $number ) {
			return $number;
		}
		$now    = time();
		$hours  = max( 1, (int) MDT_PI_Settings::get( 'validity_hours' ) );
		$number = self::next( $now );
		$order->update_meta_data( '_mdt_pi_number', $number );
		$order->update_meta_data( '_mdt_pi_issued_at', $now );
		$order->update_meta_data( '_mdt_pi_valid_until', $now + $hours * HOUR_IN_SECONDS );
		if ( class_exists( 'MDT_USD_Rate_Manager' ) && MDT_Toolkit::is_enabled( 'usd-pricing' ) ) {
			$order->update_meta_data( '_mdt_pi_usd_rate', MDT_USD_Rate_Manager::get_effective_rate() );
		}
		$order->save();
		/* translators: 1: pre-invoice number, 2: order ID */
		MDT_Logger::add( 'pre-invoice', 'info', sprintf( __( 'Issued %1$s for order #%2$d.', 'mim-dollar-toolkit' ), $number, $order->get_id() ) );
		return $number;
	}
}
