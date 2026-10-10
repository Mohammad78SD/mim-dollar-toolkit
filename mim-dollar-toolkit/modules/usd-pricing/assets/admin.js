/* MiM Dollar Toolkit - USD Pricing - product edit screen helpers. */
(function ($) {
	'use strict';

	var cfg = window.mdtUsd || {};

	function sprintfHint(usd) {
		return (cfg.hint || '')
			.replace('%1$s', usd)
			.replace('%2$s', cfg.rateFormatted || '');
	}

	function refresh($scope, $tomanInputs) {
		var $usd = $scope.find('.mdt-usd-regular');
		var $wrap = $scope.find('.mdt-usd-hint-wrap');
		var $hint = $scope.find('.mdt-usd-hint');
		var usd = $.trim($usd.val() || '');
		var active = usd !== '' && parseFloat(usd.replace(',', '.')) > 0;

		$wrap.toggleClass('mdt-usd-active', active);
		if (active) {
			$hint.text(cfg.rate > 0 ? sprintfHint(usd) : (cfg.noRate || ''));
		}
		$tomanInputs.each(function () {
			$(this).prop('readOnly', active).toggleClass('mdt-usd-locked', active);
		});
	}

	function initSimple() {
		var $scope = $('.mdt-usd-fields[data-mdt-usd-scope="simple"]');
		if (!$scope.length) {
			return;
		}
		var $toman = $('#_regular_price, #_sale_price');
		refresh($scope, $toman);
		$scope.on('input change keyup', '.mdt-usd-regular', function () {
			refresh($scope, $toman);
		});
	}

	function initVariations() {
		$('.woocommerce_variation').each(function () {
			var $variation = $(this);
			var $scope = $variation.find('.mdt-usd-fields');
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

	$(document).on('input change keyup', '.mdt-usd-fields[data-mdt-usd-scope="variation"] .mdt-usd-regular', function () {
		var $variation = $(this).closest('.woocommerce_variation');
		var $scope = $variation.find('.mdt-usd-fields');
		var $toman = $variation.find('input[name^="variable_regular_price["], input[name^="variable_sale_price["]');
		refresh($scope, $toman);
	});
})(jQuery);
