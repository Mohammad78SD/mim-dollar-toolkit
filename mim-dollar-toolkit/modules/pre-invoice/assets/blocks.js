( function () {
	'use strict';
	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings ) {
		return;
	}
	var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
	var getSetting = window.wc.wcSettings.getSetting;
	var createElement = window.wp.element.createElement;
	var decode = window.wp.htmlEntities.decodeEntities;

	var data = getSetting( 'mdt_preinvoice_data', {} );
	var title = decode( data.title || 'Request pre-invoice' );
	var description = decode( data.description || '' );
	var buttonText = decode( data.button_text || title );

	var Content = function () {
		return createElement( 'div', { className: 'mdt-pi-description' }, description );
	};
	var Label = function () {
		return createElement( 'span', { className: 'mdt-pi-label' }, title );
	};

	registerPaymentMethod( {
		name: 'mdt_preinvoice',
		label: createElement( Label, null ),
		ariaLabel: title,
		placeOrderButtonLabel: buttonText,
		content: createElement( Content, null ),
		edit: createElement( Content, null ),
		canMakePayment: function () {
			return true;
		},
		supports: {
			features: data.supports || [ 'products' ],
		},
	} );
} )();
