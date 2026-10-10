<?php

class JalaliTest extends MDT_TestCase {

	public function conversionProvider() {
		return array(
			'epoch'                     => array( 1970, 1, 1, array( 1348, 10, 11 ) ),
			'y2k'                       => array( 2000, 1, 1, array( 1378, 10, 11 ) ),
			'last day of 1402'          => array( 2024, 3, 19, array( 1402, 12, 29 ) ),
			'nowruz 1403'               => array( 2024, 3, 20, array( 1403, 1, 1 ) ),
			'shahrivar 31 1403'         => array( 2024, 9, 21, array( 1403, 6, 31 ) ),
			'mehr 1 1403'               => array( 2024, 9, 22, array( 1403, 7, 1 ) ),
			'leap day 1403 (30 Esfand)' => array( 2025, 3, 20, array( 1403, 12, 30 ) ),
			'nowruz 1404'               => array( 2025, 3, 21, array( 1404, 1, 1 ) ),
			'last day of 1404'          => array( 2026, 3, 20, array( 1404, 12, 29 ) ),
			'nowruz 1405'               => array( 2026, 3, 21, array( 1405, 1, 1 ) ),
			'leap day 1408 (30 Esfand)' => array( 2030, 3, 20, array( 1408, 12, 30 ) ),
			'nowruz 1409'               => array( 2030, 3, 21, array( 1409, 1, 1 ) ),
			'gregorian leap day 2024'   => array( 2024, 2, 29, array( 1402, 12, 10 ) ),
			'new year gregorian 2026'   => array( 2026, 1, 1, array( 1404, 10, 11 ) ),
		);
	}

	/** @dataProvider conversionProvider */
	public function test_gregorian_to_jalali( $gy, $gm, $gd, $expected ) {
		$this->assertSame( $expected, mdt_gregorian_to_jalali( $gy, $gm, $gd ) );
	}

	public function test_conversion_is_consecutive_across_five_years() {
		// Walk every day 2023-01-01..2030-12-31 and check the Jalali date advances by exactly one day
		// (day+1, or month+1/day 1, or year+1/month 1/day 1), with month lengths 31x6, 30x5 and 29/30.
		$prev  = null;
		$t     = gmmktime( 12, 0, 0, 1, 1, 2023 );
		$end   = gmmktime( 12, 0, 0, 12, 31, 2030 );
		$leaps = array();
		for ( ; $t <= $end; $t += 86400 ) {
			$cur = mdt_gregorian_to_jalali( (int) gmdate( 'Y', $t ), (int) gmdate( 'n', $t ), (int) gmdate( 'j', $t ) );
			if ( $prev ) {
				list( $py, $pm, $pd ) = $prev;
				list( $cy, $cm, $cd ) = $cur;
				if ( $cd === $pd + 1 && $cm === $pm && $cy === $py ) {
					$ok = true;
				} elseif ( 1 === $cd && $cm === $pm + 1 && $cy === $py ) {
					$ok = true;
					$max = $pm <= 6 ? 31 : 30;
					$this->assertSame( $max, $pd, "month $pm of $py length" );
				} elseif ( 1 === $cd && 1 === $cm && $cy === $py + 1 ) {
					$ok = true;
					$this->assertSame( 12, $pm );
					if ( 30 === $pd ) {
						$leaps[] = $py;
					} else {
						$this->assertSame( 29, $pd );
					}
				} else {
					$ok = false;
				}
				$this->assertTrue( $ok, 'Non-consecutive step ' . implode( '/', $prev ) . ' -> ' . implode( '/', $cur ) );
			}
			$prev = $cur;
		}
		$this->assertSame( array( 1403, 1408 ), $leaps );
	}

	public function test_month_names() {
		$names = mdt_jalali_month_names();
		$this->assertCount( 12, $names );
		$this->assertSame( 'فروردین', $names[1] );
		$this->assertSame( 'اسفند', $names[12] );
	}

	public function test_jdate_default_format_and_padding() {
		// 2024-03-20 00:00 UTC.
		$this->assertSame( '1403/01/01', mdt_jdate( 'Y/m/d', gmmktime( 0, 0, 0, 3, 20, 2024 ) ) );
		$this->assertSame( '03/1/1', mdt_jdate( 'y/n/j', gmmktime( 0, 0, 0, 3, 20, 2024 ) ) );
	}

	public function test_jdate_names_time_and_escaping() {
		$ts = gmmktime( 14, 5, 9, 3, 20, 2024 ); // a Wednesday.
		$this->assertSame( '1 فروردین 1403', mdt_jdate( 'j F Y', $ts ) );
		$this->assertSame( 'چهارشنبه', mdt_jdate( 'l', $ts ) );
		$this->assertSame( '14:05:09', mdt_jdate( 'H:i:s', $ts ) );
		$this->assertSame( 'Y 1403', mdt_jdate( '\Y Y', $ts ) );
	}

	public function test_jdate_uses_site_timezone() {
		// 2024-03-19 21:00 UTC is 00:30 on 20 March in Tehran (UTC+3:30, no DST since 2022).
		$ts = gmmktime( 21, 0, 0, 3, 19, 2024 );
		$this->assertSame( '1402/12/29', mdt_jdate( 'Y/m/d', $ts ) );
		$GLOBALS['mdt_test']['tz'] = 'Asia/Tehran';
		$this->assertSame( '1403/01/01 00:30', mdt_jdate( 'Y/m/d H:i', $ts ) );
	}

	public function test_fa_digits() {
		$this->assertSame( '۱۴۰۳/۰۱/۰۱', mdt_fa_digits( '1403/01/01' ) );
		$this->assertSame( '۰۱۲۳۴۵۶۷۸۹', mdt_fa_digits( '0123456789' ) );
		$this->assertSame( '۱۲۳٫۵', mdt_fa_digits( '123٫5' ), 'non-digits pass through' );
		$this->assertSame( '۴۲', mdt_fa_digits( 42 ) );
		$this->assertSame( '', mdt_fa_digits( '' ) );
		$this->assertSame( 'abc ۱', mdt_fa_digits( 'abc 1' ) );
	}

	public function test_is_fa_locale() {
		$this->assertFalse( mdt_is_fa_locale() );
		$GLOBALS['mdt_test']['locale'] = 'fa_IR';
		$this->assertTrue( mdt_is_fa_locale() );
	}
}
