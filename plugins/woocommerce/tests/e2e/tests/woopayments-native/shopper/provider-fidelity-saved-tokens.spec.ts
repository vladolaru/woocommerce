import type { Page, Request, Response } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import {
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `saved-token-lifecycle` provider-fidelity family (T.4 Batch P3
 * rewrite): the retained browser smoke.
 *
 * `FIDELITY-CLAIMS.md` states the claim this case exists to establish: a
 * `4242` SetupIntent at the real provider yields exactly one
 * payment-method-to-token relationship, and deleting that token detaches the
 * method at the provider. Every assertion names an exact SetupIntent, an
 * exact provider payment method and an exact local token, and asserts
 * cardinality on both sides.
 *
 * The other five cases this family used to run moved to PHPUnit in T.1 batch
 * 3, with expectations cited from WooPayments 11.1.0:
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
 * Dropped from the old harness rewrite (harness-self, not business facts):
 * the provider-fixture approval list, the write-locks/submission-journal
 * wrapper, and the quarantine-on-cleanup-failure error type. Provider-write
 * safety is `requireTestModeAccount` (D5); cleanup here is a plain
 * best-effort delete of the card this run created.
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:saved-token-lifecycle',
];

const CONTRACT_ADD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:146::Shopper can save and delete cards › Testing card: basic › should add the basic card as a new payment method';
const CONTRACT_DELETE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:249::Shopper can save and delete cards › Testing card: basic › should be able to delete basic card';

const SAVED_CARD_EVIDENCE_API = 'wc-native-payments-e2e/v1/saved-card-evidence';
const PAYMENT_METHODS_ROUTE = 'wc/v3/payments/customers';
const ADD_FORM = '#add_payment_method';
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
const ADD_SUCCESS_NOTICE = 'Payment method successfully added.';
const DELETE_SUCCESS_NOTICE = 'Payment method deleted.';
const CONVERGENCE_WINDOW_MS = 2_000;

interface SavedCardToken {
	tokenId: number;
	paymentMethodId: string;
	isDefault: boolean;
}

interface SavedCardEvidence {
	tokens: SavedCardToken[];
	providerCustomerId?: string;
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function getSavedCardEvidence(
	restApi: ApiClient,
	usernameOrToken:
		| { customerUsername: string }
		| { tokenId: number; paymentMethodId: string }
): Promise< SavedCardEvidence > {
	const params =
		'customerUsername' in usernameOrToken
			? { customer_username: usernameOrToken.customerUsername }
			: {
					token_id: String( usernameOrToken.tokenId ),
					payment_method_id: usernameOrToken.paymentMethodId,
			  };
	const data = ( await restApi.get( SAVED_CARD_EVIDENCE_API, params ) )
		.data as {
		tokens?: Array< {
			token_id: number;
			payment_method_id: string;
			is_default: boolean;
		} >;
		provider_customer_id?: string;
	};
	const tokens = Array.isArray( data.tokens )
		? data.tokens.map( ( token ) => ( {
				tokenId: token.token_id,
				paymentMethodId: token.payment_method_id,
				isDefault: token.is_default,
		  } ) )
		: [];
	return { tokens, providerCustomerId: data.provider_customer_id };
}

/**
 * The provider customer ID the store holds for the run's shopper, when the
 * store can name one. A shopper who has never reached the provider has none.
 */
async function findProviderCustomerId(
	restApi: ApiClient,
	customerUsername: string
): Promise< string | undefined > {
	const evidence = await getSavedCardEvidence( restApi, {
		customerUsername,
	} );
	if ( evidence.providerCustomerId ) {
		return evidence.providerCustomerId;
	}
	const defaultToken = evidence.tokens.find( ( token ) => token.isDefault );
	if ( ! defaultToken ) {
		return undefined;
	}
	return (
		await getSavedCardEvidence( restApi, {
			tokenId: defaultToken.tokenId,
			paymentMethodId: defaultToken.paymentMethodId,
		} )
	).providerCustomerId;
}

async function getProviderPaymentMethodIds(
	restApi: ApiClient,
	providerCustomerId: string
): Promise< string[] > {
	const methods = (
		await restApi.get(
			`${ PAYMENT_METHODS_ROUTE }/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		)
	).data as Array< { id: string } >;
	return methods.map( ( method ) => method.id );
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
 * Fills the optional billing country and postcode fields the My Account
 * payment element renders alongside the card fields, when the store's
 * Stripe billing-details collection actually asks for them. `fillCardDetails`
 * only ever fills number/expiry/CVC, so this surface's extra fields are
 * handled here rather than in the shared helper, which has no other
 * consumer for them.
 */
async function fillOptionalBillingFields( page: Page ): Promise< void > {
	const frame = page.frameLocator(
		`${ ADD_FORM } #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]`
	);
	const country = frame.getByRole( 'combobox', { name: /country/i } );
	if ( ( await country.count() ) > 0 && ( await country.isVisible() ) ) {
		await country.selectOption( 'US' );
	}
	const postcode = frame.getByRole( 'textbox', {
		name: /^(?:ZIP|Postal code)/i,
	} );
	if ( ( await postcode.count() ) > 0 && ( await postcode.isVisible() ) ) {
		await postcode.fill( '94107' );
	}
}

interface SetupIntentExchange {
	httpStatus: number;
	setupIntentId: string;
	setupIntentStatus: string;
	errorMessage: string;
}

function isCreateSetupIntentRequest( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		if (
			! new URL( request.url() ).pathname.endsWith(
				'/wp-admin/admin-ajax.php'
			)
		) {
			return false;
		}
	} catch {
		return false;
	}
	return (
		new URLSearchParams( request.postData() ?? '' ).get( 'action' ) ===
		'create_setup_intent'
	);
}

function isAddPaymentMethodSubmission( request: Request ): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		return new URL( request.url() ).pathname
			.replace( /\/+$/, '' )
			.endsWith( '/add-payment-method' );
	} catch {
		return false;
	}
}

function readSetupIntentExchange(
	status: number,
	body: unknown
): SetupIntentExchange {
	const payload =
		typeof body === 'object' && body !== null
			? ( body as { data?: unknown } ).data
			: undefined;
	const data =
		typeof payload === 'object' && payload !== null
			? ( payload as { id?: unknown; status?: unknown; error?: unknown } )
			: {};
	const error =
		typeof data.error === 'object' && data.error !== null
			? ( data.error as { message?: unknown } )
			: {};
	return {
		httpStatus: status,
		setupIntentId: typeof data.id === 'string' ? data.id : '',
		setupIntentStatus: typeof data.status === 'string' ? data.status : '',
		errorMessage: typeof error.message === 'string' ? error.message : '',
	};
}

let customer: ReturnType< typeof getFakeUser >;
let customerId: number;

test.describe( 'WooPayments native saved-token lifecycle fidelity', () => {
	test.describe.configure( { timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		customer = getFakeUser( 'customer' );
		const created = ( await restApi.post( 'wc/v3/customers', customer ) )
			.data as { id: number };
		customerId = created.id;
	} );

	test.afterAll( async ( { restApi } ) => {
		if ( customerId ) {
			await restApi.delete( `wc/v3/customers/${ customerId }`, {
				force: true,
			} );
		}
	} );

	test(
		'one My Account card save creates exactly one succeeded SetupIntent, one provider attachment, and one Woo token that stores that exact method, and deleting it detaches that method at the provider',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_ADD },
				{ type: 'woopayments-contract', description: CONTRACT_DELETE },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			await page.goto( 'wp-login.php' );
			await logIn( page, customer.username, customer.password, false );
			await page.goto( 'my-account/' );
			await expect(
				page.getByText( new RegExp( `Hello ${ customer.first_name }` ) )
			).toBeVisible();

			const baseline = await getSavedCardEvidence( restApi, {
				customerUsername: customer.username,
			} );
			const baselineProviderCustomerId = await findProviderCustomerId(
				restApi,
				customer.username
			);
			const baselineAttachments = baselineProviderCustomerId
				? await getProviderPaymentMethodIds(
						restApi,
						baselineProviderCustomerId
				  )
				: undefined;

			let createdCard:
				| { tokenId: number; paymentMethodId: string }
				| undefined;
			let providerCustomerId = baselineProviderCustomerId;

			try {
				await page.goto( 'my-account/payment-methods/' );
				await page
					.getByRole( 'link', { name: /add payment method/i } )
					.click();
				await expect( page.locator( ADD_FORM ) ).toBeVisible();

				const cardRadio = page.locator(
					`${ ADD_FORM } input[name="payment_method"][value="${ WOOPAYMENTS_GATEWAY }"]`
				);
				if ( await cardRadio.isVisible() ) {
					await cardRadio.check();
				}

				await fillCardDetails( page, TEST_CARDS.basic, 'my-account' );
				await fillOptionalBillingFields( page );

				const setupIntentExchanges: Array<
					Promise< SetupIntentExchange >
				> = [];
				const submittedSetupIntentIds: string[] = [];
				let formSubmissionCount = 0;
				const onRequest = ( request: Request ): void => {
					if ( ! isAddPaymentMethodSubmission( request ) ) {
						return;
					}
					formSubmissionCount += 1;
					submittedSetupIntentIds.push(
						new URLSearchParams( request.postData() ?? '' ).get(
							'wcpay-setup-intent'
						) ?? ''
					);
				};
				const onResponse = ( response: Response ): void => {
					if ( ! isCreateSetupIntentRequest( response.request() ) ) {
						return;
					}
					setupIntentExchanges.push(
						response
							.json()
							.then( ( body: unknown ) =>
								readSetupIntentExchange(
									response.status(),
									body
								)
							)
							.catch( () =>
								readSetupIntentExchange(
									response.status(),
									undefined
								)
							)
					);
				};
				page.on( 'request', onRequest );
				page.on( 'response', onResponse );
				try {
					await page
						.getByRole( 'button', {
							name: 'Add payment method',
							exact: true,
						} )
						.click();
					await expect(
						page.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
					).toBeVisible( { timeout: 45_000 } );
				} finally {
					page.off( 'request', onRequest );
					page.off( 'response', onResponse );
				}

				const exchanges = await Promise.all( setupIntentExchanges );
				expect(
					exchanges,
					'one add must ask the setup-intent bridge exactly once'
				).toHaveLength( 1 );
				const [ exchange ] = exchanges;
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
					formSubmissionCount,
					'one add gesture must submit the add-payment-method form exactly once'
				).toBe( 1 );
				expect(
					submittedSetupIntentIds,
					'the submission must carry that exact SetupIntent and no other'
				).toEqual( [ exchange.setupIntentId ] );

				const after = await getSavedCardEvidence( restApi, {
					customerUsername: customer.username,
				} );
				const created = after.tokens.filter(
					( token ) =>
						! baseline.tokens.some(
							( existing ) => existing.tokenId === token.tokenId
						)
				);
				expect(
					created,
					'one succeeded SetupIntent must create exactly one local token'
				).toHaveLength( 1 );
				createdCard = {
					tokenId: created[ 0 ].tokenId,
					paymentMethodId: created[ 0 ].paymentMethodId,
				};

				providerCustomerId = await findProviderCustomerId(
					restApi,
					customer.username
				);
				if ( baselineProviderCustomerId !== undefined ) {
					expect(
						providerCustomerId,
						'the run must work against the same provider customer the baseline named'
					).toBe( baselineProviderCustomerId );
				}
				if ( ! providerCustomerId ) {
					throw new Error(
						'the store disclosed no provider customer after a save.'
					);
				}
				const resolvedProviderCustomerId: string = providerCustomerId;
				const attached = await getProviderPaymentMethodIds(
					restApi,
					resolvedProviderCustomerId
				);
				expect(
					attached.filter(
						( id ) => id === createdCard!.paymentMethodId
					),
					'the provider must hold exactly one attachment of the saved method'
				).toHaveLength( 1 );
				expect(
					attached.toSorted(),
					'the save must add exactly one attachment and remove none'
				).toEqual(
					[
						...( baselineAttachments ?? [] ),
						createdCard.paymentMethodId,
					].toSorted()
				);

				// Convergence: the same terminal identities twice, two seconds
				// apart, on both sides of the relationship.
				await delay( CONVERGENCE_WINDOW_MS );
				expect(
					(
						await getProviderPaymentMethodIds(
							restApi,
							providerCustomerId
						)
					).toSorted(),
					'the attachment set must be terminal, not still settling'
				).toEqual( attached.toSorted() );
				expect(
					tokenIdentities(
						(
							await getSavedCardEvidence( restApi, {
								customerUsername: customer.username,
							} )
						).tokens
					),
					'the local token set must be terminal, not still settling'
				).toEqual( tokenIdentities( after.tokens ) );

				// The deletion half of this smoke: deleting the token must
				// remove it locally and detach its method at the provider.
				await page.goto( 'my-account/payment-methods/' );
				const deleteLinks = await page
					.getByRole( 'link', { name: 'Delete', exact: true } )
					.all();
				const matching: typeof deleteLinks = [];
				for ( const link of deleteLinks ) {
					const href = await link.getAttribute( 'href' );
					if (
						href &&
						new URL( href, page.url() ).pathname.endsWith(
							`/delete-payment-method/${ createdCard.tokenId }/`
						)
					) {
						matching.push( link );
					}
				}
				expect(
					matching,
					'the delete action must be uniquely bound to the created local token'
				).toHaveLength( 1 );
				await matching[ 0 ].click();
				await expect(
					page.getByText( DELETE_SUCCESS_NOTICE, { exact: true } )
				).toBeVisible();
				const deletedCard = createdCard;
				createdCard = undefined;

				// The claim is two absent reads 2 seconds apart, not one: the
				// first is taken right after the notice, the second after the
				// convergence window, and each checks the local token by both
				// its id and its payment method, plus provider detachment.
				const expectDeletedCardAbsent = async (
					reason: string
				): Promise< void > => {
					const tokens = (
						await getSavedCardEvidence( restApi, {
							customerUsername: customer.username,
						} )
					).tokens;
					expect(
						tokens.map( ( token ) => token.tokenId ),
						`${ reason }: the deleted local token id must be absent`
					).not.toContain( deletedCard.tokenId );
					expect(
						tokens.map( ( token ) => token.paymentMethodId ),
						`${ reason }: the deleted local payment method must be absent`
					).not.toContain( deletedCard.paymentMethodId );
					expect(
						await getProviderPaymentMethodIds(
							restApi,
							resolvedProviderCustomerId
						),
						`${ reason }: the deleted method must be detached at the provider`
					).not.toContain( deletedCard.paymentMethodId );
				};

				await expectDeletedCardAbsent( 'immediately after deletion' );
				await delay( CONVERGENCE_WINDOW_MS );
				await expectDeletedCardAbsent( '2 seconds after deletion' );
			} finally {
				// Best-effort: if the case failed before reaching the delete
				// step, do not leave a live credential on the run's customer.
				if ( createdCard && providerCustomerId ) {
					await page
						.goto( 'my-account/payment-methods/' )
						.catch( () => undefined );
					const link = page.locator(
						`a[href*="/delete-payment-method/${ createdCard.tokenId }/"]`
					);
					if ( ( await link.count().catch( () => 0 ) ) === 1 ) {
						await link.click().catch( () => undefined );
					}
				}
			}
		}
	);
} );
