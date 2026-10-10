<?php
class SmsProviderTest extends MDT_TestCase {

	private function faraz() {
		return new MDT_SMS_Provider_FarazSMS( array( 'api_key' => 'k-123', 'sender' => '+9890008361' ) );
	}

	private function edge() {
		return new MDT_SMS_Provider_IPPanel_Edge( array( 'api_key' => 'k-123', 'sender' => '+983000505' ) );
	}

	/* ---- FarazSMS (IranPayamak) ---- */

	public function test_faraz_success_returns_id() {
		$this->http( self::resp( 200, array( 'status' => 'success', 'data' => array( 'id' => 98765 ) ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'abc123', array( 'code' => 12345 ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '98765', $r['id'] );
		$this->assertFalse( $r['retry'] );
		$this->assertNull( $r['error'] );
	}

	public function test_faraz_request_shape() {
		$this->http( self::resp( 200, array( 'status' => 'success', 'data' => 1 ) ) );
		$this->faraz()->send_pattern( '+989123456789', 'abc123', array( 'code' => 12345 ) );
		$call = $GLOBALS['mdt_test']['http_calls'][0];
		$this->assertSame( 'https://api.iranpayamak.com/ws/v1/sms/pattern', $call['url'] );
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( 'k-123', $call['args']['headers']['Api-Key'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( '09123456789', $body['recipient'] );
		$this->assertSame( '90008361', $body['line_number'] );
		$this->assertSame( 'abc123', $body['code'] );
		$this->assertSame( array( 'code' => '12345' ), $body['attributes'], 'variables are sent as strings' );
	}

	public function test_faraz_id_shapes() {
		foreach ( array(
			array( 'data' => 'abc', 'expected' => 'abc' ),
			array( 'data' => array( 'send_request_id' => 7 ), 'expected' => '7' ),
			array( 'data' => array( array( 'id' => 11 ) ), 'expected' => '11' ),
			array( 'data' => array( 22 ), 'expected' => '22' ),
			array( 'data' => array(), 'expected' => null ),
		) as $case ) {
			$this->http( self::resp( 200, array( 'status' => 'success', 'data' => $case['data'] ) ) );
			$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
			$this->assertTrue( $r['ok'] );
			$this->assertSame( $case['expected'], $r['id'] );
		}
	}

	public function test_faraz_http_200_with_error_status_is_a_failure_without_retry() {
		$this->http( self::resp( 200, array( 'status' => 'error', 'message' => 'bad pattern' ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retry'] );
		$this->assertStringContainsString( 'bad pattern', $r['error'] );
	}

	public function test_faraz_401_is_not_retried() {
		$this->http( self::resp( 401, array( 'status' => 'error', 'message' => 'Unauthorized' ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retry'] );
		$this->assertSame( '401', $r['code'] );
		$this->assertSame( 'Unauthorized (HTTP 401)', $r['error'] );
	}

	public function test_faraz_422_is_not_retried_and_message_is_sanitised() {
		$this->http( self::resp( 422, array( 'message' => array( 'recipient' => array( 'invalid' ) ) ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retry'] );
		$this->assertSame( '422', $r['code'] );
		$this->assertStringContainsString( 'invalid', $r['error'] );

		$this->http( self::resp( 422, array( 'message' => '<b>bad</b> <script>x</script>' ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertStringNotContainsString( '<', $r['error'] );
	}

	public function test_faraz_long_message_is_truncated() {
		$this->http( self::resp( 400, array( 'message' => str_repeat( 'x', 1000 ) ) ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertLessThan( 330, strlen( $r['error'] ) );
	}

	public function serverErrorProvider() {
		return array( array( 500 ), array( 502 ), array( 503 ), array( 504 ) );
	}

	/** @dataProvider serverErrorProvider */
	public function test_faraz_5xx_is_retried( $code ) {
		$this->http( self::resp( $code, 'upstream down' ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['retry'] );
		$this->assertSame( (string) $code, $r['code'] );
		$this->assertStringContainsString( 'Request failed', $r['error'] );
	}

	public function test_faraz_wp_error_is_retried() {
		$this->http( new WP_Error( 'http_request_failed', 'cURL error 28: timeout' ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertTrue( $r['retry'] );
		$this->assertSame( 'cURL error 28: timeout', $r['error'] );
	}

	public function test_faraz_non_json_success_body_is_still_ok() {
		$this->http( self::resp( 200, 'OK' ) );
		$r = $this->faraz()->send_pattern( '+989123456789', 'c', array() );
		$this->assertTrue( $r['ok'] );
		$this->assertNull( $r['id'] );
	}

	public function test_faraz_check_reads_balance_and_lines() {
		$this->http( function ( $url ) {
			if ( false !== strpos( $url, 'balance' ) ) {
				return self::resp( 200, array( 'status' => 'success', 'data' => 15000 ) );
			}
			return self::resp( 200, array( 'status' => 'success', 'data' => array( array( 'number' => '90008361' ), array( 'line_number' => '3000505' ), array( 'number' => '90008361' ) ) ) );
		} );
		$r = $this->faraz()->check();
		$this->assertTrue( $r['ok'] );
		$this->assertSame( 15000, $r['balance'] );
		$this->assertSame( array( '90008361', '3000505' ), $r['lines'] );
	}

	public function test_faraz_check_failure_modes() {
		$this->http( self::resp( 401, array( 'message' => 'Unauthorized' ) ) );
		$r = $this->faraz()->check();
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( '401', $r['error'] );

		$this->http( new WP_Error( 'x', 'no route' ) );
		$r = $this->faraz()->check();
		$this->assertFalse( $r['ok'] );
		$this->assertSame( 'no route', $r['error'] );
	}

	/* ---- IPPanel Edge (legacy) ---- */

	public function test_edge_success() {
		$this->http( self::resp( 200, array( 'meta' => array( 'status' => true ), 'data' => array( 'message_outbox_ids' => array( 555 ) ) ) ) );
		$r = $this->edge()->send_pattern( '+989123456789', 'c', array( 'code' => '1' ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '555', $r['id'] );
		$call = $GLOBALS['mdt_test']['http_calls'][0];
		$this->assertSame( 'k-123', $call['args']['headers']['Authorization'] );
		$body = json_decode( $call['args']['body'], true );
		$this->assertSame( '+983000505', $body['from_number'] );
		$this->assertSame( array( '+989123456789' ), $body['recipients'] );
	}

	public function test_edge_failures() {
		$this->http( self::resp( 401, array( 'meta' => array( 'status' => false, 'message' => 'Unauthorized', 'message_code' => 'E401' ) ) ) );
		$r = $this->edge()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'] );
		$this->assertFalse( $r['retry'] );
		$this->assertSame( 'E401', $r['code'] );
		$this->assertSame( 'Unauthorized (code E401, HTTP 401)', $r['error'] );

		$this->http( self::resp( 200, array( 'meta' => array( 'status' => false, 'message' => 'no credit' ) ) ) );
		$r = $this->edge()->send_pattern( '+989123456789', 'c', array() );
		$this->assertFalse( $r['ok'], 'HTTP 200 with meta.status=false is a failure' );

		$this->http( self::resp( 503, '' ) );
		$this->assertTrue( $this->edge()->send_pattern( '+989123456789', 'c', array() )['retry'] );

		$this->http( new WP_Error( 'e', 'timeout' ) );
		$r = $this->edge()->send_pattern( '+989123456789', 'c', array() );
		$this->assertTrue( $r['retry'] );
		$this->assertSame( 'timeout', $r['error'] );
	}
}
