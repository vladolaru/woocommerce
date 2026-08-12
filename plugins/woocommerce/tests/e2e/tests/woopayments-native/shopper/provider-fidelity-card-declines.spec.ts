/*
 * Fourteen of this family's tests are one shared body driven by a different
 * fixture, so each test's assertions live in the runner it calls rather than
 * inline. Name those runners for the assertion rule; without this every test
 * here reads as assertionless.
 */
/* eslint playwright/expect-expect: [ "warn", { "assertFunctionNames": [ "expect", "runClassicDeclineCase", "runBlocksDeclineCase", "runSetupIntentDeclineCase" ] } ] */
import type { APIResponse, Page, Request } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	ProviderSubmissionNotStartedError,
	ResourceQuarantineRequiredError,
	submitBlocksCheckout,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	CLASSIC_CHECKOUT_PATH,
	PlaywrightClassicCardCheckoutBrowser,
	normalizeClassicRejectedRequest,
	type ClassicCheckoutRecoveryState,
	type ClassicCheckoutRejectionNotice,
	type ClassicRejectedRequestEvidence,
} from '../../../utils/woopayments-native/drivers/classic-card-checkout';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import { readHighestOrderId } from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `card-decline-vocabulary` fidelity family.
 *
 * `FIDELITY-CLAIMS.md` states the claim these tests exist to make falsifiable:
 * the provider's real decline vocabulary for five fixture cards is the code set
 * native's error mapping is keyed on, each card returns its fixed top-level
 * error and decline-code pair, and native answers with the matching semantic
 * error, exactly one unpaid order, and no success-side object anywhere.
 *
 * Two things shape every test below.
 *
 * **The provider record is the assertion; the rendered sentence is the
 * corroboration.** Every client-suite row this family replaces asserted a
 * message and nothing else, which is why their recorded residual risks all say
 * some version of "a wrong internal mapping producing a similar sentence would
 * escape". The `D-PI-*` cases therefore read the exact PaymentIntent — terminal
 * status, `last_payment_error.code`, `last_payment_error.decline_code`, amount
 * and currency — and only then check what the shopper was told.
 *
 * **Classic and Blocks are two native code paths, not two skins.** The ledger
 * splits these rows across `shopper-checkout-failures.spec.ts` (classic
 * shortcode) and `shopper-wc-blocks-checkout-failures.spec.ts` (Blocks), and
 * neither surface's row may be proven by the other's test. `FIDELITY-CLAIMS.md`
 * pins one surface per `D-PI-*` case; where the ledger has rows on both
 * surfaces for the same case, the case runs once per surface and each run
 * carries only its own surface's row.
 *
 * Correlating a failed payment to its provider intent. Native does not persist
 * the intent identity synchronously on a decline:
 * `WooPaymentsApiClient::throw_api_error()` drops the `payment_intent` from the
 * provider's error body, and `WooPaymentsIntentCodec::failed_transport_outcome()`
 * builds the failed outcome with an empty provider payment ID, so
 * `WooPaymentsOutcomeMetadataMapper` writes no `_intent_id`. The identity
 * arrives with the ingested `payment_intent.payment_failed` event, which is what
 * the convergence budget below waits for. A run against a store whose provider
 * event listener is not delivering will fail here — correctly, because without
 * the intent there is no provider-fidelity claim to make.
 *
 * Limiter isolation. Core bumps the failed-transaction limiter for top-level
 * `card_declined`, `incorrect_number` and `incorrect_cvc`, and refuses the next
 * submission once five bumps accumulate in one WooCommerce session. Six of the
 * nine `D-PI-*` runs in this file bump. Every checkout case therefore runs as a
 * guest in Playwright's own fresh browser context, so each owns an empty
 * session registry by construction and cannot be turned away by a sibling's
 * declines. The mapping itself — which codes bump and which do not — is not
 * claimed here; it is fixed by `NativeWooPaymentsGatewayTest`, and no read path
 * for the session registry exists in this harness.
 */

const CHECKOUT_FAILURES_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:';
const BLOCKS_FAILURES_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:';
const ADD_METHOD_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a ';
const ADD_METHOD_SUFFIX = ' card › it should not add the card';

/** Capabilities every case in this family needs. */
const BASE_CAPABILITIES = [ 'product/payment', 'card-decline-checkout' ];
/** The classic cases additionally provision a shortcode checkout page. */
const CLASSIC_CAPABILITIES = [ ...BASE_CAPABILITIES, 'classic-checkout-page' ];
/** The My Account cases drive SetupIntents instead of checkouts. */
const SETUP_INTENT_CAPABILITIES = [
	'card-decline-setup-intent',
	'card-decline-customer-state',
];

const CURRENCY = 'USD';
const PROVIDER_CURRENCY = 'usd';
const TERMINAL_INTENT_STATUS = 'requires_payment_method';
/** Statuses an order the store made but never took money for may hold. */
const UNPAID_ORDER_STATUSES = [ 'pending', 'failed' ];
/** Statuses that would mean money moved. */
const PAID_ORDER_STATUSES = [
	'processing',
	'completed',
	'on-hold',
	'refunded',
];

// The family's fixed convergence contract.
const CONVERGENCE_BUDGET_MS = 45_000;
const CONVERGENCE_POLL_MS = 2_000;
const CONVERGENCE_QUIET_MS = 4_000;
/** The empty-attachment interval the negative SetupIntent cases require. */
const ATTACHMENT_QUIET_MS = 10_000;
const NOTICE_TIMEOUT_MS = 30_000;
const CHECKOUT_RESPONSE_TIMEOUT_MS = 60_000;

/** Native's Classic and My Account shopper-facing error region. */
const NATIVE_PAYMENT_ERROR_REGION = '#wcpay-core-payment-errors';
/** WordPress's shared assertive announcement region, used by the Blocks notice. */
const ASSERTIVE_ANNOUNCEMENT_REGION = '#a11y-speak-assertive';

interface DeclineFixture {
	/** The `FIDELITY-CLAIMS.md` case this fixture drives. */
	readonly familyCase: string;
	readonly card: ProviderTestCard;
	/** Top-level provider error code on `last_payment_error`/`last_setup_error`. */
	readonly errorCode: string;
	/** Provider decline code. Every card in this matrix returns one. */
	readonly declineCode: string;
	/** Native's own catalog sentence for this code pair. */
	readonly message: string;
}

/**
 * The five provider test cards this family is about, with the exact code pair
 * each one returns and the sentence `WooPaymentsErrorMessages` maps it to.
 *
 * Expiry and security code carry no provider meaning beyond being well-formed;
 * they match the WooPayments extension suite's fixtures so a native run and an
 * extension run can be compared field by field.
 *
 * **The decline codes below are observed, not assumed.** `FIDELITY-CLAIMS.md`
 * originally fixed `expired_card`, `incorrect_cvc` and `processing_error` as
 * returning *no* decline code. The first authorized run of this family
 * falsified that on both checkout surfaces: the provider returns a decline code
 * for all five cards, and for those three it mirrors the top-level code. The
 * claims file carries the dated correction; these values are what the provider
 * actually returned. Nothing user-visible was wrong, because
 * `WooPaymentsErrorMessages::get_shopper_message()` consults `decline_code`
 * first and all three mirrored codes are in the same catalog that the top-level
 * codes map into, so the shopper sentence is identical either way.
 */
const GENERIC_DECLINE: DeclineFixture = {
	familyCase: 'D-PI-generic',
	card: { number: '4000000000000002', expiry: '0245', securityCode: '424' },
	errorCode: 'card_declined',
	declineCode: 'generic_decline',
	message: 'Error: Your card was declined.',
};
const EXPIRED_CARD: DeclineFixture = {
	familyCase: 'D-PI-expired',
	card: { number: '4000000000000069', expiry: '0245', securityCode: '424' },
	errorCode: 'expired_card',
	declineCode: 'expired_card',
	message: 'Error: Your card has expired.',
};
const INSUFFICIENT_FUNDS: DeclineFixture = {
	familyCase: 'D-PI-insufficient',
	card: { number: '4000000000009995', expiry: '0245', securityCode: '424' },
	errorCode: 'card_declined',
	declineCode: 'insufficient_funds',
	message: 'Error: Your card has insufficient funds.',
};
const INCORRECT_CVC: DeclineFixture = {
	familyCase: 'D-PI-cvc',
	card: { number: '4000000000000127', expiry: '0245', securityCode: '424' },
	errorCode: 'incorrect_cvc',
	declineCode: 'incorrect_cvc',
	message: "Error: Your card's security code is incorrect.",
};
const PROCESSING_ERROR: DeclineFixture = {
	familyCase: 'D-PI-processing',
	card: { number: '4000000000000119', expiry: '0245', securityCode: '424' },
	errorCode: 'processing_error',
	declineCode: 'processing_error',
	message:
		'Error: An error occurred while processing your card. Try again in a little bit.',
};

interface CheckoutCase {
	readonly contractId: string;
	readonly fixture: DeclineFixture;
	/** The case's fixed order total, from `FIDELITY-CLAIMS.md`. */
	readonly price: string;
	readonly amountMinor: number;
}

/**
 * The classic shortcode half of the `D-PI-*` matrix: the five ledger rows whose
 * source is `shopper-checkout-failures.spec.ts`.
 */
const CLASSIC_GENERIC: CheckoutCase = {
	contractId: `${ CHECKOUT_FAILURES_PREFIX }47::Shopper › Checkout › Failures with various cards › should throw an error that the card was simply declined`,
	fixture: GENERIC_DECLINE,
	price: '10.01',
	amountMinor: 1001,
};
const CLASSIC_EXPIRED: CheckoutCase = {
	contractId: `${ CHECKOUT_FAILURES_PREFIX }79::Shopper › Checkout › Failures with various cards › should throw an error that the card expiration date is in the past`,
	fixture: EXPIRED_CARD,
	price: '10.02',
	amountMinor: 1002,
};
const CLASSIC_INSUFFICIENT: CheckoutCase = {
	contractId: `${ CHECKOUT_FAILURES_PREFIX }111::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to insufficient funds`,
	fixture: INSUFFICIENT_FUNDS,
	price: '10.03',
	amountMinor: 1003,
};
const CLASSIC_CVC: CheckoutCase = {
	contractId: `${ CHECKOUT_FAILURES_PREFIX }131::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to incorrect CVC number`,
	fixture: INCORRECT_CVC,
	price: '10.04',
	amountMinor: 1004,
};
const CLASSIC_PROCESSING: CheckoutCase = {
	contractId: `${ CHECKOUT_FAILURES_PREFIX }143::Shopper › Checkout › Failures with various cards › should throw an error that the card was declined due to processing error`,
	fixture: PROCESSING_ERROR,
	price: '10.05',
	amountMinor: 1005,
};

/**
 * The Blocks half. Four ledger rows, one per code pair; the processing-error
 * Blocks row is `not-dischargeable` in `fidelity-partition.tsv` and is
 * deliberately absent rather than folded into the classic case.
 */
const BLOCKS_GENERIC: CheckoutCase = {
	contractId: `${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card was declined.`,
	fixture: GENERIC_DECLINE,
	price: '10.01',
	amountMinor: 1001,
};
const BLOCKS_EXPIRED: CheckoutCase = {
	contractId: `${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card has expired.`,
	fixture: EXPIRED_CARD,
	price: '10.02',
	amountMinor: 1002,
};
const BLOCKS_INSUFFICIENT: CheckoutCase = {
	contractId: `${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card has insufficient funds.`,
	fixture: INSUFFICIENT_FUNDS,
	price: '10.03',
	amountMinor: 1003,
};
const BLOCKS_CVC: CheckoutCase = {
	contractId: `${ BLOCKS_FAILURES_PREFIX }90::WooCommerce Blocks › Checkout failures › Should show error – Your card's security code is incorrect.`,
	fixture: INCORRECT_CVC,
	price: '10.04',
	amountMinor: 1004,
};

interface SetupIntentCase {
	readonly contractId: string;
	readonly familyCase: string;
	readonly card: ProviderTestCard;
	readonly message: string;
}

/**
 * The `D-SI-*` matrix: the same five cards through one My Account SetupIntent
 * each.
 */
const SETUP_GENERIC: SetupIntentCase = {
	contractId: `${ ADD_METHOD_PREFIX }declined${ ADD_METHOD_SUFFIX }`,
	familyCase: 'D-SI-generic',
	card: GENERIC_DECLINE.card,
	message: GENERIC_DECLINE.message,
};
const SETUP_CVC: SetupIntentCase = {
	contractId: `${ ADD_METHOD_PREFIX }declined-cvc${ ADD_METHOD_SUFFIX }`,
	familyCase: 'D-SI-cvc',
	card: INCORRECT_CVC.card,
	message: INCORRECT_CVC.message,
};
const SETUP_EXPIRED: SetupIntentCase = {
	contractId: `${ ADD_METHOD_PREFIX }declined-expired${ ADD_METHOD_SUFFIX }`,
	familyCase: 'D-SI-expired',
	card: EXPIRED_CARD.card,
	message: EXPIRED_CARD.message,
};
const SETUP_FUNDS: SetupIntentCase = {
	contractId: `${ ADD_METHOD_PREFIX }declined-funds${ ADD_METHOD_SUFFIX }`,
	familyCase: 'D-SI-funds',
	card: INSUFFICIENT_FUNDS.card,
	message: INSUFFICIENT_FUNDS.message,
};
const SETUP_PROCESSING: SetupIntentCase = {
	contractId: `${ ADD_METHOD_PREFIX }declined-processing${ ADD_METHOD_SUFFIX }`,
	familyCase: 'D-SI-processing',
	card: PROCESSING_ERROR.card,
	message: PROCESSING_ERROR.message,
};

/** The grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:card-decline-vocabulary';

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * Assert an entire capability set before a provider interval opens, so an
 * incomplete approval costs nothing rather than a paid run.
 */
function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
}

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
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
		throw new Error(
			`Card-decline evidence requires one ${ label } object.`
		);
	}
	return value as Record< string, unknown >;
}

/**
 * Whether a request is the Store API checkout POST, in either permalink shape.
 */
function isStoreCheckoutRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const url = new URL( request.url() );
		const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
		return (
			url.pathname.replace( /\/+$/, '' ) ===
				'/wp-json/wc/store/v1/checkout' ||
			restRoute.replace( /\/+$/, '' ) === '/wc/store/v1/checkout'
		);
	} catch {
		return false;
	}
}

/**
 * Undo the HTML escaping core applies to a Store API error message.
 *
 * `CheckoutTrait::process_payment()` re-throws the payment failure as
 * `throw new RouteException( …, esc_html( $e->getMessage() ), 400 )`, so the
 * transport JSON carries `Error: Your card&#039;s security code is incorrect.`
 * where native's catalog sentence has a plain apostrophe. That is core encoding
 * the message for transport, not a different sentence, so the comparison
 * decodes rather than hard-coding the entity — which would leave the expectation
 * silently wrong the day core stops escaping.
 *
 * Deliberately narrow: it reverses exactly the five substitutions `esc_html()`
 * makes and nothing else, so a genuinely different string cannot be massaged
 * into a match. The shopper-facing assertions still run against the rendered
 * DOM, where a literal `&#039;` would fail to match and would be a real bug
 * rather than something this helper hides.
 */
function decodeEscapedHtml( value: string ): string {
	return value
		.replace( /&#0?39;/g, "'" )
		.replace( /&quot;/g, '"' )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' );
}

/**
 * Whether a Store API checkout body asked to save the card.
 *
 * Native's Blocks integration always sends
 * `wc-woocommerce_payments-new-payment-method` in `payment_data`, with the
 * value `false` when nothing is being saved, so key presence proves nothing and
 * an earlier `postData.includes(...)` check read every submission as a save.
 * The value is what matters, and an unrecognized encoding fails loudly rather
 * than defaulting to "not saving".
 */
function readBlocksSaveFlag( postData: string ): boolean {
	const body = JSON.parse( postData ) as {
		payment_data?: Array< { key?: unknown; value?: unknown } >;
	};
	if ( ! Array.isArray( body.payment_data ) ) {
		throw new Error(
			'The Store API checkout body carried no payment_data collection.'
		);
	}
	const entry = body.payment_data.find(
		( item ) => item.key === 'wc-woocommerce_payments-new-payment-method'
	);
	if ( entry === undefined ) {
		return false;
	}
	if ( entry.value === false || entry.value === 'false' ) {
		return false;
	}
	if ( entry.value === true || entry.value === 'true' ) {
		return true;
	}
	throw new Error(
		`The Store API save-payment-method flag carried an unrecognized value: ${ JSON.stringify(
			entry.value
		) }`
	);
}

/** Whether a request is the native add-payment-method SetupIntent AJAX call. */
function isCreateSetupIntentRequest( request: Request ): boolean {
	return (
		request.method() === 'POST' &&
		request.url().includes( 'admin-ajax.php' ) &&
		( request.postData() ?? '' ).includes( 'action=create_setup_intent' )
	);
}

interface OrderDelta {
	newOrderIds: number[];
	paidOrderIds: number[];
}

/**
 * Orders created since a baseline, read twice two seconds apart and returned
 * only when both reads agree.
 *
 * The Classic authentication driver exports a near-identical helper, and this
 * one deliberately does not reuse it: that version also tracks whether each new
 * order carries provider identifiers, and quarantines when the two reads
 * disagree. On this family both behaviours are wrong. The ingested
 * `payment_intent.payment_failed` event attaches `_intent_id` to the order at an
 * arbitrary moment inside the interval, so provider-linkage legitimately changes
 * between the reads — and quarantining the shared account for a webhook arriving
 * on time would be a false alarm. What must be stable here is only which orders
 * exist and whether any of them was paid.
 */
async function readOrderDelta(
	session: ProviderWriteSession,
	afterOrderId: number
): Promise< OrderDelta > {
	const read = async (): Promise< OrderDelta > => {
		const listed = await readJson(
			await session.adminApi.get(
				'/wp-json/wc/v3/orders?status=any&per_page=50&orderby=id&order=desc'
			),
			'WooCommerce order list'
		);
		if ( ! Array.isArray( listed ) ) {
			throw new Error( 'The order list did not return a collection.' );
		}
		const orders = listed
			.map( ( value, index ) => {
				const order = requireObject(
					value,
					`order list entry ${ index + 1 }`
				);
				return {
					id: Number( order.id ),
					status: String( order.status ),
				};
			} )
			.filter( ( order ) => order.id > afterOrderId );

		return {
			newOrderIds: orders.map( ( order ) => order.id ).toSorted(),
			paidOrderIds: orders
				.filter( ( order ) =>
					PAID_ORDER_STATUSES.includes( order.status )
				)
				.map( ( order ) => order.id )
				.toSorted(),
		};
	};

	const first = await read();
	await delay( CONVERGENCE_POLL_MS );
	const second = await read();
	expect(
		second,
		'the set of orders this submission created must be stable across two reads'
	).toEqual( first );

	return second;
}

interface FailedPaymentEvidence {
	orderStatus: string;
	orderTotal: string;
	orderCurrency: string;
	intentIdMeta: string;
	chargeIdMeta: string;
	intentionStatusMeta: string;
	intentId: string;
	intentStatus: unknown;
	intentAmount: unknown;
	intentCurrency: unknown;
	amountReceived: unknown;
	errorCode: unknown;
	declineCode: unknown;
	chargeStatuses: string[];
	capturedCharges: number;
	failureNoteCount: number;
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const entry = meta.find( ( item ) => item.key === key );
	return typeof entry?.value === 'string' ? entry.value : '';
}

/**
 * Collect every charge the provider exposes on an intent, across the three
 * shapes the payment-details controller passes through.
 */
async function readIntentCharges(
	session: ProviderWriteSession,
	intent: Record< string, unknown >
): Promise< Array< Record< string, unknown > > > {
	const charges = intent.charges;
	if (
		typeof charges === 'object' &&
		charges !== null &&
		'data' in charges &&
		Array.isArray( ( charges as { data: unknown[] } ).data )
	) {
		return ( charges as { data: unknown[] } ).data.map( ( value, index ) =>
			requireObject( value, `intent charge ${ index + 1 }` )
		);
	}
	if ( typeof intent.charge === 'object' && intent.charge !== null ) {
		return [ requireObject( intent.charge, 'intent charge' ) ];
	}
	if ( typeof intent.latest_charge === 'string' && intent.latest_charge ) {
		return [
			requireObject(
				await readJson(
					await session.adminApi.get(
						`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
							intent.latest_charge
						) }`
					),
					`provider charge ${ intent.latest_charge }`
				),
				'provider charge'
			),
		];
	}
	return [];
}

/**
 * One complete read of everything this family asserts about a failed payment:
 * the local order, the provider intent it names, and the failure effect the
 * order carries.
 */
async function readFailedPaymentEvidence(
	session: ProviderWriteSession,
	orderId: number
): Promise< FailedPaymentEvidence > {
	const order = requireObject(
		await readJson(
			await session.adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		),
		'order'
	);
	const intentIdMeta = orderMeta( order, '_intent_id' );

	const notes = await readJson(
		await session.adminApi.get(
			`/wp-json/wc/v3/orders/${ orderId }/notes?context=edit&per_page=100`
		),
		`WooCommerce order ${ orderId } notes`
	);
	if ( ! Array.isArray( notes ) ) {
		throw new Error( 'The order notes route did not return a collection.' );
	}
	// The one local effect an ingested `payment_intent.payment_failed` applies.
	// Counting it is how "no duplicate local failure effect" is observed.
	const failureNoteCount = notes.filter( ( value ) => {
		const note = ( value as { note?: unknown } ).note;
		return (
			typeof note === 'string' &&
			note.includes( '<strong>failed</strong> using WooPayments' ) &&
			( intentIdMeta === '' || note.includes( intentIdMeta ) )
		);
	} ).length;

	const base = {
		orderStatus: String( order.status ),
		orderTotal: String( order.total ),
		orderCurrency: String( order.currency ).toUpperCase(),
		intentIdMeta,
		chargeIdMeta: orderMeta( order, '_charge_id' ),
		intentionStatusMeta: orderMeta( order, '_intention_status' ),
		failureNoteCount,
	};

	if ( intentIdMeta === '' ) {
		return {
			...base,
			intentId: '',
			intentStatus: null,
			intentAmount: null,
			intentCurrency: null,
			amountReceived: null,
			errorCode: null,
			declineCode: null,
			chargeStatuses: [],
			capturedCharges: 0,
		};
	}

	const intent = requireObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					intentIdMeta
				) }`
			),
			`provider intent ${ intentIdMeta }`
		),
		'provider intent'
	);
	const lastPaymentError =
		typeof intent.last_payment_error === 'object' &&
		intent.last_payment_error !== null
			? ( intent.last_payment_error as Record< string, unknown > )
			: {};
	const charges = await readIntentCharges( session, intent );

	return {
		...base,
		intentId: String( intent.id ),
		intentStatus: intent.status,
		intentAmount: intent.amount,
		intentCurrency:
			typeof intent.currency === 'string'
				? intent.currency.toLowerCase()
				: intent.currency,
		amountReceived: intent.amount_received ?? null,
		errorCode: lastPaymentError.code ?? null,
		declineCode: lastPaymentError.decline_code ?? null,
		chargeStatuses: charges.map( ( charge ) => String( charge.status ) ),
		capturedCharges: charges.filter(
			( charge ) => charge.captured === true
		).length,
	};
}

/**
 * Poll the exact order and its provider intent on the family's fixed budget,
 * and return the state only once it has stopped moving.
 *
 * Two consecutive identical terminal reads, then a four-second quiet interval
 * that must change nothing: a charge, capture or second failure effect landing
 * after the assertions would otherwise go unseen.
 */
async function convergeFailedPayment(
	session: ProviderWriteSession,
	orderId: number
): Promise< FailedPaymentEvidence > {
	const deadline = Date.now() + CONVERGENCE_BUDGET_MS;
	let previous = '';
	let converged: FailedPaymentEvidence | undefined;

	for (;;) {
		const current = await readFailedPaymentEvidence( session, orderId );
		const serialized = JSON.stringify( current );
		if (
			current.intentId !== '' &&
			current.intentStatus === TERMINAL_INTENT_STATUS &&
			serialized === previous
		) {
			converged = current;
			break;
		}
		previous = serialized;

		if ( Date.now() >= deadline ) {
			throw new Error(
				`Order ${ orderId } did not reach a stable terminal failed payment within ${ CONVERGENCE_BUDGET_MS }ms; ` +
					`last read: ${ serialized }\n` +
					'An empty intentIdMeta here almost always means the provider event listener is not running. ' +
					'Native persists no intent identity on a decline, so the only thing that ever writes _intent_id ' +
					'onto a failed order is the ingested payment_intent.payment_failed event, and that event only ' +
					'reaches the store while the operator-run listener (`wpcom-local transact listen`) is forwarding ' +
					'Stripe events into local WPCOM. The pull fallback cannot substitute: with the listener down the ' +
					'platform never receives the event, so it has none queued to hand back.'
			);
		}
		await delay( CONVERGENCE_POLL_MS );
	}

	await delay( CONVERGENCE_QUIET_MS );
	const afterQuietInterval = await readFailedPaymentEvidence(
		session,
		orderId
	);
	expect(
		afterQuietInterval,
		'nothing may happen to the failed payment during the quiet interval'
	).toEqual( converged );

	return converged;
}

/**
 * Every sentence native produces when it refuses a submission *itself*, before
 * or instead of asking the provider.
 *
 * A decline test that silently passes on one of these proves nothing: no
 * PaymentIntent was ever created, so the provider's vocabulary was never
 * exercised. Enumerating them turns that failure mode into a named diagnosis
 * instead of a confusing mismatch against the expected decline sentence.
 */
const NATIVE_LOCAL_REFUSALS = [
	// Card-testing protection rejected the checkout before creating a payment
	// context (`NativeWooPaymentsGateway::get_fraud_prevention_error_message`).
	"We're not able to process this payment. Please refresh the page and try again.",
	// The same guard on the My Account add-payment-method form.
	"We're not able to add this payment method. Please refresh the page and try again.",
	// The failed-transaction rate limiter refused this session's next attempt.
	'Your payment was not processed.',
	// `WooPaymentsErrorMessages::get_generic_message()` — reached when no
	// provider code was mapped at all, including non-card_error transport
	// failures.
	"We're not able to process this request. Please refresh the page and try again.",
];

/**
 * Prove the submission reached the provider and came back declined.
 *
 * This is the property the family is about, and it is deliberately independent
 * of card-testing-protection state. An earlier version of this guard asserted
 * that the submitted request carried a fraud-prevention token, which conflated
 * two different things: with protection off, native issues no session token at
 * all, so the field is legitimately empty *and* the submission is admitted
 * because the token check is skipped. That guard read the store's
 * configuration, not this submission's fate. What distinguishes the two is
 * whose vocabulary came back — the provider's mapped decline for this
 * fixture's code pair, or one of native's own refusals above.
 */
function expectProviderDerivedDecline(
	observed: readonly string[],
	expectedMessage: string,
	label: string
): void {
	const localRefusal = NATIVE_LOCAL_REFUSALS.find( ( refusal ) =>
		observed.some( ( message ) => message.includes( refusal ) )
	);
	expect(
		localRefusal,
		`${ label }: the submission must have reached the provider; native refused it locally instead, so no provider intent exists to make a fidelity claim about`
	).toBeUndefined();
	expect(
		observed,
		`${ label }: the store must answer with the provider decline for this fixture`
	).toContain( expectedMessage );
}

/**
 * The whole provider-side and local-side oracle for one declined checkout.
 */
function expectDeclinedPaymentGraph(
	evidence: FailedPaymentEvidence,
	checkoutCase: CheckoutCase
): void {
	const { fixture } = checkoutCase;

	// The provider record first. This is the half every row this family
	// replaces was blind to.
	expect(
		evidence.intentStatus,
		'the declined intent must end awaiting a new payment method'
	).toBe( TERMINAL_INTENT_STATUS );
	expect(
		evidence.errorCode,
		`${ fixture.card.number } must return top-level ${ fixture.errorCode }`
	).toBe( fixture.errorCode );
	// Exact pair, not "some decline code": the oracle is as strong as the one
	// the claim originally stated, just true. A card that starts returning a
	// different decline code must fail here rather than pass a loosened check.
	expect(
		evidence.declineCode,
		`${ fixture.card.number } must return decline code ${ fixture.declineCode }`
	).toBe( fixture.declineCode );
	expect( evidence.intentAmount ).toBe( checkoutCase.amountMinor );
	expect( evidence.intentCurrency ).toBe( PROVIDER_CURRENCY );

	// The no-success graph.
	expect(
		evidence.chargeStatuses.filter( ( status ) => status === 'succeeded' ),
		'a declined intent must carry no succeeded charge'
	).toEqual( [] );
	expect(
		evidence.capturedCharges,
		'a declined intent must carry no captured charge'
	).toBe( 0 );
	expect( [ 0, null ] ).toContain( evidence.amountReceived );
	expect(
		evidence.chargeIdMeta,
		'a declined order must carry no charge identity'
	).toBe( '' );
	expect( UNPAID_ORDER_STATUSES ).toContain( evidence.orderStatus );
	expect( evidence.orderTotal ).toBe( checkoutCase.price );
	expect( evidence.orderCurrency ).toBe( CURRENCY );
	expect(
		evidence.failureNoteCount,
		'one submission must leave at most one local failure effect'
	).toBeLessThanOrEqual( 1 );
	// Corroboration only, never a substitute for the provider read above.
	// `WooPaymentsOutcomeMetadataMapper::get_default_intention_status()` writes
	// this synchronously for *any* failed outcome, including one where native
	// never reached the provider at all, so it says what native concluded rather
	// than what the provider returned. Observed present on every real run.
	if ( evidence.intentionStatusMeta !== '' ) {
		expect( evidence.intentionStatusMeta ).toBe( TERMINAL_INTENT_STATUS );
	}
}

interface ClassicRejection {
	request: ClassicRejectedRequestEvidence;
	status: number;
	result: unknown;
	messages: string;
	notice: ClassicCheckoutRejectionNotice;
	recovery: ClassicCheckoutRecoveryState;
	requestCount: number;
	responseCount: number;
}

/**
 * Drive one guest classic-shortcode checkout with a declining card.
 *
 * Composed from the Classic checkout driver's own primitives rather than from
 * `submitClassicCardAuthentication`, which requires the customer-action
 * confirmation hash a decline never produces, and from
 * `submitClassicTokenlessCheckout`, which removes the session token this case
 * must carry.
 */
async function submitClassicDecline(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	pageId: number,
	fixture: DeclineFixture
): Promise< ClassicRejection > {
	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		pageId
	);

	await browser.preflightClassicPage( CLASSIC_CHECKOUT_PATH, pageId );
	let addActivations = 0;
	await browser.addProductOnce( product.id, async ( write ) => {
		addActivations += 1;
		expect(
			addActivations,
			'Add to cart must be activated exactly once'
		).toBe( 1 );
		return session.performWrite( write );
	} );
	await browser.openClassicCheckout( CLASSIC_CHECKOUT_PATH );
	await browser.fillBillingDetails( session.runId );
	await browser.selectWooPaymentsCard();
	await browser.fillTestCard( fixture.card );
	await browser.prepareSubmission();

	return session.withProviderSubmissionJournal(
		`card-decline-classic-${ fixture.familyCase }`,
		async () => {
			let activations = 0;
			const observation = await browser.observeSubmissionInterval(
				async ( activate ) => {
					activations += 1;
					if ( activations !== 1 ) {
						throw new ResourceQuarantineRequiredError(
							'A classic decline must activate Place order exactly once.',
							'uncertain-provider-write'
						);
					}
					await session.performWrite( activate );
				},
				async () => {
					const shown = await browser.waitForCheckoutRejectionNotice(
						NOTICE_TIMEOUT_MS
					);
					if ( ! shown ) {
						throw new ResourceQuarantineRequiredError(
							'A declining classic submission produced no rejection notice, so its outcome is unknown.',
							'uncertain-provider-write'
						);
					}
					return {
						notice: await browser.readCheckoutRejectionNotice(),
						recovery: await browser.readCheckoutRecoveryState(),
					};
				}
			);

			if (
				observation.dispatch.requests.length !== 1 ||
				observation.dispatch.responses.length !== 1
			) {
				throw new ResourceQuarantineRequiredError(
					`A classic decline observed ${ observation.dispatch.requests.length } checkout request(s) and ${ observation.dispatch.responses.length } response(s); exactly one of each is required.`,
					'uncertain-provider-write'
				);
			}
			const rawRequest = observation.dispatch.requests[ 0 ];
			const rawResponse = observation.dispatch.responses[ 0 ];
			const body = requireObject( rawResponse.body, 'checkout response' );

			return {
				request: normalizeClassicRejectedRequest( {
					method: () => rawRequest.method,
					url: () => rawRequest.url,
					postData: () => rawRequest.body,
				} ),
				status: rawResponse.status,
				result: body.result,
				messages:
					typeof body.messages === 'string' ? body.messages : '',
				notice: observation.result.notice,
				recovery: observation.result.recovery,
				requestCount: observation.dispatch.requests.length,
				responseCount: observation.dispatch.responses.length,
			};
		}
	);
}

/**
 * Fill the Blocks checkout as a guest.
 *
 * The card checkout driver has an equivalent, but it is private; kept in step
 * with it by hand, as `shopper/card-authentication.spec.ts` already does.
 */
async function fillBlocksCheckoutDetails(
	page: Page,
	runId: string
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const address = ( await shipping.isVisible() ) ? shipping : billing;

	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ runId }@example.com` );
	await address
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( 'US' );
	await address.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await address
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await address
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( '123 Test Street' );
	await address
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( 'San Francisco' );
	await address
		.getByRole( 'combobox', { name: 'State', exact: true } )
		.selectOption( 'CA' );
	await address.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await address
		.getByRole( 'textbox', { name: 'Phone (optional)' } )
		.fill( '5555550100' );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
}

interface BlocksRejection {
	status: number;
	code: unknown;
	message: unknown;
	requestCount: number;
	savePaymentMethodRequested: boolean;
}

/** Drive one guest Blocks checkout with a declining card. */
async function submitBlocksDecline(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	fixture: DeclineFixture
): Promise< BlocksRejection > {
	let requestCount = 0;
	const countCheckoutRequest = ( request: Request ): void => {
		if ( isStoreCheckoutRequest( request ) ) {
			requestCount += 1;
		}
	};
	page.on( 'request', countCheckoutRequest );

	try {
		await page.goto( `?post_type=product&p=${ product.id }` );
		await session.performWrite( () =>
			page
				.getByRole( 'button', { name: 'Add to cart', exact: true } )
				.click()
		);
		await page.goto( 'checkout/' );
		await fillBlocksCheckoutDetails( page, session.runId );

		const frame = page.frameLocator(
			getBlocksCardFrameSelector( session.runtime )
		);
		await frame
			.getByRole( 'textbox', { name: 'Card number' } )
			.fill( fixture.card.number );
		await frame
			.getByRole( 'textbox', { name: /Expiration date/i } )
			.fill( fixture.card.expiry );
		await frame
			.getByRole( 'textbox', { name: 'Security code' } )
			.fill( fixture.card.securityCode );
		await page.getByRole( 'button', { name: /place order/i } ).focus();

		return await session.withProviderSubmissionJournal(
			`card-decline-blocks-${ fixture.familyCase }`,
			async () => {
				// Registered before the click: the response carries the store's
				// own verdict, and reading it after the fact would race the
				// notice the page renders from it.
				const checkoutResponse = page.waitForResponse(
					( response ) =>
						isStoreCheckoutRequest( response.request() ),
					{ timeout: CHECKOUT_RESPONSE_TIMEOUT_MS }
				);
				await submitBlocksCheckout( page, async ( button ) => {
					await session.performWrite( () => button.click() );
					return 'dispatched';
				} );

				let response;
				try {
					response = await checkoutResponse;
				} catch ( error ) {
					// Same distinction as the SetupIntent helper: a submission
					// the browser never sent reached no provider, so it closes
					// the journal rather than quarantining the account.
					if ( requestCount === 0 ) {
						throw new ProviderSubmissionNotStartedError(
							'The Blocks submission never dispatched a checkout request, so nothing reached the provider.',
							{ cause: error }
						);
					}
					throw new ResourceQuarantineRequiredError(
						`A declining Blocks submission dispatched ${ requestCount } checkout request(s) and saw no response, so its outcome is unknown.`,
						'uncertain-provider-write',
						error
					);
				}
				const body = requireObject(
					await response.json(),
					'Store API checkout response'
				);

				return {
					status: response.status(),
					code: body.code,
					message:
						typeof body.message === 'string'
							? decodeEscapedHtml( body.message )
							: body.message,
					requestCount,
					savePaymentMethodRequested: readBlocksSaveFlag(
						response.request().postData() ?? ''
					),
				};
			}
		);
	} finally {
		page.off( 'request', countCheckoutRequest );
	}
}

interface RunOwnedShopper {
	id: number;
	username: string;
	password: string;
}

/**
 * A shopper account this case owns outright, created for it and deleted after.
 *
 * `FIDELITY-CLAIMS.md` specifies five independent fresh My Account customers,
 * one per `D-SI-*` case, and it is right to. An earlier revision of this file
 * ran all five against the standing E2E customer and compared an exact recorded
 * baseline, which is a weaker oracle — a delta rather than an absolute — and,
 * more importantly, it was observed to trip the platform's own
 * `wcpay_card_testing_prevention` after four consecutive declines on one
 * provider customer. That surfaces as native's generic message (the platform
 * error is not a `card_error`, so `WooPaymentsErrorMessages` falls through to
 * the generic sentence) and fails the case for a reason that has nothing to do
 * with the card under test. A fresh shopper per case gives each one its own
 * provider customer, and turns every assertion below from "nothing changed"
 * into "there is nothing here at all".
 */
async function createRunOwnedShopper(
	session: ProviderWriteSession,
	setupCase: SetupIntentCase
): Promise< RunOwnedShopper > {
	await session.assertCanWrite();
	// The run ID is unique per test, so one short slice of it plus the case
	// name keeps the login inside WordPress's 60-character limit while staying
	// attributable to this exact run.
	const runSlice = session.runId.replace( 'woopayments-', '' ).slice( 0, 8 );
	const caseSlug = setupCase.familyCase.replace( 'D-SI-', '' );
	const username = `wcdecl-${ runSlice }-${ caseSlug }`;
	// Derived rather than shared: a throwaway credential for one local-store
	// account that exists for the length of one case.
	const password = `woopayments-e2e-${ runSlice }`;

	const created = requireObject(
		await readJson(
			await session.performWrite( () =>
				session.adminApi.post( '/wp-json/wc/v3/customers', {
					data: {
						email: `${ username }@example.com`,
						username,
						password,
						first_name: 'E2E',
						last_name: 'WooPayments',
					},
				} )
			),
			`run-owned shopper ${ username } creation`
		),
		'created customer'
	);
	if ( ! Number.isSafeInteger( created.id ) || Number( created.id ) <= 0 ) {
		throw new Error(
			`Run-owned shopper ${ username } creation returned no usable ID, so nothing here could remove it.`
		);
	}

	return { id: created.id as number, username, password };
}

/**
 * Remove the run-owned shopper and prove it is gone.
 *
 * Deleting the WordPress user removes any local token with it. The provider
 * customer the failed attempt created is left behind deliberately: it holds no
 * attached payment method — that is precisely what the case asserts — so it is
 * inert, and the family's cleanup contract retains provider-side records under
 * their run rather than deleting them.
 */
async function deleteRunOwnedShopper(
	session: ProviderWriteSession,
	shopper: RunOwnedShopper
): Promise< void > {
	const response = await session.performWrite( () =>
		session.adminApi.delete( `/wp-json/wc/v3/customers/${ shopper.id }`, {
			params: { force: true, reassign: 0 },
			failOnStatusCode: false,
		} )
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Run-owned shopper ${ shopper.username } (${
				shopper.id
			}) could not be deleted: HTTP ${ response.status() } ${ await response.text() }`
		);
	}

	const readBack = await session.adminApi.get(
		`/wp-json/wc/v3/customers/${ shopper.id }`,
		{ failOnStatusCode: false }
	);
	if ( readBack.status() !== 404 ) {
		throw new Error(
			`Run-owned shopper ${ shopper.username } (${
				shopper.id
			}) still exists after deletion: HTTP ${ readBack.status() }`
		);
	}
}

/** Sign the browser in as a run-owned shopper. */
async function logInAsShopper(
	page: Page,
	shopper: RunOwnedShopper
): Promise< void > {
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await page
		.getByLabel( 'Username or Email Address' )
		.fill( shopper.username );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( shopper.password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	// Prove the session belongs to this shopper before anything is submitted
	// under it; a failed login would otherwise drive the add-payment-method
	// form as whoever the browser was last.
	await page.goto( 'my-account/edit-account/' );
	await expect(
		page.getByRole( 'textbox', { name: /Email address/i } )
	).toHaveValue( `${ shopper.username }@example.com` );
}

interface ShopperProviderState {
	providerCustomerId: string;
	attachedPaymentMethodIds: string[];
	localTokenIds: number[];
}

/**
 * Everything this family asserts about one shopper's saved-method state: the
 * local tokens they hold, and the payment methods the provider has attached to
 * their customer.
 *
 * Read through the harness route by username rather than through the
 * saved-card driver, whose reader is bound to the standing E2E customer.
 */
async function readShopperProviderState(
	session: ProviderWriteSession,
	username: string
): Promise< ShopperProviderState > {
	const evidence = requireObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?customer_username=${ encodeURIComponent(
					username
				) }`
			),
			`saved-card evidence for ${ username }`
		),
		'saved-card evidence'
	);
	if ( ! Array.isArray( evidence.tokens ) ) {
		throw new Error(
			`Saved-card evidence for ${ username } carried no token collection.`
		);
	}
	const localTokenIds = evidence.tokens
		.map( ( value, index ) => {
			const token = requireObject(
				value,
				`saved-card token ${ index + 1 }`
			);
			if (
				! Number.isSafeInteger( token.token_id ) ||
				Number( token.token_id ) <= 0
			) {
				throw new Error(
					`Saved-card token ${
						index + 1
					} for ${ username } has no exact ID.`
				);
			}
			return token.token_id as number;
		} )
		.toSorted();

	const providerCustomerId =
		typeof evidence.provider_customer_id === 'string'
			? evidence.provider_customer_id
			: '';
	if ( providerCustomerId === '' ) {
		return {
			providerCustomerId,
			attachedPaymentMethodIds: [],
			localTokenIds,
		};
	}

	const listed = await readJson(
		await session.adminApi.get(
			`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		),
		`provider customer ${ providerCustomerId } payment methods`
	);
	if ( ! Array.isArray( listed ) ) {
		throw new Error(
			'The provider payment-method route did not return a collection.'
		);
	}

	return {
		providerCustomerId,
		attachedPaymentMethodIds: listed
			.map( ( value, index ) =>
				String(
					requireObject( value, `payment method ${ index + 1 }` ).id
				)
			)
			.toSorted(),
		localTokenIds,
	};
}

interface SetupIntentRejection {
	status: number;
	message: unknown;
	success: unknown;
	requestCount: number;
	paymentMethodId: string;
}

/**
 * Drive one My Account add-payment-method submission with a declining card and
 * report exactly what the store answered.
 *
 * The provider's SetupIntent object itself is unreachable from here: native
 * answers a declined `create_and_confirm_setup_intention` with only the mapped
 * shopper message, and no route reads a SetupIntent by ID. What the response
 * does carry is code-derived — `WooPaymentsErrorMessages::get_shopper_message()`
 * keys it on the provider's `error.type`, `error.code` and `error.decline_code`,
 * and the catalog is injective over this family's five codes — so the sentence
 * is a witness for the code, and the provider-customer attachment state below
 * is the direct provider-side observation.
 */
async function submitDecliningPaymentMethod(
	session: ProviderWriteSession,
	page: Page,
	setupCase: SetupIntentCase,
	shopper: RunOwnedShopper
): Promise< SetupIntentRejection > {
	const { card } = setupCase;
	let requestCount = 0;
	let paymentMethodId = '';
	const countSetupIntentRequest = ( request: Request ): void => {
		if ( ! isCreateSetupIntentRequest( request ) ) {
			return;
		}
		requestCount += 1;
		paymentMethodId =
			new URLSearchParams( request.postData() ?? '' ).get(
				'wcpay-payment-method'
			) ?? '';
	};
	page.on( 'request', countSetupIntentRequest );

	try {
		await logInAsShopper( page, shopper );
		// The same navigation the saved-card driver uses, so this negative case
		// meets exactly the form its positive twin is known to drive.
		await page.goto( 'my-account/payment-methods/' );
		await page.getByRole( 'link', { name: /add payment method/i } ).click();

		// WooPayments is the store's only gateway, so core renders its radio
		// pre-selected. Assert that rather than clicking a label: there is no
		// choice to make, and a run against a store offering a second gateway
		// must fail here instead of adding a card through something else.
		const gateway = page.locator( 'input[name="payment_method"]' );
		await expect( gateway ).toHaveCount( 1 );
		await expect( gateway ).toHaveValue( 'woocommerce_payments' );
		await expect( gateway ).toBeChecked();

		// Native's own mount, not the client plugin's. Binding the frame to
		// `#wcpay-core-payment-element` keeps a client-runtime page from
		// silently satisfying this locator.
		const cardFrame = page.frameLocator(
			'#wcpay-core-payment-element iframe[name^="__privateStripeFrame"]'
		);
		await cardFrame
			.getByRole( 'textbox', { name: 'Card number' } )
			.fill( card.number );
		await cardFrame
			.getByRole( 'textbox', { name: /Expiration date/i } )
			.fill( card.expiry );
		await cardFrame
			.getByRole( 'textbox', { name: 'Security code' } )
			.fill( card.securityCode );
		await cardFrame
			.getByRole( 'combobox', { name: /country/i } )
			.selectOption( 'US' );
		// The postal field only exists once a country that uses one is chosen.
		await cardFrame
			.getByRole( 'textbox', { name: /zip|postal/i } )
			.fill( '90210' );

		return await session.withProviderSubmissionJournal(
			`card-decline-setup-intent-${ setupCase.familyCase }`,
			async () => {
				const setupIntentResponse = page.waitForResponse(
					( response ) =>
						isCreateSetupIntentRequest( response.request() ),
					{ timeout: CHECKOUT_RESPONSE_TIMEOUT_MS }
				);
				await session.performWrite( () =>
					page
						.getByRole( 'button', {
							name: 'Add payment method',
							exact: true,
						} )
						.click()
				);

				let response;
				try {
					response = await setupIntentResponse;
				} catch ( error ) {
					// Tell "never dispatched" apart from "dispatched, outcome
					// unknown". The native script calls Stripe.js
					// `createPaymentMethod()` before it POSTs
					// `create_setup_intent`, so a client-side failure means no
					// request left the browser and nothing reached the
					// provider. Quarantining the shared account for that is a
					// false alarm that blocks every later test; the journal
					// just needs to close cleanly.
					if ( requestCount === 0 ) {
						throw new ProviderSubmissionNotStartedError(
							'The add-payment-method submission never dispatched a SetupIntent request, so nothing reached the provider.',
							{ cause: error }
						);
					}
					throw new ResourceQuarantineRequiredError(
						`A declining add-payment-method submission dispatched ${ requestCount } SetupIntent request(s) and saw no response, so its outcome is unknown.`,
						'uncertain-provider-write',
						error
					);
				}
				const body = requireObject(
					await response.json(),
					'SetupIntent response'
				);
				const data = requireObject(
					body.data ?? {},
					'SetupIntent response data'
				);
				const error = requireObject(
					data.error ?? {},
					'SetupIntent response error'
				);

				return {
					status: response.status(),
					message: error.message ?? null,
					success: body.success,
					requestCount,
					paymentMethodId,
				};
			}
		);
	} finally {
		page.off( 'request', countSetupIntentRequest );
	}
}

/**
 * Run one classic-shortcode `D-PI-*` case end to end.
 *
 * The whole case is a runner rather than an inline body because nine of the
 * fourteen tests in this file differ only in their card, amount, code pair and
 * the ledger row they carry; sharing the body keeps those nine provably
 * identical in everything but their fixture.
 */
async function runClassicDeclineCase(
	session: ProviderWriteSession,
	page: Page,
	checkoutCase: CheckoutCase
): Promise< void > {
	requireCapabilities( session, CLASSIC_CAPABILITIES );
	await session.assertCurrentRuntimeReady( 'native' );

	await session.withProviderWriteLocks(
		{
			recordEvent: `card-decline-classic-${ checkoutCase.fixture.familyCase }`,
		},
		async () => {
			await withClassicCheckoutPage(
				session,
				session.runId,
				async ( scope ) => {
					const baselineOrderId = await readHighestOrderId( session );
					const product = await session.createOwnedProduct(
						checkoutCase.price
					);

					const rejection = await submitClassicDecline(
						session,
						page,
						product,
						scope.classicCheckout.pageId,
						checkoutCase.fixture
					);

					// One gesture, one exchange, one gateway, nothing saved.
					expect( rejection.requestCount ).toBe( 1 );
					expect( rejection.responseCount ).toBe( 1 );
					expect( rejection.request.gateway ).toBe(
						'woocommerce_payments'
					);
					expect( rejection.request.savePaymentMethod ).toBe( false );
					expect( rejection.result ).toBe( 'failure' );

					// Asserted here, before any convergence budget is spent: the
					// store came back with the provider's decline for this
					// fixture, not with one of native's own refusals. A run that
					// never reached the provider fails in seconds, and with a
					// diagnosis, instead of after 45 seconds of polling for an
					// intent that was never created.
					expect(
						rejection.notice.alertCount,
						'a rejected classic submission must render exactly one alert'
					).toBe( 1 );
					expectProviderDerivedDecline(
						rejection.notice.messages,
						checkoutCase.fixture.message,
						checkoutCase.fixture.familyCase
					);
					expect( rejection.notice.messages ).toEqual( [
						checkoutCase.fixture.message,
					] );
					expect( rejection.messages ).toContain(
						checkoutCase.fixture.message
					);

					const delta = await readOrderDelta(
						session,
						baselineOrderId
					);
					expect(
						delta.newOrderIds,
						'one declined submission must leave exactly one order'
					).toHaveLength( 1 );
					expect(
						delta.paidOrderIds,
						'a declined submission must pay for nothing'
					).toEqual( [] );

					const orderId = delta.newOrderIds[ 0 ];
					await session.setOrderRunId( orderId, session.runId );

					const evidence = await convergeFailedPayment(
						session,
						orderId
					);
					expectDeclinedPaymentGraph( evidence, checkoutCase );

					// And the shopper can still act on the same page.
					expect( rejection.recovery.onClassicCheckout ).toBe( true );
					expect(
						rejection.recovery.placeOrderEnabled,
						'a declined shopper must be able to try another method'
					).toBe( true );
				}
			);
		}
	);
}

/** Run one Blocks `D-PI-*` case end to end. */
async function runBlocksDeclineCase(
	session: ProviderWriteSession,
	page: Page,
	checkoutCase: CheckoutCase
): Promise< void > {
	requireCapabilities( session, BASE_CAPABILITIES );
	await session.assertCurrentRuntimeReady( 'native' );

	await session.withProviderWriteLocks(
		{
			recordEvent: `card-decline-blocks-${ checkoutCase.fixture.familyCase }`,
		},
		async () => {
			const baselineOrderId = await readHighestOrderId( session );
			const product = await session.createOwnedProduct(
				checkoutCase.price
			);

			const rejection = await submitBlocksDecline(
				session,
				page,
				product,
				checkoutCase.fixture
			);

			expect(
				rejection.requestCount,
				'one Place order activation must ask the Store API exactly once'
			).toBe( 1 );
			expect( rejection.status ).toBe( 400 );
			// Core wraps this twice and the outer wrapper wins. A declined
			// gateway result reaches `StoreApi\Legacy::process_legacy_payment()`,
			// which turns the queued notice into a `RouteException` coded
			// `woocommerce_rest_payment_error`; that exception then propagates
			// into `CheckoutTrait::process_payment()`'s `catch ( \Exception )`,
			// which re-wraps it as `woocommerce_rest_checkout_process_payment_error`
			// while preserving the message. Both are core's own coding of the
			// same event, so the code is asserted as a set and the message —
			// which survives the re-wrap intact — carries the discrimination.
			expect( [
				'woocommerce_rest_payment_error',
				'woocommerce_rest_checkout_process_payment_error',
			] ).toContain( rejection.code );
			expect(
				rejection.savePaymentMethodRequested,
				'a purchase that saves nothing must not ask to save'
			).toBe( false );

			// Before any convergence budget is spent: the Store API came back
			// with the provider's decline for this fixture, not with one of
			// native's own refusals. Card-testing protection produces the same
			// `woocommerce_rest_payment_error` code, so the code alone does not
			// distinguish a declined payment from a submission never sent.
			expectProviderDerivedDecline(
				[ String( rejection.message ) ],
				checkoutCase.fixture.message,
				checkoutCase.fixture.familyCase
			);
			expect( rejection.message ).toBe( checkoutCase.fixture.message );

			const delta = await readOrderDelta( session, baselineOrderId );
			expect(
				delta.newOrderIds,
				'one declined submission must leave exactly one order'
			).toHaveLength( 1 );
			expect(
				delta.paidOrderIds,
				'a declined submission must pay for nothing'
			).toEqual( [] );

			const orderId = delta.newOrderIds[ 0 ];
			await session.setOrderRunId( orderId, session.runId );

			const evidence = await convergeFailedPayment( session, orderId );
			expectDeclinedPaymentGraph( evidence, checkoutCase );

			// Corroboration: Blocks both showed and announced the sentence.
			// WordPress announces through a shared off-screen region rather than
			// an alert role on the notice, so losing the announcement fails here
			// rather than passing because the text is on screen somewhere.
			await expect(
				page.getByText( checkoutCase.fixture.message ).first()
			).toBeVisible();
			await expect(
				page.locator( ASSERTIVE_ANNOUNCEMENT_REGION )
			).toHaveText( checkoutCase.fixture.message );
		}
	);
}

/** Run one My Account `D-SI-*` case end to end. */
async function runSetupIntentDeclineCase(
	session: ProviderWriteSession,
	page: Page,
	setupCase: SetupIntentCase
): Promise< void > {
	requireCapabilities( session, SETUP_INTENT_CAPABILITIES );
	await session.assertCurrentRuntimeReady( 'native' );

	await session.withProviderWriteLocks(
		{
			recordEvent: `card-decline-setup-intent-${ setupCase.familyCase }`,
		},
		async () => {
			const baselineOrderId = await readHighestOrderId( session );
			const shopper = await createRunOwnedShopper( session, setupCase );
			let primaryError: unknown;

			try {
				// A genuinely fresh shopper: no local token, and not yet known
				// to the provider at all. This is what makes every assertion
				// after the submission absolute rather than a delta.
				const before = await readShopperProviderState(
					session,
					shopper.username
				);
				expect(
					before.localTokenIds,
					'a fresh shopper must hold no saved card'
				).toEqual( [] );
				expect(
					before.providerCustomerId,
					'a fresh shopper must not yet be known to the provider'
				).toBe( '' );

				const rejection = await submitDecliningPaymentMethod(
					session,
					page,
					setupCase,
					shopper
				);

				expect(
					rejection.requestCount,
					'one Add payment method activation must ask the store exactly once'
				).toBe( 1 );
				expect(
					rejection.paymentMethodId,
					'the submission must have created one provider payment method to confirm'
				).not.toBe( '' );
				expect( rejection.success ).toBe( false );
				expect( rejection.status ).toBe( 502 );
				// Native derives this sentence from the provider's own error
				// type, code and decline code, and its catalog is injective
				// over this family's five codes, so the sentence identifies the
				// code the provider returned — and rules out the local
				// refusals, which never reach
				// `create_and_confirm_setup_intention` at all.
				expectProviderDerivedDecline(
					[ String( rejection.message ) ],
					setupCase.message,
					setupCase.familyCase
				);
				expect( rejection.message ).toBe( setupCase.message );

				// The provider-side observation the ledger rows ask for. The
				// attempt created this shopper's provider customer before
				// confirming against it, so the customer must now exist and
				// must hold nothing: the declined method never attached.
				const after = await readShopperProviderState(
					session,
					shopper.username
				);
				expect(
					after.providerCustomerId,
					'the attempt must have created the provider customer it confirmed against'
				).not.toBe( '' );
				expect(
					after.attachedPaymentMethodIds,
					'a declined SetupIntent must attach no payment method to the provider customer'
				).toEqual( [] );
				expect(
					after.localTokenIds,
					'a declined SetupIntent must create no Woo token'
				).toEqual( [] );

				// A late attachment cannot hide inside the quiet interval.
				await delay( ATTACHMENT_QUIET_MS );
				expect(
					await readShopperProviderState( session, shopper.username ),
					'no attachment or token may appear after the rejection'
				).toEqual( after );

				// And nothing else was created either.
				const delta = await readOrderDelta( session, baselineOrderId );
				expect(
					delta.newOrderIds,
					'a failed SetupIntent must create no order'
				).toEqual( [] );

				// The shopper was told, in native's own error region.
				const errorRegion = page.locator( NATIVE_PAYMENT_ERROR_REGION );
				await expect( errorRegion ).toBeVisible();
				await expect( errorRegion ).toHaveText( setupCase.message );
				await expect( errorRegion ).toHaveAttribute( 'role', 'alert' );
			} catch ( error ) {
				primaryError = error;
			}

			// Removal always runs, but it must never replace the failure that
			// brought us here: a plain `finally` that throws would report a
			// cleanup problem and discard the assertion that actually failed.
			let cleanupError: unknown;
			try {
				await deleteRunOwnedShopper( session, shopper );
			} catch ( error ) {
				cleanupError = error;
			}

			if ( primaryError !== undefined ) {
				if ( cleanupError !== undefined ) {
					console.error(
						'Run-owned shopper removal also failed after the primary failure:',
						cleanupError
					);
				}
				throw primaryError;
			}
			if ( cleanupError !== undefined ) {
				throw cleanupError;
			}
		}
	);
}

const PROVIDER_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

test.describe( 'WooPayments native card decline vocabulary', () => {
	// Independent, not serial. Overlap is already impossible — the provider
	// project runs one worker and every case holds the account and store locks
	// for its whole interval — and `withClassicCheckoutPage()` creates, verifies
	// and removes the shared `classic-checkout` slug per case, so the classic
	// cases do not depend on each other either. Serial mode was tried and
	// removed: each case owns its own product, order and provider objects, so a
	// failure in one says nothing about the next, and skipping the remaining
	// thirteen hid which parts of the family actually work. A run that leaves
	// the store genuinely unclear quarantines instead, which is the mechanism
	// that is supposed to stop a suite mid-flight.
	test.describe.configure( { timeout: 300_000 } );

	test(
		'A classic-checkout submission with the generic-decline card leaves one PaymentIntent for 1001 usd in requires_payment_method with error card_declined and decline code generic_decline, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CLASSIC_GENERIC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runClassicDeclineCase( pilotRuntime, page, CLASSIC_GENERIC );
		}
	);

	test(
		'A classic-checkout submission with the expired card leaves one PaymentIntent for 1002 usd in requires_payment_method with error expired_card and decline code expired_card, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CLASSIC_EXPIRED.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runClassicDeclineCase( pilotRuntime, page, CLASSIC_EXPIRED );
		}
	);

	test(
		'A classic-checkout submission with the insufficient-funds card leaves one PaymentIntent for 1003 usd in requires_payment_method with error card_declined and decline code insufficient_funds, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CLASSIC_INSUFFICIENT.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runClassicDeclineCase(
				pilotRuntime,
				page,
				CLASSIC_INSUFFICIENT
			);
		}
	);

	test(
		'A classic-checkout submission with the incorrect-CVC card leaves one PaymentIntent for 1004 usd in requires_payment_method with error incorrect_cvc and decline code incorrect_cvc, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CLASSIC_CVC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runClassicDeclineCase( pilotRuntime, page, CLASSIC_CVC );
		}
	);

	test(
		'A classic-checkout submission with the processing-error card leaves one PaymentIntent for 1005 usd in requires_payment_method with error processing_error and decline code processing_error, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CLASSIC_PROCESSING.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runClassicDeclineCase(
				pilotRuntime,
				page,
				CLASSIC_PROCESSING
			);
		}
	);

	test(
		'A Blocks-checkout submission with the generic-decline card leaves one PaymentIntent for 1001 usd in requires_payment_method with error card_declined and decline code generic_decline, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: BLOCKS_GENERIC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runBlocksDeclineCase( pilotRuntime, page, BLOCKS_GENERIC );
		}
	);

	test(
		'A Blocks-checkout submission with the expired card leaves one PaymentIntent for 1002 usd in requires_payment_method with error expired_card and decline code expired_card, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: BLOCKS_EXPIRED.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runBlocksDeclineCase( pilotRuntime, page, BLOCKS_EXPIRED );
		}
	);

	test(
		'A Blocks-checkout submission with the insufficient-funds card leaves one PaymentIntent for 1003 usd in requires_payment_method with error card_declined and decline code insufficient_funds, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: BLOCKS_INSUFFICIENT.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runBlocksDeclineCase(
				pilotRuntime,
				page,
				BLOCKS_INSUFFICIENT
			);
		}
	);

	test(
		'A Blocks-checkout submission with the incorrect-CVC card leaves one PaymentIntent for 1004 usd in requires_payment_method with error incorrect_cvc and decline code incorrect_cvc, one unpaid run-owned order, and no charge, capture, paid order, or token',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: BLOCKS_CVC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runBlocksDeclineCase( pilotRuntime, page, BLOCKS_CVC );
		}
	);

	test(
		'Adding the generic-decline card through My Account fails its SetupIntent with the generic-decline mapping and leaves the provider customer, its attached methods, and the local token set exactly as they were',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SETUP_GENERIC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runSetupIntentDeclineCase(
				pilotRuntime,
				page,
				SETUP_GENERIC
			);
		}
	);

	test(
		'Adding the incorrect-CVC card through My Account fails its SetupIntent with the incorrect-CVC mapping and leaves the provider customer, its attached methods, and the local token set exactly as they were',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SETUP_CVC.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runSetupIntentDeclineCase( pilotRuntime, page, SETUP_CVC );
		}
	);

	test(
		'Adding the expired card through My Account fails its SetupIntent with the expired-card mapping and leaves the provider customer, its attached methods, and the local token set exactly as they were',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SETUP_EXPIRED.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runSetupIntentDeclineCase(
				pilotRuntime,
				page,
				SETUP_EXPIRED
			);
		}
	);

	test(
		'Adding the insufficient-funds card through My Account fails its SetupIntent with the insufficient-funds mapping and leaves the provider customer, its attached methods, and the local token set exactly as they were',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SETUP_FUNDS.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runSetupIntentDeclineCase( pilotRuntime, page, SETUP_FUNDS );
		}
	);

	test(
		'Adding the processing-error card through My Account fails its SetupIntent with the processing-error mapping and leaves the provider customer, its attached methods, and the local token set exactly as they were',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SETUP_PROCESSING.contractId,
				},
			],
			tag: PROVIDER_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			await runSetupIntentDeclineCase(
				pilotRuntime,
				page,
				SETUP_PROCESSING
			);
		}
	);
} );
