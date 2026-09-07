( function ( window, document ) {
	'use strict';

	window.__wooPaymentsStripeMessagingCalls = [];

	function assertKeys( value, expected, label ) {
		const keys = Object.keys( value || {} ).sort();
		const wanted = expected.slice().sort();
		if ( JSON.stringify( keys ) !== JSON.stringify( wanted ) ) {
			throw new Error(
				label + ' received unexpected keys: ' + keys.join( ', ' )
			);
		}
	}

	window.Stripe = function ( publishableKey, stripeOptions ) {
		if ( publishableKey !== 'pk_test_native_ci' ) {
			throw new Error( 'Stripe adapter rejected the publishable key.' );
		}
		assertKeys( stripeOptions, [ 'locale', 'stripeAccount' ], 'Stripe' );
		if ( stripeOptions.stripeAccount !== 'acct_native_ci' ) {
			throw new Error( 'Stripe adapter rejected the connected account.' );
		}

		return {
			elements( elementsOptions ) {
				if (
					typeof elementsOptions !== 'object' ||
					! elementsOptions
				) {
					throw new Error(
						'Stripe adapter requires Elements options.'
					);
				}
				return {
					create( type, messagingOptions ) {
						if ( type !== 'paymentMethodMessaging' ) {
							throw new Error(
								'Stripe adapter rejected element type ' + type
							);
						}
						assertKeys(
							messagingOptions,
							[
								'amount',
								'countryCode',
								'currency',
								'paymentMethodTypes',
							],
							'paymentMethodMessaging'
						);
						if (
							messagingOptions.amount !== 10000 ||
							messagingOptions.currency !== 'USD' ||
							JSON.stringify(
								messagingOptions.paymentMethodTypes
							) !== JSON.stringify( [ 'klarna' ] )
						) {
							throw new Error(
								'Stripe adapter rejected messaging values.'
							);
						}
						let readyCallback = function () {};
						return {
							mount( selector ) {
								if ( selector !== '#payment-method-message' ) {
									throw new Error(
										'Stripe adapter rejected mount target.'
									);
								}
								window.__wooPaymentsStripeMessagingCalls.push( {
									type,
									paymentMethodTypes:
										messagingOptions.paymentMethodTypes,
									currency: messagingOptions.currency,
									amount: messagingOptions.amount,
									mount: selector,
								} );
								const iframe =
									document.createElement( 'iframe' );
								iframe.title =
									'Deterministic Klarna payment messaging adapter';
								document
									.querySelector( selector )
									.appendChild( iframe );
								readyCallback();
							},
							on( event, callback ) {
								if (
									event !== 'ready' ||
									typeof callback !== 'function'
								) {
									throw new Error(
										'Stripe adapter rejected event binding.'
									);
								}
								readyCallback = callback;
							},
							update( nextOptions ) {
								assertKeys(
									nextOptions,
									[ 'amount', 'currency' ],
									'paymentMethodMessaging update'
								);
							},
						};
					},
				};
			},
		};
	};
} )( window, document );
