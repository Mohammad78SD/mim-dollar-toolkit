<?php
class NumberWordsTest extends MDT_TestCase {

	public function wordsProvider() {
		return array(
			'zero'                => array( 0, 'صفر' ),
			'one'                 => array( 1, 'یک' ),
			'ten'                 => array( 10, 'ده' ),
			'eleven'              => array( 11, 'یازده' ),
			'nineteen'            => array( 19, 'نوزده' ),
			'twenty'              => array( 20, 'بیست' ),
			'twenty-one'          => array( 21, 'بیست و یک' ),
			'ninety-nine'         => array( 99, 'نود و نه' ),
			'hundred'             => array( 100, 'صد' ),
			'hundred one'         => array( 101, 'صد و یک' ),
			'hundred eleven'      => array( 111, 'صد و یازده' ),
			'two hundred'         => array( 200, 'دویست' ),
			'nine nine nine'      => array( 999, 'نهصد و نود و نه' ),
			'thousand'            => array( 1000, 'یک هزار' ),
			'thousand one'        => array( 1001, 'یک هزار و یک' ),
			'12,345'              => array( 12345, 'دوازده هزار و سیصد و چهل و پنج' ),
			'million'             => array( 1000000, 'یک میلیون' ),
			'2,500,000'           => array( 2500000, 'دو میلیون و پانصد هزار' ),
			'million + one'       => array( 1000001, 'یک میلیون و یک' ),
			'billion'             => array( 1000000000, 'یک میلیارد' ),
			'trillion'            => array( 1000000000000, 'یک تریلیون' ),
			'mixed trillions'     => array( 1234567890123, 'یک تریلیون و دویست و سی و چهار میلیارد و پانصد و شصت و هفت میلیون و هشتصد و نود هزار و صد و بیست و سه' ),
			'skips empty groups'  => array( 5000000007, 'پنج میلیارد و هفت' ),
			'negative'            => array( -5, 'منفی پنج' ),
			'negative million'    => array( -1500000, 'منفی یک میلیون و پانصد هزار' ),
			'numeric string'      => array( '250', 'دویست و پنجاه' ),
		);
	}

	/** @dataProvider wordsProvider */
	public function test_number_to_words( $input, $expected ) {
		$this->assertSame( $expected, mdt_number_to_fa_words( $input ) );
	}

	public function test_float_input_is_truncated_to_int() {
		$this->assertSame( 'دوازده', mdt_number_to_fa_words( 12.9 ) );
	}
}
