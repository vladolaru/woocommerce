import type { APIResponse, Page } from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import {
	getOrderPaymentEvidence,
	type PaymentEvidence,
} from '../record-evidence';
import { waitForPaymentState } from '../provider-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderTestCard } from '../test-cards';
import {
	completeCardAuthentication,
	PlaywrightCardAuthenticationBrowser,
	type CardAuthenticationEvidence,
	type ChallengeResponse,
} from './card-authentication';
import {
	normalizeClassicCheckoutRequest,
	normalizeClassicRejectedRequest,
	PlaywrightClassicCardCheckoutBrowser,
	type ClassicCheckoutRecoveryState,
	type ClassicCheckoutRejectionNotice,
	type ClassicCheckoutRequestEvidence,
	type ClassicOrderReceipt,
	type ClassicPaymentErrorNotice,
	type ClassicRejectedRequestEvidence,
	type PublicTokenDigest,
	type RawClassicCheckoutRequest,
	type RawClassicCheckoutResponse,
} from './classic-card-checkout';
import type { ClassicCheckoutTarget } from './classic-checkout-page';

/**
 * Drives an authenticated card payment through native's Classic checkout and
 * proves what the provider did with it.
 *
 * Classic and Blocks are two integrations, not two skins. Blocks confirms the
 * payment inside its own submission and the challenge opens while the checkout
 * request is still in flight. Classic returns from the checkout request first,
 * carrying a confirmation hash, and only then does `woopayments-checkout.js`
 * call `handleNextAction`, answer it, post `update_order_status`, and navigate.
 * The existing Classic driver waits for the receipt as part of the submission,
 * so it cannot express that middle at all - and the middle is where every one of
 * these contracts lives.
 *
 * Three things follow, and they are the reason this file exists:
 *
 * - The confirmation hash names the PaymentIntent before the shopper answers
 *   anything. That is the join the ledger rows ask for: the same intent
 *   identity is read in its customer-action state, carried through the
 *   challenge, and read again at its terminal state. A journey that only sees
 *   the intent afterwards cannot say the challenge belonged to it.
 * - A failed challenge never navigates. It lands in native's own Classic error
 *   region, which is a different surface, a different code path and a different
 *   announcement mechanism from the Blocks notice.
 * - The challenge itself is mandatory. `completeCardAuthentication` fails when
 *   no challenge is presented, so a card that stops triggering one fails the
 *   run instead of passing it frictionlessly.
 */

// Native builds this in `WooPaymentsIntentCodec::confirmation_redirect_for()`;
// `woopayments-checkout.js` parses it with the same shape.
const CONFIRMATION_HASH_PATTERN =
	/^#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/;
const CLIENT_SECRET_SEPARATOR = '_secret_';
const RECEIPT_TIMEOUT_MS = 60_000;
const PAYMENT_ERROR_TIMEOUT_MS = 30_000;
const REJECTION_NOTICE_TIMEOUT_MS = 30_000;
const ORDER_EVIDENCE_TIMEOUT_MS = 60_000;
const PROVIDER_STATE_TIMEOUT_MS = 60_000;
const ORDER_SETTLE_INTERVAL_MS = 2_000;
const POLL_INTERVAL_MS = 500;
// Both states native's own codec treats as needing the confirmation hash. The
// driver refuses anything outside this set, because a challenge answered
// against an intent that is already terminal proves nothing; which of the two
// the provider actually returns is the caller's assertion, not the driver's.
const CUSTOMER_ACTION_STATUSES = [ 'requires_action', 'requires_confirmation' ];
const AUTHENTICATION_FAILED_STATUS = 'requires_payment_method';
const SETTLED_STATUS = 'succeeded';

function fail( message: string ): never {
	throw new Error( `Classic card authentication ${ message }` );
}

/**
 * Used only after a submission has left the browser. Before that point a
 * failure costs nothing and is reported plainly; after it, an outcome this run
 * cannot account for is provider state, not a test failure.
 */
function quarantine(
	message: string,
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments Classic card authentication ${ message }`,
		'uncertain-provider-write',
		primaryError
	);
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

export interface PreparedClassicCardCheckout {
	readonly browser: PlaywrightClassicCardCheckoutBrowser;
	readonly runId: string;
	readonly orderTotal: string;
	readonly card: ProviderTestCard;
	readonly savePaymentMethod: boolean;
	/** Present only when the caller asked for card-testing protection evidence. */
	readonly exposedTokenDigest?: PublicTokenDigest;
}

export interface PrepareClassicCardCheckoutOptions {
	product: OwnedProduct;
	runId: string;
	checkout: ClassicCheckoutTarget;
	card: ProviderTestCard;
	/** Ticks the Classic save-to-account control before submitting. */
	savePaymentMethod?: boolean;
	/** Reads the token the page exposes, for the protection-on contract. */
	captureExposedToken?: boolean;
	/** Signs the shopper in first; a guest checkout is driven otherwise. */
	logInAsCustomer?: boolean;
}

/**
 * What the store answered when the submission asked for customer action.
 */
export interface ClassicCustomerActionDispatch {
	requestId: string;
	request: ClassicCheckoutRequestEvidence;
	status: number;
	result: unknown;
	orderId: number;
	intentType: 'pi' | 'si';
	intentId: string;
	/** The provider payment method the submission created, as native reports it. */
	paymentMethodId: unknown;
}

/**
 * The intent as it stood between the checkout response and the challenge
 * answer: the state a frictionless or skipped challenge could not produce.
 */
export interface ClassicPendingIntent {
	id: string;
	status: unknown;
	nextActionType: unknown;
	amount: unknown;
	currency: unknown;
	chargeCount: number;
}

export interface ClassicCardAuthenticationOutcome {
	dispatch: ClassicCustomerActionDispatch;
	pendingIntent: ClassicPendingIntent;
	challenge: CardAuthenticationEvidence;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	settled: boolean;
	url: string;
	receipt?: ClassicOrderReceipt;
	paymentError?: ClassicPaymentErrorNotice;
	recovery?: ClassicCheckoutRecoveryState;
}

export interface ClassicTokenlessRejection {
	requestId: string;
	request: ClassicRejectedRequestEvidence;
	status: number;
	result: unknown;
	messages: string;
	notice: ClassicCheckoutRejectionNotice;
	recovery: ClassicCheckoutRecoveryState;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	url: string;
}

/**
 * The terminal state of an intent whose challenge was failed.
 */
export interface ClassicFailedAuthenticationIntent {
	id: string;
	status: unknown;
	lastPaymentErrorCode: unknown;
	chargeCount: number;
}

/**
 * Orders that appeared after a submission that must not have created a payment.
 */
export interface ClassicOrderDelta {
	newOrderIds: number[];
	paidOrderIds: number[];
	providerLinkedOrderIds: number[];
}

function requireString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `requires a non-empty ${ label }.` );
	}
	return value;
}

function requireObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `requires exactly one ${ label } object.` );
	}
	return value as Record< string, unknown >;
}

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		fail(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

/**
 * Counts the charges an intent carries, across both shapes native's payment
 * details controller passes through.
 */
function chargeCount( intent: Record< string, unknown > ): number {
	const charges = intent.charges;
	if (
		typeof charges === 'object' &&
		charges !== null &&
		'data' in charges &&
		Array.isArray( ( charges as { data: unknown[] } ).data )
	) {
		return ( charges as { data: unknown[] } ).data.length;
	}
	const charge = intent.charge;
	if ( typeof charge === 'object' && charge !== null ) {
		return 1;
	}
	if ( typeof intent.latest_charge === 'string' && intent.latest_charge ) {
		return 1;
	}
	return 0;
}

export function providerIntentReadTarget( intentId: string ): {
	kind: 'payment' | 'setup';
	path: string;
} {
	if ( intentId.startsWith( 'seti_' ) ) {
		return {
			kind: 'setup',
			path: `/wp-json/wc-native-payments-e2e/v1/subscription-evidence?setup_intent_id=${ encodeURIComponent(
				intentId
			) }`,
		};
	}
	return {
		kind: 'payment',
		path: `/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
			intentId
		) }`,
	};
}

async function readProviderIntent(
	session: ProviderWriteSession,
	intentId: string
): Promise< Record< string, unknown > > {
	const target = providerIntentReadTarget( intentId );
	if ( target.kind === 'setup' ) {
		const evidence = requireObject(
			await readJson(
				await session.adminApi.get( target.path ),
				`provider setup intent ${ intentId }`
			),
			'provider setup-intent evidence'
		);
		const setupIntent = requireObject(
			evidence.setup_intent,
			'provider setup intent'
		);
		return {
			...setupIntent,
			next_action: {
				type: setupIntent.next_action_type ?? null,
			},
			charges: { data: [] },
		};
	}
	return requireObject(
		await readJson(
			await session.adminApi.get( target.path ),
			`provider intent ${ intentId }`
		),
		'provider intent'
	);
}

function nextActionType( value: unknown ): unknown {
	if ( typeof value !== 'object' || value === null ) {
		return null;
	}
	return ( value as { type?: unknown } ).type ?? null;
}

/**
 * Reads the order a successful checkout response names, and nothing else.
 *
 * Kept separate from the confirmation hash below so the order can be tagged
 * with this run before any judgement is passed on what the store answered: a
 * submission that settled when it should have challenged still created a real
 * payment, and an untagged payment is one nobody can attribute later.
 */
function readCheckoutSuccessOrder( response: RawClassicCheckoutResponse ): {
	orderId: number;
	result: unknown;
	paymentMethodId: unknown;
} {
	if ( response.status < 200 || response.status >= 300 ) {
		fail( `response is HTTP ${ response.status }, not a success.` );
	}
	const body = requireObject( response.body, 'checkout response' );
	if ( body.result !== 'success' ) {
		fail(
			`expected a successful checkout result, but the store answered ${ String(
				body.result
			) }. A challenge journey cannot start from a rejected submission.`
		);
	}
	if (
		! Number.isSafeInteger( body.order_id ) ||
		Number( body.order_id ) <= 0
	) {
		fail( 'response carries no exact order ID.' );
	}

	return {
		orderId: body.order_id as number,
		result: body.result,
		paymentMethodId: body.payment_method,
	};
}

/**
 * Reads the confirmation hash native answered the submission with.
 *
 * Only the intent identity is kept. The client secret and the nonce the hash
 * also carries are credentials for this one payment and never leave this
 * function.
 */
function readConfirmationHash(
	response: RawClassicCheckoutResponse,
	orderId: number
): { intentType: 'pi' | 'si'; intentId: string } {
	const body = requireObject( response.body, 'checkout response' );
	const redirect = requireString( body.redirect, 'checkout redirect' );
	const match = redirect.match( CONFIRMATION_HASH_PATTERN );
	if ( ! match ) {
		fail(
			'response carries no customer-action confirmation hash, so the provider did not ask for authentication. A payment that settles without a challenge proves nothing about the authenticated path.'
		);
	}
	const [ , intentType, hashOrderId, clientSecret ] = match;
	if ( Number( hashOrderId ) !== orderId ) {
		fail( 'confirmation hash names a different order than the response.' );
	}
	const intentId = clientSecret.split( CLIENT_SECRET_SEPARATOR )[ 0 ];
	if ( ! intentId || intentId === clientSecret ) {
		fail( 'confirmation hash carries no readable intent identity.' );
	}

	return { intentType: intentType as 'pi' | 'si', intentId };
}

function requireSingleExchange(
	requests: RawClassicCheckoutRequest[],
	responses: RawClassicCheckoutResponse[]
): void {
	if ( requests.length !== 1 || responses.length !== 1 ) {
		throw quarantine(
			`observed ${ requests.length } checkout request(s) and ${ responses.length } response(s); exactly one of each is required, and anything else may have left a second payment behind.`
		);
	}
	if ( responses[ 0 ].requestId !== requests[ 0 ].requestId ) {
		throw quarantine(
			'observed a checkout response that does not belong to the observed request.'
		);
	}
}

/**
 * Navigates the Classic checkout and fills it, stopping before Place order.
 *
 * Nothing here reaches the provider, so every failure is reported plainly.
 */
export async function prepareClassicCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	options: PrepareClassicCardCheckoutOptions
): Promise< PreparedClassicCardCheckout > {
	const { product, runId, checkout, card } = options;
	if ( ! runId || runId !== session.runId ) {
		fail( 'requires the exact active run ID.' );
	}
	if (
		! Number.isSafeInteger( checkout.pageId ) ||
		checkout.pageId <= 0 ||
		checkout.slug !== 'classic-checkout' ||
		checkout.path !== 'classic-checkout/'
	) {
		fail( 'requires the exact marker-bound Classic checkout page.' );
	}
	if ( product.name !== `WooPayments native E2E ${ runId }` ) {
		fail( 'requires the run-owned product.' );
	}
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'card-authentication' );

	if ( options.logInAsCustomer ) {
		await session.logInAsCustomer( page );
	}

	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		checkout.pageId
	);
	await browser.preflightClassicPage( checkout.path, checkout.pageId );

	let addActivationCount = 0;
	await browser.addProductOnce( product.id, async ( write ) => {
		addActivationCount += 1;
		if ( addActivationCount !== 1 ) {
			fail( 'must activate Add to cart exactly once.' );
		}
		return session.performWrite( write );
	} );
	if ( addActivationCount !== 1 ) {
		fail( 'must activate Add to cart exactly once.' );
	}

	await browser.openClassicCheckout( checkout.path );
	await browser.fillBillingDetails( runId );
	await browser.selectWooPaymentsCard();
	await browser.fillTestCard( card );
	if ( options.savePaymentMethod ) {
		await browser.setSavePaymentMethod( true );
	}
	const exposedTokenDigest = options.captureExposedToken
		? await browser.captureExposedTokenDigest()
		: undefined;
	await browser.prepareSubmission();

	return {
		browser,
		runId,
		orderTotal: product.amount,
		card,
		savePaymentMethod: options.savePaymentMethod === true,
		exposedTokenDigest,
	};
}

/**
 * Submits once, answers the challenge once, and reports the outcome.
 *
 * The whole interval runs inside one provider submission journal, so a run that
 * dies between the dispatch and a proven outcome leaves an unresolved attempt
 * rather than a silent charge. Assertions belong to the caller: everything
 * returned here is an observation, and only states this run cannot account for
 * raise a quarantine from inside.
 */
export async function submitClassicCardAuthentication(
	session: ProviderWriteSession,
	prepared: PreparedClassicCardCheckout,
	page: Page,
	options: { response: ChallengeResponse; journal: string }
): Promise< ClassicCardAuthenticationOutcome > {
	await session.assertCanWrite();
	const { browser } = prepared;

	return session.withProviderSubmissionJournal( options.journal, async () => {
		let activationCount = 0;
		const observation = await browser.observeSubmissionInterval(
			async ( activate ) => {
				activationCount += 1;
				if ( activationCount !== 1 ) {
					throw quarantine(
						'must activate Place order exactly once.'
					);
				}
				await session.performWrite( activate );
			},
			async ( dispatched ) => {
				requireSingleExchange(
					dispatched.requests,
					dispatched.responses
				);
				const rawRequest = dispatched.requests[ 0 ];
				const rawResponse = dispatched.responses[ 0 ];
				const request = normalizeClassicCheckoutRequest( {
					method: () => rawRequest.method,
					url: () => rawRequest.url,
					postData: () => rawRequest.body,
				} );
				const order = readCheckoutSuccessOrder( rawResponse );
				// Attribute the order before judging the answer. Whatever the
				// store did, this run caused it, and the run ID is how a later
				// reader tells this order from someone else's.
				await session.setOrderRunId( order.orderId, prepared.runId );
				const dispatch: ClassicCustomerActionDispatch = {
					requestId: rawRequest.requestId,
					request,
					status: rawResponse.status,
					result: order.result,
					orderId: order.orderId,
					paymentMethodId: order.paymentMethodId,
					...readConfirmationHash( rawResponse, order.orderId ),
				};

				// Read the intent while it still requires action. This is
				// the half of the proof a post-hoc read cannot supply: it
				// binds the challenge about to be answered to the exact
				// intent the submission created.
				const pending = await readProviderIntent(
					session,
					dispatch.intentId
				);
				const pendingIntent: ClassicPendingIntent = {
					id: requireString( pending.id, 'pending intent ID' ),
					status: pending.status,
					nextActionType: nextActionType( pending.next_action ),
					amount: pending.amount,
					currency: pending.currency,
					chargeCount: chargeCount( pending ),
				};
				if (
					pendingIntent.id !== dispatch.intentId ||
					typeof pendingIntent.status !== 'string' ||
					! CUSTOMER_ACTION_STATUSES.includes( pendingIntent.status )
				) {
					throw quarantine(
						`expected intent ${
							dispatch.intentId
						} to await customer action before the challenge, but the provider reported ${ String(
							pendingIntent.status
						) } on ${ String( pendingIntent.id ) }.`
					);
				}

				const challenge = await completeCardAuthentication( {
					expectation: 'challenge',
					response: options.response,
					browser: new PlaywrightCardAuthenticationBrowser( page ),
				} );

				if ( options.response === 'complete' ) {
					let receipt: ClassicOrderReceipt;
					try {
						receipt =
							await browser.waitForClassicReceipt(
								RECEIPT_TIMEOUT_MS
							);
					} catch ( error ) {
						const notice = await browser.readPaymentErrorNotice();
						throw quarantine(
							`answered a challenge and reached no receipt; the page is at ${ page.url() } showing ${ JSON.stringify(
								notice
							) }.`,
							error
						);
					}
					if ( receipt.orderId !== dispatch.orderId ) {
						throw quarantine(
							`settled on order ${ receipt.orderId }, which is not the dispatched order ${ dispatch.orderId }.`
						);
					}
					return { dispatch, pendingIntent, challenge, receipt };
				}

				const shown = await browser.waitForPaymentErrorNotice(
					PAYMENT_ERROR_TIMEOUT_MS
				);
				if ( ! shown ) {
					throw quarantine(
						`failed a challenge and the Classic payment error region never appeared; the page is at ${ page.url() }, so the outcome of the submission is unknown.`
					);
				}

				return {
					dispatch,
					pendingIntent,
					challenge,
					paymentError: await browser.readPaymentErrorNotice(),
					recovery: await browser.readCheckoutRecoveryState(),
				};
			}
		);

		const settled = observation.result.receipt !== undefined;
		return {
			...observation.result,
			checkoutRequestCount: observation.dispatch.requests.length,
			checkoutResponseCount: observation.dispatch.responses.length,
			settled,
			url: observation.url,
		};
	} );
}

/**
 * Submits once with no session token and reports how native turned it away.
 *
 * The token is removed from every place the Classic script reads it, and the
 * effective value is re-read before anything is clicked: a submission that
 * still carried a valid token would settle a real payment rather than prove an
 * enforcement boundary, so it must not be dispatched at all.
 */
export async function submitClassicTokenlessCheckout(
	session: ProviderWriteSession,
	prepared: PreparedClassicCardCheckout,
	options: { journal: string }
): Promise< ClassicTokenlessRejection > {
	await session.assertCanWrite();
	const { browser } = prepared;

	return session.withProviderSubmissionJournal( options.journal, async () => {
		await browser.clearFraudPreventionToken();
		const effective =
			await browser.captureEffectiveFraudPreventionTokenDigest();
		if ( effective.present ) {
			// Nothing has been dispatched, so the journal closes cleanly and
			// no provider resource is quarantined for a run that never wrote.
			throw new ProviderSubmissionNotStartedError(
				'WooPayments Classic tokenless submission was not dispatched because the page still exposes a fraud-prevention token.'
			);
		}

		let activationCount = 0;
		const observation = await browser.observeSubmissionInterval(
			async ( activate ) => {
				activationCount += 1;
				if ( activationCount !== 1 ) {
					throw quarantine(
						'must activate Place order exactly once.'
					);
				}
				await session.performWrite( activate );
			},
			async ( dispatched ) => {
				requireSingleExchange(
					dispatched.requests,
					dispatched.responses
				);
				const shown = await browser.waitForCheckoutRejectionNotice(
					REJECTION_NOTICE_TIMEOUT_MS
				);
				if ( ! shown ) {
					throw quarantine(
						'submitted without a session token and saw no rejection notice, so whether the submission was refused is unknown.'
					);
				}
				return {
					notice: await browser.readCheckoutRejectionNotice(),
					recovery: await browser.readCheckoutRecoveryState(),
				};
			}
		);

		const rawRequest = observation.dispatch.requests[ 0 ];
		const rawResponse = observation.dispatch.responses[ 0 ];
		const body = requireObject( rawResponse.body, 'checkout response' );

		return {
			requestId: rawRequest.requestId,
			request: normalizeClassicRejectedRequest( {
				method: () => rawRequest.method,
				url: () => rawRequest.url,
				postData: () => rawRequest.body,
			} ),
			status: rawResponse.status,
			result: body.result,
			messages: typeof body.messages === 'string' ? body.messages : '',
			notice: observation.result.notice,
			recovery: observation.result.recovery,
			checkoutRequestCount: observation.dispatch.requests.length,
			checkoutResponseCount: observation.dispatch.responses.length,
			url: observation.url,
		};
	} );
}

/**
 * Waits for the order to carry its provider identifiers and for the intent to
 * reach its settled state, then returns the exact correlated graph.
 *
 * A completed challenge that never produces a readable graph is a payment this
 * run cannot account for, so the wait quarantines rather than merely failing.
 */
export async function readSettledClassicPayment(
	session: ProviderWriteSession,
	orderId: number,
	intentId: string
): Promise< PaymentEvidence > {
	const orderDeadline = Date.now() + ORDER_EVIDENCE_TIMEOUT_MS;
	let lastError: unknown;
	for (;;) {
		try {
			const order = await getOrderPaymentEvidence(
				session.adminApi,
				orderId
			);
			if ( order.intentId !== intentId ) {
				throw quarantine(
					`order ${ orderId } carries intent ${ order.intentId }, not the authenticated intent ${ intentId }.`
				);
			}
			break;
		} catch ( error ) {
			if ( error instanceof ResourceQuarantineRequiredError ) {
				throw error;
			}
			lastError = error;
			if ( Date.now() >= orderDeadline ) {
				throw quarantine(
					`order ${ orderId } never carried the provider identifiers for intent ${ intentId }.`,
					lastError
				);
			}
			await delay( POLL_INTERVAL_MS );
		}
	}

	try {
		return await waitForPaymentState(
			session.adminApi,
			{ orderId, intentId },
			SETTLED_STATUS,
			Date.now() + PROVIDER_STATE_TIMEOUT_MS
		);
	} catch ( error ) {
		throw quarantine(
			`intent ${ intentId } never reached ${ SETTLED_STATUS } for order ${ orderId }.`,
			error
		);
	}
}

/**
 * Waits for the intent whose challenge was failed to reach its terminal
 * unauthenticated state.
 */
export async function readFailedAuthenticationIntent(
	session: ProviderWriteSession,
	intentId: string
): Promise< ClassicFailedAuthenticationIntent > {
	const deadline = Date.now() + PROVIDER_STATE_TIMEOUT_MS;
	let intent = await readProviderIntent( session, intentId );
	while (
		intent.status !== AUTHENTICATION_FAILED_STATUS &&
		Date.now() < deadline
	) {
		await delay( POLL_INTERVAL_MS );
		intent = await readProviderIntent( session, intentId );
	}

	const lastPaymentError = intent.last_payment_error;
	return {
		id: requireString( intent.id, 'failed intent ID' ),
		status: intent.status,
		lastPaymentErrorCode:
			typeof lastPaymentError === 'object' && lastPaymentError !== null
				? ( lastPaymentError as { code?: unknown } ).code ?? null
				: null,
		chargeCount: chargeCount( intent ),
	};
}

/**
 * Reads the provider customer an intent was created against.
 */
export async function readProviderCustomerId(
	session: ProviderWriteSession,
	intentId: string
): Promise< string > {
	const intent = await readProviderIntent( session, intentId );
	const customer = intent.customer;
	return requireString(
		typeof customer === 'object' && customer !== null
			? ( customer as { id?: unknown } ).id
			: customer,
		`provider customer ID on intent ${ intentId }`
	);
}

/**
 * Lists the payment methods currently attached to a provider customer.
 */
export async function readProviderCustomerPaymentMethodIds(
	session: ProviderWriteSession,
	providerCustomerId: string
): Promise< string[] > {
	const listed = await readJson(
		await session.adminApi.get(
			`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		),
		`provider customer ${ providerCustomerId } payment methods`
	);
	if ( ! Array.isArray( listed ) ) {
		fail( 'provider payment-method evidence is not a collection.' );
	}
	return listed.map( ( value, index ) =>
		requireString(
			requireObject( value, `payment method ${ index + 1 }` ).id,
			`payment method ${ index + 1 } ID`
		)
	);
}

interface OrderListEntry {
	id: number;
	status: string;
	providerLinked: boolean;
}

async function readOrdersAfter(
	session: ProviderWriteSession,
	afterOrderId: number
): Promise< OrderListEntry[] > {
	const listed = await readJson(
		await session.adminApi.get(
			'/wp-json/wc/v3/orders?status=any&per_page=50&orderby=id&order=desc'
		),
		'WooCommerce order list'
	);
	if ( ! Array.isArray( listed ) ) {
		fail( 'order list did not return a collection.' );
	}

	return listed
		.map( ( value, index ) => {
			const order = requireObject(
				value,
				`order list entry ${ index + 1 }`
			);
			const meta = Array.isArray( order.meta_data )
				? ( order.meta_data as Array< {
						key?: unknown;
						value?: unknown;
				  } > )
				: [];
			return {
				id: Number( order.id ),
				status: String( order.status ),
				providerLinked: meta.some(
					( entry ) =>
						( entry.key === '_intent_id' ||
							entry.key === '_charge_id' ||
							entry.key === '_payment_method_id' ) &&
						typeof entry.value === 'string' &&
						entry.value.trim() !== ''
				),
			};
		} )
		.filter( ( order ) => order.id > afterOrderId );
}

/**
 * Reads the highest order ID the store currently holds, as the baseline a
 * later "created nothing that reached the provider" claim is measured against.
 */
export async function readHighestOrderId(
	session: ProviderWriteSession
): Promise< number > {
	const listed = await readJson(
		await session.adminApi.get(
			'/wp-json/wc/v3/orders?status=any&per_page=1&orderby=id&order=desc'
		),
		'WooCommerce order list'
	);
	if ( ! Array.isArray( listed ) ) {
		fail( 'order list did not return a collection.' );
	}
	if ( listed.length === 0 ) {
		return 0;
	}
	const order = requireObject( listed[ 0 ], 'newest order' );
	if ( ! Number.isSafeInteger( order.id ) || Number( order.id ) <= 0 ) {
		fail( 'newest order carries no exact ID.' );
	}
	return order.id as number;
}

/**
 * Reports every order created since a baseline, and which of them are paid or
 * carry provider identifiers.
 *
 * Read twice, two seconds apart, and only returned when both reads agree: a
 * payment created a moment after the rejection would otherwise slip between a
 * single read and the assertion that follows it. The store is held under this
 * run's exclusive locks, so any order in the interval belongs to this run.
 */
export async function readOrderDeltaAfter(
	session: ProviderWriteSession,
	afterOrderId: number
): Promise< ClassicOrderDelta > {
	const paidStatuses = [ 'processing', 'completed', 'on-hold', 'refunded' ];
	const summarize = ( orders: OrderListEntry[] ): ClassicOrderDelta => ( {
		newOrderIds: orders.map( ( order ) => order.id ).toSorted(),
		paidOrderIds: orders
			.filter( ( order ) => paidStatuses.includes( order.status ) )
			.map( ( order ) => order.id )
			.toSorted(),
		providerLinkedOrderIds: orders
			.filter( ( order ) => order.providerLinked )
			.map( ( order ) => order.id )
			.toSorted(),
	} );

	const first = summarize( await readOrdersAfter( session, afterOrderId ) );
	await delay( ORDER_SETTLE_INTERVAL_MS );
	const second = summarize( await readOrdersAfter( session, afterOrderId ) );

	if ( JSON.stringify( first ) !== JSON.stringify( second ) ) {
		throw quarantine(
			`order state after the rejected submission did not converge: ${ JSON.stringify(
				first
			) } then ${ JSON.stringify( second ) }.`
		);
	}
	return second;
}
