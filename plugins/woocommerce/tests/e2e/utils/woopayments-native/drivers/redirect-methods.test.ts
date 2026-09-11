import { expect, test } from '@playwright/test';

import {
	AFFIRM,
	AFTERPAY,
	ALIPAY,
	BANCONTACT,
	KLARNA,
	readReturnUrlFacts,
} from './redirect-methods';

/**
 * Unit coverage for the pure half of the redirect-method driver.
 *
 * `readReturnUrlFacts` is what turns "native sent the provider a return URL"
 * into an assertion about *this run's* order, so its parsing is worth pinning
 * without a store: a reader that quietly returned empty strings would make the
 * `redirect-method-provider-outcome` return-URL contract pass against a return
 * URL belonging to someone else.
 */

const RETURN_URL =
	'http://localhost:8889/checkout/order-received/4821/?key=wc_order_abc123&wc_payment_method=woocommerce_payments&_wpnonce=deadbeef';

test( 'the run-identifying half of a return URL is read exactly', () => {
	expect( readReturnUrlFacts( RETURN_URL ) ).toEqual( {
		origin: 'http://localhost:8889',
		orderId: 4821,
		orderKey: 'wc_order_abc123',
		paymentMethod: 'woocommerce_payments',
		noncePresent: true,
	} );
} );

test( 'the nonce is reported as present without being carried into the report', () => {
	const facts = readReturnUrlFacts( RETURN_URL );
	expect( facts.noncePresent ).toBe( true );
	expect( JSON.stringify( facts ) ).not.toContain( 'deadbeef' );
} );

test( 'an absent nonce, key, or gateway is reported rather than invented', () => {
	expect(
		readReturnUrlFacts( 'http://localhost:8889/checkout/order-received/7/' )
	).toEqual( {
		origin: 'http://localhost:8889',
		orderId: 7,
		orderKey: '',
		paymentMethod: '',
		noncePresent: false,
	} );
	expect(
		readReturnUrlFacts(
			'http://localhost:8889/checkout/order-received/7/?_wpnonce='
		).noncePresent
	).toBe( false );
} );

test( 'a URL that names no order-received page fails rather than parsing', () => {
	expect( () =>
		readReturnUrlFacts( 'http://localhost:8889/checkout/' )
	).toThrow( /does not name an order-received page/ );
	// Order zero is not an order, and a bare path is not a URL.
	expect( () =>
		readReturnUrlFacts( 'http://localhost:8889/checkout/order-received/0/' )
	).toThrow( /does not name an order-received page/ );
	expect( () => readReturnUrlFacts( '/checkout/order-received/7/' ) ).toThrow(
		/is not a URL/
	);
} );

test( 'the driven method catalog states the exact provider IDs and fixed amounts', () => {
	// These are the fixed values `FIDELITY-CLAIMS.md` states for `A1`-`A5`, and
	// the provider type IDs `WooPaymentsPaymentMethodRegistry` registers. A
	// silent edit here would move a claim rather than a fixture.
	expect(
		[ ALIPAY, AFFIRM, AFTERPAY, BANCONTACT, KLARNA ].map( ( method ) => [
			method.id,
			method.gatewayId,
			method.currency,
			method.amountMinor,
			method.price,
		] )
	).toEqual( [
		[ 'alipay', 'woocommerce_payments_alipay', 'USD', 1200, '12.00' ],
		[ 'affirm', 'woocommerce_payments_affirm', 'USD', 10000, '100.00' ],
		[
			'afterpay_clearpay',
			'woocommerce_payments_afterpay_clearpay',
			'USD',
			10000,
			'100.00',
		],
		[
			'bancontact',
			'woocommerce_payments_bancontact',
			'EUR',
			1234,
			'12.34',
		],
		[ 'klarna', 'woocommerce_payments_klarna', 'USD', 10000, '100.00' ],
	] );
	expect( BANCONTACT.billing.country ).toBe( 'BE' );
	for ( const method of [ ALIPAY, AFFIRM, AFTERPAY, KLARNA ] ) {
		expect( method.billing.country ).toBe( 'US' );
	}
} );
