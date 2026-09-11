import path from 'node:path';

import { expect, test } from '@playwright/test';

const adapterPath = path.resolve(
	__dirname,
	'../../envs/woopayments-native/stripe-messaging-adapter.js'
);

test( 'strict messaging adapter records the exact Klarna Elements invocation', async ( {
	page,
} ) => {
	await page.setContent( '<div id="payment-method-message"></div>' );
	await page.addScriptTag( { path: adapterPath } );

	const result = await page.evaluate( async () => {
		let ready = false;
		const stripe = window.Stripe( 'pk_test_native_ci', {
			locale: 'en',
			stripeAccount: 'acct_native_ci',
		} );
		const element = stripe
			.elements( {
				appearance: { theme: 'stripe' },
				fonts: [ { cssSrc: 'https://example.test/font.css' } ],
			} )
			.create( 'paymentMethodMessaging', {
				amount: 10000,
				countryCode: 'US',
				currency: 'USD',
				paymentMethodTypes: [ 'klarna' ],
			} );
		element.mount( '#payment-method-message' );
		element.on( 'ready', () => {
			ready = true;
		} );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
		return {
			call: Reflect.get(
				window,
				'__wooPaymentsStripeMessagingCalls'
			)[ 0 ],
			ready,
		};
	} );

	expect( result.call ).toEqual( {
		type: 'paymentMethodMessaging',
		locale: 'en',
		countryCode: 'US',
		elementsOptions: {
			appearance: { theme: 'stripe' },
			fonts: [ { cssSrc: 'https://example.test/font.css' } ],
		},
		paymentMethodTypes: [ 'klarna' ],
		currency: 'USD',
		amount: 10000,
		mount: '#payment-method-message',
		lifecycle: [ 'mount', 'ready-listener' ],
	} );
	expect( result.ready ).toBe( true );
	await expect(
		page.locator( '#payment-method-message iframe' )
	).toHaveCount( 1 );
} );

for ( const mutation of [
	{ label: 'locale', stripeOptions: { locale: 'fr' } },
	{ label: 'country', messagingOptions: { countryCode: 'CA' } },
	{ label: 'Elements option', elementsOptions: { unexpected: true } },
] ) {
	test( `strict messaging adapter rejects a mutated ${ mutation.label }`, async ( {
		page,
	} ) => {
		await page.setContent( '<div id="payment-method-message"></div>' );
		await page.addScriptTag( { path: adapterPath } );
		const result = await page.evaluate( ( selectedMutation ) => {
			try {
				window
					.Stripe( 'pk_test_native_ci', {
						locale: 'en',
						stripeAccount: 'acct_native_ci',
						...selectedMutation.stripeOptions,
					} )
					.elements( selectedMutation.elementsOptions ?? {} )
					.create( 'paymentMethodMessaging', {
						amount: 10000,
						countryCode: 'US',
						currency: 'USD',
						paymentMethodTypes: [ 'klarna' ],
						...selectedMutation.messagingOptions,
					} );
				return { errors: [], message: '' };
			} catch ( error ) {
				return {
					errors: Reflect.get(
						window,
						'__wooPaymentsStripeAdapterErrors'
					),
					message: String( error ),
				};
			}
		}, mutation );
		expect( result.message ).toContain( 'Stripe adapter rejected' );
		expect( result.errors ).toEqual( [ result.message ] );
	} );
}

test( 'strict messaging adapter rejects unexpected arguments', async ( {
	page,
} ) => {
	await page.setContent( '<div id="payment-method-message"></div>' );
	await page.addScriptTag( { path: adapterPath } );

	const message = await page.evaluate( () => {
		try {
			window
				.Stripe( 'pk_test_native_ci', {
					locale: 'en',
					stripeAccount: 'acct_native_ci',
				} )
				.elements( {} )
				.create( 'card', {
					amount: 10000,
					countryCode: 'US',
					currency: 'USD',
					paymentMethodTypes: [ 'klarna' ],
				} );
			return '';
		} catch ( error ) {
			return String( error );
		}
	} );

	expect( message ).toContain( 'rejected element type card' );
} );

test( 'strict payment adapter records the shipped Blocks invocation and mounts through Core', async ( {
	page,
} ) => {
	await page.setContent(
		'<div id="wcpay-core-blocks-payment-element"></div>'
	);
	await page.addScriptTag( { path: adapterPath } );

	const call = await page.evaluate( () => {
		const stripe = window.Stripe( 'pk_test_native_ci', {
			locale: 'en',
			stripeAccount: 'acct_native_ci',
			betas: [ 'card_country_event_beta_1' ],
		} );
		const element = stripe
			.elements( {
				mode: 'payment',
				loader: 'never',
				currency: 'usd',
				paymentMethodCreation: 'manual',
				paymentMethodTypes: [ 'card' ],
				amount: 1000,
			} )
			.create( 'payment', {
				fields: {
					billingDetails: {
						name: 'never',
						email: 'never',
						phone: 'never',
						address: {
							country: 'never',
							line1: 'never',
							line2: 'never',
							city: 'never',
							state: 'never',
							postalCode: 'never',
						},
					},
				},
				wallets: {
					applePay: 'never',
					googlePay: 'never',
					link: 'never',
				},
				terms: { card: 'never' },
			} );
		element.mount(
			document.getElementById(
				'wcpay-core-blocks-payment-element'
			) as HTMLElement
		);
		return Reflect.get( window, '__wooPaymentsStripePaymentCalls' )[ 0 ];
	} );

	expect( call ).toMatchObject( {
		type: 'payment',
		stripeOptions: {
			locale: 'en',
			stripeAccount: 'acct_native_ci',
			betas: [ 'card_country_event_beta_1' ],
		},
		elementsOptions: {
			mode: 'payment',
			loader: 'never',
			currency: 'usd',
			paymentMethodCreation: 'manual',
			paymentMethodTypes: [ 'card' ],
			amount: 1000,
		},
		mount: '#wcpay-core-blocks-payment-element',
		lifecycle: [ 'mount' ],
	} );
	await expect(
		page.locator(
			'#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]'
		)
	).toHaveAttribute( 'title', /\S/ );
} );

test( 'strict payment adapter records the WooPay platform-card invocation without a connected account', async ( {
	page,
} ) => {
	await page.setContent(
		'<div id="wcpay-core-blocks-payment-element"></div>'
	);
	await page.addScriptTag( { path: adapterPath } );

	const call = await page.evaluate( () => {
		const element = window
			.Stripe( 'pk_test_native_ci', { locale: 'en' } )
			.elements( {
				mode: 'payment',
				loader: 'never',
				currency: 'usd',
				paymentMethodCreation: 'manual',
				paymentMethodTypes: [ 'card' ],
				amount: 1000,
			} )
			.create( 'payment', {
				wallets: {
					applePay: 'never',
					googlePay: 'never',
					link: 'never',
				},
				terms: { card: 'never' },
			} );
		element.mount(
			document.getElementById(
				'wcpay-core-blocks-payment-element'
			) as HTMLElement
		);
		return Reflect.get( window, '__wooPaymentsStripePaymentCalls' )[ 0 ];
	} );

	expect( call.stripeOptions ).toEqual( { locale: 'en' } );
	expect( call.mount ).toBe( '#wcpay-core-blocks-payment-element' );
} );

test( 'strict payment adapter rejects a foreign connected account', async ( {
	page,
} ) => {
	await page.addScriptTag( { path: adapterPath } );

	const message = await page.evaluate( () => {
		try {
			window.Stripe( 'pk_test_native_ci', {
				locale: 'en',
				stripeAccount: 'acct_foreign',
			} );
			return '';
		} catch ( error ) {
			return String( error );
		}
	} );

	expect( message ).toContain( 'rejected the connected account' );
} );

test( 'strict payment adapter rejects connected-account betas on the WooPay platform instance', async ( {
	page,
} ) => {
	await page.addScriptTag( { path: adapterPath } );

	const message = await page.evaluate( () => {
		try {
			window.Stripe( 'pk_test_native_ci', {
				locale: 'en',
				betas: [ 'card_country_event_beta_1' ],
			} );
			return '';
		} catch ( error ) {
			return String( error );
		}
	} );

	expect( message ).toContain( 'rejected platform Stripe betas' );
} );

test( 'strict payment adapter records classic loaderror listener before its Core mount', async ( {
	page,
} ) => {
	await page.setContent( '<div id="wcpay-core-payment-element"></div>' );
	await page.addScriptTag( { path: adapterPath } );
	const lifecycle = await page.evaluate( () => {
		const element = window
			.Stripe( 'pk_test_native_ci', {
				locale: 'en',
				stripeAccount: 'acct_native_ci',
				betas: [ 'card_country_event_beta_1' ],
			} )
			.elements( {
				mode: 'payment',
				loader: 'never',
				currency: 'usd',
				paymentMethodCreation: 'manual',
				paymentMethodTypes: [ 'card' ],
				amount: 1000,
			} )
			.create( 'payment', {
				wallets: {
					applePay: 'never',
					googlePay: 'never',
					link: 'never',
				},
				terms: { card: 'never' },
			} );
		element.on( 'loaderror', () => undefined );
		element.mount(
			document.getElementById(
				'wcpay-core-payment-element'
			) as HTMLElement
		);
		return Reflect.get( window, '__wooPaymentsStripePaymentCalls' )[ 0 ]
			.lifecycle;
	} );

	expect( lifecycle ).toEqual( [ 'loaderror-listener', 'mount' ] );
} );

for ( const mutation of [
	{ label: 'payment Elements key', elementsOptions: { unexpected: true } },
	{ label: 'payment element key', paymentOptions: { unexpected: true } },
	{ label: 'Stripe beta', stripeOptions: { betas: [ 'unknown_beta' ] } },
] ) {
	test( `strict payment adapter rejects a mutated ${ mutation.label }`, async ( {
		page,
	} ) => {
		await page.setContent(
			'<div id="wcpay-core-blocks-payment-element"></div>'
		);
		await page.addScriptTag( { path: adapterPath } );
		const message = await page.evaluate( ( selectedMutation ) => {
			try {
				window
					.Stripe( 'pk_test_native_ci', {
						locale: 'en',
						stripeAccount: 'acct_native_ci',
						betas: [ 'card_country_event_beta_1' ],
						...selectedMutation.stripeOptions,
					} )
					.elements( {
						mode: 'payment',
						loader: 'never',
						currency: 'usd',
						paymentMethodCreation: 'manual',
						paymentMethodTypes: [ 'card' ],
						amount: 1000,
						...selectedMutation.elementsOptions,
					} )
					.create( 'payment', {
						fields: { billingDetails: {} },
						wallets: {
							applePay: 'never',
							googlePay: 'never',
							link: 'never',
						},
						terms: { card: 'never' },
						...selectedMutation.paymentOptions,
					} );
				return '';
			} catch ( error ) {
				return String( error );
			}
		}, mutation );
		expect( message ).toContain( 'Stripe adapter rejected' );
	} );
}
