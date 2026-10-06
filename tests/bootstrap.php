<?php
/**
 * PHPUnit bootstrap: tiny stand-ins for the WordPress / WooCommerce functions the tested code touches.
 * No WordPress install is needed. Anything not stubbed here is not exercised by the unit tests.
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'WTT_TK_DIR', dirname( __DIR__ ) . '/woo-toman-toolkit/' );
define( 'WTT_USD_VERSION', 'test' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

/* ---- in-memory state, reset by WTT_TestCase::setUp() ---- */
$GLOBALS['wtt_test'] = array();

function wtt_test_reset() {
	$GLOBALS['wtt_test'] = array(
		'options'    => array(),
		'transients' => array(),
		'http'       => null,      // callable( $url, $args ) returning a response array or WP_Error.
		'http_calls' => array(),
		'currency'   => 'IRT',
		'tz'         => 'UTC',
		'locale'     => 'en_US',
	);
}
wtt_test_reset();

/* ---- i18n / escaping ---- */
function __( $text ) { return $text; }
function _x( $text ) { return $text; }
function _n( $single, $plural, $number ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function wp_strip_all_tags( $text ) { return trim( strip_tags( (string) $text ) ); }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function absint( $n ) { return abs( (int) $n ); }
function wc_format_decimal( $n ) { return is_scalar( $n ) ? str_replace( ',', '.', trim( (string) $n ) ) : ''; }
function wp_json_encode( $data, $flags = 0 ) { return json_encode( $data, $flags ); }
function wp_specialchars_decode( $s ) { return htmlspecialchars_decode( (string) $s, ENT_QUOTES ); }
function get_bloginfo() { return 'Test Store'; }
function home_url( $path = '' ) { return 'https://example.test' . $path; }
function wp_unslash( $v ) { return is_string( $v ) ? stripslashes( $v ) : $v; }
function wp_salt( $scheme = 'auth' ) { return 'test-salt-' . $scheme; }

/* ---- hooks ---- */
function apply_filters( $hook, $value ) { return $value; }
function add_action() {}
function add_filter() {}

/* ---- options & transients (array backed, no expiry bookkeeping beyond what tests need) ---- */
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wtt_test']['options'] ) ? $GLOBALS['wtt_test']['options'][ $name ] : $default;
}
function update_option( $name, $value ) { $GLOBALS['wtt_test']['options'][ $name ] = $value; return true; }
function add_option( $name, $value ) { return update_option( $name, $value ); }
function delete_option( $name ) { unset( $GLOBALS['wtt_test']['options'][ $name ] ); return true; }
function get_transient( $name ) {
	return array_key_exists( $name, $GLOBALS['wtt_test']['transients'] ) ? $GLOBALS['wtt_test']['transients'][ $name ] : false;
}
function set_transient( $name, $value ) { $GLOBALS['wtt_test']['transients'][ $name ] = $value; return true; }
function delete_transient( $name ) { unset( $GLOBALS['wtt_test']['transients'][ $name ] ); return true; }

/* ---- WooCommerce / locale / time ---- */
function get_woocommerce_currency() { return $GLOBALS['wtt_test']['currency']; }
function get_locale() { return $GLOBALS['wtt_test']['locale']; }
function wp_date( $format, $timestamp = null ) {
	$dt = new DateTime( '@' . (int) $timestamp );
	$dt->setTimezone( new DateTimeZone( $GLOBALS['wtt_test']['tz'] ) );
	return $dt->format( $format );
}

/* ---- HTTP ---- */
class WP_Error {
	private $code;
	private $message;
	public function __construct( $code = '', $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
function is_wp_error( $thing ) { return $thing instanceof WP_Error; }
function wtt_test_http( $url, $args ) {
	$GLOBALS['wtt_test']['http_calls'][] = array( 'url' => $url, 'args' => $args );
	return call_user_func( $GLOBALS['wtt_test']['http'], $url, $args );
}
function wp_remote_post( $url, $args = array() ) { return wtt_test_http( $url, $args ); }
function wp_remote_request( $url, $args = array() ) { return wtt_test_http( $url, $args ); }
function wp_remote_retrieve_response_code( $r ) { return isset( $r['response']['code'] ) ? $r['response']['code'] : ''; }
function wp_remote_retrieve_body( $r ) { return isset( $r['body'] ) ? $r['body'] : ''; }

/* ---- code under test (only files that are safe to load without WordPress) ---- */
require_once WTT_TK_DIR . 'includes/helpers-jalali.php';
require_once WTT_TK_DIR . 'includes/helpers-fa.php';
require_once WTT_TK_DIR . 'includes/class-logger.php';
require_once WTT_TK_DIR . 'modules/usd-pricing/includes/class-logger.php';
require_once WTT_TK_DIR . 'modules/usd-pricing/includes/class-settings.php';
require_once WTT_TK_DIR . 'modules/usd-pricing/includes/class-rate-manager.php';
require_once WTT_TK_DIR . 'modules/usd-pricing/includes/class-price-sync.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-phone.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-provider.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-provider-farazsms.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-provider-ippanel-edge.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-events.php';
require_once WTT_TK_DIR . 'modules/sms/includes/class-settings.php';
require_once WTT_TK_DIR . 'modules/accounts/includes/class-settings.php';
require_once WTT_TK_DIR . 'modules/accounts/includes/class-limiter.php';
require_once WTT_TK_DIR . 'modules/accounts/includes/class-otp.php';

abstract class WTT_TestCase extends \PHPUnit\Framework\TestCase {
	protected function setUp(): void {
		wtt_test_reset();
		parent::setUp();
	}

	/** Set the HTTP stub: a fixed response array or a callable. */
	protected function http( $response ) {
		$GLOBALS['wtt_test']['http'] = is_callable( $response ) ? $response : function () use ( $response ) {
			return $response;
		};
	}

	protected static function resp( $code, $body ) {
		return array( 'response' => array( 'code' => $code ), 'body' => is_array( $body ) ? json_encode( $body ) : $body );
	}

	/** Call a private/protected static method. */
	protected static function priv( $class, $method, array $args = array() ) {
		$m = new ReflectionMethod( $class, $method );
		$m->setAccessible( true );
		return $m->invokeArgs( null, $args );
	}
}
