import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	ResourceQuarantineRequiredError,
	submitBlocksCheckout,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import {
	getChargeWithTransportRetry,
	getWithReadRetry,
} from '../../../utils/woopayments-native/provider-evidence';
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import { DISPUTED_FRAUDULENT_CARD } from '../../../utils/woopayments-native/test-cards';

/**
 * The `dispute-lifecycle` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * Trimmed to `DP-nav`, the family's one retained browser smoke (`data/t1-provider-family-audit.md`
 * §3): a real provider-raised dispute keeps its exact dispute, charge and order
 * identities from checkout through the `on-hold` transition and the
 * dispute-created order note, and the note's link reaches the native dispute
 * details surface through the legacy deep-link redirect. Voluntary acceptance,
 * winning evidence and losing evidence (formerly `DP1`-`DP3`) moved to
 * `WooPaymentsDisputeEventHandlerTest`, `WooPaymentsEventIngestorTest`,
 * `WooPaymentsApiClientTest`, `WooPaymentsMoneyMovementRestControllerTest` and
 * `dispute-challenge-page.test.tsx`, backed by REC-5b (`Fixtures/rec-5b-disputes.json`,
 * `Fixtures/rec-5b-dispute-events.json`).
 *
 * Two things shape the case below.
 *
 * **Nothing here creates a dispute locally.** The only lever is the provider's
 * disputed-card fixture, `4000000000000259`: a captured charge on it is
 * disputed by the provider, asynchronously, as `fraudulent`. The dispute record
 * itself is then readable through native's own uncached dispute route, but
 * every *native* effect — the `on-hold` transition and the created note — is
 * written only by `WooPaymentsDisputeEventHandler` when
 * `WooPaymentsEventIngestor` ingests the matching provider event. A store whose
 * provider event listener is not delivering `charge.dispute.*` will therefore
 * fail at the convergence budget below rather than pass on a half-observed
 * graph, and the failure text says so. This suite does not synthesise provider
 * events, and it never returns early when an effect is missing.
 *
 * **Identity is the assertion.** The client row this case replaces asserted a
 * rendered status label; this case pins the exact dispute ID, charge ID and
 * order ID on every read, and fails the moment one is replaced.
 *
 * Cost note: one full pass creates one USD 50.00 card charge and one provider
 * dispute. Nothing is retried: `--retries=0` is mandatory, and an uncertain
 * terminal state quarantines the graph rather than being re-driven.
 */

/** Grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:dispute-lifecycle';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/** The journey capability every case in this family needs. */
const CAPABILITY_FAMILY = 'dispute-lifecycle';
/** Creating the run-owned products the disputed charges are made from. */
const CAPABILITY_PRODUCT = 'product/payment';
/** Entering the disputed-card fixture and paying with it. */
const CAPABILITY_DISPUTED_CARD = 'dispute-lifecycle-card';
/** Loading the disputed order and following its notice to dispute details. */
const CAPABILITY_NAVIGATION = 'dispute-lifecycle-navigation';

const CONTRACT_DP_NAV =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-view-details-via-order-notice.spec.ts:40::Disputes › View dispute details via disputed order notice › should navigate to dispute details when disputed order notice button clicked';

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const STORE_CURRENCY_API =
	'/wp-json/wc/v3/settings/general/woocommerce_currency';

/** The claim's fixture: one USD 50.00 disputed charge. */
const PRICE = '50.00';
const AMOUNT_MINOR = 5000;
const CURRENCY = 'USD';

/** What the provider raises for the disputed-card fixture. */
const DISPUTE_REASON = 'fraudulent';
const CREATED_STATUS = 'needs_response';
/** Native carries the challenge's product type as dispute metadata. */
const PRODUCT_TYPE_METADATA_KEY = '__product_type';

/** Convergence, as fixed by the claim. */
const CREATION_INTERVAL_MS = 5_000;
const CREATION_BUDGET_MS = 180_000;
/** The provider-backed admin surface `DP-nav` lands on fetches as it renders. */
const DETAILS_BUDGET_MS = 60_000;
const CHECKOUT_RECEIPT_TIMEOUT_MS = 60_000;

function fail( message: string ): never {
	throw new Error( `WooPayments dispute fidelity ${ message }` );
}

/** How long to wait before re-asking after a dead connection. */

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

/**
 * Asserts the whole capability set before any provider interval opens, so an
 * incomplete approval costs nothing rather than a paid run and a live dispute.
 */
function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
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

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `evidence requires a ${ label }.` );
	}
	return value;
}

function requiredNumber( value: unknown, label: string ): number {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		fail( `evidence requires a ${ label }.` );
	}
	return value;
}

function requiredBoolean( value: unknown, label: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		fail( `evidence requires a ${ label }.` );
	}
	return value;
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
		fail( `evidence requires ${ label } to be an object.` );
	}
	return value as Record< string, unknown >;
}

/**
 * The ID of a related provider object, which the platform sends either expanded
 * or as a bare identifier depending on the route.
 */
function relatedObjectId( value: unknown ): string {
	if (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		'id' in value
	) {
		const id = ( value as { id?: unknown } ).id;
		return typeof id === 'string' ? id : '';
	}
	return typeof value === 'string' ? value : '';
}

/* -------------------------------------------------------------------------
 * Provider dispute reads
 * ---------------------------------------------------------------------- */

/**
 * The dispute as native reports it, joined to the local order by native's own
 * enrichment rather than by anything this suite infers.
 */
interface ProviderDisputeRecord {
	id: string;
	chargeId: string;
	status: string;
	reason: string;
	amountMinor: number;
	currency: string;
	dueBy: number;
	submissionCount: number;
	hasEvidence: boolean;
	orderId: number;
	coverLetter: string;
	productType: string;
}

function toDisputeRecord( payload: unknown ): ProviderDisputeRecord {
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
	// Native's own order enrichment (`WooPaymentsMoneyMovementOrderService`),
	// which is the join this family asserts rather than re-derives. An empty
	// enrichment means native could not resolve this dispute's charge to a
	// local order, which is a finding rather than a shape to work around.
	const order = requiredObject(
		dispute.order,
		'the enriched dispute order (native resolved this dispute to no local order)'
	);

	return {
		id: requiredString( dispute.id, 'dispute ID' ),
		chargeId: requiredString(
			relatedObjectId( dispute.charge ),
			'dispute charge ID'
		),
		status: requiredString( dispute.status, 'dispute status' ),
		reason: requiredString( dispute.reason, 'dispute reason' ),
		amountMinor: requiredNumber( dispute.amount, 'dispute amount' ),
		currency: requiredString(
			dispute.currency,
			'dispute currency'
		).toUpperCase(),
		// The response deadline is present while a dispute can still be
		// answered and cleared once it is closed, so it is read rather than
		// required. The cases assert it where the claim needs it: non-zero on
		// creation, and carried unchanged into the action each case takes.
		dueBy:
			typeof evidenceDetails.due_by === 'number' &&
			Number.isFinite( evidenceDetails.due_by )
				? evidenceDetails.due_by
				: 0,
		submissionCount: requiredNumber(
			evidenceDetails.submission_count,
			'dispute evidence submission count'
		),
		hasEvidence: requiredBoolean(
			evidenceDetails.has_evidence,
			'dispute has-evidence flag'
		),
		orderId: requiredNumber( order.id, 'enriched dispute order ID' ),
		coverLetter:
			typeof evidence.uncategorized_text === 'string'
				? evidence.uncategorized_text
				: '',
		productType:
			typeof metadata[ PRODUCT_TYPE_METADATA_KEY ] === 'string'
				? ( metadata[ PRODUCT_TYPE_METADATA_KEY ] as string )
				: '',
	};
}

async function readDispute(
	restApi: APIRequestContext,
	disputeId: string
): Promise< ProviderDisputeRecord > {
	const dispute = toDisputeRecord(
		await readJson< unknown >(
			// Read through the shared retry: this poll runs against a dispute
			// the provider is concurrently adjudicating, so a 429 lock_timeout
			// is routine and means "not reported yet", not "reported wrong".
			await getWithReadRetry(
				restApi,
				`/wp-json/wc/v3/payments/disputes/${ encodeURIComponent(
					disputeId
				) }`,
				`dispute ${ disputeId }`
			),
			`Provider dispute ${ disputeId } read`
		)
	);

	if ( dispute.id !== disputeId ) {
		fail(
			`dispute identity mismatch: asked for ${ disputeId }, received ${ dispute.id }.`
		);
	}

	return dispute;
}

/**
 * The dispute the provider has attached to this exact charge, or an empty
 * string while none exists yet. Read off the charge rather than off the
 * disputes list: the list is cached by `WooPaymentsDisputeCacheService`, and a
 * stale page of it would make "no dispute yet" and "a dispute this run cannot
 * see yet" indistinguishable.
 */
async function readChargeDisputeId(
	restApi: APIRequestContext,
	chargeId: string
): Promise< string > {
	const charge = await readJson< Record< string, unknown > >(
		await getChargeWithTransportRetry( restApi, chargeId ),
		`Provider charge ${ chargeId } read`
	);

	if ( charge.id !== chargeId ) {
		fail(
			`charge identity mismatch: expected ${ chargeId }, received ${ String(
				charge.id
			) }.`
		);
	}

	const disputeId = relatedObjectId( charge.dispute );
	// A charge the provider reports as disputed but names no dispute for is an
	// unknown response shape, not "no dispute yet"; polling on would burn the
	// whole creation budget waiting for a field that will never arrive.
	if ( charge.disputed === true && ! disputeId ) {
		fail(
			`charge ${ chargeId } reports itself disputed but names no dispute.`
		);
	}

	return disputeId;
}

/* -------------------------------------------------------------------------
 * Local order reads
 * ---------------------------------------------------------------------- */

async function readOrderStatus(
	restApi: APIRequestContext,
	orderId: number
): Promise< string > {
	const order = await readJson< { id?: unknown; status?: unknown } >(
		await restApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`Order ${ orderId } read`
	);

	if ( order.id !== orderId ) {
		fail(
			`order identity mismatch: expected ${ orderId }, received ${ String(
				order.id
			) }.`
		);
	}

	return requiredString( order.status, 'order status' );
}

async function readOrderNotes(
	restApi: APIRequestContext,
	orderId: number
): Promise< string[] > {
	const notes = await readJson< unknown[] >(
		await restApi.get(
			`/wp-json/wc/v3/orders/${ orderId }/notes?context=edit&per_page=100`
		),
		`Order ${ orderId } notes read`
	);

	return notes.map( ( item, index ) =>
		requiredString(
			( item as { note?: unknown } ).note,
			`order note ${ index + 1 }`
		)
	);
}

/* -------------------------------------------------------------------------
 * Native dispute side effects, read off the order's own notes
 * ---------------------------------------------------------------------- */

function createdDisputeNotes(
	notes: readonly string[],
	chargeId: string
): string[] {
	// The link the created note carries is `get_dispute_url( charge_id, … )`,
	// so matching on the charge keeps one shopper's note from being counted
	// against another's order if the harness ever mixes them up.
	const linksToCharge = new RegExp( `id=${ escapeRegExp( chargeId ) }\\b` );
	return notes.filter(
		( note ) =>
			note.includes( 'Payment has been disputed for' ) &&
			linksToCharge.test( note )
	);
}

/**
 * WooCommerce writes one note per status transition, so the order carries a
 * durable record of every status it passed through. A poll that samples the
 * current status every few seconds cannot tell "never transitioned" from
 * "transitioned and was moved back"; this record can.
 */
function statusTransitionNotes( notes: readonly string[] ): string[] {
	return notes.filter( ( note ) =>
		note.includes( 'Order status changed from' )
	);
}

/**
 * No creation or closure event produced two native effects.
 *
 * `WooPaymentsDisputeEventHandler` deduplicates by (dispute, provider status,
 * note type), so a repeated sentence is a repeated effect — but only where the
 * sentence itself distinguishes the event. The created and closed notes do:
 * one names the dispute's own charge link, the other names the terminal status.
 * The update-family notes deliberately do not, which is why they are excluded
 * here and counted directly by the cases that fix how many of them the claim
 * allows.
 */
function expectNoDuplicatedDisputeEffect( notes: readonly string[] ): void {
	const effects = notes.filter(
		( note ) =>
			note.includes( 'Payment has been disputed for' ) ||
			note.includes( 'Dispute has been closed with status' )
	);
	const distinct = new Set( effects );
	expect(
		distinct.size,
		`each dispute creation and closure event must produce exactly one native side effect; the order carries ${ effects.length } such notes with ${ distinct.size } distinct contents`
	).toBe( effects.length );
}

/* -------------------------------------------------------------------------
 * Store configuration: recorded, and proven unchanged
 * ---------------------------------------------------------------------- */

/**
 * This family mutates no store configuration: it pays with a card, closes one
 * dispute and submits two evidence payloads. "Restore byte-for-byte" therefore
 * degenerates to proving the recorded snapshot is still the snapshot, which is
 * also the unowned-delta check the claim's cleanup row asks for.
 */
async function readStoreConfigurationSnapshot(
	restApi: APIRequestContext
): Promise< string > {
	const settings = await readJson< Record< string, unknown > >(
		await restApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	const currency = await readJson< { value?: unknown } >(
		await restApi.get( STORE_CURRENCY_API ),
		'Store currency read'
	);

	return JSON.stringify( {
		enabled_payment_method_ids: settings.enabled_payment_method_ids,
		is_wcpay_enabled: settings.is_wcpay_enabled,
		is_manual_capture_enabled: settings.is_manual_capture_enabled,
		is_test_mode_enabled: settings.is_test_mode_enabled,
		store_currency: currency.value,
	} );
}

/**
 * Run `callback` and prove the recorded store configuration survived it
 * unchanged, without ever masking the case's own failure with a teardown one.
 */
async function withUnchangedStoreConfiguration(
	restApi: APIRequestContext,
	callback: () => Promise< void >
): Promise< void > {
	const recorded = await readStoreConfigurationSnapshot( restApi );

	let caseError: unknown;
	try {
		await callback();
	} catch ( error ) {
		caseError = error;
	}

	let restorationError: unknown;
	try {
		const observed = await readStoreConfigurationSnapshot( restApi );
		if ( observed !== recorded ) {
			restorationError = new ResourceQuarantineRequiredError(
				`The store configuration changed during a dispute case: recorded ${ recorded }, observed ${ observed }.`,
				'restoration-failed'
			);
		}
	} catch ( error ) {
		restorationError = new ResourceQuarantineRequiredError(
			'Re-reading the store configuration after a dispute case failed.',
			'restoration-failed',
			error
		);
	}

	if ( caseError !== undefined ) {
		throw caseError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
}

/* -------------------------------------------------------------------------
 * Shared creation: three independent disputed charges
 * ---------------------------------------------------------------------- */

/**
 * Fill the checkout as a fresh guest.
 *
 * The card checkout driver has an equivalent, but it is private; kept in step
 * with it by hand, exactly as `shopper/card-authentication.spec.ts` already
 * does. Returns whether the store served the Blocks surface, because the card
 * fields and the submission differ between the two and this family claims
 * neither surface.
 */
async function fillGuestCheckoutDetails(
	page: Page,
	email: string
): Promise< boolean > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const blocksAddress = ( await shipping.isVisible() ) ? shipping : billing;

	if ( await blocksAddress.isVisible() ) {
		await page
			.getByRole( 'textbox', { name: 'Email address' } )
			.fill( email );
		await blocksAddress
			.getByRole( 'combobox', { name: 'Country/Region' } )
			.selectOption( 'US' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'First name' } )
			.fill( 'E2E' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'Last name' } )
			.fill( 'WooPayments' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'Address', exact: true } )
			.fill( '123 Test Street' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'City', exact: true } )
			.fill( 'San Francisco' );
		await blocksAddress
			.getByRole( 'combobox', { name: 'State', exact: true } )
			.selectOption( 'CA' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'ZIP Code' } )
			.fill( '94107' );
		await blocksAddress
			.getByRole( 'textbox', { name: 'Phone (optional)' } )
			.fill( '5555550100' );
		await page
			.getByRole( 'group', { name: 'Payment options' } )
			.getByRole( 'radio', { name: /Card/i } )
			.first()
			.check();
		return true;
	}

	await page.getByRole( 'textbox', { name: /first name/i } ).fill( 'E2E' );
	await page
		.getByRole( 'textbox', { name: /last name/i } )
		.fill( 'WooPayments' );
	await page
		.getByRole( 'textbox', { name: /street address/i } )
		.fill( '123 Test Street' );
	await page
		.getByRole( 'textbox', { name: /town|city/i } )
		.fill( 'San Francisco' );
	await page
		.getByRole( 'textbox', { name: /zip|postcode/i } )
		.fill( '94107' );
	await page.getByRole( 'textbox', { name: /phone/i } ).fill( '5555550100' );
	await page.getByRole( 'textbox', { name: /email/i } ).fill( email );
	await page.getByLabel( /WooPayments|credit card/i ).check();
	return false;
}

/**
 * The billing phone still holds a phone number, and not a card.
 *
 * The third checkout of a run has twice stored
 * `<phone><card PAN>` in `billing_phone` and failed with no provider object,
 * while the first two stored the phone alone. Every `fill` in those runs
 * targeted its intended element — the trace shows the card going into the
 * provider iframe each time — so the corruption is not the driver typing in the
 * wrong place. Checking here says whether the corrupted value exists in the
 * browser *before* the submission or appears between the form and the stored
 * order, and fails at the point of corruption rather than sixty seconds later
 * at a receipt that never arrives.
 */
async function expectUncorruptedBillingPhone( page: Page ): Promise< void > {
	const phone = page
		.locator(
			'input[name="phone"], input[id$="-phone"], #billing_phone, #shipping-phone, #billing-phone'
		)
		.first();
	if ( ( await phone.count() ) === 0 ) {
		return;
	}
	const value = ( await phone.inputValue().catch( () => '' ) ) ?? '';
	expect(
		value.includes( DISPUTED_FRAUDULENT_CARD.number ),
		`the billing phone field holds ${ JSON.stringify(
			value
		) }, which contains the card number; submitting would store a PAN on the order`
	).toBe( false );
}

async function fillDisputedCard(
	session: ProviderWriteSession,
	page: Page,
	isBlockCheckout: boolean
): Promise< void > {
	if ( isBlockCheckout ) {
		const frame = page.frameLocator(
			getBlocksCardFrameSelector( session.runtime )
		);
		await enterProviderCardTriple(
			frame,
			DISPUTED_FRAUDULENT_CARD,
			'Blocks checkout'
		);
		await page.getByRole( 'button', { name: /place order/i } ).focus();
		await expectUncorruptedBillingPhone( page );
		return;
	}

	const upeContainer = page.locator(
		'#payment .payment_method_woocommerce_payments .wcpay-upe-element'
	);
	const frame = ( await upeContainer.isVisible() )
		? page.frameLocator(
				'#payment .payment_method_woocommerce_payments .wcpay-upe-element iframe'
		  )
		: page.frameLocator(
				'#payment #wcpay-card-element iframe[name^="__privateStripeFrame"]'
		  );
	const isUpe = await upeContainer.isVisible();

	await frame
		.locator( isUpe ? '[name="number"]' : '[name="cardnumber"]' )
		.fill( DISPUTED_FRAUDULENT_CARD.number );
	await frame
		.locator( isUpe ? '[name="expiry"]' : '[name="exp-date"]' )
		.fill( DISPUTED_FRAUDULENT_CARD.expiry );
	await frame
		.locator( '[name="cvc"]' )
		.fill( DISPUTED_FRAUDULENT_CARD.securityCode );
	await page.getByRole( 'button', { name: /place order/i } ).focus();
	await expectUncorruptedBillingPhone( page );
}

/**
 * Drive one guest checkout with the disputed-card fixture and hand back the
 * order it created.
 *
 * Each dispute belongs to its own shopper, as the claim's Shared creation row
 * requires: the browsing context's cookies are cleared first, so the session,
 * cart and address of the previous shopper cannot follow this one, and the
 * email is unique per dispute.
 */
async function completeDisputedCardCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	shopperIndex: number
): Promise< number > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( CAPABILITY_DISPUTED_CARD );

	await page.context().clearCookies();
	const email = `woopayments-${ session.runId }-dispute-${ shopperIndex }@example.com`;

	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	const isBlockCheckout = await fillGuestCheckoutDetails( page, email );
	await fillDisputedCard( session, page, isBlockCheckout );

	await session.withProviderSubmissionJournal(
		`dispute-lifecycle-checkout-${ shopperIndex }`,
		async () => {
			if ( isBlockCheckout ) {
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
					/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/,
					{ timeout: CHECKOUT_RECEIPT_TIMEOUT_MS }
				);
				await expect(
					page.getByText(
						/^(Your order has been received|Order received)$/i
					)
				).toBeVisible();
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					`A disputed-card checkout for shopper ${ shopperIndex } has no proven outcome.`,
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

/** One created dispute and the paid graph it was raised against. */
interface DisputedGraph {
	paid: PaymentEvidence;
	dispute: ProviderDisputeRecord;
}

/**
 * Wait for the provider to raise the dispute *and* for native to apply its
 * creation effects: the `on-hold` transition and exactly one created note.
 *
 * Both halves are required together, because they answer different questions.
 * The provider record proves the dispute exists; the order effects prove the
 * `charge.dispute.created` event reached this store and was ingested once.
 */
async function waitForCreatedDispute(
	restApi: APIRequestContext,
	paid: PaymentEvidence
): Promise< ProviderDisputeRecord > {
	const deadline = Date.now() + CREATION_BUDGET_MS;
	let lastSeen = 'no dispute on the charge yet';
	let lastHistory: string[] = [];

	for (;;) {
		const disputeId = await readChargeDisputeId( restApi, paid.chargeId );

		if ( disputeId ) {
			const dispute = await readDispute( restApi, disputeId );
			const orderStatus = await readOrderStatus( restApi, paid.orderId );
			const notes = await readOrderNotes( restApi, paid.orderId );
			const created = createdDisputeNotes( notes, paid.chargeId );
			lastHistory = statusTransitionNotes( notes );

			if ( created.length > 1 ) {
				fail(
					`order ${ paid.orderId } carries ${ created.length } dispute-created notes; one provider creation event must produce exactly one.`
				);
			}
			lastSeen = `dispute ${ dispute.id } ${ dispute.status }, order ${ orderStatus }, ${ created.length } created note(s)`;

			if ( orderStatus === 'on-hold' && created.length === 1 ) {
				return dispute;
			}
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`charge ${
					paid.chargeId
				} did not reach a created dispute with its native effects within ${ CREATION_BUDGET_MS }ms (last seen: ${ lastSeen }). The order's own status history was: ${
					lastHistory.length > 0
						? lastHistory.join( ' | ' )
						: 'no status transition at all'
				}. A history that never reaches On hold means the creation effect did not run; one that reaches it and leaves again means a later event overwrote it. Dispute creation is delivered to this store as a provider event; a run whose platform event listener is not forwarding charge.dispute.created will always stop here.`
			);
		}
		await delay( Math.min( CREATION_INTERVAL_MS, remaining ) );
	}
}

/**
 * The fixture every case below is measured against: exactly one captured
 * `5000 usd` charge, and exactly one fraudulent dispute in `needs_response`
 * whose dispute, charge and order identities agree.
 */
function expectCreationContract(
	paid: PaymentEvidence,
	dispute: ProviderDisputeRecord
): void {
	expect( paid.amountMinor ).toBe( AMOUNT_MINOR );
	expect( paid.currency ).toBe( CURRENCY );
	expect( paid.providerStatus ).toBe( 'succeeded' );
	expect( paid.chargeStatus ).toBe( 'succeeded' );
	expect( paid.chargeCaptured ).toBe( true );
	expect( paid.occurrenceCount ).toBe( 1 );
	expect( paid.captureOccurrenceCount ).toBe( 1 );

	expect( dispute.chargeId ).toBe( paid.chargeId );
	expect( dispute.orderId ).toBe( paid.orderId );
	expect( dispute.status ).toBe( CREATED_STATUS );
	expect( dispute.reason ).toBe( DISPUTE_REASON );
	expect( dispute.amountMinor ).toBe( AMOUNT_MINOR );
	expect( dispute.currency ).toBe( CURRENCY );
	expect(
		dispute.dueBy,
		'a dispute in needs_response must carry a response deadline'
	).toBeGreaterThan( 0 );
	expect( dispute.submissionCount ).toBe( 0 );
	expect( dispute.hasEvidence ).toBe( false );
}

/* -------------------------------------------------------------------------
 * `DP-nav`: the disputed order's notice, followed to dispute details
 * ---------------------------------------------------------------------- */

/**
 * Open the classic order edit screen. Stores keeping orders in the dedicated
 * tables edit them under `wc-orders`; stores still on the posts table edit them
 * through `post.php`. The order notes panel is the screen's identity either
 * way, and it is where the dispute notice lives.
 */
async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< void > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	const notesPanel = page.locator( '#woocommerce-order-notes' );
	if ( ( await notesPanel.count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( notesPanel ).toBeVisible();
}

/**
 * Wait for the surface the notice reached to declare itself, one way or the
 * other.
 *
 * The legacy `/payments/transactions/details` deep link native writes must be
 * redirected to a real native route. Both refusal shapes are watched alongside
 * the heading, because an unrouted path renders the admin app's not-allowed
 * card after a three-second delay, and simply waiting for the heading would
 * report a missing element rather than a lost deep link.
 */
async function expectDisputeDetailsSurface( details: Page ): Promise< void > {
	const heading = details.getByRole( 'heading', {
		name: /^(Payment details|Transaction details)$/,
	} );
	const deadline = Date.now() + DETAILS_BUDGET_MS;

	for (;;) {
		if ( await heading.isVisible() ) {
			return;
		}
		const refused = details.getByText(
			/Sorry, you are not allowed to access this page\.|Your current account status does not allow access to this page\./
		);
		if ( await refused.isVisible() ) {
			fail(
				`a dispute-created notice landed on a surface that refuses access (${ details.url() }); the legacy deep link native writes did not reach a native dispute details route.`
			);
		}
		if ( Date.now() >= deadline ) {
			fail(
				`a dispute-created notice did not reach a dispute details surface within ${ DETAILS_BUDGET_MS }ms; it stopped at ${ details.url() }.`
			);
		}
		await delay( 1_000 );
	}
}

/**
 * Follow one disputed order's dispute-created notice to the dispute details
 * surface and prove it landed on that row's exact dispute and order.
 *
 * The notice's link opens in a new tab — native writes it with
 * `target="_blank"` — so the case follows the popup rather than the current
 * page. The href it carries is the legacy `/payments/transactions/details` deep
 * link, which `WooPaymentsAdminNavigationController` redirects to the native
 * route while preserving the charge and balance-transaction identifiers.
 */
async function followDisputeNotice(
	page: Page,
	graph: DisputedGraph
): Promise< void > {
	await openOrderEditScreen( page, graph.paid.orderId );

	const notice = page
		.locator( '#woocommerce-order-notes' )
		.getByRole( 'link', { name: /^Response due by / } );
	await expect(
		notice,
		`order ${ graph.paid.orderId } must carry exactly one dispute-created notice linking to its dispute`
	).toHaveCount( 1 );
	await expect( notice ).toHaveAttribute(
		'href',
		new RegExp( `id=${ escapeRegExp( graph.paid.chargeId ) }\\b` )
	);

	// Native writes the notice link with `target="_blank"`, so following it
	// normally opens a second tab. The same-tab shape is handled too, because
	// the case is about where the link lands and not about how it opens.
	const popup = page.waitForEvent( 'popup' ).catch( () => null );
	await notice.click();
	const details = ( await popup ) ?? page;

	try {
		await details.waitForLoadState( 'domcontentloaded' );
		await expectDisputeDetailsSurface( details );

		// The two identities the case is about, on the surface the notice
		// reached: this dispute, and this order.
		await expect(
			details.getByRole( 'heading', { name: 'Dispute details' } )
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
		await expect(
			details.getByText( graph.dispute.id, { exact: true } ),
			`the dispute details surface must present dispute ${ graph.dispute.id }`
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
		await expect(
			details.getByRole( 'link', {
				name: `Order #${ graph.paid.orderId }`,
				exact: true,
			} ),
			`the dispute details surface must present order ${ graph.paid.orderId }`
		).toBeVisible( { timeout: DETAILS_BUDGET_MS } );
	} finally {
		if ( details !== page ) {
			await details.close();
		}
	}
}

/* -------------------------------------------------------------------------
 * The cases
 * ---------------------------------------------------------------------- */

test.describe.serial( 'dispute-lifecycle', () => {
	test(
		'Each disputed order carries one dispute-created notice whose link reaches the dispute details surface for that exact dispute and order',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DP_NAV,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime } ) => {
			// One checkout, one 180-second creation convergence and one
			// navigation, with headroom: the claim's budgets alone add up to
			// four minutes before a single assertion is slow.
			test.setTimeout( 600_000 );
			requireCapabilities( pilotRuntime, [
				CAPABILITY_FAMILY,
				CAPABILITY_PRODUCT,
				CAPABILITY_DISPUTED_CARD,
				CAPABILITY_NAVIGATION,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'dispute-lifecycle-creation' },
				async () => {
					await withUnchangedStoreConfiguration(
						adminApi,
						async () => {
							const product =
								await pilotRuntime.createOwnedProduct( PRICE );
							const orderId = await completeDisputedCardCheckout(
								pilotRuntime,
								page,
								product,
								1
							);
							const paid = await getPaymentEvidence(
								adminApi,
								orderId
							);
							const dispute = await waitForCreatedDispute(
								adminApi,
								paid
							);
							expectCreationContract( paid, dispute );
							const graph: DisputedGraph = { paid, dispute };

							await pilotRuntime.logInAsAdmin( page );
							await page.waitForURL( '**/wp-admin/**' );
							await followDisputeNotice( page, graph );

							// Looking at the dispute changed nothing: the order
							// still carries exactly one creation effect and no
							// duplicate of any other.
							const notes = await readOrderNotes(
								adminApi,
								paid.orderId
							);
							expect(
								createdDisputeNotes( notes, paid.chargeId )
							).toHaveLength( 1 );
							expectNoDuplicatedDisputeEffect( notes );

							// The run's shopper session and its cart go with
							// the cookies; the run-owned product is removed by
							// the pilot runtime's own cleanup.
							await page.context().clearCookies();
						}
					);
				}
			);
		}
	);
} );
