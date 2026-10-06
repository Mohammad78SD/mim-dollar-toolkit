<?php
/**
 * Compute Toman prices from USD prices and batch-sync them via Action Scheduler.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_USD_Price_Sync {

	const BATCH_HOOK     = 'wtt_usd_sync_batch';
	const GROUP          = 'wtt_usd';
	const BATCH_SIZE     = 50;
	const META_REGULAR   = '_wtt_usd_regular_price';
	const META_SALE      = '_wtt_usd_sale_price';
	const RUN_OPTION     = 'wtt_usd_sync_run';
	const PARENTS_OPTION = 'wtt_usd_sync_parents';

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static $syncing = false;

	public static function init() {
		add_action( self::BATCH_HOOK, array( __CLASS__, 'run_batch' ), 10, 1 );
	}

	public static function is_syncing() {
		return self::$syncing;
	}

	/**
	 * Round a number up to a multiple of $step.
	 *
	 * @param float $value Value.
	 * @param int   $step  Step.
	 * @return float
	 */
	private static function round_up( $value, $step ) {
		$step = max( 1, (int) $step );
		return (float) ( ceil( round( $value / $step, 6 ) ) * $step );
	}

	/**
	 * Format an integer-valued float without scientific notation.
	 *
	 * @param float $value Value.
	 * @return string
	 */
	private static function to_string( $value ) {
		return sprintf( '%.0f', $value );
	}

	/**
	 * Compute store-currency prices from USD prices.
	 *
	 * @param string|float $usd_regular USD regular price.
	 * @param string|float $usd_sale    USD sale price (may be empty).
	 * @param float        $rate        Effective rate (Toman per USD).
	 * @return array {regular: string, sale: string}
	 */
	public static function calculate_prices( $usd_regular, $usd_sale, $rate ) {
		$factor = WTT_USD_Rate_Manager::currency_factor();
		$step   = (int) WTT_USD_Settings::get( 'price_round_step' );

		$regular = self::round_up( (float) $usd_regular * $rate * $factor, $step );
		$sale    = '';
		if ( '' !== (string) $usd_sale && (float) $usd_sale > 0 && (float) $usd_sale < (float) $usd_regular ) {
			$sale_value = self::round_up( (float) $usd_sale * $rate * $factor, $step );
			if ( $sale_value < $regular ) {
				$sale = self::to_string( $sale_value );
			}
		}
		return array(
			'regular' => self::to_string( $regular ),
			'sale'    => $sale,
		);
	}

	/**
	 * Set Toman prices on a product object from its USD meta. Does NOT save.
	 *
	 * @param WC_Product $product Product or variation.
	 * @param float      $rate    Effective rate.
	 * @return bool Whether any price changed.
	 */
	public static function apply_to_product( $product, $rate ) {
		$usd_regular = $product->get_meta( self::META_REGULAR, true, 'edit' );
		if ( '' === (string) $usd_regular || (float) $usd_regular <= 0 || $rate <= 0 ) {
			return false;
		}
		$usd_sale = $product->get_meta( self::META_SALE, true, 'edit' );
		$prices   = self::calculate_prices( $usd_regular, $usd_sale, $rate );

		$changed = false;
		if ( (string) $product->get_regular_price( 'edit' ) !== $prices['regular'] ) {
			$product->set_regular_price( $prices['regular'] );
			$changed = true;
		}
		if ( (string) $product->get_sale_price( 'edit' ) !== $prices['sale'] ) {
			$product->set_sale_price( $prices['sale'] );
			$changed = true;
		}
		return $changed;
	}

	/**
	 * Whether Action Scheduler is available.
	 *
	 * @return bool
	 */
	private static function has_scheduler() {
		return function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_unschedule_all_actions' );
	}

	/**
	 * Whether a sync run for this rate is in progress.
	 *
	 * @param float $rate Rate.
	 * @return bool
	 */
	public static function is_running_for( $rate ) {
		$run = get_option( self::RUN_OPTION, array() );
		if ( ! is_array( $run ) || empty( $run['rate'] ) || abs( (float) $run['rate'] - $rate ) > 0.000001 ) {
			return false;
		}
		if ( function_exists( 'as_has_scheduled_action' ) ) {
			return (bool) as_has_scheduled_action( self::BATCH_HOOK, null, self::GROUP );
		}
		return false;
	}

	/**
	 * Start a full sync using the current effective rate.
	 *
	 * @return bool False when there is no rate or no scheduler.
	 */
	public static function start_full_sync() {
		$rate = WTT_USD_Rate_Manager::get_effective_rate();
		if ( $rate <= 0 ) {
			WTT_USD_Logger::error( __( 'Cannot sync prices: no effective rate available.', 'woo-toman-toolkit' ) );
			return false;
		}

		if ( self::has_scheduler() ) {
			// Prevent concurrent full syncs.
			as_unschedule_all_actions( self::BATCH_HOOK, array(), self::GROUP );
		}
		delete_option( self::PARENTS_OPTION );
		update_option(
			self::RUN_OPTION,
			array(
				'rate'    => $rate,
				'started' => time(),
				'count'   => 0,
			),
			false
		);

		if ( self::has_scheduler() ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( 'page' => 1 ), self::GROUP );
			WTT_USD_Logger::info(
				/* translators: %s: rate */
				sprintf( __( 'Price sync queued at rate %s.', 'woo-toman-toolkit' ), $rate )
			);
			return true;
		}

		// Fallback: synchronous run.
		WTT_USD_Logger::warning( __( 'Action Scheduler unavailable; running price sync inline.', 'woo-toman-toolkit' ) );
		$page = 1;
		do {
			$more = self::process_page( $page );
			$page++;
		} while ( $more && $page < 1000 );
		return true;
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $page Page number (1-based).
	 */
	public static function run_batch( $page = 1 ) {
		$more = self::process_page( max( 1, (int) $page ) );
		if ( $more ) {
			as_enqueue_async_action( self::BATCH_HOOK, array( 'page' => (int) $page + 1 ), self::GROUP );
		}
	}

	/**
	 * Process a single page and finish when it was the last.
	 *
	 * @param int $page Page.
	 * @return bool True when another page follows.
	 */
	private static function process_page( $page ) {
		$run  = get_option( self::RUN_OPTION, array() );
		$rate = ( is_array( $run ) && ! empty( $run['rate'] ) ) ? (float) $run['rate'] : WTT_USD_Rate_Manager::get_effective_rate();
		if ( $rate <= 0 ) {
			WTT_USD_Logger::error( __( 'Price sync aborted: no rate.', 'woo-toman-toolkit' ) );
			return false;
		}
		if ( ! is_array( $run ) || empty( $run['rate'] ) ) {
			$run = array(
				'rate'    => $rate,
				'started' => time(),
				'count'   => 0,
			);
		}

		$ids = get_posts(
			array(
				'post_type'        => array( 'product', 'product_variation' ),
				'post_status'      => 'any',
				'posts_per_page'   => self::BATCH_SIZE,
				'paged'            => $page,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => true,
				'meta_query'       => array(
					array(
						'key'     => self::META_REGULAR,
						'value'   => '',
						'compare' => '!=',
					),
				),
			)
		);

		$parents = get_option( self::PARENTS_OPTION, array() );
		if ( ! is_array( $parents ) ) {
			$parents = array();
		}

		self::$syncing = true;
		foreach ( $ids as $id ) {
			try {
				$product = wc_get_product( $id );
				if ( ! $product ) {
					continue;
				}
				if ( self::apply_to_product( $product, $rate ) ) {
					$product->save();
					$run['count'] = (int) $run['count'] + 1;
				}
				if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
					$parents[ (int) $product->get_parent_id() ] = 1;
				}
			} catch ( Exception $e ) {
				WTT_USD_Logger::error(
					/* translators: 1: product ID, 2: error message */
					sprintf( __( 'Failed to update product #%1$d: %2$s', 'woo-toman-toolkit' ), $id, $e->getMessage() )
				);
			}
		}
		self::$syncing = false;

		update_option( self::PARENTS_OPTION, $parents, false );
		update_option( self::RUN_OPTION, $run, false );

		if ( count( $ids ) >= self::BATCH_SIZE ) {
			return true;
		}
		self::finish( $rate, $run );
		return false;
	}

	/**
	 * Finalize a full sync.
	 *
	 * @param float $rate Rate used.
	 * @param array $run  Run data.
	 */
	private static function finish( $rate, array $run ) {
		$parents = get_option( self::PARENTS_OPTION, array() );
		if ( is_array( $parents ) ) {
			foreach ( array_keys( $parents ) as $parent_id ) {
				WC_Product_Variable::sync( (int) $parent_id );
				wc_delete_product_transients( (int) $parent_id );
			}
		}
		delete_option( self::PARENTS_OPTION );
		delete_option( self::RUN_OPTION );

		$state                     = WTT_USD_Rate_Manager::get_state();
		$state['last_synced_rate'] = $rate;
		$state['last_synced_at']   = time();
		WTT_USD_Rate_Manager::save_state( $state );

		WTT_USD_Logger::info(
			/* translators: 1: number of products updated, 2: rate */
			sprintf( __( 'Price sync finished: %1$d products updated at rate %2$s.', 'woo-toman-toolkit' ), (int) $run['count'], $rate )
		);

		do_action( 'wtt_usd_prices_synced', $rate );
		WTT_USD_Cache_Purge::purge();
	}

	/**
	 * Number of products/variations with a USD price.
	 *
	 * @return int
	 */
	public static function count_usd_products() {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND pm.meta_value <> '' AND p.post_status <> 'trash' AND p.post_type IN ('product','product_variation')",
				self::META_REGULAR
			)
		);
	}
}
