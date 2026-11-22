/**
 * Registers eSewa as a payment method in the Cart & Checkout blocks (no build step).
 */
( function () {
	'use strict';

	if ( ! window.wc || ! window.wc.wcBlocksRegistry || ! window.wc.wcSettings || ! window.wp || ! window.wp.element ) {
		return;
	}

	var el = window.wp.element.createElement;
	var settings = window.wc.wcSettings.getSetting( 'esewa_data', {} );
	var decode = window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities
		? window.wp.htmlEntities.decodeEntities
		: function ( s ) { return s; };
	var title = decode( settings.title || 'eSewa' );

	var Content = function () {
		return el( 'div', null, decode( settings.description || '' ) );
	};

	var Label = function ( props ) {
		var PaymentMethodLabel = props.components && props.components.PaymentMethodLabel;
		return PaymentMethodLabel ? el( PaymentMethodLabel, { text: title } ) : el( 'span', null, title );
	};

	window.wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'esewa',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () { return true; },
		ariaLabel: title,
		supports: { features: settings.supports || [ 'products' ] }
	} );
}() );
