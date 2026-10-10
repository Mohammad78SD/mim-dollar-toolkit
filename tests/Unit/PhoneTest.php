<?php
class PhoneTest extends MDT_TestCase {

	public function validMobileProvider() {
		$e = '+989123456789';
		return array(
			'local'                  => array( '09123456789', $e ),
			'without leading zero'   => array( '9123456789', $e ),
			'98 prefix'              => array( '989123456789', $e ),
			'plus 98'                => array( '+989123456789', $e ),
			'0098 prefix'            => array( '00989123456789', $e ),
			'spaces and dashes'      => array( '0912 345-6789', $e ),
			'plus with spaces'       => array( '+98 912 345 6789', $e ),
			'parentheses and dots'   => array( '(0912).345.6789', $e ),
			'persian digits'         => array( '۰۹۱۲۳۴۵۶۷۸۹', $e ),
			'arabic-indic digits'    => array( '٠٩١٢٣٤٥٦٧٨٩', $e ),
			'persian with +98'       => array( '+۹۸۹۱۲۳۴۵۶۷۸۹', $e ),
			'zwnj / bidi marks'      => array( "\u{200F}0912\u{200C}3456789\u{200E}", $e ),
			'surrounding whitespace' => array( "  09123456789\n", $e ),
		);
	}

	/** @dataProvider validMobileProvider */
	public function test_normalize_valid( $raw, $expected ) {
		$n = MDT_SMS_Phone::normalize( $raw );
		$this->assertSame( $expected, $n );
		$this->assertTrue( MDT_SMS_Phone::is_valid_mobile( $n ) );
	}

	public function invalidProvider() {
		return array(
			'empty'                => array( '' ),
			'letters only'         => array( 'abc' ),
			'too short'            => array( '0912345' ),
			'too long'             => array( '091234567890' ),
			'landline'             => array( '02112345678' ),
			'not a mobile prefix'  => array( '08123456789' ),
			'foreign number'       => array( '+441234567890' ),
			'foreign with plus 1'  => array( '+12025550123' ),
			'98 + landline'        => array( '982112345678' ),
		);
	}

	/** @dataProvider invalidProvider */
	public function test_invalid_numbers_are_rejected( $raw ) {
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( MDT_SMS_Phone::normalize( $raw ) ) );
	}

	public function test_is_valid_mobile_is_strict() {
		$this->assertTrue( MDT_SMS_Phone::is_valid_mobile( '+989000000000' ) );
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( '09123456789' ), 'only E.164 is accepted' );
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( '+98912345678' ) );
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( '+9891234567890' ) );
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( "+989123456789\n" ), 'no trailing newline' );
	}

	public function test_local_and_mask() {
		$this->assertSame( '09123456789', MDT_SMS_Phone::local( '+989123456789' ) );
		$this->assertSame( '+441234567890', MDT_SMS_Phone::local( '+441234567890' ) );
		$this->assertSame( '0912***6789', MDT_SMS_Phone::mask( '+989123456789' ) );
		$this->assertSame( '0912***6789', MDT_SMS_Phone::mask( '۰۹۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( '***', MDT_SMS_Phone::mask( '123' ) );
	}

	public function test_latin_digits_only_changes_digits_and_marks() {
		$this->assertSame( '09123456789', MDT_SMS_Phone::latin_digits( '۰۹۱۲۳۴۵۶۷۸۹' ) );
		$this->assertSame( '0912 345', MDT_SMS_Phone::latin_digits( "٠٩١٢ ٣٤٥" ), 'spaces are kept' );
		$this->assertSame( 'ab12', MDT_SMS_Phone::latin_digits( "ab\u{200C}۱۲" ) );
	}

	public function test_parse_list_dedupes_and_counts_invalid() {
		$invalid = 0;
		$list    = MDT_SMS_Phone::parse_list( "09123456789, +989123456789\n۰۹۳۵۱۲۳۴۵۶۷;12345،0912", $invalid );
		$this->assertSame( array( '+989123456789', '+989351234567' ), $list );
		$this->assertSame( 2, $invalid );
		$this->assertSame( array(), MDT_SMS_Phone::parse_list( '', $invalid ) );
		$this->assertSame( 0, $invalid );
	}

	public function test_known_gap_plus98_followed_by_trunk_zero_is_rejected() {
		// "+98 0912..." (country code plus the domestic leading 0) is a common typing mistake.
		// It is rejected rather than guessed; this test documents the current behaviour.
		$this->assertFalse( MDT_SMS_Phone::is_valid_mobile( MDT_SMS_Phone::normalize( '+98 0912 345 6789' ) ) );
	}
}
