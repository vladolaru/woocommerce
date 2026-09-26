import { createHash } from 'node:crypto';

import { expect, test } from '@playwright/test';

import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	completeClassicCardCheckout,
	isClassicCheckoutRequest,
	normalizeClassicCheckoutRequest,
	normalizeClassicCheckoutResponse,
	parseClassicOrderReceivedUrl,
	type ClassicCardCheckoutBrowser,
	PlaywrightClassicCardCheckoutBrowser,
	type PublicTokenDigest,
} from './classic-card-checkout';

const RUN_ID = 'run-classic-card';
const BASE_URL = 'https://native.test/';
const TOKEN = 'abcdefghijklmnop';
// A FingerprintJS visitor id, the device fingerprint the extension posts.
const DEVICE_FINGERPRINT = '2ee41551fca70edc977286f51aeb02ba';
const TOKEN_DIGEST: PublicTokenDigest = {
	length: 16,
	sha256: createHash( 'sha256' ).update( TOKEN ).digest( 'hex' ),
};
const RECEIPT_URL =
	'https://native.test/classic-checkout/order-received/73/?key=wc_order_exact';
const RESPONSE_BODY = {
	result: 'success',
	order_id: 73,
	redirect: RECEIPT_URL,
	payment_method: 'pm_must_not_be_retained',
};
const REQUEST_BODY = new URLSearchParams( [
	[ 'billing_first_name', 'Private' ],
	[ 'billing_email', 'private@example.com' ],
	[ 'payment_method', 'woocommerce_payments' ],
	[ 'wcpay-payment-method', 'pm_must_not_be_retained' ],
	[ 'wcpay-fraud-prevention-token', TOKEN ],
	[ 'wcpay-payment-method-error-code', '' ],
	[ 'wcpay-payment-method-error-message', '' ],
	[ 'wcpay-fingerprint', DEVICE_FINGERPRINT ],
] ).toString();

function checkoutRequest( body = REQUEST_BODY ) {
	return {
		method: () => 'POST',
		url: () => 'https://native.test/?wc-ajax=checkout',
		postData: () => body,
	};
}

function successfulObservation() {
	return {
		requests: [
			{
				requestId: 'classic-checkout-1',
				method: 'POST',
				url: 'https://native.test/?wc-ajax=checkout',
				body: REQUEST_BODY,
			},
		],
		responses: [
			{
				requestId: 'classic-checkout-1',
				status: 200,
				body: RESPONSE_BODY,
			},
		],
		receiptUrl: RECEIPT_URL,
	};
}

class FakeClassicBrowser implements ClassicCardCheckoutBrowser {
	public readonly events: string[] = [];
	public observation = successfulObservation();
	public exposedTokenDigest = TOKEN_DIGEST;
	public preflightError?: Error;
	public prepareSubmissionError?: Error;
	public submissionError?: Error;

	public async preflightClassicPage(
		path: 'classic-checkout/',
		pageId: number
	): Promise< void > {
		this.events.push( `preflight:${ path }:${ pageId }` );
		if ( this.preflightError ) {
			throw this.preflightError;
		}
	}

	public async addProductOnce(
		productId: number,
		performWrite: < Result >(
			write: () => Promise< Result >
		) => Promise< Result >
	): Promise< void > {
		this.events.push( `product:${ productId }` );
		await performWrite( async () => {
			this.events.push( 'product:add-click' );
		} );
	}

	public async openClassicCheckout(
		path: 'classic-checkout/'
	): Promise< void > {
		this.events.push( `checkout:${ path }` );
	}

	public async fillBillingDetails( runId: string ): Promise< void > {
		this.events.push( `billing:${ runId }` );
	}

	public async selectWooPaymentsCard(): Promise< void > {
		this.events.push( 'gateway:card' );
	}

	public async fillBasicCard(): Promise< void > {
		this.events.push( 'card:4242:02-45:424' );
	}

	public async captureExposedTokenDigest(): Promise< PublicTokenDigest > {
		this.events.push( 'token:exposed' );
		return this.exposedTokenDigest;
	}

	public async prepareSubmission(): Promise< void > {
		this.events.push( 'place-order:ready' );
		if ( this.prepareSubmissionError ) {
			throw this.prepareSubmissionError;
		}
	}

	public async observeSubmission(
		activateOnce: ( activate: () => Promise< void > ) => Promise< void >
	): Promise< ReturnType< typeof successfulObservation > > {
		this.events.push( 'observe:start' );
		await activateOnce( async () => {
			this.events.push( 'place-order:click' );
		} );
		if ( this.submissionError ) {
			throw this.submissionError;
		}
		this.events.push( 'observe:complete' );
		return this.observation;
	}
}

function harness() {
	const events: string[] = [];
	const browser = new FakeClassicBrowser();
	const session = {
		baseURL: BASE_URL,
		runId: RUN_ID,
		assertCanWrite: async () => {
			events.push( 'session:assert-can-write' );
		},
		requireApprovedProviderFixture: ( capability: string ) => {
			events.push( `capability:${ capability }` );
		},
		performWrite: async ( write: () => Promise< unknown > ) => {
			events.push( 'session:perform-write' );
			return write();
		},
		withProviderSubmissionJournal: async (
			description: string,
			submit: () => Promise< unknown >
		) => {
			events.push( `journal:${ description }` );
			return submit();
		},
		setOrderRunId: async ( orderId: number, runId: string ) => {
			events.push( `bind:${ orderId }:${ runId }` );
		},
	};
	const scope = {
		classicCheckout: {
			pageId: 314,
			slug: 'classic-checkout' as const,
			path: 'classic-checkout/' as const,
		},
		captureGuestSessionToken: async () => {
			events.push( 'token:authoritative-session' );
			return TOKEN_DIGEST;
		},
	};

	return { browser, events, scope, session };
}

type PageEventName = 'request' | 'response';
type PageEventListener = ( value: never ) => void;

class FakeRealAdapterPage {
	public readonly listeners = new Map<
		PageEventName,
		Set< PageEventListener >
	>();
	public readonly roleQueries: Array< {
		role: string;
		name: string | RegExp | undefined;
		exact: boolean | undefined;
	} > = [];
	public cardChecked = false;
	public paymentMethodCount = 1;
	// Core hides the payment-method radio when a store offers a single
	// method, because there is no choice to make; it is pre-selected instead.
	public soleHiddenCardGateway = false;
	public cardLabelText = 'Card Test Mode Visa Mastercard';
	public emitSubmissionOnClick = false;
	public placeOrderFailure?: Error;
	public waitForResponseCalls = 0;
	public waitForUrlProof?: { crossOrigin: boolean; sameOrigin: boolean };
	private currentUrl = BASE_URL;

	private accessibleNameMatches(
		expected: string | RegExp | undefined,
		actual: string,
		exact: boolean | undefined
	): boolean {
		if ( expected instanceof RegExp ) {
			return expected.test( actual );
		}
		if ( typeof expected !== 'string' ) {
			return true;
		}
		return exact ? expected === actual : actual.includes( expected );
	}

	public getByRole(
		role: string,
		options: {
			name?: string | RegExp;
			exact?: boolean;
		} = {}
	) {
		this.roleQueries.push( {
			role,
			name: options.name,
			exact: options.exact,
		} );
		const accessibleName =
			role === 'radio' ? 'Card Test Mode Visa Mastercard' : 'Place order';
		const matches = this.accessibleNameMatches(
			options.name,
			accessibleName,
			options.exact
		);
		return {
			count: async () => ( matches ? 1 : 0 ),
			isVisible: async () => matches,
			isEnabled: async () => matches,
			inputValue: async () => 'woocommerce_payments',
			check: async () => {
				this.cardChecked = true;
			},
			click: async () => {
				if ( this.placeOrderFailure ) {
					throw this.placeOrderFailure;
				}
				if ( this.emitSubmissionOnClick ) {
					const request = checkoutRequest();
					const response = {
						request: () => request,
						status: () => 200,
						json: async () => RESPONSE_BODY,
					};
					this.emit( 'request', request );
					this.emit( 'response', response );
				}
			},
		};
	}

	public locator( selector: string ) {
		if ( selector === 'input[name="payment_method"]' ) {
			return {
				count: async () => this.paymentMethodCount,
			};
		}
		if (
			selector ===
			'input[name="payment_method"][value="woocommerce_payments"]'
		) {
			return {
				count: async () => 1,
				inputValue: async () => 'woocommerce_payments',
				isVisible: async () => ! this.soleHiddenCardGateway,
				isChecked: async () => this.soleHiddenCardGateway,
				check: async () => {
					this.cardChecked = true;
				},
			};
		}
		if ( selector.startsWith( 'label[for="payment_method_' ) ) {
			return {
				isVisible: async () => true,
				innerText: async () => this.cardLabelText,
			};
		}
		throw new Error( `unexpected locator: ${ selector }` );
	}

	public on( event: PageEventName, listener: PageEventListener ): this {
		const listeners = this.listeners.get( event ) ?? new Set();
		listeners.add( listener );
		this.listeners.set( event, listeners );
		return this;
	}

	public off( event: PageEventName, listener: PageEventListener ): this {
		this.listeners.get( event )?.delete( listener );
		return this;
	}

	public emit( event: PageEventName, value: unknown ): void {
		for ( const listener of this.listeners.get( event ) ?? [] ) {
			listener( value as never );
		}
	}

	public listenerCount( event: PageEventName ): number {
		return this.listeners.get( event )?.size ?? 0;
	}

	public waitForResponse(
		predicate: ( response: never ) => boolean,
		options: { timeout: number }
	): Promise< never > {
		this.waitForResponseCalls += 1;
		return new Promise( ( resolve, reject ) => {
			const waiter: {
				timer?: ReturnType< typeof setTimeout >;
			} = {};
			const onResponse = ( response: never ) => {
				if ( ! predicate( response ) ) {
					return;
				}
				if ( waiter.timer !== undefined ) {
					clearTimeout( waiter.timer );
				}
				this.off( 'response', onResponse );
				resolve( response );
			};
			waiter.timer = setTimeout( () => {
				this.off( 'response', onResponse );
				reject( new Error( 'independent response waiter timed out' ) );
			}, options.timeout );
			this.on( 'response', onResponse );
		} );
	}

	public async waitForURL(
		predicate: unknown,
		options: { timeout: number }
	): Promise< void > {
		if ( options.timeout <= 0 ) {
			throw new Error( 'waitForURL requires a positive timeout.' );
		}
		if ( typeof predicate !== 'function' ) {
			throw new Error( 'waitForURL requires the exact-store predicate.' );
		}
		const crossOrigin = predicate(
			new URL(
				'https://attacker.test/checkout/order-received/73/?key=wc_order_exact'
			)
		);
		const receiptUrl = new URL(
			'https://native.test/configured-checkout/order-received/73/?key=wc_order_exact'
		);
		const sameOrigin = predicate( receiptUrl );
		this.waitForUrlProof = { crossOrigin, sameOrigin };
		if ( crossOrigin || ! sameOrigin ) {
			throw new Error( 'waitForURL predicate accepted the wrong store.' );
		}
		this.currentUrl = receiptUrl.href;
	}

	public url(): string {
		return this.currentUrl;
	}
}

function browserWithRealSubmissionObserver(
	page: FakeRealAdapterPage
): ClassicCardCheckoutBrowser {
	const realBrowser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);
	return {
		preflightClassicPage: async () => undefined,
		addProductOnce: async ( _productId, performWrite ) => {
			await performWrite( async () => undefined );
		},
		openClassicCheckout: async () => undefined,
		fillBillingDetails: async () => undefined,
		selectWooPaymentsCard: async () => undefined,
		fillBasicCard: async () => undefined,
		captureExposedTokenDigest: async () => TOKEN_DIGEST,
		prepareSubmission: () => realBrowser.prepareSubmission(),
		observeSubmission: ( activateOnce ) =>
			realBrowser.observeSubmission( activateOnce ),
	};
}

test( 'matches only POST requests whose exact wc-ajax query value is checkout', () => {
	expect( isClassicCheckoutRequest( checkoutRequest() as never ) ).toBe(
		true
	);
	expect(
		isClassicCheckoutRequest( {
			method: () => 'GET',
			url: () => 'https://native.test/?wc-ajax=checkout',
		} as never )
	).toBe( false );
	expect(
		isClassicCheckoutRequest( {
			method: () => 'POST',
			url: () =>
				'https://native.test/?wc-ajax=checkout&wc-ajax=update_order_review',
		} as never )
	).toBe( false );
	expect(
		isClassicCheckoutRequest( {
			method: () => 'POST',
			url: () => 'https://native.test/?wc-ajax=update_order_review',
		} as never )
	).toBe( false );
} );

test( 'selects the semantic Card radio when its accessible name includes test-mode and brand content', async () => {
	const page = new FakeRealAdapterPage();
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);

	await browser.selectWooPaymentsCard();

	expect( page.cardChecked ).toBe( true );
} );

test( 'selects Card without disabling another enabled gateway', async () => {
	const page = new FakeRealAdapterPage();
	page.paymentMethodCount = 2;
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);

	await browser.selectWooPaymentsCard();

	expect( page.cardChecked ).toBe( true );
} );

test( 'accepts the sole Card gateway that core renders hidden and pre-selected', async () => {
	const page = new FakeRealAdapterPage();
	page.soleHiddenCardGateway = true;
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);

	// No click: core does not render a control to click when the shopper has
	// only one payment method, and demanding one would fail on every
	// single-gateway store.
	await browser.selectWooPaymentsCard();

	expect( page.cardChecked ).toBe( false );
} );

test( 'refuses a gateway the shopper is not offered as Card', async () => {
	const page = new FakeRealAdapterPage();
	page.cardLabelText = 'Cash on delivery';
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);

	await expect( browser.selectWooPaymentsCard() ).rejects.toThrow(
		/not labelled as Card/
	);
} );

test( 'uses the exact-store receipt parser while waiting for navigation', async () => {
	const page = new FakeRealAdapterPage();
	page.emitSubmissionOnClick = true;
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page as never,
		BASE_URL,
		314
	);
	await browser.prepareSubmission();

	const observation = await browser.observeSubmission( async ( activate ) =>
		activate()
	);

	expect( page.waitForUrlProof ).toEqual( {
		crossOrigin: false,
		sameOrigin: true,
	} );
	expect( page.waitForResponseCalls ).toBe( 0 );
	expect( observation.receiptUrl ).toBe(
		'https://native.test/configured-checkout/order-received/73/?key=wc_order_exact'
	);
	expect( observation.requests ).toHaveLength( 1 );
	expect( observation.responses ).toHaveLength( 1 );
} );

test( 'quarantines a rejected Place order activation after releasing its response listener and timer', async () => {
	const page = new FakeRealAdapterPage();
	page.placeOrderFailure = new Error(
		'raw checkout failure ch_private pm_private private@example.com'
	);
	const browser = browserWithRealSubmissionObserver( page );
	const { events, scope, session } = harness();
	const pendingTimers = new Set< ReturnType< typeof setTimeout > >();
	const originalSetTimeout = globalThis.setTimeout;
	const originalClearTimeout = globalThis.clearTimeout;
	let nextTimer = 0;
	globalThis.setTimeout = ( ( callback: TimerHandler, timeout?: number ) => {
		void callback;
		void timeout;
		const timer = {
			id: ( nextTimer += 1 ),
		} as unknown as ReturnType< typeof setTimeout >;
		pendingTimers.add( timer );
		return timer;
	} ) as typeof globalThis.setTimeout;
	globalThis.clearTimeout = ( ( timer: ReturnType< typeof setTimeout > ) => {
		pendingTimers.delete( timer );
	} ) as typeof globalThis.clearTimeout;

	let failure: unknown;
	try {
		try {
			await completeClassicCardCheckout(
				session as never,
				page as never,
				{
					id: 82,
					name: `WooPayments native E2E ${ RUN_ID }`,
					amount: '10.99',
				},
				RUN_ID,
				scope as never,
				{ browser }
			);
		} catch ( error ) {
			failure = error;
		}
	} finally {
		globalThis.setTimeout = originalSetTimeout;
		globalThis.clearTimeout = originalClearTimeout;
	}

	expect( failure ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( ( failure as ResourceQuarantineRequiredError ).reasonCode ).toBe(
		'uncertain-provider-write'
	);
	expect( ( failure as Error ).message ).not.toContain( 'ch_private' );
	expect( page.listenerCount( 'request' ) ).toBe( 0 );
	expect( page.listenerCount( 'response' ) ).toBe( 0 );
	expect( page.waitForResponseCalls ).toBe( 0 );
	expect( pendingTimers.size ).toBe( 0 );
	expect(
		events.filter( ( event ) => event === 'session:perform-write' )
	).toHaveLength( 2 );
	expect(
		events.filter(
			( event ) => event === 'journal:basic-card-classic-checkout'
		)
	).toHaveLength( 1 );
} );

test( 'normalizes only public-safe allowlisted Classic checkout fields', () => {
	const evidence = normalizeClassicCheckoutRequest(
		checkoutRequest() as never
	);

	expect( evidence ).toEqual( {
		gateway: 'woocommerce_payments',
		savePaymentMethod: false,
		fraudPreventionToken: TOKEN_DIGEST,
		paymentMethodErrorCodePresent: false,
		paymentMethodErrorMessagePresent: false,
		platformPaymentMethod: 'false',
		fingerprintPresent: true,
		deviceFingerprint: true,
	} );
	const serialized = JSON.stringify( evidence );
	expect( serialized ).not.toContain( TOKEN );
	expect( serialized ).not.toContain( 'Private' );
	expect( serialized ).not.toContain( 'private@example.com' );
	expect( serialized ).not.toContain( 'pm_must_not_be_retained' );
} );

test( 'normalizes the explicit Classic save and platform flags', () => {
	const body = `${ REQUEST_BODY }&wc-woocommerce_payments-new-payment-method=true&wcpay-is-platform-payment-method=true`;

	expect(
		normalizeClassicCheckoutRequest( checkoutRequest( body ) as never )
	).toMatchObject( {
		savePaymentMethod: true,
		platformPaymentMethod: 'true',
	} );
} );

for ( const duplicateKey of [
	'payment_method',
	'wc-woocommerce_payments-new-payment-method',
	'wcpay-fraud-prevention-token',
	'wcpay-payment-method-error-code',
	'wcpay-payment-method-error-message',
	'wcpay-is-platform-payment-method',
	'wcpay-fingerprint',
] ) {
	test( `rejects duplicate ${ duplicateKey } fields`, () => {
		const encodedKey = encodeURIComponent( duplicateKey );
		const duplicate = `${ REQUEST_BODY }&${ encodedKey }=first&${ encodedKey }=duplicate`;

		expect( () =>
			normalizeClassicCheckoutRequest(
				checkoutRequest( duplicate ) as never
			)
		).toThrow( /duplicate allowlisted field/i );
	} );
}

test( 'correlates a positive response order with its redirect order key', () => {
	expect(
		normalizeClassicCheckoutResponse( 200, RESPONSE_BODY, BASE_URL )
	).toEqual( {
		status: 200,
		orderId: 73,
		orderKey: 'wc_order_exact',
	} );
	expect( parseClassicOrderReceivedUrl( RECEIPT_URL, BASE_URL ) ).toEqual( {
		orderId: 73,
		orderKey: 'wc_order_exact',
	} );
} );

test( 'accepts an order-received URL under a different same-origin checkout-page prefix', () => {
	expect(
		parseClassicOrderReceivedUrl(
			'https://native.test/configured-checkout/order-received/73/?key=wc_order_exact',
			BASE_URL
		)
	).toEqual( {
		orderId: 73,
		orderKey: 'wc_order_exact',
	} );
} );

test( 'rejects a cross-origin order-received URL', () => {
	expect( () =>
		parseClassicOrderReceivedUrl(
			'https://attacker.test/checkout/order-received/73/?key=wc_order_exact',
			BASE_URL
		)
	).toThrow( /invalid order-received URL/i );
} );

for ( const invalidResponse of [
	{ name: 'non-positive status', status: 500, body: RESPONSE_BODY },
	{
		name: 'failure result',
		status: 200,
		body: { ...RESPONSE_BODY, result: 'failure' },
	},
	{
		name: 'non-positive order ID',
		status: 200,
		body: { ...RESPONSE_BODY, order_id: 0 },
	},
	{
		name: 'redirect order mismatch',
		status: 200,
		body: {
			...RESPONSE_BODY,
			redirect:
				'https://native.test/classic-checkout/order-received/74/?key=wc_order_exact',
		},
	},
] ) {
	test( `rejects Classic responses with ${ invalidResponse.name }`, () => {
		expect( () =>
			normalizeClassicCheckoutResponse(
				invalidResponse.status,
				invalidResponse.body,
				BASE_URL
			)
		).toThrow( /response/i );
	} );
}

test( 'rejects Blocks markup before any click, write, or provider journal', async () => {
	const { browser, events, scope, session } = harness();
	browser.preflightError = new Error(
		'Classic checkout preflight found Blocks markup.'
	);

	await expect(
		completeClassicCardCheckout(
			session as never,
			{} as never,
			{
				id: 82,
				name: `WooPayments native E2E ${ RUN_ID }`,
				amount: '10.99',
			},
			RUN_ID,
			scope as never,
			{ browser }
		)
	).rejects.toThrow( /Blocks markup/i );
	expect( events ).not.toContain( 'session:perform-write' );
	expect( events.some( ( event ) => event.startsWith( 'journal:' ) ) ).toBe(
		false
	);
	expect( browser.events ).toEqual( [ 'preflight:classic-checkout/:314' ] );
} );

test( 'completes one exact Classic card submission and returns public-safe evidence', async () => {
	const { browser, events, scope, session } = harness();

	const evidence = await completeClassicCardCheckout(
		session as never,
		{} as never,
		{
			id: 82,
			name: `WooPayments native E2E ${ RUN_ID }`,
			amount: '10.99',
		},
		RUN_ID,
		scope as never,
		{ browser }
	);

	expect( evidence ).toMatchObject( {
		runId: RUN_ID,
		orderId: 73,
		orderKey: 'wc_order_exact',
		tokens: {
			exposed: TOKEN_DIGEST,
			authoritativeSession: TOKEN_DIGEST,
			submitted: TOKEN_DIGEST,
		},
		request: {
			gateway: 'woocommerce_payments',
			savePaymentMethod: false,
		},
		response: {
			status: 200,
			orderId: 73,
			orderKey: 'wc_order_exact',
		},
		receipt: {
			orderId: 73,
			orderKey: 'wc_order_exact',
		},
	} );
	expect( browser.events ).toEqual( [
		'preflight:classic-checkout/:314',
		'product:82',
		'product:add-click',
		'checkout:classic-checkout/',
		`billing:${ RUN_ID }`,
		'gateway:card',
		'card:4242:02-45:424',
		'token:exposed',
		'place-order:ready',
		'observe:start',
		'place-order:click',
		'observe:complete',
	] );
	expect(
		events.filter( ( event ) => event === 'session:perform-write' )
	).toHaveLength( 2 );
	expect(
		events.filter(
			( event ) => event === 'journal:basic-card-classic-checkout'
		)
	).toHaveLength( 1 );
	expect( events ).toContain( `bind:73:${ RUN_ID }` );
	const serialized = JSON.stringify( evidence );
	expect( serialized ).not.toContain( TOKEN );
	expect( serialized ).not.toContain( 'pm_must_not_be_retained' );
} );

test( 'requires one enabled Place order button before opening the journal', async () => {
	const { browser, events, scope, session } = harness();
	browser.prepareSubmissionError = new Error(
		'Classic card checkout requires one enabled Place order button.'
	);

	await expect(
		completeClassicCardCheckout(
			session as never,
			{} as never,
			{
				id: 82,
				name: `WooPayments native E2E ${ RUN_ID }`,
				amount: '10.99',
			},
			RUN_ID,
			scope as never,
			{ browser }
		)
	).rejects.toThrow( /Place order button/i );
	expect( events.some( ( event ) => event.startsWith( 'journal:' ) ) ).toBe(
		false
	);
} );

test( 'refuses submission when exposed and authoritative token digests differ', async () => {
	const { browser, events, scope, session } = harness();
	browser.exposedTokenDigest = {
		...TOKEN_DIGEST,
		sha256: 'a'.repeat( 64 ),
	};

	await expect(
		completeClassicCardCheckout(
			session as never,
			{} as never,
			{
				id: 82,
				name: `WooPayments native E2E ${ RUN_ID }`,
				amount: '10.99',
			},
			RUN_ID,
			scope as never,
			{ browser }
		)
	).rejects.toThrow( /token evidence mismatch/i );
	expect( events.some( ( event ) => event.startsWith( 'journal:' ) ) ).toBe(
		false
	);
} );

for ( const unsafeRequest of [
	{
		name: 'wrong gateway',
		body: REQUEST_BODY.replace(
			'payment_method=woocommerce_payments',
			'payment_method=cod'
		),
	},
	{
		name: 'save request',
		body: `${ REQUEST_BODY }&wc-woocommerce_payments-new-payment-method=true`,
	},
	{
		name: 'payment-method error',
		body: REQUEST_BODY.replace(
			'wcpay-payment-method-error-code=',
			'wcpay-payment-method-error-code=unsafe_error'
		),
	},
	{
		name: 'invalid platform flag',
		body: `${ REQUEST_BODY }&wcpay-is-platform-payment-method=invalid`,
	},
	{
		name: 'provider fingerprint',
		body: REQUEST_BODY.replace(
			`wcpay-fingerprint=${ DEVICE_FINGERPRINT }`,
			'wcpay-fingerprint=provider_fingerprint'
		),
	},
	{
		name: 'missing device fingerprint',
		body: REQUEST_BODY.replace(
			`wcpay-fingerprint=${ DEVICE_FINGERPRINT }`,
			'wcpay-fingerprint='
		),
	},
] ) {
	test( `quarantines a dispatched request with ${ unsafeRequest.name }`, async () => {
		const { browser, scope, session } = harness();
		browser.observation.requests[ 0 ].body = unsafeRequest.body;

		await expect(
			completeClassicCardCheckout(
				session as never,
				{} as never,
				{
					id: 82,
					name: `WooPayments native E2E ${ RUN_ID }`,
					amount: '10.99',
				},
				RUN_ID,
				scope as never,
				{ browser }
			)
		).rejects.toMatchObject( {
			reasonCode: 'uncertain-provider-write',
		} );
	} );
}

for ( const ambiguousCase of [
	{
		name: 'submission observer failure',
		mutate: ( browser: FakeClassicBrowser ) => {
			browser.submissionError = new Error( 'raw private failure' );
		},
	},
	{
		name: 'duplicate checkout request',
		mutate: ( browser: FakeClassicBrowser ) => {
			browser.observation.requests.push( {
				...browser.observation.requests[ 0 ],
				requestId: 'classic-checkout-2',
			} );
		},
	},
	{
		name: 'response/request identity mismatch',
		mutate: ( browser: FakeClassicBrowser ) => {
			browser.observation.responses[ 0 ].requestId =
				'classic-checkout-other';
		},
	},
	{
		name: 'browser receipt mismatch',
		mutate: ( browser: FakeClassicBrowser ) => {
			browser.observation.receiptUrl =
				'https://native.test/classic-checkout/order-received/74/?key=wc_order_exact';
		},
	},
] ) {
	test( `quarantines ${ ambiguousCase.name } without retrying`, async () => {
		const { browser, events, scope, session } = harness();
		ambiguousCase.mutate( browser );

		let failure: unknown;
		try {
			await completeClassicCardCheckout(
				session as never,
				{} as never,
				{
					id: 82,
					name: `WooPayments native E2E ${ RUN_ID }`,
					amount: '10.99',
				},
				RUN_ID,
				scope as never,
				{ browser }
			);
		} catch ( error ) {
			failure = error;
		}

		expect( failure ).toBeInstanceOf( ResourceQuarantineRequiredError );
		expect(
			( failure as ResourceQuarantineRequiredError ).reasonCode
		).toBe( 'uncertain-provider-write' );
		expect( ( failure as Error ).message ).not.toContain( 'raw private' );
		expect(
			events.filter( ( event ) => event === 'session:perform-write' )
		).toHaveLength( 2 );
		expect(
			events.filter(
				( event ) => event === 'journal:basic-card-classic-checkout'
			)
		).toHaveLength( 1 );
	} );
}
