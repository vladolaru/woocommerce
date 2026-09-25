/*
 * Five of this family's tests are one shared body driven by a different
 * fixture, so each test's assertions live in the runner it calls rather than
 * inline. Name that runner for the assertion rule; without this every test
 * here reads as assertionless.
 */
/* eslint playwright/expect-expect: [ "warn", { "assertFunctionNames": [ "expect", "runSetupIntentDeclineCase" ] } ] */
import type { APIResponse, Page, Request } from '@playwright/test';

import {
	expect,
	ProviderSubmissionNotStartedError,
	ResourceQuarantineRequiredError,
	tags,
	test,
	waitForWordPressLoginReady,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	CARD_EXPIRY_FIELD_NAME,
	enterProviderCardEntry,
} from '../../../utils/woopayments-native/drivers/card-entry';
import { readHighestOrderId } from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { readOrderIdStatusDelta } from '../../../utils/woopayments-native/drivers/failed-payment-evidence';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `card-decline-vocabulary` fidelity family: the My Account SetupIntent
 * half (`D-SI-*`).
 *
 * `FIDELITY-CLAIMS.md` states the claim these tests exist to make falsifiable:
 * the provider's real decline vocabulary for five fixture cards is the code set
 * native's error mapping is keyed on, and native answers a declined
 * `create_and_confirm_setup_intention` with the matching semantic error while
 * leaving the shopper's provider customer, attached methods and local tokens
 * exactly as they were.
 *
 * The checkout half of this family (`D-PI-*`, Classic and Blocks) moved to
 * PHPUnit in T.1 batch 2: request/decline-envelope mapping is
 * `WooPaymentsProviderGatewayAdapterTest::test_native_charge_decline_envelope_maps_each_card_code`
 * (fed by the recorded `Fixtures/rec-1-intention-declines.json`, REC-1), the
 * shopper message catalog is `WooPaymentsErrorMessagesTest`, and order
 * persistence (status, note, fraud meta) is `PaymentProcessingServiceTest`. One
 * provider-backed checkout smoke for this family lives in
 * `shopper/provider-fidelity-card-recovery.spec.ts:136`. Nothing here proves
 * the checkout path; do not read this file for that claim.
 *
 * **The provider record is the assertion; the rendered sentence is the
 * corroboration.** Every client-suite row this family replaces asserted a
 * message and nothing else, which is why their recorded residual risks all say
 * some version of "a wrong internal mapping producing a similar sentence would
 * escape". `WooPaymentsErrorMessages::get_shopper_message()` keys the sentence
 * on the provider's own `error.type`/`error.code`/`error.decline_code`, and the
 * catalog is injective over this family's five codes, so the sentence is a
 * witness for the code — the provider-side attachment/token state below is the
 * direct observation. See `DeclineFixture`'s docblock below for the
 * observed-vs-assumed correction to the decline codes themselves.
 */

const ADD_METHOD_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-payment-methods-add-fail.spec.ts:71::Payment Methods › when attempting to add a ';
const ADD_METHOD_SUFFIX = ' card › it should not add the card';

/** The My Account cases drive SetupIntents instead of checkouts. */
const SETUP_INTENT_CAPABILITIES = [
	'card-decline-setup-intent',
	'card-decline-customer-state',
];

/** The empty-attachment interval the negative SetupIntent cases require. */
const ATTACHMENT_QUIET_MS = 10_000;
const CHECKOUT_RESPONSE_TIMEOUT_MS = 60_000;

/** Native's My Account shopper-facing error region. */
const NATIVE_PAYMENT_ERROR_REGION = '#wcpay-core-payment-errors';

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

/** Whether a request is the native add-payment-method SetupIntent AJAX call. */
function isCreateSetupIntentRequest( request: Request ): boolean {
	return (
		request.method() === 'POST' &&
		request.url().includes( 'admin-ajax.php' ) &&
		( request.postData() ?? '' ).includes( 'action=create_setup_intent' )
	);
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
	await waitForWordPressLoginReady( page );
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
		// The billing pair belongs in the same entry as the card: the element
		// clears everything it holds when its deferred `elements/sessions`
		// response lands, so entering the card through the read-back contract
		// and then filling country and postcode outside it would leave those
		// two subject to the very reset the contract exists to survive.
		await enterProviderCardEntry(
			[
				{
					label: 'card number',
					locator: cardFrame.getByRole( 'textbox', {
						name: 'Card number',
					} ),
					value: card.number,
					kind: 'digits',
				},
				{
					label: 'expiry',
					locator: cardFrame.getByRole( 'textbox', {
						name: CARD_EXPIRY_FIELD_NAME,
					} ),
					value: card.expiry,
					kind: 'digits',
				},
				{
					label: 'security code',
					locator: cardFrame.getByRole( 'textbox', {
						name: 'Security code',
					} ),
					value: card.securityCode,
					kind: 'digits',
				},
				{
					label: 'country',
					locator: cardFrame.getByRole( 'combobox', {
						name: /country/i,
					} ),
					value: 'US',
					kind: 'option',
				},
				{
					// Only exists once a country that uses one is chosen, and
					// the choice above is made in the same pass.
					label: 'postcode',
					locator: cardFrame.getByRole( 'textbox', {
						name: /zip|postal/i,
					} ),
					value: '90210',
					kind: 'digits',
					optional: true,
				},
			],
			'My Account add-payment-method'
		);

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
				const delta = await readOrderIdStatusDelta(
					session,
					baselineOrderId
				);
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
	// for its whole interval — and each case creates and removes its own
	// run-owned shopper, so the five cases do not depend on each other either.
	// Serial mode was tried and removed: each case owns its own shopper and
	// provider customer, so a failure in one says nothing about the next, and
	// skipping the remaining cases hid which parts of the family actually work.
	// A run that leaves the store genuinely unclear quarantines instead, which
	// is the mechanism that is supposed to stop a suite mid-flight.
	test.describe.configure( { timeout: 300_000 } );

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
