<?php
class RateTest extends WTT_TestCase {

	private function settings( array $partial ) {
		update_option( WTT_USD_Settings::OPTION, $partial );
	}

	/* ---- median ---- */

	public function test_median() {
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::median( array() ) );
		$this->assertSame( 5.0, WTT_USD_Rate_Manager::median( array( 5 ) ) );
		$this->assertSame( 15.0, WTT_USD_Rate_Manager::median( array( 10.0, 20.0 ) ) );
		$this->assertSame( 20.0, WTT_USD_Rate_Manager::median( array( 30, 10, 20 ) ) );
		$this->assertSame( 25.0, WTT_USD_Rate_Manager::median( array( 40.0, 10.0, 30.0, 20.0 ) ) );
		$this->assertSame( 2.0, WTT_USD_Rate_Manager::median( array( 'a' => 3, 'b' => 1, 'c' => 2 ) ), 'keys are ignored' );
	}

	/* ---- aggregate (median + outlier drop) ---- */

	public function test_aggregate_single_and_pair_use_median_without_outlier_drop() {
		$this->assertSame( 100000.0, WTT_USD_Rate_Manager::aggregate( array( 'bitpin' => 100000.0 ) ) );
		// Two values: no outlier filtering below three results, plain average of the two.
		$this->assertSame( 150000.0, WTT_USD_Rate_Manager::aggregate( array( 'a' => 100000.0, 'b' => 200000.0 ) ) );
	}

	public function test_aggregate_drops_outlier_when_three_or_more() {
		$r = WTT_USD_Rate_Manager::aggregate( array( 'a' => 100000.0, 'b' => 101000.0, 'c' => 150000.0 ) );
		// Median is 101000; c deviates ~48% and is dropped; median of {a, b} = 100500.
		$this->assertSame( 100500.0, $r );
		$log = WTT_Logger::all( 'usd-pricing' );
		$this->assertCount( 1, $log );
		$this->assertSame( 'warning', $log[0]['level'] );
		$this->assertStringContainsString( 'c', $log[0]['message'] );
	}

	public function test_aggregate_keeps_values_within_five_percent() {
		// 105000 is exactly 5% from the median 100000: kept (the cut is "> 5%").
		$r = WTT_USD_Rate_Manager::aggregate( array( 'a' => 95000.0, 'b' => 100000.0, 'c' => 105000.0 ) );
		$this->assertSame( 100000.0, $r );
		$this->assertSame( array(), WTT_Logger::all( 'usd-pricing' ) );

		// Four values: median 100250; a is 6.2% below it and dropped; the median of {b, c, d} is 100500.
		$r = WTT_USD_Rate_Manager::aggregate( array( 'a' => 94000.0, 'b' => 100000.0, 'c' => 100500.0, 'd' => 100600.0 ) );
		$this->assertSame( 100500.0, $r );
	}

	/* ---- effective rate ---- */

	public function test_effective_defaults_round_up_to_1000() {
		$this->assertSame( 101000.0, WTT_USD_Rate_Manager::compute_effective( 100500.0 ) );
		$this->assertSame( 100000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ), 'already a multiple: unchanged' );
		$this->assertSame( 100000.0, WTT_USD_Rate_Manager::compute_effective( 99000.5 ) );
	}

	public function test_effective_with_markup_and_step() {
		$this->settings( array( 'markup_percent' => 5, 'rate_round_step' => 1000 ) );
		$this->assertSame( 105000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ) );
		$this->settings( array( 'markup_percent' => 2.5, 'rate_round_step' => 100 ) );
		$this->assertSame( 102500.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ) );
		$this->settings( array( 'markup_percent' => 1, 'rate_round_step' => 1 ) );
		$this->assertSame( 101011.0, WTT_USD_Rate_Manager::compute_effective( 100010.0 ) );
	}

	public function test_effective_ignores_float_noise_before_rounding() {
		// 100000 * 1.1 = 110000.00000000001 in binary floating point; must not round up to 111000.
		$this->settings( array( 'markup_percent' => 10, 'rate_round_step' => 1000 ) );
		$this->assertSame( 110000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ) );
	}

	public function test_effective_clamps_markup() {
		$this->settings( array( 'markup_percent' => 500, 'rate_round_step' => 1 ) );
		$this->assertSame( 200000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ), 'capped at 100%' );
		$this->settings( array( 'markup_percent' => -50, 'rate_round_step' => 1 ) );
		$this->assertSame( 100000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ), 'negative markup becomes 0' );
	}

	public function test_manual_override_is_final() {
		$this->settings( array( 'markup_percent' => 20, 'rate_round_step' => 10000, 'manual_rate_enabled' => true, 'manual_rate' => 123456.0 ) );
		// No markup and no rounding on a manual rate, and the raw rate is irrelevant (even zero).
		$this->assertSame( 123456.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ) );
		$this->assertSame( 123456.0, WTT_USD_Rate_Manager::compute_effective( 0.0 ) );
	}

	public function test_manual_override_needs_a_positive_value() {
		$this->settings( array( 'manual_rate_enabled' => true, 'manual_rate' => 0, 'rate_round_step' => 1 ) );
		$this->assertSame( 100000.0, WTT_USD_Rate_Manager::compute_effective( 100000.0 ) );
	}

	public function test_no_rate_means_zero() {
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::compute_effective( 0.0 ) );
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::compute_effective( -5.0 ) );
	}

	public function test_state_defaults_are_merged() {
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::get_effective_rate() );
		update_option( WTT_USD_Rate_Manager::STATE_OPTION, array( 'effective_rate' => '123000' ) );
		$this->assertSame( 123000.0, WTT_USD_Rate_Manager::get_effective_rate() );
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::get_raw_rate() );
		update_option( WTT_USD_Rate_Manager::STATE_OPTION, 'corrupt' );
		$this->assertSame( 0.0, WTT_USD_Rate_Manager::get_effective_rate(), 'a corrupt option does not fatal' );
	}

	public function test_currency_factor() {
		$this->assertSame( 1, WTT_USD_Rate_Manager::currency_factor() );
		$GLOBALS['wtt_test']['currency'] = 'IRR';
		$this->assertSame( 10, WTT_USD_Rate_Manager::currency_factor() );
		$GLOBALS['wtt_test']['currency'] = 'IRHT';
		$this->assertSame( 1, WTT_USD_Rate_Manager::currency_factor() );
	}
}
