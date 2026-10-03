( function ( window, document ) {
	'use strict';

	window.__wooPaymentsStripeMessagingCalls = [];
	window.__wooPaymentsStripePaymentCalls = [];
	window.__wooPaymentsStripeAdapterErrors = [];

	function rejection( message ) {
		const error = new Error( message );
		window.__wooPaymentsStripeAdapterErrors.push( String( error ) );
		return error;
	}

	function assertKeys( value, expected, label ) {
		const keys = Object.keys( value || {} );
		if (
			keys.length !== expected.length ||
			keys.some( ( key ) => ! expected.includes( key ) )
		) {
			throw rejection(
				label + ' received unexpected keys: ' + keys.join( ', ' )
			);
		}
	}

	function copy( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function assertAllowedKeys( value, allowed, label ) {
		const unexpected = Object.keys( value || {} ).filter(
			( key ) => ! allowed.includes( key )
		);
		if ( unexpected.length ) {
			throw rejection(
				'Stripe adapter rejected ' +
					label +
					' keys: ' +
					unexpected.join( ', ' )
			);
		}
	}

	function validateAppearanceAndFonts( options ) {
		if ( options.appearance !== undefined ) {
			if (
				! options.appearance ||
				typeof options.appearance !== 'object'
			) {
				throw rejection(
					'Stripe adapter rejected Elements appearance.'
				);
			}
			assertAllowedKeys(
				options.appearance,
				[ 'labels', 'rules', 'theme', 'variables' ],
				'Elements appearance'
			);
		}
		if ( options.fonts !== undefined ) {
			if ( ! Array.isArray( options.fonts ) ) {
				throw rejection( 'Stripe adapter rejected Elements fonts.' );
			}
			for ( const font of options.fonts ) {
				if ( ! font || typeof font !== 'object' ) {
					throw rejection(
						'Stripe adapter rejected an Elements font.'
					);
				}
				assertAllowedKeys(
					font,
					[
						'cssSrc',
						'family',
						'src',
						'style',
						'unicodeRange',
						'weight',
					],
					'Elements font'
				);
			}
		}
	}

	function validateStripeOptions( options ) {
		assertAllowedKeys(
			options,
			[ 'locale', 'stripeAccount', 'betas' ],
			'Stripe'
		);
		if (
			options.stripeAccount !== undefined &&
			options.stripeAccount !== 'acct_native_ci'
		) {
			throw rejection( 'Stripe adapter rejected the connected account.' );
		}
		if (
			options.stripeAccount === undefined &&
			options.betas !== undefined
		) {
			throw rejection( 'Stripe adapter rejected platform Stripe betas.' );
		}
		if ( options.locale !== 'en' ) {
			throw rejection( 'Stripe adapter rejected the locale.' );
		}
		if ( options.betas !== undefined ) {
			const allowedBetas = [
				[ 'card_country_event_beta_1' ],
				[ 'card_country_event_beta_1', 'link_autofill_modal_beta_1' ],
			];
			if (
				! allowedBetas.some(
					( betas ) =>
						JSON.stringify( betas ) ===
						JSON.stringify( options.betas )
				)
			) {
				throw rejection( 'Stripe adapter rejected Stripe betas.' );
			}
		}
	}

	function validatePaymentElementsOptions( options ) {
		assertAllowedKeys(
			options,
			[
				'amount',
				'appearance',
				'currency',
				'fonts',
				'loader',
				'mode',
				'paymentMethodCreation',
				'paymentMethodTypes',
			],
			'payment Elements'
		);
		validateAppearanceAndFonts( options );
		const methodTypes = JSON.stringify( options.paymentMethodTypes );
		if (
			options.mode !== 'payment' ||
			options.loader !== 'never' ||
			! [
				'usd',
				'eur',
				'aud',
				'cad',
				'chf',
				'gbp',
				'jpy',
				'nzd',
				'sek',
			].includes( options.currency ) ||
			options.paymentMethodCreation !== 'manual' ||
			! [ '["card"]', '["card","link"]' ].includes( methodTypes ) ||
			! Number.isSafeInteger( options.amount ) ||
			options.amount <= 0
		) {
			throw rejection(
				'Stripe adapter rejected payment Elements values.'
			);
		}
	}

	function validateTerms( terms ) {
		if ( ! terms || typeof terms !== 'object' || Array.isArray( terms ) ) {
			throw rejection( 'Stripe adapter rejected payment terms.' );
		}
		for ( const value of Object.values( terms ) ) {
			if ( value !== 'always' && value !== 'never' ) {
				throw rejection( 'Stripe adapter rejected payment terms.' );
			}
		}
	}

	function validatePaymentOptions( options ) {
		assertAllowedKeys(
			options,
			[ 'defaultValues', 'fields', 'terms', 'wallets' ],
			'payment element'
		);
		assertKeys(
			options.wallets,
			[ 'applePay', 'googlePay', 'link' ],
			'payment wallets'
		);
		for ( const value of Object.values( options.wallets ) ) {
			if ( value !== 'auto' && value !== 'never' ) {
				throw rejection( 'Stripe adapter rejected payment wallets.' );
			}
		}
		validateTerms( options.terms );
		if ( options.fields !== undefined ) {
			assertAllowedKeys(
				options.fields,
				[ 'billingDetails' ],
				'payment fields'
			);
		}
		if ( options.defaultValues !== undefined ) {
			assertAllowedKeys(
				options.defaultValues,
				[ 'billingDetails' ],
				'payment default values'
			);
		}
	}

	function createPaymentElement(
		stripeOptions,
		elementsOptions,
		paymentOptions
	) {
		validatePaymentElementsOptions( elementsOptions );
		validatePaymentOptions( paymentOptions );
		let mountedFrame = null;
		const call = {
			type: 'payment',
			stripeOptions: copy( stripeOptions ),
			elementsOptions: copy( elementsOptions ),
			paymentOptions: copy( paymentOptions ),
			mount: null,
			lifecycle: [],
			updates: [],
		};
		window.__wooPaymentsStripePaymentCalls.push( call );

		return {
			mount( target ) {
				if (
					! target ||
					target.nodeType !== 1 ||
					! [
						'wcpay-core-blocks-payment-element',
						'wcpay-core-payment-element',
					].includes( target.id )
				) {
					throw rejection(
						'Stripe adapter rejected payment mount target.'
					);
				}
				const iframe = document.createElement( 'iframe' );
				iframe.name =
					'__privateStripeFrame_native_ci_' +
					window.__wooPaymentsStripePaymentCalls.length;
				iframe.title = 'Deterministic card payment element adapter';
				target.appendChild( iframe );
				mountedFrame = iframe;
				call.mount = '#' + target.id;
				call.lifecycle.push( 'mount' );
			},
			on( event, callback ) {
				if ( event !== 'loaderror' || typeof callback !== 'function' ) {
					throw rejection(
						'Stripe adapter rejected payment event binding.'
					);
				}
				call.lifecycle.push( 'loaderror-listener' );
			},
			update( nextOptions ) {
				assertKeys( nextOptions, [ 'terms' ], 'payment update' );
				validateTerms( nextOptions.terms );
				call.updates.push( copy( nextOptions ) );
			},
			unmount() {
				if ( mountedFrame ) {
					mountedFrame.remove();
					mountedFrame = null;
				}
				call.lifecycle.push( 'unmount' );
			},
		};
	}

	window.Stripe = function ( publishableKey, stripeOptions ) {
		if ( publishableKey !== 'pk_test_native_ci' ) {
			throw rejection( 'Stripe adapter rejected the publishable key.' );
		}
		validateStripeOptions( stripeOptions );

		return {
			elements( elementsOptions ) {
				if (
					typeof elementsOptions !== 'object' ||
					! elementsOptions
				) {
					throw rejection(
						'Stripe adapter requires Elements options.'
					);
				}
				return {
					create( type, elementOptions ) {
						if ( type === 'payment' ) {
							return createPaymentElement(
								stripeOptions,
								elementsOptions,
								elementOptions
							);
						}
						if ( type !== 'paymentMethodMessaging' ) {
							throw rejection(
								'Stripe adapter rejected element type ' + type
							);
						}
						assertAllowedKeys(
							elementsOptions,
							[ 'appearance', 'fonts' ],
							'messaging Elements'
						);
						validateAppearanceAndFonts( elementsOptions );
						assertKeys(
							elementOptions,
							[
								'amount',
								'countryCode',
								'currency',
								'paymentMethodTypes',
							],
							'paymentMethodMessaging'
						);
						if (
							elementOptions.amount !== 10000 ||
							elementOptions.countryCode !== 'US' ||
							elementOptions.currency !== 'USD' ||
							JSON.stringify(
								elementOptions.paymentMethodTypes
							) !== JSON.stringify( [ 'klarna' ] )
						) {
							throw rejection(
								'Stripe adapter rejected messaging values.'
							);
						}
						let readyCallback;
						const call = {
							type,
							locale: stripeOptions.locale,
							countryCode: elementOptions.countryCode,
							elementsOptions: copy( elementsOptions ),
							paymentMethodTypes:
								elementOptions.paymentMethodTypes,
							currency: elementOptions.currency,
							amount: elementOptions.amount,
							mount: null,
							lifecycle: [],
						};
						return {
							mount( selector ) {
								if ( selector !== '#payment-method-message' ) {
									throw rejection(
										'Stripe adapter rejected mount target.'
									);
								}
								call.mount = selector;
								call.lifecycle.push( 'mount' );
								window.__wooPaymentsStripeMessagingCalls.push(
									call
								);
								const iframe =
									document.createElement( 'iframe' );
								iframe.title =
									'Deterministic Klarna payment messaging adapter';
								document
									.querySelector( selector )
									.appendChild( iframe );
								setTimeout( function () {
									if ( typeof readyCallback === 'function' ) {
										readyCallback();
									}
								}, 0 );
							},
							on( event, callback ) {
								if (
									event !== 'ready' ||
									typeof callback !== 'function'
								) {
									throw rejection(
										'Stripe adapter rejected event binding.'
									);
								}
								call.lifecycle.push( 'ready-listener' );
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
