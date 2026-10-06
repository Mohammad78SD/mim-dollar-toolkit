<?php
/**
 * Only the parts of the OTP / limiter code that do not need a database or WordPress users:
 * code hashing, transient-backed counters and client IP extraction.
 */
class OtpTest extends WTT_TestCase {

	protected function setUp(): void {
		parent::setUp();
		$_SERVER = array();
	}

	protected function tearDown(): void {
		$_SERVER = array();
		parent::tearDown();
	}

	/* ---- code hashing ---- */

	public function test_hash_is_hmac_sha256_bound_to_mobile_and_code() {
		$h = self::priv( 'WTT_ACC_OTP', 'hash_code', array( '12345', '09123456789' ) );
		$this->assertSame( hash_hmac( 'sha256', '09123456789|12345', 'test-salt-auth' ), $h );
		$this->assertSame( 64, strlen( $h ) );
		$this->assertSame( $h, self::priv( 'WTT_ACC_OTP', 'hash_code', array( '12345', '09123456789' ) ), 'deterministic' );
		$this->assertNotSame( $h, self::priv( 'WTT_ACC_OTP', 'hash_code', array( '12346', '09123456789' ) ) );
		$this->assertNotSame( $h, self::priv( 'WTT_ACC_OTP', 'hash_code', array( '12345', '09123456780' ) ), 'a code for one mobile does not verify for another' );
		$this->assertStringNotContainsString( '12345', $h );
	}

	public function test_leading_zero_codes_are_distinct() {
		$this->assertNotSame(
			self::priv( 'WTT_ACC_OTP', 'hash_code', array( '01234', '09123456789' ) ),
			self::priv( 'WTT_ACC_OTP', 'hash_code', array( '1234', '09123456789' ) )
		);
	}

	public function test_max_attempts_constant() {
		$this->assertSame( 5, WTT_ACC_OTP::MAX_ATTEMPTS );
	}

	/* ---- limiter keys and counters ---- */

	public function test_limiter_key_hashes_the_identifier() {
		$k = WTT_ACC_Limiter::key( 'otp', '09123456789' );
		$this->assertSame( 'wtt_acc_otp_' . hash( 'sha256', '09123456789' ), $k );
		$this->assertStringNotContainsString( '0912', $k, 'no phone number in transient names' );
		$this->assertNotSame( $k, WTT_ACC_Limiter::key( 'rmo', '09123456789' ) );
		$this->assertLessThanOrEqual( 172, strlen( $k ), 'fits the 172-char transient name limit' );
	}

	public function test_hit_and_status_count_within_a_window() {
		$key = WTT_ACC_Limiter::key( 'rmo', 'x' );
		$this->assertSame( array( 'count' => 0, 'reset' => 0 ), WTT_ACC_Limiter::status( $key ) );
		$this->assertSame( 1, WTT_ACC_Limiter::hit( $key, HOUR_IN_SECONDS ) );
		$this->assertSame( 2, WTT_ACC_Limiter::hit( $key, HOUR_IN_SECONDS ) );
		$this->assertSame( 3, WTT_ACC_Limiter::hit( $key, 5 ), 'the window length only applies when a window starts' );
		$s = WTT_ACC_Limiter::status( $key );
		$this->assertSame( 3, $s['count'] );
		$this->assertGreaterThan( 3590, $s['reset'] );
		$this->assertLessThanOrEqual( 3600, $s['reset'] );
	}

	public function test_expired_window_starts_over() {
		$key = WTT_ACC_Limiter::key( 'rmo', 'y' );
		set_transient( $key, array( 'count' => 9, 'until' => time() - 1 ) );
		$this->assertSame( array( 'count' => 0, 'reset' => 0 ), WTT_ACC_Limiter::status( $key ) );
		$this->assertSame( 1, WTT_ACC_Limiter::hit( $key, 60 ) );
	}

	public function test_malformed_counter_is_treated_as_empty() {
		$key = WTT_ACC_Limiter::key( 'rmo', 'z' );
		set_transient( $key, 'garbage' );
		$this->assertSame( 0, WTT_ACC_Limiter::status( $key )['count'] );
		$this->assertSame( 1, WTT_ACC_Limiter::hit( $key, 60 ) );
	}

	/* ---- client IP (CDN / proxy header handling) ---- */

	private function source( $source, $custom = '' ) {
		update_option( WTT_ACC_Settings::OPTION, array( 'ip_source' => $source, 'ip_custom_header' => $custom ) );
	}

	public function test_default_source_is_remote_addr_and_headers_are_ignored() {
		$_SERVER['REMOTE_ADDR']          = '203.0.113.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.9';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
		$this->assertSame( '203.0.113.7', WTT_ACC_Limiter::client_ip() );
	}

	public function test_configured_header_is_used_when_valid() {
		$_SERVER['REMOTE_ADDR']           = '10.0.0.1';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.10';
		$this->source( 'cf-connecting-ip' );
		$this->assertSame( '198.51.100.10', WTT_ACC_Limiter::client_ip() );
	}

	public function test_invalid_or_missing_header_falls_back_to_remote_addr() {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.7';
		$this->source( 'x-real-ip' );
		$this->assertSame( '203.0.113.7', WTT_ACC_Limiter::client_ip() );
		$_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';
		$this->assertSame( '203.0.113.7', WTT_ACC_Limiter::client_ip() );
	}

	public function test_x_forwarded_for_takes_leftmost_public_ip() {
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '192.168.1.5, 198.51.100.20, 203.0.113.3';
		$this->source( 'x-forwarded-for' );
		$this->assertSame( '198.51.100.20', WTT_ACC_Limiter::client_ip(), 'private ranges are skipped' );
	}

	public function test_custom_header() {
		$_SERVER['REMOTE_ADDR']        = '10.0.0.1';
		$_SERVER['HTTP_AR_REAL_IP']    = '2001:db8::1';
		$this->source( 'custom', 'AR-Real-IP' );
		$this->assertSame( '2001:db8::1', WTT_ACC_Limiter::client_ip() );
	}

	public function test_unusable_remote_addr_becomes_zero_address() {
		$this->assertSame( '0.0.0.0', WTT_ACC_Limiter::client_ip() );
		$_SERVER['REMOTE_ADDR'] = 'garbage';
		$this->assertSame( '0.0.0.0', WTT_ACC_Limiter::client_ip() );
	}

	public function test_server_key_mapping() {
		$this->assertSame( 'HTTP_X_REAL_IP', WTT_ACC_Limiter::server_key( 'X-Real-IP' ) );
	}

	public function test_settings_header_name_validation() {
		$this->assertSame( 'X-Real-IP', WTT_ACC_Settings::clean_header_name( ' X-Real-IP ' ) );
		$this->assertSame( '', WTT_ACC_Settings::clean_header_name( "X-Real\r\nIP" ) );
		$this->assertSame( '', WTT_ACC_Settings::clean_header_name( 'bad header' ) );
		$this->assertSame( '', WTT_ACC_Settings::clean_header_name( array() ) );
	}

	public function test_settings_are_clamped() {
		update_option( WTT_ACC_Settings::OPTION, array( 'code_length' => 9, 'cooldown' => 1, 'expiry' => 999, 'mobile_per_hour' => 0, 'ip_source' => 'evil' ) );
		$s = WTT_ACC_Settings::all();
		$this->assertSame( 5, $s['code_length'] );
		$this->assertSame( 30, $s['cooldown'] );
		$this->assertSame( 30, $s['expiry'] );
		$this->assertSame( 1, $s['mobile_per_hour'] );
		$this->assertSame( 'remote_addr', $s['ip_source'] );
	}
}
