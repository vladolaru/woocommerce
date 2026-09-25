import {
	expect,
	type Locator,
	type Page,
	type Request,
	type Response,
} from '@playwright/test';

import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import { CARD_EXPIRY_FIELD_NAME, enterProviderCardEntry } from './card-entry';
import { submitBlocksCheckout } from './checkout';
import type { ProviderTestCard } from '../test-cards';
import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { customer } from '../../../test-data/data';

export interface SavedCardIdentity {
	tokenId: number;
	paymentMethodId: string;
}

export interface SavedCardState extends SavedCardIdentity {
	isDefault: boolean;
	providerCustomerId: string;
}

interface SavedCardTokenEvidence {
	tokenId: number;
	paymentMethodId: string;
	isDefault: boolean;
}

export interface SavedCardEvidence {
	creationReady: boolean;
	tokens: SavedCardTokenEvidence[];
	providerCustomerId?: string;
}

function parseSavedCardEvidence( value: unknown ): SavedCardEvidence {
	if ( typeof value !== 'object' || value === null ) {
		throw new Error( 'Saved-card evidence must be an object.' );
	}
	const raw = value as {
		creation_ready?: unknown;
		tokens?: unknown;
		provider_customer_id?: unknown;
	};
	if ( typeof raw.creation_ready !== 'boolean' ) {
		throw new Error(
			'Saved-card evidence creation_ready must be a boolean.'
		);
	}
	if ( ! Array.isArray( raw.tokens ) ) {
		throw new Error( 'Saved-card evidence tokens must be an array.' );
	}

	const tokenIds = new Set< number >();
	const paymentMethodIds = new Set< string >();
	const tokens = raw.tokens.map( ( tokenValue, index ) => {
		if ( typeof tokenValue !== 'object' || tokenValue === null ) {
			throw new Error(
				`Saved-card evidence token ${ index } must be an object.`
			);
		}
		const token = tokenValue as {
			token_id?: unknown;
			payment_method_id?: unknown;
			is_default?: unknown;
		};
		if (
			typeof token.token_id !== 'number' ||
			! Number.isSafeInteger( token.token_id ) ||
			token.token_id <= 0
		) {
			throw new Error(
				`Saved-card evidence token_id at index ${ index } must be a positive integer.`
			);
		}
		if (
			typeof token.payment_method_id !== 'string' ||
			token.payment_method_id === ''
		) {
			throw new Error(
				`Saved-card evidence payment_method_id at index ${ index } must be a non-empty string.`
			);
		}
		if ( typeof token.is_default !== 'boolean' ) {
			throw new Error(
				`Saved-card evidence is_default at index ${ index } must be a boolean.`
			);
		}
		if ( tokenIds.has( token.token_id ) ) {
			throw new Error(
				`Saved-card evidence contains duplicate token_id ${ token.token_id }.`
			);
		}
		if ( paymentMethodIds.has( token.payment_method_id ) ) {
			throw new Error(
				`Saved-card evidence contains duplicate payment_method_id ${ token.payment_method_id }.`
			);
		}
		tokenIds.add( token.token_id );
		paymentMethodIds.add( token.payment_method_id );

		return {
			tokenId: token.token_id,
			paymentMethodId: token.payment_method_id,
			isDefault: token.is_default,
		};
	} );

	const providerCustomerId = raw.provider_customer_id;
	if (
		providerCustomerId !== undefined &&
		( typeof providerCustomerId !== 'string' || providerCustomerId === '' )
	) {
		throw new Error(
			'Saved-card evidence provider_customer_id must be a non-empty string.'
		);
	}
	return {
		creationReady: raw.creation_ready,
		tokens,
		providerCustomerId,
	};
}

export async function getSavedCardEvidence(
	session: ProviderWriteSession,
	card?: SavedCardIdentity
): Promise< SavedCardEvidence > {
	const parameters = new URLSearchParams( {
		customer_username: customer.username,
	} );
	if ( card ) {
		parameters.set( 'token_id', card.tokenId.toString() );
		parameters.set( 'payment_method_id', card.paymentMethodId );
	}
	const response = await session.adminApi.get(
		`/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?${ parameters.toString() }`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Saved-card evidence failed: HTTP ${ response.status() }.`
		);
	}

	return parseSavedCardEvidence( await response.json() );
}

/**
 * Lists every payment method the provider currently holds attached to one
 * customer, in the order the provider returns them.
 */
export async function getProviderPaymentMethodIds(
	session: ProviderWriteSession,
	providerCustomerId: string
): Promise< string[] > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
			providerCustomerId
		) }/payment_methods`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Provider payment-method evidence failed: HTTP ${ response.status() }.`
		);
	}
	const paymentMethods = await response.json();
	if ( ! Array.isArray( paymentMethods ) ) {
		throw new Error( 'Provider payment-method evidence must be an array.' );
	}
	return paymentMethods.map( ( paymentMethod, index ) => {
		if (
			typeof paymentMethod !== 'object' ||
			paymentMethod === null ||
			typeof ( paymentMethod as { id?: unknown } ).id !== 'string' ||
			( paymentMethod as { id: string } ).id === ''
		) {
			throw new Error(
				`Provider payment-method evidence at index ${ index } has no exact ID.`
			);
		}
		return ( paymentMethod as { id: string } ).id;
	} );
}

async function waitForSavedCardCreationReady(
	session: ProviderWriteSession
): Promise< SavedCardEvidence > {
	const deadline = Date.now() + 25_000;
	let evidence = await getSavedCardEvidence( session );
	while ( ! evidence.creationReady ) {
		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			throw new Error(
				'Core add-payment-method rate limit did not become ready before the saved-card deadline.'
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, Math.min( 500, remaining ) )
		);
		evidence = await getSavedCardEvidence( session );
	}
	return evidence;
}

export async function createPluginOwnedSavedCard(
	session: ProviderWriteSession,
	page: Page,
	label: string
): Promise< SavedCardIdentity > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'plugin-owned-saved-card' );
	const before = await waitForSavedCardCreationReady( session );

	await session.logInAsCustomer( page );
	await page.goto( 'my-account/payment-methods/' );
	await page.getByRole( 'link', { name: /add payment method/i } ).click();

	await page.getByText( 'Card', { exact: true } ).click();
	const cardFrame = page
		.getByTitle( 'Secure payment input frame' )
		.contentFrame();
	await cardFrame
		.getByPlaceholder( '1234 1234 1234 1234' )
		.fill( '4242 4242 4242 4242' );
	await cardFrame.getByPlaceholder( 'MM / YY' ).fill( '02 / 45' );
	await cardFrame.getByPlaceholder( 'CVC' ).fill( '123' );
	await cardFrame
		.getByRole( 'combobox', { name: /country/i } )
		.selectOption( 'US' );
	await cardFrame.getByLabel( /ZIP/i ).fill( '90210' );
	const addPaymentMethod = page.getByRole( 'button', {
		name: 'Add payment method',
		exact: true,
	} );
	return session.withProviderSubmissionJournal(
		'plugin-saved-card-create',
		async () => {
			let submissionAttempted = false;
			try {
				await session.performWrite( () => {
					submissionAttempted = true;
					return addPaymentMethod.click();
				} );
				await expect(
					page.getByText( 'Payment method successfully added.', {
						exact: true,
					} )
				).toBeVisible();

				const after = await getSavedCardEvidence( session );
				const beforeByTokenId = new Map(
					before.tokens.map( ( token ) => [ token.tokenId, token ] )
				);
				for ( const token of before.tokens ) {
					const preserved = after.tokens.find(
						( candidate ) => candidate.tokenId === token.tokenId
					);
					if (
						! preserved ||
						preserved.paymentMethodId !== token.paymentMethodId
					) {
						throw new Error(
							`Saved-card ${ label } changed the existing local token ${ token.tokenId } mapping.`
						);
					}
				}

				const created = after.tokens.filter(
					( token ) => ! beforeByTokenId.has( token.tokenId )
				);
				if ( created.length !== 1 ) {
					throw new Error(
						`Saved-card ${ label } must create exactly one new local token; found ${ created.length }.`
					);
				}

				return {
					tokenId: created[ 0 ].tokenId,
					paymentMethodId: created[ 0 ].paymentMethodId,
				};
			} catch ( error ) {
				if ( ! submissionAttempted ) {
					throw new ProviderSubmissionNotStartedError(
						`Saved-card ${ label } submission was never dispatched.`,
						{ cause: error }
					);
				}
				if ( error instanceof ResourceQuarantineRequiredError ) {
					throw error;
				}
				throw new ResourceQuarantineRequiredError(
					`Saved-card ${ label } creation could not be proven after submission.`,
					'uncertain-provider-write',
					error
				);
			}
		}
	);
}

export async function makeSavedCardDefault(
	session: ProviderWriteSession,
	page: Page,
	tokenId: number
): Promise< void > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'saved-card-default' );
	await page.goto( 'my-account/payment-methods/' );
	const candidates = await page
		.getByRole( 'link', { name: /make default/i } )
		.all();
	const matchingActions: Locator[] = [];
	for ( const candidate of candidates ) {
		const href = await candidate.getAttribute( 'href' );
		if (
			href &&
			new URL( href, session.baseURL ).pathname.endsWith(
				`/set-default-payment-method/${ tokenId }/`
			)
		) {
			matchingActions.push( candidate );
		}
	}
	if ( matchingActions.length !== 1 ) {
		throw new Error(
			`Saved-card default action is not uniquely bound to local token ${ tokenId }.`
		);
	}
	await session.performWrite( () => matchingActions[ 0 ].click() );
}

export async function deleteExactSavedCards(
	session: ProviderWriteSession,
	page: Page,
	cards: readonly SavedCardIdentity[],
	providerCustomerId?: string
): Promise< void > {
	if ( cards.length === 0 ) {
		return;
	}

	try {
		await session.assertCanWrite();
		session.requireApprovedProviderFixture( 'saved-card-cleanup' );
		await session.logInAsCustomer( page );

		await session.withProviderSubmissionJournal(
			'plugin-saved-card-delete',
			async () => {
				for ( const card of cards.toReversed() ) {
					let submissionAttempted = false;
					try {
						await page.goto( 'my-account/payment-methods/' );
						const candidates = await page
							.getByRole( 'link', {
								name: 'Delete',
								exact: true,
							} )
							.all();
						const matchingActions: Locator[] = [];
						for ( const candidate of candidates ) {
							const href = await candidate.getAttribute( 'href' );
							if ( ! href ) {
								continue;
							}
							const url = new URL( href, session.baseURL );
							if (
								url.pathname.endsWith(
									`/delete-payment-method/${ card.tokenId }/`
								) &&
								url.searchParams.has( '_wpnonce' )
							) {
								matchingActions.push( candidate );
							}
						}
						if ( matchingActions.length !== 1 ) {
							throw new Error(
								`Saved-card delete action is not uniquely bound to local token ${ card.tokenId }.`
							);
						}
						await session.performWrite( () => {
							submissionAttempted = true;
							return matchingActions[ 0 ].click();
						} );
						await expect(
							page.getByText( 'Payment method deleted.', {
								exact: true,
							} )
						).toBeVisible();
					} catch ( error ) {
						if ( ! submissionAttempted ) {
							throw new ProviderSubmissionNotStartedError(
								`Saved-card ${ card.tokenId } deletion was never dispatched.`,
								{ cause: error }
							);
						}
						if (
							error instanceof ResourceQuarantineRequiredError
						) {
							throw error;
						}
						throw new ResourceQuarantineRequiredError(
							`Saved-card ${ card.tokenId } deletion could not be proven after submission.`,
							'uncertain-provider-write',
							error
						);
					}
				}
			}
		);

		const evidence = await getSavedCardEvidence( session );
		for ( const card of cards ) {
			if (
				evidence.tokens.some(
					( token ) =>
						token.tokenId === card.tokenId ||
						token.paymentMethodId === card.paymentMethodId
				)
			) {
				throw new Error(
					`Saved-card cleanup did not remove exact token ${ card.tokenId } (${ card.paymentMethodId }).`
				);
			}
		}
		if ( ! providerCustomerId ) {
			throw new Error(
				'Saved-card cleanup cannot prove provider detachment without an exact provider customer ID.'
			);
		}

		const providerIds = await getProviderPaymentMethodIds(
			session,
			providerCustomerId
		);
		for ( const card of cards ) {
			if ( providerIds.includes( card.paymentMethodId ) ) {
				throw new Error(
					`Saved-card cleanup left provider payment method ${ card.paymentMethodId } attached.`
				);
			}
		}
	} catch ( error ) {
		if ( error instanceof ResourceQuarantineRequiredError ) {
			throw error;
		}
		throw new ResourceQuarantineRequiredError(
			error instanceof Error
				? error.message
				: 'Exact saved-card cleanup failed.',
			'cleanup-failed',
			error
		);
	}
}

export async function getSavedCardState(
	session: ProviderWriteSession,
	cards: readonly [
		firstCard: SavedCardIdentity,
		defaultCard: SavedCardIdentity,
	]
): Promise< SavedCardState > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'saved-card-state' );
	const [ firstCard, defaultCard ] = cards;
	if (
		firstCard.tokenId === defaultCard.tokenId ||
		firstCard.paymentMethodId === defaultCard.paymentMethodId
	) {
		throw new Error(
			'The two recorded saved cards must have distinct local and provider identities.'
		);
	}

	const evidence = await getSavedCardEvidence( session, defaultCard );
	for ( const [ index, card ] of cards.entries() ) {
		const localMatches = evidence.tokens.filter(
			( token ) => token.tokenId === card.tokenId
		);
		if (
			localMatches.length !== 1 ||
			localMatches[ 0 ].paymentMethodId !== card.paymentMethodId
		) {
			throw new Error(
				`The local token ${ card.tokenId } is not mapped exactly to ${ card.paymentMethodId }.`
			);
		}
		const shouldBeDefault = index === 1;
		if ( localMatches[ 0 ].isDefault !== shouldBeDefault ) {
			throw new Error(
				shouldBeDefault
					? `The local token ${ card.tokenId } is not the exact default payment method.`
					: `The local token ${ card.tokenId } must not remain the default payment method.`
			);
		}
	}

	if ( ! evidence.providerCustomerId ) {
		throw new Error(
			'Named saved-card evidence did not contain an exact provider customer ID.'
		);
	}

	const providerIds = await getProviderPaymentMethodIds(
		session,
		evidence.providerCustomerId
	);
	for ( const card of cards ) {
		const providerMatches = providerIds.filter(
			( paymentMethodId ) => paymentMethodId === card.paymentMethodId
		);
		if ( providerMatches.length !== 1 ) {
			throw new Error(
				`Expected exactly one provider payment method ${ card.paymentMethodId }; found ${ providerMatches.length }.`
			);
		}
	}

	return {
		...defaultCard,
		isDefault: true,
		providerCustomerId: evidence.providerCustomerId,
	};
}

export async function payWithExactSavedCard(
	session: ProviderWriteSession,
	page: Page,
	card: SavedCardIdentity,
	checkout: 'classic' | 'blocks',
	runId: string
): Promise< number > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( `saved-card-${ checkout }` );
	const product = await session.createOwnedProduct( '10.99' );
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page
			.getByRole( 'button', {
				name: 'Add to cart',
				exact: true,
			} )
			.click()
	);
	await page.goto(
		checkout === 'classic' ? 'classic-checkout/' : 'checkout/'
	);
	const token = page.locator(
		checkout === 'classic'
			? `input.woocommerce-SavedPaymentMethods-tokenInput[name="wc-woocommerce_payments-payment-token"][value="${ card.tokenId }"]`
			: `input.wc-block-components-radio-control__input[name="radio-control-wc-payment-method-saved-tokens"][value="${ card.tokenId }"]`
	);
	const localTokenId = await token.getAttribute( 'value' );
	if ( localTokenId !== card.tokenId.toString() ) {
		throw new Error(
			`Saved-card checkout selection is not bound to local token ${ card.tokenId }.`
		);
	}
	await token.check();
	await session.withProviderSubmissionJournal(
		`saved-card-${ checkout }-checkout`,
		async () => {
			if ( checkout === 'blocks' ) {
				await submitBlocksCheckout( page, async ( button ) => {
					await session.performWrite( () => button.click() );
					return 'dispatched';
				} );
			} else {
				await session.performWrite( () =>
					page.getByRole( 'button', { name: /place order/i } ).click()
				);
			}
			try {
				await page.waitForURL(
					/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/
				);
				await expect(
					page.getByText( 'Your order has been received' )
				).toBeVisible();
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					`WooPayments saved-card ${ checkout } checkout submission has no proven outcome.`,
					'uncertain-provider-write',
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
		}
	);
	const orderId = session.getOrderIdFromUrl( page.url() );
	await session.setOrderRunId( orderId, runId );
	return orderId;
}

/*
 * ---------------------------------------------------------------------------
 * Native saved-method surfaces
 *
 * Everything above drives the *plugin* runtime's My Account form, which is what
 * the ephemeral cutover pilot needs: it creates plugin-origin cards and then
 * hands the store to native. The helpers below drive native's own surfaces, and
 * they are separate because the markup is: native prints its payment element in
 * `#wcpay-core-payment-element` and its shopper-facing error in
 * `#wcpay-core-payment-errors` (`WooPaymentsCheckoutBridge::render_payment_fields`),
 * where the plugin prints `.wcpay-upe-element` and routes errors elsewhere. A
 * client-only selector cannot find a native field.
 *
 * These helpers observe and prove the outcome of one submission; they assert
 * nothing about whether that outcome is the contracted one. Reading the result
 * belongs to the test that stated the contract.
 * ---------------------------------------------------------------------------
 */

const NATIVE_ADD_FORM = '#add_payment_method';
const NATIVE_ADD_CARD_FRAME = `${ NATIVE_ADD_FORM } #wcpay-core-payment-element iframe[name^="__privateStripeFrame"]`;
const NATIVE_ADD_ERROR = `${ NATIVE_ADD_FORM } #wcpay-core-payment-errors`;
const WOOPAYMENTS_GATEWAY = 'woocommerce_payments';
// `WC_Form_Handler::add_payment_method_action()` prints this on a successful
// add; native's own gateway result only decides whether it appears.
const ADD_SUCCESS_NOTICE = 'Payment method successfully added.';
// One submission may create a provider payment method, a SetupIntent and a Woo
// token across three hops, so the outcome budget is deliberately wider than the
// suite's default expectation timeout.
const ADD_OUTCOME_TIMEOUT_MS = 45_000;

export type SavedCardToken = SavedCardEvidence[ 'tokens' ][ number ];

/**
 * One `create_setup_intent` exchange, reduced to its public-safe parts. The
 * client secret the route also returns is deliberately never recorded.
 */
export interface NativeSetupIntentExchange {
	httpStatus: number;
	setupIntentId: string;
	setupIntentStatus: string;
	errorMessage: string;
}

export interface NativeAddPaymentMethodObservation {
	/** Every `create_setup_intent` AJAX exchange, in arrival order. */
	setupIntentExchanges: NativeSetupIntentExchange[];
	/** The `wcpay-setup-intent` value carried by each add-form submission. */
	submittedSetupIntentIds: string[];
	formSubmissionCount: number;
	/** Local tokens that exist now and did not exist before the submission. */
	createdCards: SavedCardIdentity[];
	tokensBefore: SavedCardToken[];
	tokensAfter: SavedCardToken[];
	paymentError: { visible: boolean; role: string; text: string };
	successNoticeVisible: boolean;
}

export interface NativeAddPaymentMethodOptions {
	card: ProviderTestCard;
	/** Provider submission journal description for this one submission. */
	journal: string;
	/**
	 * `accepted` waits out WooCommerce's add-payment-method rate limit first and
	 * requires the success notice; `rejected` requires the limit to be active
	 * before anything is clicked, so a run cannot spend a provider write on a
	 * cooldown that already expired.
	 */
	expectation: 'accepted' | 'rejected';
	logInAsCustomer?: boolean;
}

function savedCardFailure( message: string ): never {
	throw new Error( `Native saved-method surface ${ message }` );
}

/**
 * The provider customer ID the store holds for the E2E shopper, when it can
 * disclose one.
 *
 * Two disclosures exist and both are used, because a caller must not depend on
 * which one the store it is driving offers. Unnamed evidence reports the
 * persisted customer directly when there is one. Named evidence reports it
 * alongside a token that is the shopper's current default, which covers a store
 * whose evidence route predates the unnamed disclosure. Neither path changes
 * anything: a shopper who has never reached the provider simply has no ID.
 */
export async function findSavedCardProviderCustomerId(
	session: ProviderWriteSession
): Promise< string | undefined > {
	const evidence = await getSavedCardEvidence( session );
	if ( evidence.providerCustomerId ) {
		return evidence.providerCustomerId;
	}

	const defaultToken = evidence.tokens.find( ( token ) => token.isDefault );
	if ( ! defaultToken ) {
		return undefined;
	}
	return (
		await getSavedCardEvidence( session, {
			tokenId: defaultToken.tokenId,
			paymentMethodId: defaultToken.paymentMethodId,
		} )
	).providerCustomerId;
}

/**
 * The provider customer ID, required. Callers that already created or found a
 * card cannot proceed without it: there is no other way to read the provider's
 * attachment list for this shopper.
 */
export async function readSavedCardProviderCustomerId(
	session: ProviderWriteSession
): Promise< string > {
	const providerCustomerId = await findSavedCardProviderCustomerId( session );
	if ( ! providerCustomerId ) {
		savedCardFailure(
			'disclosed no exact provider customer ID for the E2E shopper.'
		);
	}
	return providerCustomerId;
}

/**
 * Requires WooCommerce's 20-second add-payment-method rate limit to be active.
 *
 * Read before anything is clicked: a cooldown that has already expired would
 * turn a rejection contract into a silent second card at the provider.
 */
async function requireAddPaymentMethodCooldown(
	session: ProviderWriteSession
): Promise< SavedCardEvidence > {
	const evidence = await getSavedCardEvidence( session );
	if ( evidence.creationReady ) {
		savedCardFailure(
			'requires the add-payment-method cooldown to still be active; it is not, so a second add would attach a card instead of being rejected.'
		);
	}
	return evidence;
}

async function openNativeAddPaymentMethodForm( page: Page ): Promise< void > {
	await page.goto( 'my-account/payment-methods/' );
	const addLink = page.getByRole( 'link', { name: /add payment method/i } );
	if ( ( await addLink.count() ) !== 1 ) {
		savedCardFailure(
			'requires exactly one Add payment method link on the saved-methods page.'
		);
	}
	await addLink.click();
	const form = page.locator( NATIVE_ADD_FORM );
	await form.waitFor( { state: 'visible' } );
}

/**
 * Chooses the WooPayments Card method on the add-payment-method form.
 *
 * Located by input name rather than by role because WooCommerce renders the
 * radio and then hides it when the store offers a single method, and a hidden
 * input has no accessibility role. The contract is that the shopper adds a
 * WooPayments Card either way.
 */
async function selectNativeCardGateway( page: Page ): Promise< void > {
	const form = page.locator( NATIVE_ADD_FORM );
	const woopayments = form.locator(
		`input[name="payment_method"][value="${ WOOPAYMENTS_GATEWAY }"]`
	);
	if ( ( await woopayments.count() ) !== 1 ) {
		savedCardFailure(
			'requires exactly one WooPayments Card method on the add-payment-method form.'
		);
	}
	const label = form.locator(
		`label[for="payment_method_${ WOOPAYMENTS_GATEWAY }"]`
	);
	if ( ( await label.count() ) !== 1 ) {
		savedCardFailure( 'requires exactly one labelled Card method.' );
	}

	if ( await woopayments.isVisible() ) {
		await woopayments.check();
		return;
	}
	if ( ! ( await woopayments.isChecked() ) ) {
		savedCardFailure(
			'found the sole Card method hidden and unselected, so no method is chosen.'
		);
	}
	if (
		( await form.locator( 'input[name="payment_method"]' ).count() ) !== 1
	) {
		savedCardFailure(
			'found a hidden Card method next to other methods, so the chosen method is ambiguous.'
		);
	}
}

/**
 * Fills native's payment element on the add-payment-method form.
 *
 * The billing country and postcode are part of the element on this surface -
 * unlike the checkout, native does not set those fields to `never` - so they
 * are filled when the provider renders them.
 */
async function fillNativeAddPaymentMethodCard(
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	const iframe = page.locator( NATIVE_ADD_CARD_FRAME );
	await iframe.first().waitFor( { state: 'visible' } );
	if ( ( await iframe.count() ) !== 1 ) {
		savedCardFailure(
			'requires exactly one native payment element frame on the add-payment-method form.'
		);
	}

	const frame = page.frameLocator( NATIVE_ADD_CARD_FRAME );
	await enterProviderCardEntry(
		[
			{
				label: 'card number',
				locator: frame.getByRole( 'textbox', { name: 'Card number' } ),
				value: card.number,
				kind: 'digits',
			},
			{
				label: 'expiry',
				locator: frame.getByRole( 'textbox', {
					name: CARD_EXPIRY_FIELD_NAME,
				} ),
				value: card.expiry,
				kind: 'digits',
			},
			{
				label: 'security code',
				locator: frame.getByRole( 'textbox', {
					name: 'Security code',
				} ),
				value: card.securityCode,
				kind: 'digits',
			},
			{
				label: 'billing country',
				locator: frame.getByRole( 'combobox', { name: /country/i } ),
				value: 'US',
				kind: 'option',
				optional: true,
			},
			{
				label: 'billing postcode',
				locator: frame.getByRole( 'textbox', {
					name: /^(?:ZIP|Postal code)/i,
				} ),
				value: '94107',
				kind: 'digits',
				optional: true,
			},
		],
		'add-payment-method',
		savedCardFailure
	);
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
): NativeSetupIntentExchange {
	const payload =
		typeof body === 'object' && body !== null
			? ( body as { data?: unknown } ).data
			: undefined;
	const data =
		typeof payload === 'object' && payload !== null
			? ( payload as {
					id?: unknown;
					status?: unknown;
					error?: unknown;
			  } )
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

async function readNativeAddPaymentError(
	page: Page
): Promise< NativeAddPaymentMethodObservation[ 'paymentError' ] > {
	const error = page.locator( NATIVE_ADD_ERROR );
	if ( ( await error.count() ) !== 1 ) {
		return { visible: false, role: '', text: '' };
	}
	return {
		visible: await error.isVisible(),
		role: ( await error.getAttribute( 'role' ) ) ?? '',
		text: ( ( await error.textContent() ) ?? '' ).trim(),
	};
}

/**
 * Waits for an accepted add to reach an outcome, and requires that outcome to
 * be the success notice.
 *
 * The refusal region is raced against the notice rather than ignored, because
 * of what the alternative costs: an add native turns away shows its reason at
 * once, and waiting the whole outcome budget for a notice that will never
 * appear spends forty-five seconds and then reports "no proven outcome" for a
 * submission whose outcome was stated on screen the entire time. The refusal
 * is still a failure, and still an uncertain one as far as the provider is
 * concerned - a refusal can follow a provider write as easily as precede one -
 * it is simply a described failure now.
 */
async function requireAcceptedAddOutcome( page: Page ): Promise< void > {
	const notice = page.getByText( ADD_SUCCESS_NOTICE, { exact: true } );
	const refusal = page.locator( NATIVE_ADD_ERROR );
	await expect(
		notice.or( refusal ).first(),
		'an add must reach either the success notice or a stated refusal'
	).toBeVisible( { timeout: ADD_OUTCOME_TIMEOUT_MS } );
	if ( await notice.isVisible() ) {
		return;
	}
	savedCardFailure(
		`refused an add that had to succeed: "${ (
			( await refusal.textContent() ) ?? ''
		).trim() }".`
	);
}

/**
 * Submits the native My Account add-payment-method form exactly once and
 * reports what the store, the provider bridge and the local token store did.
 *
 * The whole interval runs inside one provider submission journal: the browser
 * creates a provider payment method before native's own rate limit or gateway
 * ever answers, so even a submission native turns away has touched the
 * provider and must not be able to disappear silently.
 */
export async function submitNativeAddPaymentMethod(
	session: ProviderWriteSession,
	page: Page,
	options: NativeAddPaymentMethodOptions
): Promise< NativeAddPaymentMethodObservation > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'saved-card-add' );

	const before =
		options.expectation === 'accepted'
			? await waitForSavedCardCreationReady( session )
			: await requireAddPaymentMethodCooldown( session );

	if ( options.logInAsCustomer ) {
		await session.logInAsCustomer( page );
	}
	await openNativeAddPaymentMethodForm( page );
	await selectNativeCardGateway( page );
	await fillNativeAddPaymentMethodCard( page, options.card );

	// Bodies are read the moment the response arrives rather than after the
	// interval: an accepted add navigates away, and a body read after that
	// navigation can no longer be fetched from the browser.
	const setupIntentExchanges: Array< Promise< NativeSetupIntentExchange > > =
		[];
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
					readSetupIntentExchange( response.status(), body )
				)
				.catch( () =>
					readSetupIntentExchange( response.status(), undefined )
				)
		);
	};
	page.on( 'request', onRequest );
	page.on( 'response', onResponse );

	try {
		return await session.withProviderSubmissionJournal(
			options.journal,
			async () => {
				let submissionAttempted = false;
				try {
					const submit = page.getByRole( 'button', {
						name: 'Add payment method',
						exact: true,
					} );
					await session.performWrite( () => {
						submissionAttempted = true;
						return submit.click();
					} );

					if ( options.expectation === 'accepted' ) {
						await requireAcceptedAddOutcome( page );
					} else {
						await expect(
							page.locator( NATIVE_ADD_ERROR )
						).toBeVisible( { timeout: ADD_OUTCOME_TIMEOUT_MS } );
					}

					const exchanges = await Promise.all( setupIntentExchanges );

					const after = await getSavedCardEvidence( session );
					for ( const token of before.tokens ) {
						const preserved = after.tokens.find(
							( candidate ) => candidate.tokenId === token.tokenId
						);
						if (
							! preserved ||
							preserved.paymentMethodId !== token.paymentMethodId
						) {
							savedCardFailure(
								`remapped or dropped the pre-existing local token ${ token.tokenId }.`
							);
						}
					}
					const known = new Set(
						before.tokens.map( ( token ) => token.tokenId )
					);
					const createdCards = after.tokens
						.filter( ( token ) => ! known.has( token.tokenId ) )
						.map( ( token ) => ( {
							tokenId: token.tokenId,
							paymentMethodId: token.paymentMethodId,
						} ) );
					// An accepted add whose local effect is not exactly one new
					// token leaves a provider method this run cannot name, which
					// is the uncertain outcome the journal exists for. A
					// rejected add reports whatever it finds so its caller can
					// both fail and clean up.
					if (
						options.expectation === 'accepted' &&
						createdCards.length !== 1
					) {
						savedCardFailure(
							`accepted one add and found ${ createdCards.length } new local tokens.`
						);
					}

					return {
						setupIntentExchanges: exchanges,
						submittedSetupIntentIds,
						formSubmissionCount,
						createdCards,
						tokensBefore: before.tokens,
						tokensAfter: after.tokens,
						paymentError: await readNativeAddPaymentError( page ),
						successNoticeVisible: await page
							.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
							.isVisible(),
					};
				} catch ( error ) {
					if ( ! submissionAttempted ) {
						throw new ProviderSubmissionNotStartedError(
							`Native add-payment-method submission ${ options.journal } was never dispatched.`,
							{ cause: error }
						);
					}
					if ( error instanceof ResourceQuarantineRequiredError ) {
						throw error;
					}
					throw new ResourceQuarantineRequiredError(
						`Native add-payment-method submission ${ options.journal } has no proven outcome.`,
						'uncertain-provider-write',
						error
					);
				}
			}
		);
	} finally {
		page.off( 'request', onRequest );
		page.off( 'response', onResponse );
	}
}
