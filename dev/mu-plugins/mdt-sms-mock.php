<?php
/**
 * DEV ONLY: mock the FarazSMS APIs (api.iranpayamak.com and legacy edge.ippanel.com). Logs requests (Authorization redacted) to wp-content/sms.log.
 * API key "bad" => 401; pattern code "bad" => 422; code "fail5xx" => 503; code "neterr" => WP_Error.
 * Pattern params (incl. the OTP code) are logged in the clear: DEV ONLY, so tests can read the code.
 * If wp-content/sms-fast-retry exists the retry delay becomes 1 second.
 */
function mdt_sms_mock_iranpayamak( $args, $url ) {
	$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
	$key     = isset( $headers['Api-Key'] ) ? (string) $headers['Api-Key'] : '';
	if ( isset( $headers['Api-Key'] ) ) {
		$headers['Api-Key'] = '[redacted]';
	}
	$body = isset( $args['body'] ) ? (string) $args['body'] : '';
	$data = json_decode( $body, true );
	file_put_contents(
		WP_CONTENT_DIR . '/sms.log',
		wp_json_encode(
			array(
				'time'    => gmdate( 'c' ),
				'host'    => 'api.iranpayamak.com',
				'method'  => isset( $args['method'] ) ? $args['method'] : '',
				'url'     => $url,
				'headers' => $headers,
				'body'    => $data,
				'params'  => is_array( $data ) && isset( $data['attributes'] ) ? $data['attributes'] : null,
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		) . "\n",
		FILE_APPEND
	);
	$resp = function ( $http, $payload ) {
		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			'response' => array( 'code' => $http, 'message' => '' ),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	if ( 'bad' === $key ) {
		return $resp( 401, array( 'status' => 'error', 'message' => 'Api-Key not valid', 'data' => null ) );
	}
	$path = (string) wp_parse_url( $url, PHP_URL_PATH );
	if ( '/ws/v1/account/balance' === $path ) {
		return $resp( 200, array( 'status' => 'success', 'data' => array( 'balance' => 125000 ) ) );
	}
	if ( '/ws/v1/lines/accessible' === $path ) {
		return $resp( 200, array( 'status' => 'success', 'data' => array( array( 'number' => '90008361' ) ) ) );
	}
	$code = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
	if ( 'bad' === $code ) {
		return $resp( 422, array( 'status' => 'error', 'message' => 'pattern not found', 'data' => null ) );
	}
	if ( 'fail5xx' === $code ) {
		return $resp( 503, array( 'status' => 'error', 'message' => 'service unavailable', 'data' => null ) );
	}
	if ( 'neterr' === $code ) {
		return new WP_Error( 'http_request_failed', 'cURL error 28: timeout (mock)' );
	}
	return $resp( 200, array( 'status' => 'success', 'message' => 'ok', 'data' => array( 'id' => 12345 ) ) );
}

add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( 'api.iranpayamak.com' === $host ) {
			// Live-probe switch: create wp-content/sms-mock-off-iranpayamak to hit the real host.
			if ( file_exists( WP_CONTENT_DIR . '/sms-mock-off-iranpayamak' ) ) {
				return $pre;
			}
			return mdt_sms_mock_iranpayamak( $args, $url );
		}
		if ( 'edge.ippanel.com' !== $host ) {
			return $pre;
		}
		$headers = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$key     = isset( $headers['Authorization'] ) ? (string) $headers['Authorization'] : '';
		if ( isset( $headers['Authorization'] ) ) {
			$headers['Authorization'] = '[redacted]';
		}
		$body = isset( $args['body'] ) ? (string) $args['body'] : '';
		file_put_contents(
			WP_CONTENT_DIR . '/sms.log',
			wp_json_encode(
				array(
					'time'    => gmdate( 'c' ),
					'method'  => isset( $args['method'] ) ? $args['method'] : '',
					'url'     => $url,
					'timeout' => isset( $args['timeout'] ) ? $args['timeout'] : null,
					'headers' => $headers,
					'body'    => json_decode( $body, true ),
					'params'  => isset( json_decode( $body, true )['params'] ) ? json_decode( $body, true )['params'] : null,
				),
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			) . "\n",
			FILE_APPEND
		);
		$data = json_decode( $body, true );
		$code = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '';
		$resp = function ( $http, $payload ) {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
				'response' => array( 'code' => $http, 'message' => '' ),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		if ( 'bad' === $key ) {
			return $resp( 401, array( 'data' => null, 'meta' => array( 'status' => false, 'message' => 'توکن نامعتبر است', 'message_code' => '400-1' ) ) );
		}
		if ( 'bad' === $code ) {
			return $resp( 422, array( 'data' => null, 'meta' => array( 'status' => false, 'message' => 'خطای اعتبارسنجی', 'message_code' => '400-2' ) ) );
		}
		if ( 'fail5xx' === $code ) {
			return $resp( 503, array( 'meta' => array( 'status' => false, 'message' => 'سرویس در دسترس نیست', 'message_code' => '500-1' ) ) );
		}
		if ( 'neterr' === $code ) {
			return new WP_Error( 'http_request_failed', 'cURL error 28: timeout (mock)' );
		}
		return $resp( 200, array( 'data' => array( 'message_outbox_ids' => array( wp_rand( 1000000000, 1999999999 ) ) ), 'meta' => array( 'status' => true, 'message' => 'انجام شد', 'message_code' => '200-1' ) ) );
	},
	10,
	3
);
add_filter(
	'mdt_sms_retry_delay',
	function ( $d ) {
		return file_exists( WP_CONTENT_DIR . '/sms-fast-retry' ) ? 1 : $d;
	}
);
