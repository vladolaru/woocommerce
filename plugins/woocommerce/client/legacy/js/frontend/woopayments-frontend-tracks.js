( function () {
	'use strict';

	// Client 11.1.0 WooPayDirectCheckout.redirectElements, which its cart script listens on.
	var proceedToCheckoutSelector =
		'.wc-proceed-to-checkout .checkout-button,' +
		'.wp-block-woocommerce-proceed-to-checkout-block,' +
		'a.wp-block-woocommerce-mini-cart-checkout-button-block,' +
		'a.wc-block-mini-cart__footer-checkout,' +
		'.widget_shopping_cart a.button.checkout';
	var params = window.wc_woopayments_frontend_tracks_params;
	var events;
	var proceedToCheckout;

	if ( ! params || ! window.fetch || ! window.FormData ) {
		return;
	}

	function recordUserEvent( eventName, eventProperties ) {
		var body = new window.FormData();
		body.append( 'tracksNonce', params.nonce );
		body.append( 'action', 'platform_tracks' );
		body.append( 'tracksEventName', eventName );
		body.append( 'tracksEventProp', JSON.stringify( eventProperties || {} ) );

		window
			.fetch( params.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body: body,
			} )
			.catch( function () {} );
	}

	events = params.events || [];
	params.events = [];
	events.forEach( function ( track ) {
		recordUserEvent( track.event, track.properties );
	} );

	proceedToCheckout = params.proceedToCheckout;
	params.proceedToCheckout = null;
	if ( ! proceedToCheckout ) {
		return;
	}

	document.addEventListener(
		'click',
		function ( event ) {
			if (
				! event.target ||
				! event.target.closest ||
				! event.target.closest( proceedToCheckoutSelector )
			) {
				return;
			}

			// Client 11.1.0 sends `wcpay_proceed_to_checkout_button_click`, which its recorder prefixes again.
			recordUserEvent( 'proceed_to_checkout_button_click', {
				woopay_direct_checkout:
					!! proceedToCheckout.woopayDirectCheckout &&
					! /(?:^|;\s*)skip_woopay=1(?:;|$)/.test( document.cookie ),
			} );
		},
		true
	);
} )();
