<?php
/**
 * Fetch, aggregate, guard, round and store the effective rate.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Rate_Manager {

	const STATE_OPTION = 'wtt_usd_rate_state';

	public static function init() {
		add_action( WTT_USD_Plugin::CRON_HOOK, array( __CLASS__, 'cron_fetch' ) );
		add_action( 'update_option_' . WTT_USD_Settings::OPTION, array( __CLASS__, 'on_settings_updated' ), 10, 2 );
	}

	/**
	 * @return array
	 */
	public static function default_state() {
		return array(
			'raw_rate'              => 0,
			'effective_rate'        => 0,
			'sources'               => array(),
			'fetched_at'            => 0,
			'last_attempt_at'       => 0,
			'last_synced_rate'      => 0,
			'last_synced_at'        => 0,
			'pending_rejected_rate' => 0,
			'fail_count'            => 0,
			'fail_since'            => 0,
		);
	}

	public static function get_state() {
		$state = get_option( self::STATE_OPTION, array() );
		if ( ! is_array( $state ) ) {
			$state = array();
		}
		return array_merge( self::default_state(), $state );
	}

	public static function save_state( array $state ) {
		update_option( self::STATE_OPTION, $state );
	}

	public static function get_effective_rate() {
		$state = self::get_state();
		return (float) $state['effective_rate'];
	}

	public static function get_raw_rate() {
		$state = self::get_state();
		return (float) $state['raw_rate'];
	}

	/**
	 * Currency multiplier: IRR stores need Toman x 10.
	 *
	 * @return int
	 */
	public static function currency_factor() {
		return 'IRR' === get_woocommerce_currency() ? 10 : 1;
	}

	/**
	 * Cron callback.
	 */
	public static function cron_fetch() {
		self::fetch_and_update();
	}

	/**
	 * Query enabled providers without saving anything.
	 *
	 * @return array name => float|string(error message)
	 */
	public static function query_providers() {
		$results = array();
		foreach ( WTT_USD_Rate_Providers::get_enabled() as $name => $provider ) {
			$value = $provider->fetch();
			if ( is_wp_error( $value ) ) {
				$results[ $name ] = $value->get_error_message();
			} else {
				$results[ $name ] = (float) $value;
			}
		}
		return $results;
	}

	/**
	 * Median of numbers.
	 *
	 * @param float[] $values Values.
	 * @return float
	 */
	public static function median( array $values ) {
		$values = array_values( $values );
		sort( $values );
		$n = count( $values );
		if ( 0 === $n ) {
			return 0.0;
		}
		$mid = (int) floor( $n / 2 );
		return ( $n % 2 ) ? (float) $values[ $mid ] : ( $values[ $mid - 1 ] + $values[ $mid ] ) / 2;
	}

	/**
	 * Aggregate successful results: median, dropping >5% outliers when >= 3 values.
	 *
	 * @param array $results name => float.
	 * @return float
	 */
	public static function aggregate( array $results ) {
		$median = self::median( $results );
		if ( count( $results ) >= 3 && $median > 0 ) {
			$kept = array();
			foreach ( $results as $name => $value ) {
				if ( abs( $value - $median ) / $median * 100 <= 5 ) {
					$kept[ $name ] = $value;
				} else {
					WTT_USD_Logger::warning(
						/* translators: 1: provider name, 2: value, 3: median */
						sprintf( __( 'Dropped outlier from %1$s (%2$s vs median %3$s).', 'woo-toman-toolkit' ), $name, $value, $median )
					);
				}
			}
			if ( ! empty( $kept ) ) {
				return self::median( $kept );
			}
		}
		return $median;
	}

	/**
	 * Effective rate from a raw rate and current settings.
	 *
	 * @param float $raw Raw rate.
	 * @return float
	 */
	public static function compute_effective( $raw ) {
		$settings = WTT_USD_Settings::all();
		if ( ! empty( $settings['manual_rate_enabled'] ) && (float) $settings['manual_rate'] > 0 ) {
			return (float) $settings['manual_rate'];
		}
		if ( $raw <= 0 ) {
			return 0.0;
		}
		$markup = max( 0, min( 100, (float) $settings['markup_percent'] ) );
		$step   = max( 1, (int) $settings['rate_round_step'] );
		$value  = $raw * ( 1 + $markup / 100 );
		return (float) ( ceil( round( $value / $step, 6 ) ) * $step );
	}

	/**
	 * Fetch all providers and update stored state.
	 *
	 * @return array|WP_Error Updated state, or error when all providers failed.
	 */
	public static function fetch_and_update() {
		$state   = self::get_state();
		$results = self::query_providers();

		$state['last_attempt_at'] = time();
		$state['sources']         = $results;

		$values = array();
		foreach ( $results as $name => $value ) {
			if ( is_float( $value ) ) {
				$values[ $name ] = $value;
			}
		}

		if ( empty( $values ) ) {
			$state['fail_count'] = (int) $state['fail_count'] + 1;
			if ( empty( $state['fail_since'] ) ) {
				$state['fail_since'] = time();
			}
			self::save_state( $state );
			WTT_USD_Logger::error( __( 'All rate providers failed; keeping the last good rate.', 'woo-toman-toolkit' ) );
			return new WP_Error( 'wtt_usd_all_failed', __( 'All rate providers failed.', 'woo-toman-toolkit' ) );
		}

		$state['fail_count'] = 0;
		$state['fail_since'] = 0;
		$dismissed           = get_option( 'wtt_usd_dismissed', array() );
		if ( is_array( $dismissed ) && isset( $dismissed['fail'] ) ) {
			unset( $dismissed['fail'] );
			update_option( 'wtt_usd_dismissed', $dismissed, false );
		}

		$raw  = self::aggregate( $values );
		$prev = (float) $state['raw_rate'];
		$max  = (float) WTT_USD_Settings::get( 'max_change_percent' );

		if ( $prev > 0 && $max > 0 && abs( $raw - $prev ) / $prev * 100 > $max ) {
			$state['pending_rejected_rate'] = $raw;
			self::save_state( $state );
			WTT_USD_Logger::warning(
				/* translators: 1: new rate, 2: previous rate, 3: max percent */
				sprintf( __( 'Rejected new rate %1$s: differs from last good rate %2$s by more than %3$s%%.', 'woo-toman-toolkit' ), $raw, $prev, $max )
			);
			return $state;
		}

		$state['raw_rate']              = $raw;
		$state['fetched_at']            = time();
		$state['pending_rejected_rate'] = 0;
		self::save_state( $state );
		WTT_USD_Logger::info(
			/* translators: %s: rate */
			sprintf( __( 'Fetched rate: %s Toman per USDT.', 'woo-toman-toolkit' ), $raw )
		);

		self::recompute_and_sync();
		return self::get_state();
	}

	/**
	 * Accept the rate rejected by the sanity guard.
	 *
	 * @return bool
	 */
	public static function accept_pending() {
		$state = self::get_state();
		if ( (float) $state['pending_rejected_rate'] <= 0 ) {
			return false;
		}
		$state['raw_rate']              = (float) $state['pending_rejected_rate'];
		$state['pending_rejected_rate'] = 0;
		$state['fetched_at']            = time();
		self::save_state( $state );
		WTT_USD_Logger::info(
			/* translators: %s: rate */
			sprintf( __( 'Accepted rate manually: %s Toman per USDT.', 'woo-toman-toolkit' ), $state['raw_rate'] )
		);
		self::recompute_and_sync();
		return true;
	}

	/**
	 * Recompute effective rate from stored raw rate and sync prices when needed.
	 *
	 * @param bool $force Force a sync even if the rate did not change.
	 * @return float Effective rate.
	 */
	public static function recompute_and_sync( $force = false ) {
		$state     = self::get_state();
		$effective = self::compute_effective( (float) $state['raw_rate'] );
		if ( $effective <= 0 ) {
			return 0.0;
		}
		$state['effective_rate'] = $effective;
		self::save_state( $state );

		$changed = abs( $effective - (float) $state['last_synced_rate'] ) > 0.000001;
		if ( $force || $changed ) {
			if ( ! $force && WTT_USD_Price_Sync::is_running_for( $effective ) ) {
				return $effective;
			}
			WTT_USD_Price_Sync::start_full_sync();
		}
		return $effective;
	}

	/**
	 * React to settings changes.
	 *
	 * @param mixed $old Old value.
	 * @param mixed $new New value.
	 */
	public static function on_settings_updated( $old, $new ) {
		$old = is_array( $old ) ? array_merge( WTT_USD_Settings::defaults(), $old ) : WTT_USD_Settings::defaults();
		$new = is_array( $new ) ? array_merge( WTT_USD_Settings::defaults(), $new ) : WTT_USD_Settings::defaults();

		if ( (int) $old['update_interval_minutes'] !== (int) $new['update_interval_minutes'] ) {
			WTT_USD_Plugin::schedule( true );
		}
		$force = ( (int) $old['price_round_step'] !== (int) $new['price_round_step'] );
		self::recompute_and_sync( $force );
	}
}
