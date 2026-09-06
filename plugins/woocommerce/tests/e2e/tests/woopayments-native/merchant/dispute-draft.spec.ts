import type {
	APIRequestContext,
	APIResponse,
	Page,
	Request,
	Response,
} from '@playwright/test';

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
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import { fillBlocksCheckoutAddress } from '../../../utils/woopayments-native/drivers/checkout';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import { DISPUTED_FRAUDULENT_CARD } from '../../../utils/woopayments-native/test-cards';

const CONTRACT =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-respond.spec.ts:497::Disputes › Respond to a dispute › Save a dispute challenge without submitting evidence';

const CAPABILITIES = [
	'dispute-draft',
	'product/payment',
	'dispute-lifecycle-card',
	'dispute-lifecycle-navigation',
	'dispute-draft-save',
] as const;

const PRICE = '50.00';
const AMOUNT_MINOR = 5000;
const CURRENCY = 'USD';
const DISPUTE_REASON = 'fraudulent';
const ACTIONABLE_STATUS = 'needs_response';
const PRODUCT_TYPE = 'offline_service';
const PRODUCT_TYPE_METADATA_KEY = '__product_type';
const SUBMITTED_AT_METADATA_KEY = '__evidence_submitted_at';

const CHECKOUT_TIMEOUT_MS = 60_000;
const DISPUTE_CREATION_BUDGET_MS = 180_000;
const DRAFT_READBACK_BUDGET_MS = 40_000;
const DISPUTE_READ_INTERVAL_MS = 3_000;

interface DisputeRecord {
	id: string;
	chargeId: string;
	orderId: number;
	status: string;
	reason: string;
	submissionCount: number;
	productDescription: string;
	productType: string;
	hasSubmittedAt: boolean;
}

function fail( message: string ): never {
	throw new Error( `WooPayments dispute draft ${ message }` );
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function requiredObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `requires ${ label } to be an object.` );
	}
	return value as Record< string, unknown >;
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `requires a non-empty ${ label }.` );
	}
	return value;
}

function requiredNumber( value: unknown, label: string ): number {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		fail( `requires a numeric ${ label }.` );
	}
	return value;
}

function relatedObjectId( value: unknown ): string {
	if (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		'id' in value
	) {
		return typeof value.id === 'string' ? value.id : '';
	}
	return typeof value === 'string' ? value : '';
}

async function readJson< Result >(
	response: APIResponse,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

function toDisputeRecord( payload: unknown ): DisputeRecord {
	const dispute = requiredObject( payload, 'the dispute response' );
	const evidenceDetails = requiredObject(
		dispute.evidence_details,
		'the dispute evidence details'
	);
	const evidence =
		typeof dispute.evidence === 'object' &&
		dispute.evidence !== null &&
		! Array.isArray( dispute.evidence )
			? ( dispute.evidence as Record< string, unknown > )
			: {};
	const metadata =
		typeof dispute.metadata === 'object' &&
		dispute.metadata !== null &&
		! Array.isArray( dispute.metadata )
			? ( dispute.metadata as Record< string, unknown > )
			: {};
	const order =
		typeof dispute.order === 'object' &&
		dispute.order !== null &&
		! Array.isArray( dispute.order )
			? ( dispute.order as Record< string, unknown > )
			: {};
	const submittedAt = metadata[ SUBMITTED_AT_METADATA_KEY ];

	return {
		id: requiredString( dispute.id, 'dispute ID' ),
		chargeId: requiredString(
			relatedObjectId( dispute.charge ),
			'dispute charge ID'
		),
		orderId:
			typeof order.id === 'number' && Number.isFinite( order.id )
				? order.id
				: 0,
		status: requiredString( dispute.status, 'dispute status' ),
		reason: requiredString( dispute.reason, 'dispute reason' ),
		submissionCount: requiredNumber(
			evidenceDetails.submission_count,
			'dispute submission count'
		),
		productDescription:
			typeof evidence.product_description === 'string'
				? evidence.product_description
				: '',
		productType:
			typeof metadata[ PRODUCT_TYPE_METADATA_KEY ] === 'string'
				? ( metadata[ PRODUCT_TYPE_METADATA_KEY ] as string )
				: '',
		hasSubmittedAt:
			submittedAt !== undefined &&
			submittedAt !== null &&
			submittedAt !== '',
	};
}

async function readDispute(
	restApi: APIRequestContext,
	disputeId: string
): Promise< DisputeRecord > {
	const dispute = toDisputeRecord(
		await readJson< unknown >(
			await restApi.get(
				`/wp-json/wc/v3/payments/disputes/${ encodeURIComponent(
					disputeId
				) }`
			),
			`Dispute ${ disputeId } read`
		)
	);
	if ( dispute.id !== disputeId ) {
		fail(
			`identity changed: requested dispute ${ disputeId }, received ${ dispute.id }.`
		);
	}
	return dispute;
}

async function readChargeDisputeId(
	restApi: APIRequestContext,
	payment: PaymentEvidence
): Promise< string > {
	const charge = await readJson< Record< string, unknown > >(
		await restApi.get(
			`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
				payment.chargeId
			) }`
		),
		`Charge ${ payment.chargeId } read`
	);

	if ( charge.id !== payment.chargeId ) {
		fail(
			`charge identity changed: expected ${
				payment.chargeId
			}, received ${ String( charge.id ) }.`
		);
	}
	if ( relatedObjectId( charge.payment_intent ) !== payment.intentId ) {
		fail(
			`charge ${ payment.chargeId } no longer belongs to payment intent ${ payment.intentId }.`
		);
	}

	const disputeId = relatedObjectId( charge.dispute );
	if ( charge.disputed === true && ! disputeId ) {
		fail(
			`charge ${ payment.chargeId } is disputed but exposes no dispute identity.`
		);
	}
	return disputeId;
}

async function waitForCreatedDispute(
	restApi: APIRequestContext,
	payment: PaymentEvidence
): Promise< DisputeRecord > {
	const deadline = Date.now() + DISPUTE_CREATION_BUDGET_MS;
	let lastSeen = 'no dispute on the exact charge';

	for (;;) {
		const disputeId = await readChargeDisputeId( restApi, payment );
		if ( disputeId ) {
			const dispute = await readDispute( restApi, disputeId );
			if ( dispute.chargeId !== payment.chargeId ) {
				fail(
					`dispute ${ dispute.id } belongs to ${ dispute.chargeId }, not run charge ${ payment.chargeId }.`
				);
			}
			if ( dispute.orderId && dispute.orderId !== payment.orderId ) {
				fail(
					`dispute ${ dispute.id } resolves to order ${ dispute.orderId }, not run order ${ payment.orderId }.`
				);
			}
			lastSeen = `${ dispute.id } ${ dispute.status }, order ${
				dispute.orderId || 'not enriched yet'
			}`;

			if (
				dispute.orderId === payment.orderId &&
				dispute.status === ACTIONABLE_STATUS
			) {
				return dispute;
			}
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`charge ${ payment.chargeId } did not expose its actionable, order-correlated dispute within ${ DISPUTE_CREATION_BUDGET_MS }ms (last seen: ${ lastSeen }).`
			);
		}
		await delay( Math.min( DISPUTE_READ_INTERVAL_MS, remaining ) );
	}
}

function requireCapabilities( session: ProviderWriteSession ): void {
	for ( const capability of CAPABILITIES ) {
		session.requireApprovedProviderFixture( capability );
	}
}

async function completeDisputedCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct
): Promise< number > {
	await session.assertCanWrite();
	await page.context().clearCookies();
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutAddress( page, session.runId );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
	await enterProviderCardTriple(
		page.frameLocator( getBlocksCardFrameSelector( session.runtime ) ),
		DISPUTED_FRAUDULENT_CARD,
		'Blocks checkout'
	);
	await page.getByRole( 'button', { name: /place order/i } ).focus();

	await session.withProviderSubmissionJournal(
		'dispute-draft-card-checkout',
		async () => {
			await submitBlocksCheckout( page, async ( button ) => {
				await session.performWrite( () => button.click() );
				return 'dispatched';
			} );
			try {
				await page.waitForURL(
					/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/,
					{ timeout: CHECKOUT_TIMEOUT_MS }
				);
				await expect(
					page.getByText(
						/^(Your order has been received|Order received)$/i
					)
				).toBeVisible();
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					'The disputed-card checkout has no proven outcome.',
					'uncertain-provider-write',
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
		}
	);

	const orderId = session.getOrderIdFromUrl( page.url() );
	await session.setOrderRunId( orderId, session.runId );
	return orderId;
}

function disputeRestPath( disputeId: string ): string {
	return `/wc/v3/payments/disputes/${ encodeURIComponent( disputeId ) }`;
}

function requestUsesExactRestPath(
	request: Request,
	method: 'GET' | 'POST',
	restPath: string
): boolean {
	if ( request.method() !== method ) {
		return false;
	}
	const url = new URL( request.url() );
	const queryRoute = url.searchParams.get( 'rest_route' );
	if ( queryRoute ) {
		return queryRoute.replace( /\/+$/, '' ) === restPath;
	}
	const marker = '/wp-json';
	const markerIndex = url.pathname.indexOf( marker );
	return (
		markerIndex >= 0 &&
		url.pathname
			.slice( markerIndex + marker.length )
			.replace( /\/+$/, '' ) === restPath
	);
}

function disputeDetailsUrl( disputeId: string ): string {
	return `wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fdisputes%2Fdetails&id=${ encodeURIComponent(
		disputeId
	) }`;
}

async function openDisputeDetails(
	page: Page,
	disputeId: string
): Promise< ReturnType< Page[ 'getByRole' ] > > {
	await page.goto( disputeDetailsUrl( disputeId ) );
	await expect(
		page.getByRole( 'heading', { name: 'Dispute details', exact: true } )
	).toBeVisible( { timeout: CHECKOUT_TIMEOUT_MS } );
	await expect( page.getByText( disputeId, { exact: true } ) ).toBeVisible();

	const challenge = page.getByRole( 'link', {
		name: 'Challenge dispute',
		exact: true,
	} );
	await expect( challenge ).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Accept dispute', exact: true } )
	).toBeVisible();
	return challenge;
}

async function enterChallengeFromDetails(
	page: Page,
	disputeId: string,
	challenge: ReturnType< Page[ 'getByRole' ] >
): Promise< void > {
	const restPath = disputeRestPath( disputeId );
	const freshRead = page.waitForResponse(
		( response ) =>
			requestUsesExactRestPath( response.request(), 'GET', restPath ),
		{ timeout: CHECKOUT_TIMEOUT_MS }
	);
	await challenge.click();
	const response = await freshRead;
	expect( response.ok() ).toBe( true );
	await expect(
		page.getByRole( 'heading', { name: 'Challenge dispute', exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', {
			name: "Let's gather the basics",
			exact: true,
		} )
	).toBeVisible();
}

function expectDraftState(
	dispute: DisputeRecord,
	payment: PaymentEvidence,
	description: string
): void {
	expect( dispute.chargeId ).toBe( payment.chargeId );
	expect( dispute.orderId ).toBe( payment.orderId );
	expect( dispute.status ).toBe( ACTIONABLE_STATUS );
	expect( dispute.reason ).toBe( DISPUTE_REASON );
	expect( dispute.submissionCount ).toBe( 0 );
	expect( dispute.hasSubmittedAt ).toBe( false );
	expect( dispute.productDescription ).toBe( description );
	expect( dispute.productType ).toBe( PRODUCT_TYPE );
}

async function waitForDurableDraft(
	restApi: APIRequestContext,
	disputeId: string,
	payment: PaymentEvidence,
	description: string
): Promise< DisputeRecord > {
	const deadline = Date.now() + DRAFT_READBACK_BUDGET_MS;
	let lastSeen = 'no read yet';

	for (;;) {
		const dispute = await readDispute( restApi, disputeId );
		if ( dispute.chargeId !== payment.chargeId ) {
			fail( `dispute ${ dispute.id } changed charge during draft save.` );
		}
		if ( dispute.orderId !== payment.orderId ) {
			fail( `dispute ${ dispute.id } changed order during draft save.` );
		}
		if (
			dispute.status !== ACTIONABLE_STATUS ||
			dispute.submissionCount !== 0 ||
			dispute.hasSubmittedAt
		) {
			fail(
				`save submitted or closed dispute ${
					dispute.id
				} instead of preserving a draft (${
					dispute.status
				}, submission count ${
					dispute.submissionCount
				}, submitted-at ${ String( dispute.hasSubmittedAt ) }).`
			);
		}
		lastSeen = `description ${ JSON.stringify(
			dispute.productDescription
		) }, product type ${ JSON.stringify( dispute.productType ) }`;
		if (
			dispute.productDescription === description &&
			dispute.productType === PRODUCT_TYPE
		) {
			return dispute;
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			throw new ResourceQuarantineRequiredError(
				`Dispute ${ dispute.id } acknowledged one draft write but did not return that draft within ${ DRAFT_READBACK_BUDGET_MS }ms (last seen: ${ lastSeen }).`,
				'uncertain-provider-write'
			);
		}
		await delay( Math.min( DISPUTE_READ_INTERVAL_MS, remaining ) );
	}
}

test.describe( 'native dispute draft persistence', () => {
	test(
		'one acknowledged dispute draft write survives navigation and read-only re-entry without submission or replay',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT,
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { adminApi, page, pilotRuntime } ) => {
			test.setTimeout( 420_000 );
			requireCapabilities( pilotRuntime );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'dispute-draft' },
				async () => {
					const product =
						await pilotRuntime.createOwnedProduct( PRICE );
					const orderId = await completeDisputedCardCheckout(
						pilotRuntime,
						page,
						product
					);
					const payment = await getPaymentEvidence(
						adminApi,
						orderId
					);
					expect( payment.runId ).toBe( pilotRuntime.runId );
					expect( payment.orderId ).toBe( orderId );
					expect( payment.amountMinor ).toBe( AMOUNT_MINOR );
					expect( payment.currency ).toBe( CURRENCY );
					expect( payment.providerStatus ).toBe( 'succeeded' );
					expect( payment.chargeStatus ).toBe( 'succeeded' );
					expect( payment.chargeCaptured ).toBe( true );
					expect( payment.occurrenceCount ).toBe( 1 );
					expect( payment.captureOccurrenceCount ).toBe( 1 );

					const created = await waitForCreatedDispute(
						adminApi,
						payment
					);
					expect( created.reason ).toBe( DISPUTE_REASON );
					expect( created.submissionCount ).toBe( 0 );
					expect( created.hasSubmittedAt ).toBe( false );

					await pilotRuntime.logInAsAdmin( page );
					await page.waitForURL( '**/wp-admin/**' );
					const firstChallenge = await openDisputeDetails(
						page,
						created.id
					);
					await enterChallengeFromDetails(
						page,
						created.id,
						firstChallenge
					);

					const description = `WooPayments native draft ${ pilotRuntime.runId }`;
					await page
						.getByRole( 'combobox', {
							name: 'Product type',
							exact: true,
						} )
						.selectOption( PRODUCT_TYPE );
					const descriptionField = page.getByRole( 'textbox', {
						name: 'Product description',
						exact: true,
					} );
					await descriptionField.fill( description );
					await descriptionField.press( 'Tab' );
					await expect( descriptionField ).toHaveValue( description );

					const restPath = disputeRestPath( created.id );
					const draftRequests: Request[] = [];
					const observeDraftRequest = ( request: Request ): void => {
						if (
							requestUsesExactRestPath(
								request,
								'POST',
								restPath
							)
						) {
							draftRequests.push( request );
						}
					};
					page.on( 'request', observeDraftRequest );

					try {
						await pilotRuntime.withProviderSubmissionJournal(
							`dispute-draft-save-${ created.id }`,
							async () => {
								const acknowledged = page.waitForResponse(
									( response ) =>
										requestUsesExactRestPath(
											response.request(),
											'POST',
											restPath
										),
									{ timeout: CHECKOUT_TIMEOUT_MS }
								);
								void acknowledged.catch( () => {} );

								try {
									await pilotRuntime.performWrite( () =>
										page
											.getByRole( 'button', {
												name: 'Save draft',
												exact: true,
											} )
											.click()
									);
								} catch ( error ) {
									if ( draftRequests.length === 0 ) {
										throw new ProviderSubmissionNotStartedError(
											`Dispute ${ created.id } draft save was not dispatched.`,
											{ cause: error }
										);
									}
									throw error;
								}

								let response: Response;
								try {
									response = await acknowledged;
								} catch ( error ) {
									if ( draftRequests.length === 0 ) {
										throw new ProviderSubmissionNotStartedError(
											`Dispute ${ created.id } draft save emitted no request.`,
											{ cause: error }
										);
									}
									throw error;
								}

								expect( response.ok() ).toBe( true );
								expect( draftRequests ).toHaveLength( 1 );
								const body =
									draftRequests[ 0 ].postDataJSON() as {
										evidence?: Record< string, unknown >;
										metadata?: Record< string, unknown >;
										submit?: unknown;
									};
								expect( body.submit ).toBe( false );
								expect(
									body.evidence?.product_description
								).toBe( description );
								expect(
									body.metadata?.[ PRODUCT_TYPE_METADATA_KEY ]
								).toBe( PRODUCT_TYPE );
								await expect(
									page.getByRole( 'status' ).filter( {
										hasText: 'Evidence saved!',
									} )
								).toHaveText( 'Evidence saved!' );

								// The journal remains unresolved until read-only GETs
								// prove the acknowledged write is durable. It performs
								// no replay: missing readback quarantines this graph.
								return waitForDurableDraft(
									adminApi,
									created.id,
									payment,
									description
								);
							}
						);

						// Leave the form and re-enter from a fresh native detail
						// surface. From here on, failures are product failures:
						// the journal has already proved the write's outcome.
						const freshChallenge = await openDisputeDetails(
							page,
							created.id
						);
						await enterChallengeFromDetails(
							page,
							created.id,
							freshChallenge
						);
						await expect(
							page.getByRole( 'combobox', {
								name: 'Product type',
								exact: true,
							} )
						).toHaveValue( PRODUCT_TYPE );
						await expect(
							page.getByRole( 'textbox', {
								name: 'Product description',
								exact: true,
							} )
						).toHaveValue( description );

						const finalServerState = await readDispute(
							adminApi,
							created.id
						);
						expectDraftState(
							finalServerState,
							payment,
							description
						);

						// Return once more to the actionable details state: a
						// draft must preserve both merchant decisions.
						await openDisputeDetails( page, created.id );
						expect( draftRequests ).toHaveLength( 1 );
					} finally {
						page.off( 'request', observeDraftRequest );
					}
				}
			);
		}
	);
} );
