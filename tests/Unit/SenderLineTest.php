<?php
/**
 * Sender line normalisation: IPPanel Edge stores E.164, FarazSMS (IranPayamak) stores the digits shown in the panel.
 */
class SenderLineTest extends WTT_TestCase {

	public function e164Provider() {
		return array(
			'bare line'         => array( '3000505', '+983000505' ),
			'98 prefix'         => array( '983000505', '+983000505' ),
			'plus 98'           => array( '+983000505', '+983000505' ),
			'0098 prefix'       => array( '00983000505', '+983000505' ),
			'leading zero'      => array( '03000505', '+983000505' ),
			'persian digits'    => array( '۳۰۰۰۵۰۵', '+983000505' ),
			'spaces'            => array( '+98 3000 505', '+983000505' ),
			'empty'             => array( '', '' ),
			'no digits'         => array( 'abc', '' ),
		);
	}

	/** @dataProvider e164Provider */
	public function test_ippanel_edge_sender_is_e164( $raw, $expected ) {
		$this->assertSame( $expected, WTT_SMS_Phone::normalize_sender( $raw ) );
	}

	public function farazProvider() {
		return array(
			'plain line'                 => array( '90008361', '90008361' ),
			'plus 98 is removed'         => array( '+9890008361', '90008361' ),
			'0098 is removed'            => array( '009890008361', '90008361' ),
			'e164 stored earlier'        => array( '+983000505', '3000505' ),
			'non-digits dropped'         => array( ' 3000-505 ', '3000505' ),
			'bare 98.. kept as shown'    => array( '983000505', '983000505' ),
			'empty'                      => array( '', '' ),
		);
	}

	/** @dataProvider farazProvider */
	public function test_farazsms_line_is_digits_only( $raw, $expected ) {
		$this->assertSame( $expected, WTT_SMS_Provider_FarazSMS::format_line( $raw ) );
	}

	public function test_farazsms_recipient_format() {
		$this->assertSame( '09123456789', WTT_SMS_Provider_FarazSMS::format_recipient( '+989123456789' ) );
		$this->assertSame( '09123456789', WTT_SMS_Provider_FarazSMS::format_recipient( '9123456789' ) );
		$this->assertSame( '09123456789', WTT_SMS_Provider_FarazSMS::format_recipient( '00989123456789' ) );
		$this->assertSame( '09123456789', WTT_SMS_Provider_FarazSMS::format_recipient( '09123456789' ) );
	}

	public function test_settings_sanitize_stores_sender_per_provider() {
		$faraz = WTT_SMS_Settings::sanitize( array( 'provider' => 'farazsms', 'sender' => '+9890008361' ), array() );
		$this->assertSame( 'farazsms', $faraz['provider'] );
		$this->assertSame( '90008361', $faraz['sender'] );

		$faraz = WTT_SMS_Settings::sanitize( array( 'provider' => 'farazsms', 'sender' => '۹۰۰۰۸۳۶۱' ), array() );
		$this->assertSame( '90008361', $faraz['sender'] );

		$edge = WTT_SMS_Settings::sanitize( array( 'provider' => 'ippanel_edge', 'sender' => '3000505' ), array() );
		$this->assertSame( 'ippanel_edge', $edge['provider'] );
		$this->assertSame( '+983000505', $edge['sender'] );

		$unknown = WTT_SMS_Settings::sanitize( array( 'provider' => 'nope', 'sender' => '1' ), array() );
		$this->assertSame( 'farazsms', $unknown['provider'] );
	}

	public function test_settings_sanitize_keeps_existing_api_key_when_blank() {
		$out = WTT_SMS_Settings::sanitize( array( 'api_key' => '' ), array( 'api_key' => 'secret-key' ) );
		$this->assertSame( 'secret-key', $out['api_key'] );
		$out = WTT_SMS_Settings::sanitize( array( 'api_key' => '', 'api_key_clear' => '1' ), array( 'api_key' => 'secret-key' ) );
		$this->assertSame( '', $out['api_key'] );
	}
}
