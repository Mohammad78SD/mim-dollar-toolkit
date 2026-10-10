<?php
class PriceCalcTest extends MDT_TestCase {

	private function step( $price_round_step ) {
		update_option( MDT_USD_Settings::OPTION, array( 'price_round_step' => $price_round_step ) );
	}

	public function test_regular_price_in_toman() {
		$p = MDT_USD_Price_Sync::calculate_prices( '12.5', '', 100000.0 );
		$this->assertSame( array( 'regular' => '1250000', 'sale' => '' ), $p );
	}

	public function test_irr_store_multiplies_by_ten() {
		$GLOBALS['mdt_test']['currency'] = 'IRR';
		$p = MDT_USD_Price_Sync::calculate_prices( '12.5', '', 100000.0 );
		$this->assertSame( '12500000', $p['regular'] );
	}

	public function test_non_integer_result_is_rounded_up_to_whole_toman() {
		// 1.01 * 98765 = 99752.65 -> 99753.
		$p = MDT_USD_Price_Sync::calculate_prices( '1.01', '', 98765.0 );
		$this->assertSame( '99753', $p['regular'] );
	}

	public function test_price_round_step_rounds_up() {
		$this->step( 1000 );
		$p = MDT_USD_Price_Sync::calculate_prices( '10', '8', 100123.0 );
		$this->assertSame( '1002000', $p['regular'] );  // 1,001,230 -> 1,002,000
		$this->assertSame( '801000', $p['sale'] );      //   800,984 ->   801,000
		$this->step( 100000 );
		$p = MDT_USD_Price_Sync::calculate_prices( '10', '', 100123.0 );
		$this->assertSame( '1100000', $p['regular'] );
		$this->step( 10000 );
		$p = MDT_USD_Price_Sync::calculate_prices( '10', '', 100000.0 );
		$this->assertSame( '1000000', $p['regular'], 'exact multiples are not bumped' );
	}

	public function test_round_step_is_applied_after_the_irr_factor() {
		$GLOBALS['mdt_test']['currency'] = 'IRR';
		$this->step( 10000 );
		$p = MDT_USD_Price_Sync::calculate_prices( '1', '', 100123.0 ); // 1,001,230 rial -> 1,010,000
		$this->assertSame( '1010000', $p['regular'] );
	}

	public function saleProvider() {
		return array(
			'valid sale'                   => array( '100', '80', '8000000' ),
			'empty sale'                   => array( '100', '', '' ),
			'zero sale ignored'            => array( '100', '0', '' ),
			'sale equal to regular'        => array( '100', '100', '' ),
			'sale above regular'           => array( '100', '120', '' ),
			'null sale'                    => array( '100', null, '' ),
		);
	}

	/** @dataProvider saleProvider */
	public function test_sale_price_rules( $regular, $sale, $expected_sale ) {
		$p = MDT_USD_Price_Sync::calculate_prices( $regular, $sale, 100000.0 );
		$this->assertSame( '10000000', $p['regular'] );
		$this->assertSame( $expected_sale, $p['sale'] );
	}

	public function test_sale_that_rounds_up_to_regular_is_dropped() {
		$this->step( 100000 );
		// 100 -> 10,000,000 ; 99.99 -> 9,999,000 -> rounds up to 10,000,000 = regular, so no sale.
		$p = MDT_USD_Price_Sync::calculate_prices( '100', '99.99', 100000.0 );
		$this->assertSame( '10000000', $p['regular'] );
		$this->assertSame( '', $p['sale'] );
	}

	public function test_large_values_have_no_scientific_notation() {
		$p = MDT_USD_Price_Sync::calculate_prices( '1000000', '', 100000000.0 );
		$this->assertSame( '100000000000000', $p['regular'] );
	}
}
