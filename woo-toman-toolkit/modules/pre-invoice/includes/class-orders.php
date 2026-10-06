<?php
/**
 * Admin order integration: order actions, meta box, list column, auto-cancel.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_PI_Orders {

	const CRON_HOOK = 'wtt_pi_auto_cancel';

	public static function init() {
		add_filter( 'woocommerce_order_actions', array( __CLASS__, 'order_actions' ) );
		foreach ( array( 'resend', 'mark_paid', 'extend', 'keep' ) as $a ) {
			add_action( 'woocommerce_order_action_wtt_pi_' . $a, array( __CLASS__, 'action_' . $a ) );
		}
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ), 20 );

		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'legacy_column_content' ), 10, 2 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'column' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'hpos_column_content' ), 10, 2 );
	}

	/* ---------------------------------------------------------------- cron */

	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::CRON_HOOK );
		}
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Timestamp from which the auto-cancel clock runs.
	 *
	 * @param WC_Order $order Order.
	 * @return int
	 */
	public static function clock_base( $order ) {
		$created  = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0;
		$extended = (int) $order->get_meta( '_wtt_pi_extended_at' );
		return max( $created, $extended );
	}

	/**
	 * Timestamp when the order will be auto-cancelled (0 = never).
	 */
	public static function cancel_eta( $order ) {
		$days = (int) WTT_PI_Settings::get( 'auto_cancel_days' );
		if ( $days <= 0 || 'yes' === $order->get_meta( '_wtt_pi_keep' ) ) {
			return 0;
		}
		return self::clock_base( $order ) + $days * DAY_IN_SECONDS;
	}

	/**
	 * Timestamp until which stock stays reserved (auto-cancel time), or null when
	 * there is no expiry (auto-cancel disabled or order kept).
	 *
	 * @param WC_Order $order Order.
	 * @return int|null
	 */
	public static function reserved_until( $order ) {
		$eta = self::cancel_eta( $order );
		return $eta > 0 ? $eta : null;
	}

	/**
	 * Cron callback. Safe to call directly.
	 *
	 * @return int Number of cancelled orders.
	 */
	public static function run_auto_cancel() {
		if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WTT_Toolkit' ) || ! WTT_Toolkit::is_enabled( 'pre-invoice' ) ) {
			return 0;
		}
		$days = (int) WTT_PI_Settings::get( 'auto_cancel_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$now       = time();
		$cutoff    = $now - $days * DAY_IN_SECONDS;
		$cancelled = 0;

		for ( $page = 1; $page <= 10; $page++ ) {
			$orders = wc_get_orders(
				array(
					'status'       => array( WTT_PI_Order_Status::STATUS ),
					'date_created' => '<' . $cutoff,
					'limit'        => 50,
					'paged'        => $page,
					'orderby'      => 'date',
					'order'        => 'ASC',
				)
			);
			if ( ! $orders ) {
				break;
			}
			foreach ( $orders as $order ) {
				if ( 'yes' === $order->get_meta( '_wtt_pi_keep' ) || self::clock_base( $order ) >= $cutoff ) {
					continue;
				}
				if ( ! apply_filters( 'wtt_pi_should_auto_cancel', true, $order ) ) {
					continue;
				}
				/* translators: %d: days */
				$order->update_status( 'cancelled', sprintf( __( 'Pre-invoice auto-cancelled after %d days without payment.', 'woo-toman-toolkit' ), $days ) );
				/* translators: 1: order ID, 2: pre-invoice number */
				WTT_Logger::add( 'pre-invoice', 'info', sprintf( __( 'Auto-cancelled order #%1$d (%2$s).', 'woo-toman-toolkit' ), $order->get_id(), $order->get_meta( '_wtt_pi_number' ) ) );
				$cancelled++;
			}
			if ( count( $orders ) < 50 ) {
				break;
			}
		}
		return $cancelled;
	}

	/* -------------------------------------------------------- order actions */

	private static function current_order() {
		global $theorder, $post;
		if ( $theorder instanceof WC_Order ) {
			return $theorder;
		}
		if ( $post && 'shop_order' === get_post_type( $post ) ) {
			return wc_get_order( $post->ID );
		}
		return false;
	}

	public static function order_actions( $actions ) {
		$order = self::current_order();
		if ( ! $order || '' === (string) $order->get_meta( '_wtt_pi_number' ) ) {
			return $actions;
		}
		$actions['wtt_pi_resend'] = __( 'Resend pre-invoice to customer', 'woo-toman-toolkit' );
		if ( WTT_PI_Order_Status::STATUS === $order->get_status() ) {
			$actions['wtt_pi_mark_paid'] = __( 'Mark pre-invoice as paid (→ Processing)', 'woo-toman-toolkit' );
			/* translators: %d: hours */
			$actions['wtt_pi_extend'] = sprintf( __( 'Extend validity by %d hours', 'woo-toman-toolkit' ), (int) WTT_PI_Settings::get( 'validity_hours' ) );
			$actions['wtt_pi_keep']   = 'yes' === $order->get_meta( '_wtt_pi_keep' )
				? __( 'Re-enable auto-cancel', 'woo-toman-toolkit' )
				: __( 'Keep reserved (disable auto-cancel)', 'woo-toman-toolkit' );
		}
		return $actions;
	}

	public static function action_resend( $order ) {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		if ( isset( $emails['WTT_PI_Email_Customer_Preinvoice'] ) ) {
			$emails['WTT_PI_Email_Customer_Preinvoice']->trigger( $order->get_id(), $order );
			$order->add_order_note( __( 'Pre-invoice email resent to customer.', 'woo-toman-toolkit' ), 0, true );
		}
	}

	public static function action_mark_paid( $order ) {
		$user = wp_get_current_user();
		/* translators: used as the actor name when no user is logged in (e.g. "Marked paid by system") */
		$actor = $user->exists() ? $user->display_name : __( 'system', 'woo-toman-toolkit' );
		/* translators: %s: user name */
		$order->add_order_note( sprintf( __( 'Marked paid by %s', 'woo-toman-toolkit' ), $actor ) );
		$order->update_meta_data( '_wtt_pi_paid_at', time() );
		$order->save();
		$order->payment_complete();
		if ( WTT_PI_Order_Status::STATUS === $order->get_status() ) {
			$order->update_status( 'processing' );
		}
	}

	public static function action_extend( $order ) {
		$hours = max( 1, (int) WTT_PI_Settings::get( 'validity_hours' ) );
		$base  = max( (int) $order->get_meta( '_wtt_pi_valid_until' ), time() );
		$order->update_meta_data( '_wtt_pi_valid_until', $base + $hours * HOUR_IN_SECONDS );
		$order->update_meta_data( '_wtt_pi_extended_at', time() );
		$order->save();
		/* translators: 1: hours, 2: new date */
		$order->add_order_note( sprintf( __( 'Pre-invoice validity extended by %1$d hours (until %2$s).', 'woo-toman-toolkit' ), $hours, WTT_PI_Format::datetime( $base + $hours * HOUR_IN_SECONDS ) ) );
	}

	public static function action_keep( $order ) {
		if ( 'yes' === $order->get_meta( '_wtt_pi_keep' ) ) {
			$order->delete_meta_data( '_wtt_pi_keep' );
			$order->add_order_note( __( 'Auto-cancel re-enabled for this pre-invoice.', 'woo-toman-toolkit' ) );
		} else {
			$order->update_meta_data( '_wtt_pi_keep', 'yes' );
			$order->add_order_note( __( 'Pre-invoice kept reserved (auto-cancel disabled).', 'woo-toman-toolkit' ) );
		}
		$order->save();
	}

	/* ------------------------------------------------------------ meta box */

	public static function add_meta_box() {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';
		add_meta_box( 'wtt_pi_box', __( 'Pre-invoice', 'woo-toman-toolkit' ), array( __CLASS__, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public static function render_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( is_object( $post_or_order ) ? $post_or_order->ID : 0 );
		if ( ! $order || '' === (string) $order->get_meta( '_wtt_pi_number' ) ) {
			echo '<p>' . esc_html__( 'No pre-invoice issued for this order.', 'woo-toman-toolkit' ) . '</p>';
			return;
		}
		$rate = $order->get_meta( '_wtt_pi_usd_rate' );
		$eta  = self::reserved_until( $order );
		echo '<p><strong>' . esc_html__( 'Number', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( $order->get_meta( '_wtt_pi_number' ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Issued', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( WTT_PI_Format::datetime( (int) $order->get_meta( '_wtt_pi_issued_at' ) ) ) . '</p>';
		echo '<p><strong>' . esc_html__( 'Valid until', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( WTT_PI_Format::datetime( (int) $order->get_meta( '_wtt_pi_valid_until' ) ) ) . '</p>';
		if ( '' !== (string) $rate ) {
			echo '<p><strong>' . esc_html__( 'USD rate at issue', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( number_format_i18n( (float) $rate ) ) . '</p>';
		}
		if ( WTT_PI_Order_Status::STATUS === $order->get_status() ) {
			if ( 'yes' === $order->get_meta( '_wtt_pi_keep' ) ) {
				$txt = __( 'Kept reserved (auto-cancel disabled)', 'woo-toman-toolkit' );
			} elseif ( $eta ) {
				$txt = WTT_PI_Format::datetime( $eta );
			} else {
				$txt = __( 'Never', 'woo-toman-toolkit' );
			}
			echo '<p><strong>' . esc_html__( 'Auto-cancel', 'woo-toman-toolkit' ) . ':</strong> ' . esc_html( $txt ) . '</p>';
		}
		echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url( WTT_PI_Page::url( $order ) ) . '">' . esc_html__( 'View pre-invoice', 'woo-toman-toolkit' ) . '</a></p>';
	}

	/* ---------------------------------------------------------- list column */

	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'order_number' === $key ) {
				$out['wtt_pi_number'] = __( 'Pre-invoice #', 'woo-toman-toolkit' );
			}
		}
		if ( ! isset( $out['wtt_pi_number'] ) ) {
			$out['wtt_pi_number'] = __( 'Pre-invoice #', 'woo-toman-toolkit' );
		}
		return $out;
	}

	public static function legacy_column_content( $column, $post_id ) {
		if ( 'wtt_pi_number' === $column ) {
			$order = wc_get_order( $post_id );
			echo $order ? esc_html( (string) $order->get_meta( '_wtt_pi_number' ) ) : '';
		}
	}

	public static function hpos_column_content( $column, $order ) {
		if ( 'wtt_pi_number' === $column ) {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
			echo $order ? esc_html( (string) $order->get_meta( '_wtt_pi_number' ) ) : '';
		}
	}
}
