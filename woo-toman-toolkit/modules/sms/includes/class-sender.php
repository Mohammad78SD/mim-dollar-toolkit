<?php
/**
 * Queueing (Action Scheduler), sending, idempotency, retry and result bookkeeping.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_SMS_Sender {

	const HOOK  = 'wtt_sms_send';
	const GROUP = 'wtt_sms';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'handle' ), 10, 4 );
	}

	/**
	 * Queue a message so checkout is never slowed down by the SMS gateway.
	 *
	 * @param string   $event     Event key.
	 * @param WC_Order $order     Order.
	 * @param string   $recipient 'customer' or 'admin:<index>'.
	 */
	public static function queue( $event, $order, $recipient ) {
		if ( ! WTT_SMS_Events::is_active( $event ) ) {
			return;
		}
		$args = array( $event, $order->get_id(), $recipient );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, $args, self::GROUP, true );
		} else {
			self::handle( $event, $order->get_id(), $recipient );
		}
	}

	/**
	 * Resolve a recipient to an E.164 number ('' when none).
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $recipient Recipient spec.
	 * @return string
	 */
	private static function resolve( $order, $recipient ) {
		if ( 'customer' === $recipient ) {
			return WTT_SMS_Phone::normalize( $order->get_billing_phone() );
		}
		if ( 0 === strpos( (string) $recipient, 'admin:' ) ) {
			$list = WTT_SMS_Settings::admin_numbers();
			$i    = (int) substr( $recipient, 6 );
			return isset( $list[ $i ] ) ? $list[ $i ] : '';
		}
		return '';
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param string $event     Event key.
	 * @param int    $order_id  Order id.
	 * @param string $recipient 'customer' or 'admin:<index>'.
	 * @param int    $retry     1 when this run is the retry.
	 */
	public static function handle( $event, $order_id, $recipient = 'customer', $retry = 0 ) {
		$event = (string) $event;
		$order = wc_get_order( absint( $order_id ) );
		if ( ! $order instanceof WC_Order || ! WTT_SMS_Events::is_active( $event ) ) {
			return;
		}
		$to    = self::resolve( $order, (string) $recipient );
		$label = $event;

		if ( ! WTT_SMS_Phone::is_valid_mobile( $to ) ) {
			/* translators: 1: event key, 2: masked number */
			$msg = sprintf( __( 'SMS %1$s skipped: invalid mobile number (%2$s).', 'woo-toman-toolkit' ), $label, WTT_SMS_Phone::mask( $to ) );
			$order->add_order_note( $msg );
			WTT_Logger::add( 'sms', 'warning', sprintf( '#%d %s', $order->get_id(), $msg ) );
			return;
		}

		$meta_key = '_wtt_sms_sent_' . $event;
		$sent     = $order->get_meta( $meta_key );
		$sent     = is_array( $sent ) ? $sent : array();
		if ( in_array( $to, $sent, true ) ) {
			return; // Idempotent.
		}

		$provider = WTT_SMS_Providers::make();
		if ( ! $provider ) {
			return;
		}
		$ev     = WTT_SMS_Settings::get( 'events' );
		$params = WTT_SMS_Events::params( $event, $order );
		$res    = $provider->send_pattern( $to, (string) $ev[ $event ]['code'], $params );
		$masked = WTT_SMS_Phone::mask( $to );

		if ( ! empty( $res['ok'] ) ) {
			$sent[] = $to;
			$order->update_meta_data( $meta_key, array_values( array_unique( $sent ) ) );
			$order->save_meta_data();
			$id = isset( $res['id'] ) && null !== $res['id'] ? (string) $res['id'] : '-';
			/* translators: 1: event key, 2: masked number, 3: provider message id */
			$msg = sprintf( __( 'SMS %1$s to %2$s: sent (id %3$s)', 'woo-toman-toolkit' ), $label, $masked, $id );
			$order->add_order_note( $msg );
			WTT_Logger::add( 'sms', 'info', sprintf( '#%d %s', $order->get_id(), $msg ) );
			return;
		}

		$error = isset( $res['error'] ) && '' !== (string) $res['error'] ? (string) $res['error'] : __( 'Unknown error', 'woo-toman-toolkit' );
		/* translators: 1: event key, 2: masked number, 3: error */
		$msg = sprintf( __( 'SMS %1$s to %2$s: failed: %3$s', 'woo-toman-toolkit' ), $label, $masked, $error );

		if ( ! empty( $res['retry'] ) && ! $retry ) {
			$delay = (int) apply_filters( 'wtt_sms_retry_delay', 5 * MINUTE_IN_SECONDS );
			$args  = array( $event, $order->get_id(), (string) $recipient, 1 );
			if ( function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + max( 1, $delay ), self::HOOK, $args, self::GROUP );
			} else {
				wp_schedule_single_event( time() + max( 1, $delay ), self::HOOK, $args );
			}
			$msg .= ' ' . __( '(will retry once in 5 minutes)', 'woo-toman-toolkit' );
		}
		$order->add_order_note( $msg );
		WTT_Logger::add( 'sms', 'error', sprintf( '#%d %s', $order->get_id(), $msg ) );
	}

	/**
	 * Send a pattern SMS synchronously (no queue, no order). Used for login codes (OTP), which must not wait
	 * for Action Scheduler. The phone is masked in the log and the variables (e.g. the code) are never logged.
	 *
	 * @param string $event  Event key (must be a defined, active event).
	 * @param string $to     Recipient (any format; normalized to E.164).
	 * @param array  $params Pattern variables.
	 * @return array Provider result {ok:bool, id:?string, error:?string, ...}.
	 */
	public static function send_now( $event, $to, array $params ) {
		$event  = (string) $event;
		$to     = WTT_SMS_Phone::normalize( $to );
		$masked = WTT_SMS_Phone::mask( $to );
		$fail   = function ( $error ) use ( $event, $masked ) {
			WTT_Logger::add( 'sms', 'error', sprintf( 'SMS %s to %s: failed: %s', $event, $masked, $error ) );
			return array(
				'ok'    => false,
				'id'    => null,
				'error' => $error,
				'retry' => false,
				'code'  => null,
			);
		};
		if ( ! WTT_SMS_Events::is_active( $event ) ) {
			return $fail( 'event inactive or SMS not configured' );
		}
		if ( ! WTT_SMS_Phone::is_valid_mobile( $to ) ) {
			return $fail( 'invalid mobile number' );
		}
		$provider = WTT_SMS_Providers::make();
		if ( ! $provider ) {
			return $fail( 'provider unavailable' );
		}
		$ev  = WTT_SMS_Settings::get( 'events' );
		$res = $provider->send_pattern( $to, (string) $ev[ $event ]['code'], $params );
		if ( ! empty( $res['ok'] ) ) {
			$id = isset( $res['id'] ) && null !== $res['id'] ? (string) $res['id'] : '-';
			WTT_Logger::add( 'sms', 'info', sprintf( 'SMS %s to %s: sent (id %s)', $event, $masked, $id ) );
		} else {
			$error = isset( $res['error'] ) && '' !== (string) $res['error'] ? (string) $res['error'] : 'unknown error';
			WTT_Logger::add( 'sms', 'error', sprintf( 'SMS %s to %s: failed: %s', $event, $masked, $error ) );
		}
		return is_array( $res ) ? $res : array( 'ok' => false, 'id' => null, 'error' => 'invalid provider response' );
	}

	/**
	 * Send a test message right away (no order, no idempotency).
	 *
	 * @param string $event  Event key.
	 * @param string $mobile Raw mobile.
	 * @return array {ok:bool, message:string}
	 */
	public static function send_test( $event, $mobile ) {
		$defs = WTT_SMS_Events::definitions();
		$to   = WTT_SMS_Phone::normalize( $mobile );
		if ( ! isset( $defs[ $event ] ) ) {
			return array( 'ok' => false, 'message' => __( 'Unknown event.', 'woo-toman-toolkit' ) );
		}
		if ( ! WTT_SMS_Phone::is_valid_mobile( $to ) ) {
			return array( 'ok' => false, 'message' => __( 'Enter a valid Iranian mobile number, e.g. 09123456789.', 'woo-toman-toolkit' ) );
		}
		if ( ! WTT_SMS_Settings::is_configured() ) {
			return array( 'ok' => false, 'message' => __( 'Save the API key and sender number first.', 'woo-toman-toolkit' ) );
		}
		$ev   = WTT_SMS_Settings::get( 'events' );
		$code = (string) $ev[ $event ]['code'];
		if ( '' === $code ) {
			return array( 'ok' => false, 'message' => __( 'This event has no pattern code yet.', 'woo-toman-toolkit' ) );
		}
		$provider = WTT_SMS_Providers::make();
		if ( ! $provider ) {
			return array( 'ok' => false, 'message' => __( 'The selected SMS provider is not available.', 'woo-toman-toolkit' ) );
		}
		$res    = $provider->send_pattern( $to, $code, WTT_SMS_Events::sample_params( $event, WTT_SMS_Phone::local( $to ) ) );
		$masked = WTT_SMS_Phone::mask( $to );
		if ( ! empty( $res['ok'] ) ) {
			$id = isset( $res['id'] ) && null !== $res['id'] ? (string) $res['id'] : '-';
			/* translators: 1: event key, 2: masked number, 3: provider message id */
			$msg = sprintf( __( 'Test SMS %1$s to %2$s: sent (id %3$s)', 'woo-toman-toolkit' ), $event, $masked, $id );
			WTT_Logger::add( 'sms', 'info', $msg );
			return array( 'ok' => true, 'message' => $msg );
		}
		$error = isset( $res['error'] ) && '' !== (string) $res['error'] ? (string) $res['error'] : __( 'Unknown error', 'woo-toman-toolkit' );
		/* translators: 1: event key, 2: masked number, 3: error */
		$msg = sprintf( __( 'Test SMS %1$s to %2$s: failed: %3$s', 'woo-toman-toolkit' ), $event, $masked, $error );
		WTT_Logger::add( 'sms', 'error', $msg );
		return array( 'ok' => false, 'message' => $msg );
	}
}
