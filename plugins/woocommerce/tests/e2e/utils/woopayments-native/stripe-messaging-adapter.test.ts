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

	const call = await page.evaluate( () => {
		const stripe = window.Stripe( 'pk_test_native_ci', {
			locale: 'en',
			stripeAccount: 'acct_native_ci',
		} );
		const element = stripe
			.elements( {} )
			.create( 'paymentMethodMessaging', {
				amount: 10000,
				countryCode: 'US',
				currency: 'USD',
				paymentMethodTypes: [ 'klarna' ],
			} );
		element.on( 'ready', () => {} );
		element.mount( '#payment-method-message' );
		return Reflect.get( window, '__wooPaymentsStripeMessagingCalls' )[ 0 ];
	} );

	expect( call ).toEqual( {
		type: 'paymentMethodMessaging',
		paymentMethodTypes: [ 'klarna' ],
		currency: 'USD',
		amount: 10000,
		mount: '#payment-method-message',
	} );
	await expect(
		page.locator( '#payment-method-message iframe' )
	).toHaveCount( 1 );
} );

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
