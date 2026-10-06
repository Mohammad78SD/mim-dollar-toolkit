/* Toman Toolkit - USD Pricing - product edit screen helpers. */
(function ($) {
	'use strict';

	var cfg = window.wttUsd || {};

	function sprintfHint(usd) {
		return (cfg.hint || '')
			.replace('%1$s', usd)
			.replace('%2$s', cfg.rateFormatted || '');
	}

	function refresh($scope, $tomanInputs) {
		var $usd = $scope.find('.wtt-usd-regular');
		var $wrap = $scope.find('.wtt-usd-hint-wrap');
		var $hint = $scope.find('.wtt-usd-hint');
		var usd = $.trim($usd.val() || '');
		var active = usd !== '' && parseFloat(usd.replace(',', '.')) > 0;

		$wrap.toggleClass('wtt-usd-active', active);
		if (active) {
			$hint.text(cfg.rate > 0 ? sprintfHint(usd) : (cfg.noRate || ''));
		}
		$tomanInputs.each(function () {
			$(this).prop('readOnly', active).toggleClass('wtt-usd-locked', active);
		});
	}

	function initSimple() {
		var $scope = $('.wtt-usd-fields[data-wtt-usd-scope="simple"]');
		if (!$scope.length) {
			return;
		}
		var $toman = $('#_regular_price, #_sale_price');
		refresh($scope, $toman);
		$scope.on('input change keyup', '.wtt-usd-regular', function () {
			refresh($scope, $toman);
		});
	}

	function initVariations() {
		$('.woocommerce_variation').each(function () {
			var $variation = $(this);
			var $scope = $variation.find('.wtt-usd-fields');
			if (!$scope.length) {
				return;
			}
			var $toman = $variation.find('input[name^="variable_regular_price["], input[name^="variable_sale_price["]');
			refresh($scope, $toman);
		});
	}

	$(function () {
		initSimple();
		initVariations();
	});

	$(document).on('woocommerce_variations_loaded woocommerce_variations_added', initVariations);

	$(document).on('input change keyup', '.wtt-usd-fields[data-wtt-usd-scope="variation"] .wtt-usd-regular', function () {
		var $variation = $(this).closest('.woocommerce_variation');
		var $scope = $variation.find('.wtt-usd-fields');
		var $toman = $variation.find('input[name^="variable_regular_price["], input[name^="variable_sale_price["]');
		refresh($scope, $toman);
	});
})(jQuery);
