import type { Page } from '@playwright/test';

import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
	type ProviderWriteSession,
	type SavedCardIdentity,
} from '../../../fixtures/woopayments-native';
import {
	deleteExactSavedCards,
	findSavedCardProviderCustomerId,
	getProviderPaymentMethodIds,
	getSavedCardEvidence,
	readSavedCardProviderCustomerId,
	submitNativeAddPaymentMethod,
	type NativeAddPaymentMethodObservation,
	type SavedCardToken,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import type { ProviderTestCard } from '../../../utils/woopayments-native/test-cards';

/**
 * The `saved-token-lifecycle` provider-fidelity family: the retained browser
 * smoke (T.1 batch 3).
 *
 * `FIDELITY-CLAIMS.md` states the claim this case exists to establish: a
 * `4242` SetupIntent at the real provider yields exactly one
 * payment-method-to-token relationship, and deleting that token detaches the
 * method at the provider. What this case adds over the client suite it partly
 * replaces is identity - every assertion names an exact SetupIntent, an exact
 * provider payment method and an exact local token, and asserts cardinality
 * on both sides, so a set that merely contains the right member proves
 * nothing.
 *
 * The other five cases this family used to run moved to PHPUnit in T.1 batch
 * 3, with expectations cited from WooPayments 11.1.0 or the recorded
 * `Fixtures/rec-2-setup-intent-declines.json` (REC-2) where the client's own
 * shape was needed:
 * - The 20-second My Account cooldown refusal is
 *   `WooPaymentsCheckoutAjaxControllerTest::test_create_setup_intent_refuses_inside_add_payment_method_rate_limit_without_provider_call`.
 * - Paying with an already-saved token creating no second token is
 *   `WooPaymentsOrderEffectApplierTest::test_saved_token_effects_attach_selected_token`.
 * - Deleting a token detaching its provider method is
 *   `WooPaymentsTokenServiceTest::test_detaches_native_card_payment_methods_when_token_is_deleted`,
 *   which this case's own cleanup below still proves live at the provider.
 * - A Classic or Blocks checkout that saves a card creating exactly one token
 *   is `NativeWooPaymentsGatewayTest`, `WooPaymentsProviderGatewayAdapterTest`
 *   and `WooPaymentsOrderEffectApplierTest`.
 *
 * Nothing here proves those five surfaces; do not read this file for that
 * claim.
 */

const FAMILY_TAG = '@fidelity:saved-token-lifecycle';

const CONTRACT_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:146::Shopper can save and delete cards › Testing card: basic › should add the basic card as a new payment method';
const CONTRACT_DELETE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:249::Shopper can save and delete cards › Testing card: basic › should be able to delete basic card';

/**
 * The client fixture's card for this journey. Stays written out here for the
 * reason `utils/woopayments-native/test-cards.ts` records: that module
 * carries cards whose *behaviour* the provider selects, and this one selects
 * nothing - it is an ordinary reusable Visa.
 */
const BASIC_CARD: ProviderTestCard = {
	number: '4242424242424242',
	expiry: '0245',
	securityCode: '424',
};

/** The claim's convergence rule: poll every 2 seconds. */
const POLL_INTERVAL_MS = 2_000;
/** Two consecutive reads, 2 seconds apart, must agree. */
const CONVERGENCE_WINDOW_MS = 2_000;

/*
 * Preflights the capabilities this case can reach, including the ones its
 * failure path reaches: a cleanup that discovers it was never approved leaves
 * the credential it was meant to remove.
 */
const ADD_CAPABILITIES = [ 'saved-card-add', 'saved-card-cleanup' ];
const DELETE_CAPABILITIES = [ 'saved-card-cleanup' ];

/**
 * What the shopper's saved-method state looked like before this run touched
 * it.
 *
 * `providerCustomerId` is absent when the store could disclose none at the
 * time of the reading - a shopper who has never reached the provider has no
 * customer to name.
 */
interface SavedTokenBaseline {
	tokens: SavedCardToken[];
	providerCustomerId: string | undefined;
	providerAttachments: string[] | undefined;
}

let runCard: SavedCardIdentity | undefined;
let runProviderCustomerId: string | undefined;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * Asserts the whole capability set before any provider interval opens, so an
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

async function readBaseline(
	session: ProviderWriteSession
): Promise< SavedTokenBaseline > {
	const evidence = await getSavedCardEvidence( session );
	const providerCustomerId = await findSavedCardProviderCustomerId( session );
	if ( ! providerCustomerId ) {
		return {
			tokens: evidence.tokens,
			providerCustomerId: undefined,
			providerAttachments: undefined,
		};
	}

	return {
		tokens: evidence.tokens,
		providerCustomerId,
		providerAttachments: await getProviderPaymentMethodIds(
			session,
			providerCustomerId
		),
	};
}

function tokenIdentities(
	tokens: readonly SavedCardToken[]
): Array< [ number, string, boolean ] > {
	return tokens
		.map(
			( token ) =>
				[ token.tokenId, token.paymentMethodId, token.isDefault ] as [
					number,
					string,
					boolean,
				]
		)
		.toSorted( ( left, right ) => left[ 0 ] - right[ 0 ] );
}

/**
 * Requires the provider's attachment set for one customer to stay exactly
 * what it is for a whole interval, reading it every two seconds.
 *
 * Used as the claim's two-read convergence check: an attachment that appears
 * one poll after the store answered is the falsifier this family exists to
 * catch.
 */
async function expectStableProviderAttachments(
	session: ProviderWriteSession,
	providerCustomerId: string,
	expected: readonly string[],
	durationMs: number,
	reason: string
): Promise< void > {
	const wanted = [ ...expected ].toSorted();
	const deadline = Date.now() + durationMs;
	for (;;) {
		const observed = (
			await getProviderPaymentMethodIds( session, providerCustomerId )
		).toSorted();
		expect( observed, reason ).toEqual( wanted );
		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			return;
		}
		await delay( Math.min( POLL_INTERVAL_MS, remaining ) );
	}
}

async function expectStableLocalTokens(
	session: ProviderWriteSession,
	expected: readonly SavedCardToken[],
	reason: string
): Promise< void > {
	const observed = await getSavedCardEvidence( session );
	expect( tokenIdentities( observed.tokens ), reason ).toEqual(
		tokenIdentities( expected )
	);
}

/**
 * The provider customer this run works against, proved to be the same one
 * the baseline named whenever the baseline could name one.
 */
async function resolveRunProviderCustomer(
	session: ProviderWriteSession,
	baseline: SavedTokenBaseline
): Promise< string > {
	const providerCustomerId = await readSavedCardProviderCustomerId( session );
	if ( baseline.providerCustomerId !== undefined ) {
		expect(
			providerCustomerId,
			'the run must work against the same provider customer the baseline named'
		).toBe( baseline.providerCustomerId );
	}
	return providerCustomerId;
}

/**
 * The attachment set an added method must produce.
 *
 * When the baseline was readable the expectation is exactly the baseline plus
 * the one new method. When it was not - no provider customer could be named
 * before the first card existed - the expectation is that the one new method
 * is the only attachment there is.
 */
function expectedAttachmentsAfterAdd(
	baseline: SavedTokenBaseline,
	addedPaymentMethodIds: readonly string[]
): string[] {
	return [
		...( baseline.providerAttachments ?? [] ),
		...addedPaymentMethodIds,
	];
}

/**
 * One `create_setup_intent` exchange that succeeded, and the single form
 * submission that carried its exact ID to `add_payment_method()`.
 *
 * This is the SetupIntent-to-token join the claim rests on: native reads the
 * submitted SetupIntent from the provider and creates the token from that
 * intent's payment method, so a token whose method is not the method this
 * intent settled cannot exist without one of these assertions failing.
 */
function expectAcceptedSetupIntent(
	observation: NativeAddPaymentMethodObservation
): string {
	expect(
		observation.setupIntentExchanges,
		'one add must ask the setup-intent bridge exactly once'
	).toHaveLength( 1 );
	const [ exchange ] = observation.setupIntentExchanges;
	expect( exchange.httpStatus ).toBe( 200 );
	expect( exchange.errorMessage ).toBe( '' );
	expect(
		exchange.setupIntentId,
		'the bridge must name the exact SetupIntent it created'
	).toMatch( /^seti_/ );
	expect(
		exchange.setupIntentStatus,
		'a 4242 SetupIntent must succeed at the provider'
	).toBe( 'succeeded' );

	expect(
		observation.formSubmissionCount,
		'one add gesture must submit the add-payment-method form exactly once'
	).toBe( 1 );
	expect(
		observation.submittedSetupIntentIds,
		'the submission must carry that exact SetupIntent and no other'
	).toEqual( [ exchange.setupIntentId ] );
	expect( observation.successNoticeVisible ).toBe( true );
	expect(
		observation.createdCards,
		'one succeeded SetupIntent must create exactly one local token'
	).toHaveLength( 1 );

	return exchange.setupIntentId;
}

function requireRunCard(): SavedCardIdentity {
	if ( ! runCard ) {
		throw new Error( 'The create step must run before this assertion.' );
	}
	return runCard;
}

/**
 * Removes the run's card after the case failed, so a failure cannot leave a
 * live credential on the standing shopper, and reports both failures when the
 * removal fails too.
 */
async function releaseRunCardAfterFailure(
	session: ProviderWriteSession,
	page: Page,
	failure: unknown
): Promise< never > {
	const card = runCard;
	const providerCustomerId = runProviderCustomerId;
	if ( ! card || ! providerCustomerId ) {
		throw failure;
	}
	runCard = undefined;

	try {
		await deleteExactSavedCards(
			session,
			page,
			[ card ],
			providerCustomerId
		);
	} catch ( cleanupFailure ) {
		throw new ResourceQuarantineRequiredError(
			'The saved-token case failed and the removal of the card it created failed too.',
			'cleanup-failed',
			new AggregateError(
				[
					failure,
					cleanupFailure instanceof ResourceQuarantineRequiredError
						? cleanupFailure.primaryError ?? cleanupFailure
						: cleanupFailure,
				],
				'The saved-token case failed and the removal of the card it created failed too.',
				{ cause: cleanupFailure }
			)
		);
	}
	throw failure;
}

test.describe( 'WooPayments native saved-token lifecycle fidelity', () => {
	test.describe.configure( { timeout: 300_000 } );

	test(
		'one My Account card save creates exactly one succeeded SetupIntent, one provider attachment, and one Woo token that stores that exact method, and deleting it detaches that method at the provider',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_ADD,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_DELETE,
				},
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				FAMILY_TAG,
			],
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, [
				...ADD_CAPABILITIES,
				...DELETE_CAPABILITIES,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'shopper-saved-token-create' },
				async () => {
					const baseline = await readBaseline( pilotRuntime );

					const observation = await submitNativeAddPaymentMethod(
						pilotRuntime,
						page,
						{
							card: BASIC_CARD,
							journal: 'saved-token-add',
							expectation: 'accepted',
							logInAsCustomer: true,
						}
					);
					// Recorded before the assertions so a failing assertion is
					// not the reason a live credential survives the run.
					runCard = observation.createdCards[ 0 ];

					try {
						expectAcceptedSetupIntent( observation );
						const created = requireRunCard();

						runProviderCustomerId =
							await resolveRunProviderCustomer(
								pilotRuntime,
								baseline
							);
						const attached = await getProviderPaymentMethodIds(
							pilotRuntime,
							runProviderCustomerId
						);
						expect(
							attached.filter(
								( id ) => id === created.paymentMethodId
							),
							'the provider must hold exactly one attachment of the saved method'
						).toHaveLength( 1 );
						expect(
							attached.toSorted(),
							'the save must add exactly one attachment and remove none'
						).toEqual(
							expectedAttachmentsAfterAdd( baseline, [
								created.paymentMethodId,
							] ).toSorted()
						);

						// Convergence: the same terminal identities twice, two
						// seconds apart, on both sides of the relationship.
						await expectStableProviderAttachments(
							pilotRuntime,
							runProviderCustomerId,
							attached,
							CONVERGENCE_WINDOW_MS,
							'the attachment set must be terminal, not still settling'
						);
						await expectStableLocalTokens(
							pilotRuntime,
							observation.tokensAfter,
							'the local token set must be terminal, not still settling'
						);

						// The deletion half of this smoke, absorbing the retired
						// `T3` case: deleting the token must remove it locally
						// and detach its method at the provider.
						await deleteExactSavedCards(
							pilotRuntime,
							page,
							[ created ],
							runProviderCustomerId
						);
						runCard = undefined;

						// The claim asks for two absent reads two seconds apart.
						await delay( CONVERGENCE_WINDOW_MS );
						expect(
							(
								await getSavedCardEvidence( pilotRuntime )
							).tokens.map( ( token ) => token.tokenId ),
							'the deleted local token must stay absent'
						).not.toContain( created.tokenId );
						expect(
							await getProviderPaymentMethodIds(
								pilotRuntime,
								runProviderCustomerId
							),
							'deleting the saved token must detach its method at the provider'
						).not.toContain( created.paymentMethodId );
					} catch ( error ) {
						await releaseRunCardAfterFailure(
							pilotRuntime,
							page,
							error
						);
					}
				}
			);
		}
	);
} );
