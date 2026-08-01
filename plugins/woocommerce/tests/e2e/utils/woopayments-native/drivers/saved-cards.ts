import { expect, type Locator, type Page } from '@playwright/test';

import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import { submitBlocksCheckout } from './checkout';
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

interface SavedCardEvidence {
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

async function getSavedCardEvidence(
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

async function getProviderPaymentMethodIds(
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
		defaultCard: SavedCardIdentity
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
