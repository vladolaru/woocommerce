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

	if (
		! params || ! params.tracksUrl || ! params.nonce ||
		! Array.isArray( params.allowedEvents ) || ! window.fetch || ! window.FormData
	) {
		return;
	}

	// Client 11.3.0 client/tracks/index.ts batches across checkout bundles.
	function createQueue() {
		var batch = [];
		var idleTimer;
		var deadlineTimer;

		function flush( hiding ) {
			if ( ! batch.length ) {
				return;
			}
			clearTimeout( idleTimer );
			clearTimeout( deadlineTimer );
			var body = new window.FormData();
			body.append( 'tracksNonce', params.nonce );
			body.append( 'tracksEvents', JSON.stringify( batch ) );
			batch = [];

			if ( hiding && navigator.sendBeacon ) {
				// WordPress accepts the REST nonce in the form when a beacon cannot set a header.
				if ( params.restNonce ) {
					body.append( '_wpnonce', params.restNonce );
				}
				try {
					if ( navigator.sendBeacon( params.tracksUrl, body ) ) {
						return;
					}
				} catch ( error ) {
					void error;
					// Fall back to the same keepalive send as a refused beacon.
				}
			}

			window.fetch( params.tracksUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: params.restNonce ? { 'X-WP-Nonce': params.restNonce } : {},
				keepalive: true,
				body: body,
			} ).catch( function () {} );
		}

		function push( item ) {
			if (
				params.allowedEvents.indexOf( item.event ) !== -1 &&
				! /(?:^|;\s*)tk_opt-out=(?!0(?:;|$))[^;]+/.test( document.cookie )
			) {
				if ( ! batch.length ) {
					deadlineTimer = setTimeout( flush, 5000 );
				}
				batch.push( item );
				clearTimeout( idleTimer );
				idleTimer = setTimeout( flush, 2000 );
			}
			if ( batch.length === 20 || item.event === 'checkout_place_order_button_click' ) {
				flush();
			}
		}

		window.addEventListener( 'click', function ( event ) {
			if (
				event.target.closest &&
				event.target.closest( 'form.checkout #place_order, .wc-block-components-checkout-place-order-button' )
			) {
				flush();
			}
		} );
		window.addEventListener( 'pagehide', function () {
			flush( true );
		} );
		document.addEventListener( 'visibilitychange', function () {
			if ( document.visibilityState === 'hidden' ) {
				flush( true );
			}
		} );
		return { push: push };
	}

	if ( ! window.wcWooPaymentsTracks || Array.isArray( window.wcWooPaymentsTracks ) ) {
		var pending = window.wcWooPaymentsTracks || [];
		window.wcWooPaymentsTracks = createQueue();
		pending.forEach( window.wcWooPaymentsTracks.push );
	}

	function recordUserEvent( eventName, eventProperties ) {
		window.wcWooPaymentsTracks.push( { event: eventName, properties: eventProperties || {} } );
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
