<?php
/**
 * Custom order status wc-wtt-preinvoice.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Order_Status {

	const STATUS = 'wtt-preinvoice';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_status' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_status' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_cancel', array( __CLASS__, 'add_plain' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'add_plain' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_css' ), 20 );
		// Bulk/admin status changes bypass the gateway: make sure number and stock are handled.
		add_action( 'woocommerce_order_status_' . self::STATUS, array( __CLASS__, 'on_status' ), 5, 2 );
		// Make sure the email classes (and their transition hooks) are loaded before the transition emails fire.
		foreach ( array( 'pending_to_', 'failed_to_' ) as $from ) {
			add_action( 'woocommerce_order_status_' . $from . self::STATUS, array( __CLASS__, 'load_mailer' ), 1 );
		}
		add_action( 'woocommerce_order_status_' . self::STATUS . '_to_cancelled', array( __CLASS__, 'load_mailer' ), 1 );
	}

	public static function register_status() {
		register_post_status(
			'wc-' . self::STATUS,
			array(
				'label'                     => _x( 'Pre-invoice sent', 'Order status', 'woo-toman-toolkit' ),
				'public'                    => false,
				'exclude_from_search'       => false,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: number of orders */
				'label_count'               => _n_noop( 'Pre-invoice sent <span class="count">(%s)</span>', 'Pre-invoice sent <span class="count">(%s)</span>', 'woo-toman-toolkit' ),
			)
		);
	}

	public static function add_status( $statuses ) {
		$out = array();
		foreach ( $statuses as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'wc-pending' === $key ) {
				$out[ 'wc-' . self::STATUS ] = _x( 'Pre-invoice sent', 'Order status', 'woo-toman-toolkit' );
			}
		}
		if ( ! isset( $out[ 'wc-' . self::STATUS ] ) ) {
			$out[ 'wc-' . self::STATUS ] = _x( 'Pre-invoice sent', 'Order status', 'woo-toman-toolkit' );
		}
		return $out;
	}

	public static function add_plain( $statuses ) {
		$statuses = (array) $statuses;
		if ( ! in_array( self::STATUS, $statuses, true ) ) {
			$statuses[] = self::STATUS;
		}
		return $statuses;
	}

	public static function bulk_actions( $actions ) {
		$actions['mark_' . self::STATUS] = __( 'Change status to pre-invoice sent', 'woo-toman-toolkit' );
		return $actions;
	}

	public static function admin_css() {
		$css = '.order-status.status-wtt-preinvoice{background:#fde3b5;color:#8a5a00}';
		wp_register_style( 'wtt-pi-status', false, array(), WTT_TK_VERSION );
		wp_enqueue_style( 'wtt-pi-status' );
		wp_add_inline_style( 'wtt-pi-status', $css );
	}

	public static function load_mailer() {
		WC()->mailer();
	}

	/**
	 * @param int           $order_id Order id.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status( $order_id, $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		WTT_PI_Number::ensure( $order );
		wc_maybe_reduce_stock_levels( $order_id ); // No-op when already reduced.
	}
}
