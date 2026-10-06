<?php
/**
 * USD pricing wiring (called by the toolkit module).
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Plugin {

	const CRON_HOOK     = 'wtt_usd_fetch_rate';
	const CRON_SCHEDULE = 'wtt_usd_interval';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ), 20 );

		WTT_USD_Rate_Manager::init();
		WTT_USD_Price_Sync::init();
		WTT_USD_Product_Fields::init();

		if ( is_admin() ) {
			WTT_USD_Admin_Page::init();
		}

		add_shortcode( 'wtt_usd_rate', array( __CLASS__, 'shortcode_rate' ) );
	}

	/**
	 * Register the custom cron interval.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$minutes = (int) WTT_USD_Settings::get( 'update_interval_minutes' );
		if ( $minutes < 1 ) {
			$minutes = 60;
		}
		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: minutes */
			'display'  => sprintf( __( 'Every %d minutes (USD rate)', 'woo-toman-toolkit' ), $minutes ),
		);
		return $schedules;
	}

	/**
	 * (Re)schedule the recurring fetch.
	 *
	 * @param bool $force Clear and recreate.
	 */
	public static function schedule( $force = false ) {
		if ( $force ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, self::CRON_SCHEDULE, self::CRON_HOOK );
		}
	}

	/**
	 * Self-heal a missing event.
	 */
	public static function ensure_schedule() {
		self::schedule( false );
	}

	public static function activate() {
		WTT_USD_Settings::ensure_defaults();
		self::schedule( true );
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( WTT_USD_Price_Sync::BATCH_HOOK, array(), WTT_USD_Price_Sync::GROUP );
		}
	}

	/**
	 * [wtt_usd_rate format="raw"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode_rate( $atts ) {
		$atts = shortcode_atts( array( 'format' => 'effective' ), $atts, 'wtt_usd_rate' );
		$rate = 'raw' === $atts['format'] ? WTT_USD_Rate_Manager::get_raw_rate() : WTT_USD_Rate_Manager::get_effective_rate();
		if ( $rate <= 0 ) {
			return '';
		}
		return esc_html( number_format_i18n( $rate, 0 ) );
	}
}
