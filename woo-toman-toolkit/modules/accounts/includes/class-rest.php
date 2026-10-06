<?php
/**
 * REST routes wtt/v1/otp/request and wtt/v1/otp/verify.
 * Public, but a valid wp_rest nonce (X-WP-Nonce) and, when sent, a same-origin Origin/Referer are required.
 *
 * @package WooTomanToolkit
 */

defined( 'ABSPATH' ) || exit;

class WTT_ACC_REST {

	const NS = 'wtt/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'no_store' ), 10, 3 );
	}

	/**
	 * Cache-Control: no-store on every wtt/v1/otp response (also errors).
	 *
	 * @param WP_REST_Response $response Response.
	 * @param WP_REST_Server   $server   Server.
	 * @param WP_REST_Request  $request  Request.
	 * @return WP_REST_Response
	 */
	public static function no_store( $response, $server, $request ) {
		unset( $server );
		if ( $request instanceof WP_REST_Request && 0 === strpos( $request->get_route(), '/' . self::NS . '/otp/' ) && $response instanceof WP_HTTP_Response ) {
			$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
			$response->header( 'Pragma', 'no-cache' );
		}
		return $response;
	}

	/**
	 * Same-origin check only (the nonce route hands out nonces, so it cannot require one).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission_same_origin( $request ) {
		if ( ! self::same_origin( $request ) ) {
			return new WP_Error( 'wtt_acc_bad_origin', __( 'Security check failed. Please reload the page and try again.', 'woo-toman-toolkit' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * GET /otp/nonce: a fresh wp_rest nonce for logged-out visitors (cached pages carry stale ones).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_nonce() {
		nocache_headers();
		if ( is_user_logged_in() ) {
			return self::logged_in_error();
		}
		return new WP_REST_Response( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ), 200 );
	}

	public static function register_routes() {
		$args = array(
			'mobile'      => array(
				'type'     => 'string',
				'required' => true,
			),
			'code'        => array( 'type' => 'string' ),
			'redirect_to' => array( 'type' => 'string' ),
		);
		register_rest_route(
			self::NS,
			'/otp/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_request' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => array( 'mobile' => $args['mobile'] ),
			)
		);
		register_rest_route(
			self::NS,
			'/otp/nonce',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_nonce' ),
				'permission_callback' => array( __CLASS__, 'permission_same_origin' ),
			)
		);
		register_rest_route(
			self::NS,
			'/otp/verify',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_verify' ),
				'permission_callback' => array( __CLASS__, 'permission' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Public endpoint guarded by a wp_rest nonce and a same-origin check.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return true|WP_Error
	 */
	public static function permission( $request ) {
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( ! is_string( $nonce ) || '' === $nonce ) {
			return new WP_Error( 'wtt_acc_no_nonce', __( 'Security check failed. Please reload the page and try again.', 'woo-toman-toolkit' ), array( 'status' => 401 ) );
		}
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'wtt_acc_bad_nonce', __( 'Security check failed. Please reload the page and try again.', 'woo-toman-toolkit' ), array( 'status' => 403 ) );
		}
		if ( ! self::same_origin( $request ) ) {
			return new WP_Error( 'wtt_acc_bad_origin', __( 'Security check failed. Please reload the page and try again.', 'woo-toman-toolkit' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * When an Origin (or, failing that, a Referer) header is present its host must be this site's host.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function same_origin( $request ) {
		$source = $request->get_header( 'Origin' );
		if ( ! is_string( $source ) || '' === $source ) {
			$source = $request->get_header( 'Referer' );
		}
		if ( ! is_string( $source ) || '' === $source ) {
			return true; // Nonce still required; some privacy tools strip both headers.
		}
		$host = wp_parse_url( $source, PHP_URL_HOST );
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! is_string( $host ) || ! is_string( $home ) ) {
			return false;
		}
		return strtolower( $host ) === strtolower( $home );
	}

	private static function param( $request, $name ) {
		$v = $request->get_param( $name );
		return is_string( $v ) ? $v : '';
	}

	/**
	 * Turn an OTP result into a response (errors as WP_Error so the body is {code, message, data}).
	 *
	 * @param array $res Result.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function respond( $res ) {
		nocache_headers();
		if ( ! empty( $res['ok'] ) ) {
			$body = $res;
			unset( $body['ok'], $body['status'] );
			$body['success'] = true;
			return new WP_REST_Response( $body, 200 );
		}
		$data = array( 'status' => (int) $res['status'] );
		if ( isset( $res['retry_after'] ) ) {
			$data['retry_after'] = (int) $res['retry_after'];
		}
		return new WP_Error( $res['code'], $res['message'], $data );
	}

	private static function logged_in_error() {
		return new WP_Error( 'wtt_acc_logged_in', __( 'You are already logged in.', 'woo-toman-toolkit' ), array( 'status' => 400 ) );
	}

	public static function handle_request( $request ) {
		if ( is_user_logged_in() ) {
			return self::logged_in_error();
		}
		return self::respond( WTT_ACC_OTP::request( self::param( $request, 'mobile' ) ) );
	}

	public static function handle_verify( $request ) {
		if ( is_user_logged_in() ) {
			return self::logged_in_error();
		}
		return self::respond(
			WTT_ACC_OTP::verify(
				self::param( $request, 'mobile' ),
				self::param( $request, 'code' ),
				self::param( $request, 'redirect_to' )
			)
		);
	}
}
