import path from 'node:path';

import { expect, test } from '@playwright/test';

import { startStrictStripeAdapterBrowserOracle } from './stripe-adapter-browser';

const adapterPath = path.resolve(
	__dirname,
	'../../envs/woopayments-native/stripe-messaging-adapter.js'
);

async function mountLocalPaymentElement(
	page: import('@playwright/test').Page
) {
	await page.setContent(
		'<script id="stripe-js" src="http://fixture.test/wp-content/mu-plugins/stripe-messaging-adapter.js"></script><div id="wcpay-core-blocks-payment-element"></div>'
	);
	await page.addScriptTag( { path: adapterPath } );
	await page.evaluate( () => {
		window
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
				fields: { billingDetails: {} },
				wallets: {
					applePay: 'never',
					googlePay: 'never',
					link: 'never',
				},
				terms: { card: 'never' },
			} )
			.mount(
				document.getElementById(
					'wcpay-core-blocks-payment-element'
				) as HTMLElement
			);
	} );
}

test( 'strict browser oracle proves the local adapter mounted through the Core container', async ( {
	page,
} ) => {
	const oracle = startStrictStripeAdapterBrowserOracle( page );
	await mountLocalPaymentElement( page );
	await expect(
		oracle.assertPaymentMounted( '#wcpay-core-blocks-payment-element' )
	).resolves.toBeUndefined();
} );

test( 'strict browser oracle rejects a provider script source', async ( {
	page,
} ) => {
	await page.route( 'https://js.stripe.com/**', ( route ) =>
		route.fulfill( { body: '' } )
	);
	const oracle = startStrictStripeAdapterBrowserOracle( page );
	await mountLocalPaymentElement( page );
	await page.evaluate( () => {
		const script = document.getElementById(
			'stripe-js'
		) as HTMLScriptElement;
		script.src = 'https://js.stripe.com/v3/';
	} );

	await expect(
		oracle.assertPaymentMounted( '#wcpay-core-blocks-payment-element' )
	).rejects.toThrow( 'https://js.stripe.com/v3/' );
} );

test( 'strict browser oracle rejects a provider resource request', async ( {
	page,
} ) => {
	await page.route( 'https://api.stripe.com/**', ( route ) =>
		route.fulfill( { body: '{}' } )
	);
	const oracle = startStrictStripeAdapterBrowserOracle( page );
	await mountLocalPaymentElement( page );
	await page.evaluate( () =>
		fetch( 'https://api.stripe.com/v1/elements/sessions' )
	);

	await expect(
		oracle.assertPaymentMounted( '#wcpay-core-blocks-payment-element' )
	).rejects.toThrow( 'https://api.stripe.com/v1/elements/sessions' );
} );

test( 'strict browser oracle rejects a provider iframe source', async ( {
	page,
} ) => {
	await page.route( 'https://merchant-ui-api.stripe.com/**', ( route ) =>
		route.fulfill( { body: '<!doctype html>' } )
	);
	const oracle = startStrictStripeAdapterBrowserOracle( page );
	await mountLocalPaymentElement( page );
	await page.evaluate( () => {
		const frame = document.createElement( 'iframe' );
		frame.src = 'https://merchant-ui-api.stripe.com/link/frame';
		document.body.appendChild( frame );
	} );

	await expect(
		oracle.assertPaymentMounted( '#wcpay-core-blocks-payment-element' )
	).rejects.toThrow( 'https://merchant-ui-api.stripe.com/link/frame' );
} );

test( 'strict browser oracle rejects adapter errors and a missing mount record', async ( {
	page,
} ) => {
	const oracle = startStrictStripeAdapterBrowserOracle( page );
	await mountLocalPaymentElement( page );
	await page.evaluate( () => {
		Reflect.set( window, '__wooPaymentsStripeAdapterErrors', [
			'Error: rejected test mutation',
		] );
		Reflect.set( window, '__wooPaymentsStripePaymentCalls', [] );
	} );

	await expect(
		oracle.assertPaymentMounted( '#wcpay-core-blocks-payment-element' )
	).rejects.toThrow( 'rejected test mutation' );
} );
