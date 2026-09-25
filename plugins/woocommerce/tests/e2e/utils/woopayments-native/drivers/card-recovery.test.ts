import { expect, test } from '@playwright/test';

import * as cardRecovery from './card-recovery';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	runBlocksDeclineRecovery,
	runBlocksProcessingErrorRecovery,
	runClassicDeclineRecovery,
	validateBlocksDeclineRecovery,
	validateBlocksDeclineRecoverySmoke,
	validateBlocksProcessingErrorRecovery,
	validateClassicDeclineRecovery,
	type BlocksDeclineRecoveryObservation,
	type BlocksDeclineRecoverySmokeObservation,
	type BlocksProcessingErrorRecoveryObservation,
	type CardRecoveryDependencies,
	type ClassicDeclineRecoveryObservation,
} from './card-recovery';

const FAILED_ATTEMPT = {
	documentMarker: 'document-1',
	checkoutRequestCount: 1,
	orderId: 71,
	orderStatus: 'failed',
	orderAmountMinor: 1001,
	orderCurrency: 'USD',
	paymentMethodId: 'pm_failed',
	intentId: 'pi_failed',
	intentAmount: 1001,
	intentCurrency: 'usd',
	amountReceived: 0,
	errorCode: 'card_declined',
	declineCode: 'generic_decline',
	chargeIds: [],
	chargeStatuses: [],
	chargeIdMeta: '',
	failureNoteCount: 1,
	terminalStatus: 'requires_payment_method',
	capturedChargeCount: 0,
	setupFutureUsage: null,
	providerCustomerId: '',
	providerAttachedPaymentMethodIds: [],
	orderCustomerId: 0,
	localTokenIds: [],
};

const SUCCESSFUL_ATTEMPT = {
	documentMarker: 'document-1',
	checkoutRequestCount: 1,
	orderId: 72,
	orderStatus: 'processing',
	orderAmountMinor: 1001,
	orderCurrency: 'USD',
	paymentMethodId: 'pm_succeeded',
	intentId: 'pi_succeeded',
	chargeIds: [ 'ch_succeeded' ],
	chargeStatuses: [ 'succeeded' ],
	chargeIdMeta: 'ch_succeeded',
	failureNoteCount: null,
	terminalStatus: 'succeeded',
	capturedChargeCount: 1,
	setupFutureUsage: null,
	providerCustomerId: '',
	providerAttachedPaymentMethodIds: [],
	orderCustomerId: 0,
	localTokenIds: [],
};

const CART = [
	{
		key: 'cart-line-1',
		productId: 7,
		variation: [],
		quantity: 1,
	},
];

const CONTINUITY = {
	sameDocument: true,
	sameCart: true,
	sameAddress: true,
	sameSession: true,
};

const CONTROLS = {
	paymentEnabled: true,
	paymentFocusable: true,
	placeOrderEnabled: true,
	placeOrderFocusable: true,
};

function blocksFeedbackPage( visible: string[], announced: string ) {
	const expectedNotice = ( expected: string ) => ( {
		waitFor: async () => undefined,
		innerText: async () =>
			visible.find( ( message ) => message.includes( expected ) ) ?? '',
	} );
	const notices = {
		filter: ( { hasText }: { hasText: string } ) => ( {
			first: () => expectedNotice( hasText ),
		} ),
		allInnerTexts: async () => visible,
	};
	const announcement = { innerText: async () => announced };

	return {
		locator: ( selector: string ) =>
			selector === '#a11y-speak-assertive' ? announcement : notices,
		waitForFunction: async (
			_callback: unknown,
			args: { message: string }
		) => {
			expect( announced ).toContain( args.message );
		},
	};
}

function classicObservation(): ClassicDeclineRecoveryObservation {
	return {
		surface: 'classic',
		failedAttempt: { ...FAILED_ATTEMPT },
		successfulRetry: { ...SUCCESSFUL_ATTEMPT },
		timeline: [
			'failed-request',
			'failed-terminal',
			'retry-request',
			'retry-terminal',
		],
		orderIds: [ 71, 72 ],
		paidOrderIds: [ 72 ],
		shopperFeedback: {
			visible: [ 'Error: Your card was declined.' ],
			announced: [ 'Error: Your card was declined.' ],
		},
		controls: { ...CONTROLS },
		continuity: { ...CONTINUITY },
		cart: { before: CART, beforeRetry: CART },
		cardFrame: {
			first: { marker: 'frame-1', name: 'provider-frame-a' },
			retry: { marker: 'frame-2', name: 'provider-frame-b' },
			relationship: 'replaced',
		},
		unresolvedJournalCount: 0,
	} as ClassicDeclineRecoveryObservation;
}

function blocksProcessingObservation(): BlocksProcessingErrorRecoveryObservation {
	return {
		surface: 'blocks-processing-error',
		failedAttempt: {
			...FAILED_ATTEMPT,
			orderAmountMinor: 1005,
			intentAmount: 1005,
			errorCode: 'processing_error',
			declineCode: 'processing_error',
		},
		timeline: [ 'failed-request', 'failed-terminal' ],
		draftOrderId: 71,
		orderIds: [ 71 ],
		paidOrderIds: [],
		shopperFeedback: {
			visible: [
				'Error: An error occurred while processing your card. Try again in a little bit.',
			],
			announced: [
				'Error: An error occurred while processing your card. Try again in a little bit.',
			],
		},
		controls: { ...CONTROLS },
		continuity: { ...CONTINUITY },
		cart: { before: CART, beforeRetry: CART },
		unresolvedJournalCount: 0,
	} as BlocksProcessingErrorRecoveryObservation;
}

function blocksDeclineObservation(): BlocksDeclineRecoveryObservation {
	return {
		surface: 'blocks-decline',
		failedAttempt: { ...FAILED_ATTEMPT },
		successfulRetry: {
			...SUCCESSFUL_ATTEMPT,
			orderId: 71,
			orderStatus: 'completed',
		},
		timeline: [
			'failed-request',
			'failed-terminal',
			'retry-request',
			'retry-terminal',
		],
		draftOrderId: 71,
		orderIds: [ 71 ],
		paidOrderIds: [ 71 ],
		shopperFeedback: {
			visible: [ 'Error: Your card was declined.' ],
			announced: [ 'Error: Your card was declined.' ],
		},
		controls: { ...CONTROLS },
		continuity: { ...CONTINUITY },
		cart: { before: CART, beforeRetry: CART },
		unresolvedJournalCount: 0,
	} as BlocksDeclineRecoveryObservation;
}

test( 'the three recovery entry points consume one active outer write-lock scope', async () => {
	let outerScopeActive = true;
	let assertCanWriteCalls = 0;
	let nestedLockCalls = 0;
	const session = {
		assertCanWrite: async () => {
			expect( outerScopeActive ).toBe( true );
			assertCanWriteCalls += 1;
		},
		withProviderWriteLocks: async () => {
			nestedLockCalls += 1;
			throw new Error(
				'recovery entry point nested the outer write locks'
			);
		},
	};
	const classicPage = { target: 'outer-classic-page' };
	const classicProduct = {
		id: 7,
		name: 'Outer recovery product',
		amount: '10.01',
	};
	const classicCheckout = { pageId: 10 };
	const processingPage = { target: 'outer-processing-page' };
	const processingProduct = {
		id: 8,
		name: 'Outer processing product',
		amount: '10.05',
	};
	const declinePage = { target: 'outer-decline-page' };
	const declineProduct = {
		id: 9,
		name: 'Outer decline product',
		amount: '10.01',
	};
	const dependencies: CardRecoveryDependencies = {
		executeClassicDeclineRecovery: async ( input ) => {
			expect( input.page ).toBe( classicPage );
			expect( input.product ).toBe( classicProduct );
			expect( input.checkout ).toBe( classicCheckout );
			return classicObservation();
		},
		executeBlocksProcessingErrorRecovery: async ( input ) => {
			expect( input.page ).toBe( processingPage );
			expect( input.product ).toBe( processingProduct );
			return blocksProcessingObservation();
		},
		executeBlocksDeclineRecovery: async ( input ) => {
			expect( input.page ).toBe( declinePage );
			expect( input.product ).toBe( declineProduct );
			return blocksDeclineObservation();
		},
	};

	await expect(
		runClassicDeclineRecovery(
			session as never,
			classicPage as never,
			classicProduct,
			classicCheckout,
			dependencies
		)
	).resolves.toEqual( classicObservation() );
	await expect(
		runBlocksProcessingErrorRecovery(
			session as never,
			processingPage as never,
			processingProduct,
			dependencies
		)
	).resolves.toEqual( blocksProcessingObservation() );
	await expect(
		runBlocksDeclineRecovery(
			session as never,
			declinePage as never,
			declineProduct,
			dependencies
		)
	).resolves.toEqual( blocksDeclineObservation() );

	outerScopeActive = false;
	expect( assertCanWriteCalls ).toBe( 3 );
	expect( nestedLockCalls ).toBe( 0 );
} );

test( 'a recovery entry point refuses to execute without active write ownership', async () => {
	let dependencyCalled = false;
	const session = {
		assertCanWrite: async () => {
			throw new Error( 'active provider-write ownership is required' );
		},
		withProviderWriteLocks: async () => {
			throw new Error( 'must not acquire nested provider-write locks' );
		},
	};
	const dependencies: CardRecoveryDependencies = {
		executeClassicDeclineRecovery: async () => {
			dependencyCalled = true;
			return classicObservation();
		},
		executeBlocksProcessingErrorRecovery: async () =>
			blocksProcessingObservation(),
		executeBlocksDeclineRecovery: async () => blocksDeclineObservation(),
	};

	await expect(
		runClassicDeclineRecovery(
			session as never,
			{} as never,
			{ id: 7, name: 'Recovery', amount: '10.01' },
			{ pageId: 10 },
			dependencies
		)
	).rejects.toThrow( /active provider-write ownership is required/ );
	expect( dependencyCalled ).toBe( false );
} );

test( 'a retry graph rejects the second request before the failed intent converges', () => {
	const observation = classicObservation();
	observation.timeline = [
		'failed-request',
		'retry-request',
		'failed-terminal',
		'retry-terminal',
	];

	expect( () => validateClassicDeclineRecovery( observation ) ).toThrow(
		/failed intent converges before the retry request/
	);
} );

test( 'a retry graph rejects reused PaymentMethod and intent identities', () => {
	const paymentMethodReuse = classicObservation();
	paymentMethodReuse.successfulRetry.paymentMethodId =
		paymentMethodReuse.failedAttempt.paymentMethodId;
	expect( () =>
		validateClassicDeclineRecovery( paymentMethodReuse )
	).toThrow( /distinct PaymentMethod IDs/ );

	const intentReuse = classicObservation();
	intentReuse.successfulRetry.intentId = intentReuse.failedAttempt.intentId;
	expect( () => validateClassicDeclineRecovery( intentReuse ) ).toThrow(
		/distinct intent IDs/
	);
} );

test( 'a retry graph rejects two captured charges', () => {
	const observation = classicObservation();
	observation.successfulRetry.chargeIds = [
		'ch_succeeded',
		'ch_succeeded_duplicate',
	];
	observation.successfulRetry.chargeStatuses = [ 'succeeded', 'succeeded' ];
	observation.successfulRetry.capturedChargeCount = 2;

	expect( () => validateClassicDeclineRecovery( observation ) ).toThrow(
		/exactly one captured charge/
	);
} );

test( 'failed attempts accept failed provider charge records when none succeeded or were captured', () => {
	const cases = [
		{
			label: 'Classic row 113',
			observation: classicObservation(),
			validate: validateClassicDeclineRecovery,
		},
		{
			label: 'Blocks row 166',
			observation: blocksProcessingObservation(),
			validate: validateBlocksProcessingErrorRecovery,
		},
		{
			label: 'Blocks row 175',
			observation: blocksDeclineObservation(),
			validate: validateBlocksDeclineRecovery,
		},
	];

	for ( const { label, observation, validate } of cases ) {
		observation.failedAttempt.chargeIds = [ 'ch_failed' ];
		observation.failedAttempt.chargeStatuses = [ 'failed' ];
		expect(
			() => validate( observation as never ),
			`${ label } must allow a non-captured failed charge`
		).not.toThrow();
	}
} );

test( 'failed attempts reject any succeeded or captured provider charge', () => {
	const cases = [
		{
			label: 'Classic row 113',
			observation: classicObservation(),
			validate: validateClassicDeclineRecovery,
		},
		{
			label: 'Blocks row 166',
			observation: blocksProcessingObservation(),
			validate: validateBlocksProcessingErrorRecovery,
		},
		{
			label: 'Blocks row 175',
			observation: blocksDeclineObservation(),
			validate: validateBlocksDeclineRecovery,
		},
	];

	for ( const { label, observation, validate } of cases ) {
		observation.failedAttempt.chargeIds = [ 'ch_succeeded' ];
		observation.failedAttempt.chargeStatuses = [ 'succeeded' ];
		expect(
			() => validate( observation as never ),
			`${ label } must reject a succeeded charge`
		).toThrow( /successful|succeeded|failed-attempt charge/i );

		observation.failedAttempt.chargeIds = [ 'ch_failed' ];
		observation.failedAttempt.chargeStatuses = [ 'failed' ];
		observation.failedAttempt.capturedChargeCount = 1;
		expect(
			() => validate( observation as never ),
			`${ label } must reject a captured charge`
		).toThrow( /captured|failed-attempt charge/i );
	}
} );

test( 'failed attempts reject a local charge identity and duplicate local failure effects', () => {
	const cases = [
		{
			label: 'Classic row 113',
			observation: classicObservation(),
			validate: validateClassicDeclineRecovery,
		},
		{
			label: 'Blocks row 166',
			observation: blocksProcessingObservation(),
			validate: validateBlocksProcessingErrorRecovery,
		},
		{
			label: 'Blocks row 175',
			observation: blocksDeclineObservation(),
			validate: validateBlocksDeclineRecovery,
		},
	];

	for ( const { label, observation, validate } of cases ) {
		Object.assign( observation.failedAttempt, {
			chargeIdMeta: 'ch_failed',
		} );
		expect(
			() => validate( observation as never ),
			`${ label } must reject a persisted local charge ID`
		).toThrow( /local charge identity/i );

		Object.assign( observation.failedAttempt, {
			chargeIdMeta: '',
			failureNoteCount: 2,
		} );
		expect(
			() => validate( observation as never ),
			`${ label } must reject duplicate local failure effects`
		).toThrow( /one local failure effect/i );
	}
} );

test( 'rows 113 and 175 require the exact 1001 usd generic-decline graph', () => {
	const cases = [
		{
			label: 'Classic row 113',
			observation: classicObservation(),
			validate: validateClassicDeclineRecovery,
		},
		{
			label: 'Blocks row 175',
			observation: blocksDeclineObservation(),
			validate: validateBlocksDeclineRecovery,
		},
	];
	const mutations = [
		[ 'order status', 'orderStatus', 'processing' ],
		[ 'order amount', 'orderAmountMinor', 1002 ],
		[ 'order currency', 'orderCurrency', 'EUR' ],
		[ 'intent amount', 'intentAmount', 1002 ],
		[ 'intent currency', 'intentCurrency', 'eur' ],
		[ 'amount received', 'amountReceived', 1 ],
		[ 'error code', 'errorCode', 'processing_error' ],
		[ 'decline code', 'declineCode', 'do_not_honor' ],
	] as const;

	for ( const { label, observation, validate } of cases ) {
		for ( const [ fieldLabel, field, value ] of mutations ) {
			const mutated = structuredClone( observation );
			Object.assign( mutated.failedAttempt, { [ field ]: value } );
			expect(
				() => validate( mutated as never ),
				`${ label } must reject the wrong ${ fieldLabel }`
			).toThrow( /exact 1001 usd generic-decline graph/i );
		}
	}
} );

test( 'row 166 requires the exact 1005 usd processing-error graph', () => {
	const mutations = [
		[ 'order status', 'orderStatus', 'processing' ],
		[ 'order amount', 'orderAmountMinor', 1001 ],
		[ 'order currency', 'orderCurrency', 'EUR' ],
		[ 'intent amount', 'intentAmount', 1001 ],
		[ 'intent currency', 'intentCurrency', 'eur' ],
		[ 'amount received', 'amountReceived', 1 ],
		[ 'error code', 'errorCode', 'card_declined' ],
		[ 'decline code', 'declineCode', 'generic_decline' ],
	] as const;

	for ( const [ fieldLabel, field, value ] of mutations ) {
		const mutated = structuredClone( blocksProcessingObservation() );
		Object.assign( mutated.failedAttempt, { [ field ]: value } );
		expect(
			() => validateBlocksProcessingErrorRecovery( mutated ),
			`row 166 must reject the wrong ${ fieldLabel }`
		).toThrow( /exact 1005 usd processing-error graph/i );
	}
} );

test( 'successful retries require the exact 1001 USD paid graph', () => {
	const cases = [
		{
			label: 'Classic row 113',
			observation: classicObservation(),
			validate: validateClassicDeclineRecovery,
		},
		{
			label: 'Blocks row 175',
			observation: blocksDeclineObservation(),
			validate: validateBlocksDeclineRecovery,
		},
	];
	const mutations = [
		[ 'order status', 'orderStatus', 'failed' ],
		[ 'order amount', 'orderAmountMinor', 1002 ],
		[ 'order currency', 'orderCurrency', 'EUR' ],
	] as const;

	for ( const { label, observation, validate } of cases ) {
		for ( const [ fieldLabel, field, value ] of mutations ) {
			const mutated = structuredClone( observation );
			Object.assign( mutated.successfulRetry, { [ field ]: value } );
			expect(
				() => validate( mutated as never ),
				`${ label } must reject the wrong ${ fieldLabel }`
			).toThrow( /exact 1001 USD paid graph/ );
		}
	}
} );

for ( const orderStatus of [ 'on-hold', 'refunded' ] ) {
	test( `successful retries reject the WooCommerce Core ${ orderStatus } status`, () => {
		const cases = [
			{
				observation: classicObservation(),
				validate: validateClassicDeclineRecovery,
			},
			{
				observation: blocksDeclineObservation(),
				validate: validateBlocksDeclineRecovery,
			},
		];

		for ( const { observation, validate } of cases ) {
			observation.successfulRetry.orderStatus = orderStatus;
			expect( () => validate( observation as never ) ).toThrow(
				/exact 1001 USD paid graph/
			);
		}
	} );
}

test( 'Blocks recovery rejects a retry against a sibling draft order', () => {
	const observation = blocksDeclineObservation();
	observation.successfulRetry.orderId = 72;
	observation.orderIds = [ 71, 72 ];
	observation.paidOrderIds = [ 72 ];

	expect( () => validateBlocksDeclineRecovery( observation ) ).toThrow(
		/the same draft order/
	);
} );

test( 'the processing-error graph rejects an automatic second checkout request', () => {
	const observation = blocksProcessingObservation();
	observation.failedAttempt.checkoutRequestCount = 2;

	expect( () =>
		validateBlocksProcessingErrorRecovery( observation )
	).toThrow( /exactly one checkout request/ );
} );

test( 'every recovery graph rejects an unresolved provider-write journal', () => {
	const classic = classicObservation();
	classic.unresolvedJournalCount = 1;
	expect( () => validateClassicDeclineRecovery( classic ) ).toThrow(
		/no unresolved provider-write journal/
	);

	const processing = blocksProcessingObservation();
	processing.unresolvedJournalCount = 1;
	expect( () => validateBlocksProcessingErrorRecovery( processing ) ).toThrow(
		/no unresolved provider-write journal/
	);

	const blocks = blocksDeclineObservation();
	blocks.unresolvedJournalCount = 1;
	expect( () => validateBlocksDeclineRecovery( blocks ) ).toThrow(
		/no unresolved provider-write journal/
	);
} );

test( 'a provider journal remains open through provider-derived reconciliation', async () => {
	const events: string[] = [];
	let journalOpen = false;
	const session = {
		withProviderSubmissionJournal: async (
			description: string,
			callback: () => Promise< unknown >
		) => {
			events.push( `open:${ description }` );
			journalOpen = true;
			const result = await callback();
			events.push( 'resolve' );
			journalOpen = false;
			return result;
		},
	};
	const runReconciledProviderAttempt = (
		cardRecovery as unknown as {
			runReconciledProviderAttempt: < Result, Evidence >(
				sessionValue: typeof session,
				description: string,
				dispatch: () => Promise< Result >,
				reconcile: ( result: Result ) => Promise< Evidence >
			) => Promise< Evidence >;
		}
	 ).runReconciledProviderAttempt;

	const evidence = await runReconciledProviderAttempt(
		session,
		'decline-then-reconcile',
		async () => {
			expect( journalOpen ).toBe( true );
			events.push( 'click' );
			return { requestCount: 1 };
		},
		async ( dispatch ) => {
			expect( journalOpen ).toBe( true );
			expect( dispatch.requestCount ).toBe( 1 );
			events.push( 'provider-terminal' );
			return 'reconciled';
		}
	);

	expect( evidence ).toBe( 'reconciled' );
	expect( events ).toEqual( [
		'open:decline-then-reconcile',
		'click',
		'provider-terminal',
		'resolve',
	] );
} );

test( 'zero Store API requests after an attempted click is ambiguous', () => {
	const throwProviderAttemptFailure = (
		cardRecovery as unknown as {
			throwProviderAttemptFailure: (
				error: unknown,
				observation: {
					clickAttempted: boolean;
					checkoutRequestCount: number;
				},
				label: string
			) => never;
		}
	 ).throwProviderAttemptFailure;

	expect( () =>
		throwProviderAttemptFailure(
			new Error( 'no Store API request arrived' ),
			{ clickAttempted: true, checkoutRequestCount: 0 },
			'Blocks decline'
		)
	).toThrow( /outcome is ambiguous after the click was attempted/i );
} );

test( 'the Store API request interval remains open through terminal convergence and quiet time', async () => {
	type RequestListener = ( request: {
		method: () => string;
		url: () => string;
	} ) => void;
	const listeners = new Set< RequestListener >();
	const page = {
		on: ( event: string, listener: RequestListener ) => {
			expect( event ).toBe( 'request' );
			listeners.add( listener );
		},
		off: ( event: string, listener: RequestListener ) => {
			expect( event ).toBe( 'request' );
			listeners.delete( listener );
		},
	};
	const emitCheckout = () => {
		for ( const listener of listeners ) {
			listener( {
				method: () => 'POST',
				url: () => 'https://native.test/wp-json/wc/store/v1/checkout',
			} );
		}
	};
	const countStoreCheckoutRequestsUntil = (
		cardRecovery as unknown as {
			countStoreCheckoutRequestsUntil: < Result >(
				pageValue: typeof page,
				work: () => Promise< Result >
			) => Promise< { result: Result; checkoutRequestCount: number } >;
		}
	 ).countStoreCheckoutRequestsUntil;

	const observation = await countStoreCheckoutRequestsUntil(
		page,
		async () => {
			emitCheckout();
			await Promise.resolve();
			// This models an automatic retry after the first response but before
			// the failed graph's terminal quiet interval has ended.
			emitCheckout();
			return 'terminal-and-quiet';
		}
	);

	expect( observation ).toEqual( {
		result: 'terminal-and-quiet',
		checkoutRequestCount: 2,
	} );
} );

test( 'a Blocks automatic request quarantines the failed journal before card refill or explicit retry', async () => {
	type RequestListener = ( request: {
		method: () => string;
		url: () => string;
	} ) => void;
	const listeners = new Set< RequestListener >();
	const page = {
		on: ( _event: string, listener: RequestListener ) =>
			listeners.add( listener ),
		off: ( _event: string, listener: RequestListener ) =>
			listeners.delete( listener ),
	};
	const emitCheckout = () => {
		for ( const listener of listeners ) {
			listener( {
				method: () => 'POST',
				url: () => 'https://native.test/wp-json/wc/store/v1/checkout',
			} );
		}
	};
	let journalOpen = false;
	let journalResolved = false;
	let continuationCalled = false;
	const session = {
		withProviderSubmissionJournal: async (
			_description: string,
			callback: () => Promise< unknown >
		) => {
			journalOpen = true;
			const result = await callback();
			journalResolved = true;
			journalOpen = false;
			return result;
		},
	};
	const runFailedCheckoutAttemptBeforeContinuation = (
		cardRecovery as unknown as {
			runFailedCheckoutAttemptBeforeContinuation: < Attempt, Result >(
				sessionValue: typeof session,
				description: string,
				label: string,
				observeAttempt: () => Promise< {
					result: Attempt;
					checkoutRequestCount: number;
				} >,
				continueAfterFailure: ( attempt: {
					result: Attempt;
					checkoutRequestCount: number;
				} ) => Promise< Result >
			) => Promise< Result >;
		}
	 ).runFailedCheckoutAttemptBeforeContinuation;
	const countStoreCheckoutRequestsUntil = (
		cardRecovery as unknown as {
			countStoreCheckoutRequestsUntil: < Result >(
				pageValue: typeof page,
				work: () => Promise< Result >
			) => Promise< { result: Result; checkoutRequestCount: number } >;
		}
	 ).countStoreCheckoutRequestsUntil;

	await expect(
		runFailedCheckoutAttemptBeforeContinuation(
			session,
			'blocks-failed-attempt',
			'Blocks failed attempt',
			() =>
				countStoreCheckoutRequestsUntil( page, async () => {
					expect( journalOpen ).toBe( true );
					emitCheckout();
					await Promise.resolve();
					emitCheckout();
					return 'provider-terminal-after-quiet';
				} ),
			async () => {
				continuationCalled = true;
				return 'refill-and-retry';
			}
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( continuationCalled ).toBe( false );
	expect( journalResolved ).toBe( false );
	expect( journalOpen ).toBe( true );
} );

test( 'row 166 quarantines an automatic request before its failed journal resolves', async () => {
	let journalResolved = false;
	let continuationCalled = false;
	const session = {
		withProviderSubmissionJournal: async (
			_description: string,
			callback: () => Promise< unknown >
		) => {
			const result = await callback();
			journalResolved = true;
			return result;
		},
	};
	const runFailedCheckoutAttemptBeforeContinuation = (
		cardRecovery as unknown as {
			runFailedCheckoutAttemptBeforeContinuation: < Attempt, Result >(
				sessionValue: typeof session,
				description: string,
				label: string,
				observeAttempt: () => Promise< {
					result: Attempt;
					checkoutRequestCount: number;
				} >,
				continueAfterFailure: ( attempt: {
					result: Attempt;
					checkoutRequestCount: number;
				} ) => Promise< Result >
			) => Promise< Result >;
		}
	 ).runFailedCheckoutAttemptBeforeContinuation;

	await expect(
		runFailedCheckoutAttemptBeforeContinuation(
			session,
			'blocks-processing-error',
			'Blocks processing-error attempt',
			async () => ( {
				result: 'provider-terminal-after-quiet',
				checkoutRequestCount: 2,
			} ),
			async () => {
				continuationCalled = true;
				return 'row-166-observation';
			}
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( continuationCalled ).toBe( false );
	expect( journalResolved ).toBe( false );
} );

test( 'a Blocks retry quarantines a second request inside its still-open success journal', async () => {
	type RequestListener = ( request: {
		method: () => string;
		url: () => string;
	} ) => void;
	const listeners = new Set< RequestListener >();
	const page = {
		on: ( _event: string, listener: RequestListener ) =>
			listeners.add( listener ),
		off: ( _event: string, listener: RequestListener ) =>
			listeners.delete( listener ),
	};
	const emitCheckout = () => {
		for ( const listener of listeners ) {
			listener( {
				method: () => 'POST',
				url: () => 'https://native.test/wp-json/wc/store/v1/checkout',
			} );
		}
	};
	let journalOpen = false;
	let journalResolved = false;
	let resourceReleased = false;
	const session = {
		withProviderSubmissionJournal: async (
			_description: string,
			callback: () => Promise< unknown >
		) => {
			journalOpen = true;
			const result = await callback();
			journalResolved = true;
			journalOpen = false;
			return result;
		},
	};
	const runJournaledCheckoutAttempt = (
		cardRecovery as unknown as {
			runJournaledCheckoutAttempt: < Result >(
				sessionValue: typeof session,
				pageValue: typeof page,
				description: string,
				label: string,
				work: () => Promise< Result >
			) => Promise< {
				result: Result;
				checkoutRequestCount: number;
			} >;
		}
	 ).runJournaledCheckoutAttempt;

	await expect(
		( async () => {
			await runJournaledCheckoutAttempt(
				session,
				page,
				'card-recovery-blocks-retry',
				'Blocks retry',
				async () => {
					expect( journalOpen ).toBe( true );
					emitCheckout();
					await Promise.resolve();
					emitCheckout();
					return 'successful-provider-reconciliation';
				}
			);
			resourceReleased = true;
		} )()
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( journalResolved ).toBe( false );
	expect( journalOpen ).toBe( true );
	expect( resourceReleased ).toBe( false );
} );

test( 'Classic counts checkout requests through failed provider convergence before allowing retry setup', async () => {
	const events: string[] = [];
	let journalOpen = false;
	let journalResolved = false;
	let continuationCalled = false;
	const session = {
		performWrite: async ( write: () => Promise< void > ) => write(),
		withProviderSubmissionJournal: async (
			_description: string,
			callback: () => Promise< unknown >
		) => {
			journalOpen = true;
			const result = await callback();
			journalResolved = true;
			journalOpen = false;
			return result;
		},
	};
	const firstRequest = { requestId: 'classic-checkout-1' };
	const secondRequest = { requestId: 'classic-checkout-2' };
	const browser = {
		observeSubmissionInterval: async (
			activateOnce: (
				activate: () => Promise< void >
			) => Promise< void >,
			settle: ( dispatch: {
				requests: unknown[];
				responses: unknown[];
			} ) => Promise< unknown >
		) => {
			const dispatch = {
				requests: [ firstRequest ],
				responses: [ { status: 200 } ],
			};
			await activateOnce( async () => {
				events.push( 'click' );
			} );
			const result = await settle( dispatch );
			dispatch.requests.push( secondRequest );
			events.push( 'automatic-request' );
			return { dispatch, result, url: '/checkout/' };
		},
		waitForCheckoutRejectionNotice: async () => true,
		readCheckoutRejectionNotice: async () => ( {
			messages: [ 'Error: Your card was declined.' ],
		} ),
		readCheckoutRecoveryState: async () => ( {} ),
	};
	const submitClassicFailure = (
		cardRecovery as unknown as {
			submitClassicFailure: (
				sessionValue: typeof session,
				browserValue: typeof browser,
				timeline: string[],
				reconcile: () => Promise< string >
			) => Promise< {
				result: unknown;
				checkoutRequestCount: number;
			} >;
		}
	 ).submitClassicFailure;
	const runFailedCheckoutAttemptBeforeContinuation = (
		cardRecovery as unknown as {
			runFailedCheckoutAttemptBeforeContinuation: < Attempt, Result >(
				sessionValue: typeof session,
				description: string,
				label: string,
				observeAttempt: () => Promise< {
					result: Attempt;
					checkoutRequestCount: number;
				} >,
				continueAfterFailure: ( attempt: {
					result: Attempt;
					checkoutRequestCount: number;
				} ) => Promise< Result >
			) => Promise< Result >;
		}
	 ).runFailedCheckoutAttemptBeforeContinuation;

	await expect(
		runFailedCheckoutAttemptBeforeContinuation(
			session,
			'classic-failed-attempt',
			'Classic failed attempt',
			() =>
				submitClassicFailure( session, browser, [], async () => {
					expect( journalOpen ).toBe( true );
					events.push( 'provider-terminal-after-quiet' );
					return 'failed-evidence';
				} ),
			async () => {
				continuationCalled = true;
				return 'prepare-card-refill-and-retry';
			}
		)
	).rejects.toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( events ).toEqual( [
		'click',
		'provider-terminal-after-quiet',
		'automatic-request',
	] );
	expect( continuationCalled ).toBe( false );
	expect( journalResolved ).toBe( false );
	expect( journalOpen ).toBe( true );
} );

test( 'validators reject paid IDs outside the observed order set', () => {
	const observation = classicObservation();
	observation.paidOrderIds = [ 999 ];

	expect( () => validateClassicDeclineRecovery( observation ) ).toThrow(
		/paid order must belong to the observed order set/i
	);
} );

test( 'row 166 rejects an extra order even when neither order is paid', () => {
	const observation = blocksProcessingObservation();
	observation.orderIds = [ 71, 72 ];

	expect( () =>
		validateBlocksProcessingErrorRecovery( observation )
	).toThrow( /exactly the one failed draft order/i );
} );

test( 'validators reject wrong or missing exact shopper feedback', () => {
	const classic = classicObservation();
	classic.shopperFeedback.visible = [ 'A similar but wrong decline.' ];
	expect( () => validateClassicDeclineRecovery( classic ) ).toThrow(
		/exact visible and announced feedback/i
	);

	const processing = blocksProcessingObservation();
	processing.shopperFeedback.announced = [];
	expect( () => validateBlocksProcessingErrorRecovery( processing ) ).toThrow(
		/exact visible and announced feedback/i
	);
} );

test( 'Blocks feedback excludes the notice banner summary from the exact shopper error set', async ( {
	page,
} ) => {
	const message = 'Error: Your card was declined.';
	await page.setContent( `
		<div class="wc-block-components-notice-banner">
			<p>Please fix the following error before continuing</p>
			<ul class="wc-block-components-notice-banner__list">
				<li>${ message }</li>
			</ul>
		</div>
		<div id="a11y-speak-assertive">${ message }</div>
	` );

	const feedback = await cardRecovery.blocksFeedback( page, message );

	expect( feedback ).toEqual( {
		visible: [ message ],
		announced: [ message ],
	} );
} );

test( 'Blocks feedback exposes duplicate expected alerts for exact-set rejection', async () => {
	const message = 'Error: Your card was declined.';
	const blocksFeedback = (
		cardRecovery as unknown as {
			blocksFeedback: (
				page: ReturnType< typeof blocksFeedbackPage >,
				expected: string
			) => Promise< { visible: string[]; announced: string[] } >;
		}
	 ).blocksFeedback;
	const feedback = await blocksFeedback(
		blocksFeedbackPage( [ message, message ], message ),
		message
	);

	expect( feedback ).toEqual( {
		visible: [ message, message ],
		announced: [ message ],
	} );
	const observation = blocksDeclineObservation();
	observation.shopperFeedback = feedback;
	expect( () => validateBlocksDeclineRecovery( observation ) ).toThrow(
		/exact visible and announced feedback/i
	);
} );

test( 'Blocks feedback exposes unrelated visible checkout errors for exact-set rejection', async () => {
	const message = 'Error: Your card was declined.';
	const unrelated = 'Error: Please enter a valid billing phone number.';
	const blocksFeedback = (
		cardRecovery as unknown as {
			blocksFeedback: (
				page: ReturnType< typeof blocksFeedbackPage >,
				expected: string
			) => Promise< { visible: string[]; announced: string[] } >;
		}
	 ).blocksFeedback;
	const feedback = await blocksFeedback(
		blocksFeedbackPage( [ message, unrelated ], message ),
		message
	);

	expect( feedback ).toEqual( {
		visible: [ message, unrelated ],
		announced: [ message ],
	} );
	const observation = blocksDeclineObservation();
	observation.shopperFeedback = feedback;
	expect( () => validateBlocksDeclineRecovery( observation ) ).toThrow(
		/exact visible and announced feedback/i
	);
} );

test( 'validators reject reusable-token creation or provider attachment', () => {
	const setupFutureUsage = classicObservation();
	Object.assign( setupFutureUsage.successfulRetry, {
		setupFutureUsage: 'off_session',
	} );
	expect( () => validateClassicDeclineRecovery( setupFutureUsage ) ).toThrow(
		/no reusable token/i
	);

	const providerAttachment = blocksDeclineObservation();
	Object.assign( providerAttachment.successfulRetry, {
		providerCustomerId: 'cus_attached',
		providerAttachedPaymentMethodIds: [ 'pm_succeeded' ],
	} );
	expect( () => validateBlocksDeclineRecovery( providerAttachment ) ).toThrow(
		/no reusable token/i
	);

	const localToken = blocksProcessingObservation();
	Object.assign( localToken.failedAttempt, { localTokenIds: [ 44 ] } );
	expect( () => validateBlocksProcessingErrorRecovery( localToken ) ).toThrow(
		/no reusable token/i
	);
} );

test( 'Classic validation requires complete and consistent provider-frame observations', () => {
	const observation =
		classicObservation() as ClassicDeclineRecoveryObservation & {
			cardFrame: {
				first: { marker: string; name: string };
				retry: { marker: string; name: string };
				relationship: string;
			};
		};
	observation.cardFrame.retry = { marker: '', name: '' };
	observation.cardFrame.relationship = 'reused';

	expect( () => validateClassicDeclineRecovery( observation ) ).toThrow(
		/exact first and retry card-frame observations/i
	);

	const inconsistent = classicObservation();
	inconsistent.cardFrame.relationship = 'reused';
	expect( () => validateClassicDeclineRecovery( inconsistent ) ).toThrow(
		/consistent relationship/i
	);
} );

test( 'Classic validation accepts successful card re-entry through the same provider frame', () => {
	const observation = classicObservation();
	observation.cardFrame.retry = { ...observation.cardFrame.first };
	observation.cardFrame.relationship = 'reused';

	expect( validateClassicDeclineRecovery( observation ) ).toEqual(
		observation
	);
} );

test( 'cart continuity rejects a changed product identity or quantity', () => {
	const observation =
		blocksDeclineObservation() as BlocksDeclineRecoveryObservation & {
			cart: {
				before: typeof CART;
				beforeRetry: Array< {
					key: string;
					productId: number;
					variation: Array< {
						rawAttribute: string;
						attribute: string;
						value: string;
					} >;
					quantity: number;
				} >;
			};
		};
	observation.cart.beforeRetry = [
		{ ...CART[ 0 ], productId: 8, quantity: 2 },
	];

	expect( () => validateBlocksDeclineRecovery( observation ) ).toThrow(
		/exact cart line identity and quantity/i
	);
} );

test( 'cart-line evidence derives variation identity from the Store API variation shape', async () => {
	const readCartLines = (
		cardRecovery as unknown as {
			readCartLines: ( page: unknown ) => Promise< unknown >;
		}
	 ).readCartLines;
	const page = {
		request: {
			get: async () => ( {
				ok: () => true,
				status: () => 200,
				text: async () => '',
				json: async () => ( {
					items: [
						{
							key: 'variation-line',
							id: 29,
							quantity: 2,
							variation: [
								{
									raw_attribute: 'attribute_pa_size',
									attribute: 'Size',
									value: 'Large',
								},
								{
									raw_attribute: 'attribute_pa_color',
									attribute: 'Color',
									value: 'Blue',
								},
							],
						},
					],
				} ),
			} ),
		},
	};

	await expect( readCartLines( page ) ).resolves.toEqual( [
		{
			key: 'variation-line',
			productId: 29,
			variation: [
				{
					rawAttribute: 'attribute_pa_color',
					attribute: 'Color',
					value: 'Blue',
				},
				{
					rawAttribute: 'attribute_pa_size',
					attribute: 'Size',
					value: 'Large',
				},
			],
			quantity: 2,
		},
	] );
} );

function blocksDeclineSmokeObservation(): BlocksDeclineRecoverySmokeObservation {
	return {
		firstAttemptStatus: 400,
		firstAttemptMessage: 'Error: Your card was declined.',
		shopperFeedback: {
			visible: [ 'Error: Your card was declined.' ],
			announced: [ 'Error: Your card was declined.' ],
		},
		failedAttempt: { ...FAILED_ATTEMPT },
		successfulRetry: {
			...SUCCESSFUL_ATTEMPT,
			orderId: 71,
			orderStatus: 'completed',
		},
		draftOrderId: 71,
		paidOrderIds: [ 71 ],
	};
}

test( 'the family smoke accepts its exact minimal graph', () => {
	const observation = blocksDeclineSmokeObservation();

	expect( validateBlocksDeclineRecoverySmoke( observation ) ).toEqual(
		observation
	);
} );

test( 'the family smoke rejects a first attempt that did not answer HTTP 400', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.firstAttemptStatus = 200;

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/HTTP 400/
	);
} );

test( 'the family smoke rejects a first attempt whose message is not the generic-decline sentence', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.firstAttemptMessage = 'Error: Something else went wrong.';

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/first attempt message/
	);
} );

test( 'the family smoke rejects a decline that was not shown and announced exactly once', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.shopperFeedback.announced = [];

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/shown and announced/
	);
} );

test( 'the family smoke rejects a failed-attempt graph departing from the exact card_declined/generic_decline 1001 usd read', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.failedAttempt.declineCode = 'insufficient_funds';

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/exact failed payment graph/
	);
} );

test( 'the family smoke rejects a retry that does not pay the same draft order', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.successfulRetry.orderId = 72;

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/same draft order/
	);
} );

test( 'the family smoke rejects anything other than exactly one captured charge across both attempts', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.successfulRetry.capturedChargeCount = 0;

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/exactly one captured charge/
	);
} );

test( 'the family smoke rejects anything other than exactly one paid order matching the draft order', () => {
	const observation = blocksDeclineSmokeObservation();
	observation.paidOrderIds = [ 71, 72 ];

	expect( () => validateBlocksDeclineRecoverySmoke( observation ) ).toThrow(
		/exactly one paid order/
	);
} );
