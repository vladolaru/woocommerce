import {
	expect,
	test,
	type APIResponse,
	type Frame,
	type Page,
	type Request,
	type Route,
} from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import {
	AFFIRM,
	AFTERPAY,
	ALIPAY,
	BANCONTACT,
	createProviderHandoffGate,
	KLARNA,
	readProviderHandoffObservation,
	readProviderHandoffRequestKind,
	readReturnUrlFacts,
	requiresCurrencySettingsRecreation,
	withForeignCurrency,
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

const PROVIDER_REDIRECT =
	'https://pay.example.test/authorize?payment_intent=pi_handoff';

function apiResponse( payload: unknown ): APIResponse {
	return {
		ok: () => true,
		status: () => 200,
		text: async () => '',
		json: async () => payload,
	} as unknown as APIResponse;
}

test( 'recreates an enabled currency to restore an automatic nullable settings baseline', () => {
	expect(
		requiresCurrencySettingsRecreation( {
			exchange_rate_type: 'automatic',
			manual_rate: null,
			price_rounding: null,
			price_charm: null,
		} )
	).toBe( true );
	expect(
		requiresCurrencySettingsRecreation( {
			exchange_rate_type: 'manual',
			manual_rate: 1,
			price_rounding: 0,
			price_charm: 0,
		} )
	).toBe( false );
} );

test( 'restores an enabled automatic nullable currency through removal and re-enable', async () => {
	const calls: Array< { url: string; data: unknown } > = [];
	let enabledCodes = [ 'EUR', 'USD' ];
	let settings: Record< string, unknown > = {
		exchange_rate_type: 'automatic',
		manual_rate: null,
		price_rounding: null,
		price_charm: null,
	};
	const currencies = () => ( {
		available: {
			USD: { code: 'USD', name: 'US Dollar', rate: 1, is_default: false },
			EUR: { code: 'EUR', name: 'Euro', rate: 1, is_default: true },
		},
		enabled: Object.fromEntries(
			enabledCodes.map( ( code ) => [
				code,
				{
					code,
					name: code,
					rate: 1,
					is_default: code === 'EUR',
				},
			] )
		),
		default: { code: 'EUR', name: 'Euro', rate: 1, is_default: true },
	} );
	const getHandlers = new Map< string, () => APIResponse >( [
		[
			'/wp-json/wc/v3/payments/settings',
			() => apiResponse( { is_multi_currency_enabled: true } ),
		],
		[
			'/wp-json/wc/v3/payments/multi-currency/currencies',
			() => apiResponse( currencies() ),
		],
		[
			'/wp-json/wc/v3/payments/multi-currency/currencies/USD',
			() => apiResponse( settings ),
		],
	] );
	const postHandlers = new Map< string, ( data: unknown ) => APIResponse >( [
		[
			'/wp-json/wc/v3/payments/multi-currency/currencies/USD',
			( data ) => {
				calls.push( {
					url: '/wp-json/wc/v3/payments/multi-currency/currencies/USD',
					data,
				} );
				settings = data as Record< string, unknown >;
				return apiResponse( settings );
			},
		],
		[
			'/wp-json/wc/v3/payments/multi-currency/update-enabled-currencies',
			( data ) => {
				calls.push( {
					url: '/wp-json/wc/v3/payments/multi-currency/update-enabled-currencies',
					data,
				} );
				enabledCodes = ( data as { enabled: string[] } ).enabled;
				settings = {
					exchange_rate_type: 'automatic',
					manual_rate: null,
					price_rounding: null,
					price_charm: null,
				};
				return apiResponse( currencies() );
			},
		],
	] );
	const session = {
		adminApi: {
			get: async ( url: string ) => getHandlers.get( url )!(),
			post: async ( url: string, options: { data: unknown } ) =>
				postHandlers.get( url )!( options.data ),
		},
		requireApprovedProviderFixture: () => undefined,
		performWrite: async < Result >( write: () => Promise< Result > ) =>
			write(),
	} as unknown as ProviderWriteSession;

	await withForeignCurrency(
		session,
		'USD',
		'multi-currency-settlement-currency',
		async () => {}
	);
	expect( calls ).toEqual( [
		{
			url: '/wp-json/wc/v3/payments/multi-currency/currencies/USD',
			data: {
				exchange_rate_type: 'manual',
				manual_rate: 1,
				price_rounding: 0,
				price_charm: 0,
			},
		},
		{
			url: '/wp-json/wc/v3/payments/multi-currency/update-enabled-currencies',
			data: { enabled: [ 'EUR' ] },
		},
		{
			url: '/wp-json/wc/v3/payments/multi-currency/update-enabled-currencies',
			data: { enabled: [ 'EUR', 'USD' ] },
		},
	] );
	expect( settings ).toEqual( {
		exchange_rate_type: 'automatic',
		manual_rate: null,
		price_rounding: null,
		price_charm: null,
	} );
} );

function providerHandoffPage() {
	let currentUrl = 'http://localhost:8889/checkout/';
	let routeHandler: ( ( route: Route ) => Promise< void > ) | undefined;
	let frameNavigated: ( ( frame: Frame ) => void ) | undefined;
	const mainFrame = {
		url: () => currentUrl,
	} as Frame;
	const page = {
		mainFrame: () => mainFrame,
		url: () => currentUrl,
		on: ( event: string, handler: ( frame: Frame ) => void ) => {
			if ( event === 'framenavigated' ) {
				frameNavigated = handler;
			}
		},
		off: () => undefined,
		route: async (
			_matcher: ( url: URL ) => boolean,
			handler: ( route: Route ) => Promise< void >
		) => {
			routeHandler = handler;
		},
		unroute: async () => {
			routeHandler = undefined;
		},
		waitForURL: () => new Promise< never >( () => undefined ),
	} as unknown as Page;

	const request = ( url: string, redirectedFrom: Request | null = null ) =>
		( {
			isNavigationRequest: () => true,
			frame: () => mainFrame,
			url: () => url,
			redirectedFrom: () => redirectedFrom,
		} ) as Request;
	const dispatch = async ( providerRequest: Request ) => {
		let aborted = false;
		let continued = false;
		if ( ! routeHandler ) {
			return { aborted, continued: true };
		}
		await routeHandler( {
			request: () => providerRequest,
			abort: async () => {
				aborted = true;
			},
			continue: async () => {
				continued = true;
			},
		} as Route );
		return { aborted, continued };
	};
	const commit = ( url: string ) => {
		currentUrl = url;
		frameNavigated?.( mainFrame );
	};

	return { commit, dispatch, page, request };
}

interface ProviderHandoffCandidate {
	orderId: number;
	intentId: string;
	sourceUrl: string;
	destinationUrl: string;
	frameIdentity: 'main-frame' | 'subframe';
	navigationCount: number;
	popupCount: number;
	storeOrigin: string;
	expectedDestinationUrl: string;
}

function handoffCandidate(
	overrides: Partial< ProviderHandoffCandidate > = {}
): ProviderHandoffCandidate {
	return {
		orderId: 4821,
		intentId: 'pi_handoff',
		storeOrigin: 'http://localhost:8889',
		expectedDestinationUrl: PROVIDER_REDIRECT,
		sourceUrl: 'http://localhost:8889/checkout/',
		destinationUrl: PROVIDER_REDIRECT,
		frameIdentity: 'main-frame' as const,
		navigationCount: 1,
		popupCount: 0,
		...overrides,
	};
}

test( 'handoff-only accepts one exact HTTPS provider navigation in the original main frame', () => {
	expect( readProviderHandoffObservation( handoffCandidate() ) ).toEqual( {
		orderId: 4821,
		intentId: 'pi_handoff',
		sourceUrl: 'http://localhost:8889/checkout/',
		destinationUrl: PROVIDER_REDIRECT,
		frameIdentity: 'main-frame',
		navigationCount: 1,
		popupCount: 0,
	} );
} );

test( 'handoff-only treats an HTTPS redirect hop as part of the initial provider navigation', () => {
	expect(
		readProviderHandoffRequestKind( {
			expectedDestinationUrl:
				'https://pm-redirects.stripe.com/authorize/redirect',
			requestUrl: 'https://pay.test.klarna.com/eu/checkout/',
			redirectedFromUrls: [
				'https://pm-redirects.stripe.com/authorize/redirect',
			],
		} )
	).toBe( 'redirect-hop' );
} );

test( 'handoff-only waits for a validated redirect chain to commit in the main frame', async () => {
	const browser = providerHandoffPage();
	const gate = await createProviderHandoffGate(
		browser.page,
		'http://localhost:8889'
	);
	try {
		let settled = false;
		const observation = gate
			.observe( {
				orderId: 4821,
				intentId: 'pi_handoff',
				expectedDestinationUrl: PROVIDER_REDIRECT,
			} )
			.finally( () => {
				settled = true;
			} );
		const initial = browser.request( PROVIDER_REDIRECT );
		expect( await browser.dispatch( initial ) ).toEqual( {
			aborted: false,
			continued: true,
		} );
		await Promise.resolve();
		expect( settled ).toBe( false );

		const finalUrl = 'https://pay.test.klarna.com/eu/checkout/';
		expect(
			await browser.dispatch( browser.request( finalUrl, initial ) )
		).toEqual( { aborted: false, continued: true } );
		browser.commit( finalUrl );
		expect( await observation ).toMatchObject( {
			destinationUrl: PROVIDER_REDIRECT,
			navigationCount: 1,
		} );
	} finally {
		await gate.dispose();
	}
} );

test( 'handoff-only rejects an independent navigation while the redirect chain is in flight', async () => {
	const browser = providerHandoffPage();
	const gate = await createProviderHandoffGate(
		browser.page,
		'http://localhost:8889'
	);
	try {
		const observation = gate.observe( {
			orderId: 4821,
			intentId: 'pi_handoff',
			expectedDestinationUrl: PROVIDER_REDIRECT,
		} );
		await browser.dispatch( browser.request( PROVIDER_REDIRECT ) );
		expect(
			await browser.dispatch(
				browser.request( 'https://unrelated.example.test/' )
			)
		).toEqual( { aborted: true, continued: false } );
		await expect( observation ).rejects.toThrow(
			/did not reach the exact expected URL/
		);
	} finally {
		await gate.dispose();
	}
} );

const invalidHandoffCandidates = [
	[
		'URL mismatch',
		handoffCandidate( {
			destinationUrl: 'https://pay.example.test/another-intent',
		} ),
		/exact provider redirect/,
	],
	[
		'HTTP destination',
		handoffCandidate( {
			destinationUrl:
				'http://pay.example.test/authorize?payment_intent=pi_handoff',
			expectedDestinationUrl:
				'http://pay.example.test/authorize?payment_intent=pi_handoff',
		} ),
		/HTTPS/,
	],
	[
		'same-origin destination',
		handoffCandidate( {
			storeOrigin: 'https://localhost:8889',
			sourceUrl: 'https://localhost:8889/checkout/',
			destinationUrl: 'https://localhost:8889/order-received/4821/',
			expectedDestinationUrl:
				'https://localhost:8889/order-received/4821/',
		} ),
		/off-store/,
	],
	[
		'subframe navigation',
		handoffCandidate( { frameIdentity: 'subframe' } ),
		/original main frame/,
	],
	[ 'popup substitution', handoffCandidate( { popupCount: 1 } ), /popup/ ],
	[
		'second accepted navigation',
		handoffCandidate( { navigationCount: 2 } ),
		/exactly one/,
	],
] satisfies [ string, ProviderHandoffCandidate, RegExp ][];

for ( const [ label, candidate, message ] of invalidHandoffCandidates ) {
	test( `handoff-only rejects a ${ label }`, () => {
		expect( () => readProviderHandoffObservation( candidate ) ).toThrow(
			message
		);
	} );
}

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
