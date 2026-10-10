<?php
/**
 * FarazSMS provider on its own API (IranPayamak): https://api.iranpayamak.com
 *
 * @package MimDollarToolkit
 */

defined( 'ABSPATH' ) || exit;

class MDT_SMS_Provider_FarazSMS implements MDT_SMS_Provider {

	const BASE_URL = 'https://api.iranpayamak.com';

	/** @var string */
	private $api_key;

	/** @var string */
	private $sender;

	/**
	 * @param array $settings Plugin settings (api_key, sender).
	 */
	public function __construct( $settings = array() ) {
		$this->api_key = isset( $settings['api_key'] ) ? (string) $settings['api_key'] : '';
		$this->sender  = isset( $settings['sender'] ) ? (string) $settings['sender'] : '';
	}

	/**
	 * +989123456789 => 09123456789.
	 *
	 * @param string $to Canonical mobile.
	 * @return string
	 */
	public static function format_recipient( $to ) {
		$d = preg_replace( '/\D+/', '', (string) $to );
		$d = is_string( $d ) ? $d : '';
		if ( 0 === strpos( $d, '0098' ) ) {
			$d = substr( $d, 4 );
		} elseif ( 0 === strpos( $d, '989' ) && 12 === strlen( $d ) ) {
			$d = substr( $d, 2 );
		}
		if ( 10 === strlen( $d ) && 0 === strpos( $d, '9' ) ) {
			$d = '0' . $d;
		}
		return $d;
	}

	/**
	 * Sender line digits as shown in the panel; only a leading +98 / 0098 country prefix is removed.
	 *
	 * @param string $line Stored sender.
	 * @return string
	 */
	public static function format_line( $line ) {
		$line = trim( (string) $line );
		$d    = preg_replace( '/\D+/', '', $line );
		$d    = is_string( $d ) ? $d : '';
		if ( 0 === strpos( $line, '+98' ) ) {
			return substr( $d, 2 );
		}
		if ( 0 === strpos( $d, '0098' ) ) {
			return substr( $d, 4 );
		}
		return $d;
	}

	/**
	 * Perform a request and parse the envelope.
	 *
	 * @return array {net:?string, http:int, ok:bool, data:mixed, message:string}
	 */
	private function request( $method, $path, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => 15,
			'headers' => array(
				'Api-Key'      => $this->api_key,
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$response = wp_remote_request( self::BASE_URL . $path, $args );
		if ( is_wp_error( $response ) ) {
			return array(
				'net'     => $response->get_error_message(),
				'http'    => 0,
				'ok'      => false,
				'data'    => null,
				'message' => '',
			);
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$json = is_array( $json ) ? $json : array();
		$st   = isset( $json['status'] ) && is_scalar( $json['status'] ) ? strtolower( (string) $json['status'] ) : '';
		$msg  = '';
		if ( isset( $json['message'] ) ) {
			$msg = is_scalar( $json['message'] ) ? (string) $json['message'] : (string) wp_json_encode( $json['message'], JSON_UNESCAPED_UNICODE );
			$msg = trim( wp_strip_all_tags( $msg ) );
			if ( function_exists( 'mb_substr' ) ) {
				$msg = mb_substr( $msg, 0, 300 );
			} else {
				$msg = substr( $msg, 0, 300 );
			}
		}
		return array(
			'net'     => null,
			'http'    => $http,
			'ok'      => ( $http >= 200 && $http < 300 && 'error' !== $st ),
			'data'    => isset( $json['data'] ) ? $json['data'] : null,
			'message' => $msg,
		);
	}

	private function error_text( array $r ) {
		/* translators: 1: provider message, 2: HTTP status */
		return sprintf( __( '%1$s (HTTP %2$d)', 'mim-dollar-toolkit' ), '' !== $r['message'] ? $r['message'] : __( 'Request failed', 'mim-dollar-toolkit' ), $r['http'] );
	}

	private static function extract_id( $data ) {
		if ( is_scalar( $data ) && '' !== (string) $data && ! is_bool( $data ) ) {
			return (string) $data;
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		foreach ( array( 'id', 'send_request_id' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_scalar( $data[ $k ] ) ) {
				return (string) $data[ $k ];
			}
		}
		if ( isset( $data[0] ) && is_array( $data[0] ) && isset( $data[0]['id'] ) && is_scalar( $data[0]['id'] ) ) {
			return (string) $data[0]['id'];
		}
		if ( isset( $data[0] ) && is_scalar( $data[0] ) ) {
			return (string) $data[0];
		}
		return null;
	}

	public function send_pattern( $to, $code, array $params ) {
		$result = array(
			'ok'    => false,
			'id'    => null,
			'error' => null,
			'retry' => false,
			'code'  => null,
		);

		$clean = array();
		foreach ( $params as $k => $v ) {
			$clean[ (string) $k ] = (string) $v;
		}
		$r = $this->request(
			'POST',
			'/ws/v1/sms/pattern',
			array(
				'code'          => (string) $code,
				'recipient'     => self::format_recipient( $to ),
				'attributes'    => (object) $clean,
				'line_number'   => self::format_line( $this->sender ),
				'number_format' => 'english',
			)
		);
		if ( null !== $r['net'] ) {
			$result['error'] = $r['net'];
			$result['retry'] = true;
			return $result;
		}
		if ( $r['ok'] ) {
			$result['ok'] = true;
			$result['id'] = self::extract_id( $r['data'] );
			return $result;
		}
		$result['error'] = $this->error_text( $r );
		$result['code']  = (string) $r['http'];
		$result['retry'] = ( $r['http'] >= 500 );
		return $result;
	}

	/**
	 * Free account check: balance + accessible lines. Never sends an SMS.
	 *
	 * @return array {ok:bool, error:?string, balance:mixed, lines:string[]}
	 */
	public function check() {
		$out = array(
			'ok'      => false,
			'error'   => null,
			'balance' => null,
			'lines'   => array(),
		);
		$b = $this->request( 'GET', '/ws/v1/account/balance' );
		if ( null !== $b['net'] ) {
			$out['error'] = $b['net'];
			return $out;
		}
		if ( ! $b['ok'] ) {
			$out['error'] = $this->error_text( $b );
			return $out;
		}
		$out['ok']      = true;
		$out['balance'] = $b['data'];
		$l              = $this->request( 'GET', '/ws/v1/lines/accessible' );
		if ( null === $l['net'] && $l['ok'] ) {
			$out['lines'] = self::extract_lines( $l['data'] );
		}
		return $out;
	}

	private static function extract_lines( $data ) {
		$found = array();
		$walk  = function ( $node ) use ( &$walk, &$found ) {
			if ( is_array( $node ) ) {
				foreach ( $node as $k => $v ) {
					if ( is_scalar( $v ) && in_array( (string) $k, array( 'number', 'line_number', 'line', 'linenumber' ), true ) ) {
						$found[] = (string) $v;
					} elseif ( is_array( $v ) ) {
						$walk( $v );
					} elseif ( is_scalar( $v ) && is_int( $k ) && preg_match( '/^\+?\d{4,}$/', (string) $v ) ) {
						$found[] = (string) $v;
					}
				}
			}
		};
		$walk( $data );
		return array_values( array_unique( $found ) );
	}
}
