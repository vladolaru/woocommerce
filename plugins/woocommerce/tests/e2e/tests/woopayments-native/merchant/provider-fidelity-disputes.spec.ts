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
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import { DISPUTED_FRAUDULENT_CARD } from '../../../utils/woopayments-native/test-cards';

/**
 * The `dispute-lifecycle` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * The claim these tests exist to make falsifiable: three independent USD 50.00
 * disputes raised by the real provider retain their exact dispute, charge and
 * order identities through voluntary acceptance, winning evidence and losing
 * evidence; each traverses the status sequence listed for its case; native
 * applies exactly one side effect per provider event; and each dispute-created
 * order note links to the dispute details surface for those same identities.
 *
 * Four things shape every case below.
 *
 * **Nothing here creates a dispute locally.** The only lever is the provider's
 * disputed-card fixture, `4000000000000259`: a captured charge on it is
 * disputed by the provider, asynchronously, as `fraudulent`. The dispute record
 * itself is then readable through native's own uncached dispute route, but
 * every *native* effect — the `on-hold` transition, the created note, the
 * closed note, the local dispute refund — is written only by
 * `WooPaymentsDisputeEventHandler` when `WooPaymentsEventIngestor` ingests the
 * matching provider event. A store whose provider event listener is not
 * delivering `charge.dispute.*` will therefore fail at the convergence budgets
 * below rather than pass on a half-observed graph, and the failure text says
 * so. This suite does not synthesise provider events, and it never returns
 * early when an effect is missing.
 *
 * **Identity is the assertion.** Every client row this family replaces asserted
 * a rendered status label, which is why their recorded residual risks all say
 * some version of "a generic Lost label cannot be allowed to mask a wrong
 * record". Each case here pins the exact dispute ID, charge ID and order ID on
 * every read, and fails the moment one is replaced.
 *
 * **The adjudication cases drive native's own REST controllers, not the
 * dashboard.** The claim's fixed contract states requests and payloads — "send
 * one close request for the exact first dispute ID", "submit one
 * physical-product evidence payload containing the provider's exact
 * `winning_evidence` test value with `submit=true`" — and its exclusions drop
 * dashboard layout and copy, evidence durability beyond the asserted readback,
 * and draft save behaviour. The admin surfaces are already proven at the JS
 * layer by `dispute-challenge-page.test.tsx` and `money-movement-pages.test.tsx`;
 * what a provider run adds is the join from
 * `WooPaymentsDisputesRestController` to the real provider and back through the
 * ingested event. `DP-nav` is the browser case, because navigation is what it
 * is about.
 *
 * **The claim's exclusions are honoured literally.** No case asserts a later
 * lifecycle state, evidence durability beyond the readback each submission
 * asserts, payout consequences, dashboard layout or copy, or a final order
 * status — the last because payment and dispute event ordering races there, and
 * the claim says so. The one order status this suite does assert is the
 * `on-hold` transition at creation, which the claim's Shared creation row fixes.
 *
 * Cost note: one full pass creates three USD 50.00 card charges, three provider
 * disputes, one voluntary close, and two evidence submissions. Nothing is
 * retried: `--retries=0` is mandatory, no dispute action is repeated, and an
 * uncertain terminal state quarantines the graph rather than being re-driven.
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
/** The voluntary close request. */
const CAPABILITY_ACCEPT = 'dispute-lifecycle-accept';
/** The evidence submissions. */
const CAPABILITY_EVIDENCE = 'dispute-lifecycle-evidence';

const CONTRACT_DP1 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-respond.spec.ts:103::Disputes › Respond to a dispute › Accept a dispute';
const CONTRACT_DP2 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-respond.spec.ts:158::Disputes › Respond to a dispute › Challenge a dispute with winning evidence';
const CONTRACT_DP3 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-respond.spec.ts:340::Disputes › Respond to a dispute › Challenge a dispute with losing evidence';
const CONTRACT_DP_NAV =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-disputes-view-details-via-order-notice.spec.ts:40::Disputes › View dispute details via disputed order notice › should navigate to dispute details when disputed order notice button clicked';

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const STORE_CURRENCY_API =
	'/wp-json/wc/v3/settings/general/woocommerce_currency';
const SYSTEM_STATUS_API = '/wp-json/wc/v3/system_status?_fields=environment';

/** The claim's fixture: three independent USD 50.00 disputed charges. */
const PRICE = '50.00';
const AMOUNT_MINOR = 5000;
const CURRENCY = 'USD';
const DISPUTE_COUNT = 3;

/** What the provider raises for the disputed-card fixture. */
const DISPUTE_REASON = 'fraudulent';
const CREATED_STATUS = 'needs_response';
const UNDER_REVIEW_STATUS = 'under_review';
const WON_STATUS = 'won';
const LOST_STATUS = 'lost';
/** Every provider status this family treats as terminal. */
const TERMINAL_STATUSES = [ WON_STATUS, LOST_STATUS, 'warning_closed' ];

/**
 * The provider's magic test evidence. Native forwards the cover letter as
 * `evidence.uncategorized_text` (`buildEvidencePayload` in
 * `client/.../money-movement/dispute-evidence-fields.ts`), which is the field
 * the provider reads these values from.
 */
const WINNING_EVIDENCE = 'winning_evidence';
const LOSING_EVIDENCE = 'losing_evidence';
/** Native carries the challenge's product type as dispute metadata. */
const PRODUCT_TYPE_METADATA_KEY = '__product_type';
const PHYSICAL_PRODUCT = 'physical_product';
const PRODUCT_DESCRIPTION = 'WooPayments native provider-fidelity fixture';

/** Convergence, as fixed by the claim. */
const CREATION_INTERVAL_MS = 5_000;
const CREATION_BUDGET_MS = 180_000;
const ADJUDICATION_INTERVAL_MS = 5_000;
const ADJUDICATION_BUDGET_MS = 600_000;
/** The provider-backed admin surface `DP-nav` lands on fetches as it renders. */
const DETAILS_BUDGET_MS = 60_000;
const CHECKOUT_RECEIPT_TIMEOUT_MS = 60_000;

/**
 * `DP-nav`'s explicit native-version expectation.
 *
 * The client row it replaces returned silently when the disputed-order notice
 * was absent, on the theory that WooCommerce might be older than the release
 * that introduced it. Native's dispute-created order note and its
 * `get_dispute_url` link ship with the native runtime itself
 * (`WooPaymentsDisputeEventHandler`, `@since 11.0.0`), and the legacy
 * `/payments/transactions/details` deep link it emits is redirected to the
 * native route by `WooPaymentsAdminNavigationController` from the same release.
 * The case states that expectation, asserts the store meets it, and then
 * requires the notice unconditionally.
 */
const NATIVE_DISPUTE_NOTICE_SINCE = '11.0.0';

function fail( message: string ): never {
	throw new Error( `WooPayments dispute fidelity ${ message }` );
}

/** How long to wait before re-asking after a dead connection. */
const TRANSPORT_RETRY_DELAY_MS = 2_000;

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
			await restApi.get(
				`/wp-json/wc/v3/payments/disputes/${ encodeURIComponent(
					disputeId
				) }`
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
 * Ask the store for one charge, re-asking once if the connection dies.
 *
 * This poll re-reads the same charge every couple of seconds for the whole
 * dispute-creation budget, and the request context's keep-alive connection is
 * dropped often enough under that load to fail the case with
 * `apiRequestContext.get: socket hang up`. The same request answers HTTP 200 in
 * about a second server-side, so the connection died rather than the store
 * refusing — a non-answer, not an answer.
 *
 * Retrying is safe *because this is a read*. The harness's no-retry rule exists
 * so a submission is never made twice; nothing here writes, so re-asking cannot
 * duplicate anything. A second failure still fails the case: this closes a
 * transport hole, not an evidence gap.
 */
async function getChargeWithTransportRetry(
	restApi: APIRequestContext,
	chargeId: string
): Promise< APIResponse > {
	const path = `/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
		chargeId
	) }`;
	try {
		return await restApi.get( path );
	} catch ( error ) {
		await delay( TRANSPORT_RETRY_DELAY_MS );
		try {
			return await restApi.get( path );
		} catch ( retryError ) {
			fail(
				`charge ${ chargeId } could not be read: the connection failed twice (${ String(
					retryError
				) }), so the provider's answer is unknown rather than absent.`
			);
		}
	}
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

interface WooRefundRecord {
	id: number;
	amount: string;
	reason: string;
	providerRefundId: string;
}

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

async function readWooRefunds(
	restApi: APIRequestContext,
	orderId: number
): Promise< WooRefundRecord[] > {
	const refunds = await readJson< unknown[] >(
		await restApi.get(
			`/wp-json/wc/v3/orders/${ orderId }/refunds?context=edit&per_page=100`
		),
		`Order ${ orderId } refunds read`
	);

	return refunds.map( ( item, index ) => {
		const refund = item as {
			id?: unknown;
			amount?: unknown;
			reason?: unknown;
			meta_data?: unknown;
		};
		const metaData = Array.isArray( refund.meta_data )
			? ( refund.meta_data as Array< {
					key?: unknown;
					value?: unknown;
			  } > )
			: [];
		const providerRefundId = metaData.find(
			( entry ) => entry.key === '_wcpay_refund_id'
		)?.value;

		return {
			id: requiredNumber( refund.id, `refund ${ index + 1 } ID` ),
			amount: requiredString(
				refund.amount,
				`refund ${ index + 1 } amount`
			),
			reason: typeof refund.reason === 'string' ? refund.reason : '',
			providerRefundId:
				typeof providerRefundId === 'string' ? providerRefundId : '',
		};
	} );
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

function closedDisputeNotes(
	notes: readonly string[],
	status: string
): string[] {
	return notes.filter( ( note ) =>
		note.includes( `Dispute has been closed with status ${ status }.` )
	);
}

function updatedDisputeNotes( notes: readonly string[] ): string[] {
	return notes.filter( ( note ) =>
		note.includes( 'Payment dispute has been updated' )
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
 * with it by hand, exactly as `shopper/card-authentication.spec.ts` and
 * `shopper/provider-fidelity-card-declines.spec.ts` already do. Returns whether
 * the store served the Blocks surface, because the card fields and the
 * submission differ between the two and this family claims neither surface.
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

	for (;;) {
		const disputeId = await readChargeDisputeId( restApi, paid.chargeId );

		if ( disputeId ) {
			const dispute = await readDispute( restApi, disputeId );
			const orderStatus = await readOrderStatus( restApi, paid.orderId );
			const notes = await readOrderNotes( restApi, paid.orderId );
			const created = createdDisputeNotes( notes, paid.chargeId );

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
				`charge ${ paid.chargeId } did not reach a created dispute with its native effects within ${ CREATION_BUDGET_MS }ms (last seen: ${ lastSeen }). Dispute creation is delivered to this store as a provider event; a run whose platform event listener is not forwarding charge.dispute.created will always stop here.`
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
 * Adjudication convergence
 * ---------------------------------------------------------------------- */

interface AdjudicationOutcome {
	terminal: ProviderDisputeRecord;
	statuses: string[];
	notes: string[];
	refunds: WooRefundRecord[];
}

/**
 * Poll the exact dispute until it reaches its listed terminal status twice in a
 * row *and* native has applied exactly one closed effect for it, recording
 * every distinct status the dispute passed through on the way.
 *
 * Two identical reads, not one: a single read can catch a value
 * mid-propagation, and an adjudicated dispute never regresses. The identity
 * guard is on every read, so a replacement dispute or charge fails here rather
 * than being adjudicated in silence.
 */
async function waitForAdjudication(
	restApi: APIRequestContext,
	paid: PaymentEvidence,
	created: ProviderDisputeRecord,
	terminalStatus: string,
	options: { expectsLocalRefund: boolean }
): Promise< AdjudicationOutcome > {
	const deadline = Date.now() + ADJUDICATION_BUDGET_MS;
	const statuses: string[] = [];
	let previousSignature = '';
	let lastSeen = 'no read yet';

	for (;;) {
		const dispute = await readDispute( restApi, created.id );
		if ( dispute.chargeId !== created.chargeId ) {
			fail(
				`dispute ${ created.id } changed charge mid-lifecycle: expected ${ created.chargeId }, received ${ dispute.chargeId }.`
			);
		}
		if ( dispute.orderId !== paid.orderId ) {
			fail(
				`dispute ${ created.id } changed order mid-lifecycle: expected ${ paid.orderId }, received ${ dispute.orderId }.`
			);
		}
		if ( statuses.at( -1 ) !== dispute.status ) {
			statuses.push( dispute.status );
		}
		if (
			dispute.status !== terminalStatus &&
			TERMINAL_STATUSES.includes( dispute.status )
		) {
			fail(
				`dispute ${ created.id } reached terminal ${ dispute.status } instead of ${ terminalStatus }.`
			);
		}

		const notes = await readOrderNotes( restApi, paid.orderId );
		const refunds = await readWooRefunds( restApi, paid.orderId );
		const closed = closedDisputeNotes( notes, terminalStatus );

		if ( closed.length > 1 ) {
			fail(
				`order ${ paid.orderId } carries ${ closed.length } dispute-closed notes; one provider close event must produce exactly one.`
			);
		}
		if ( refunds.length > 1 ) {
			fail(
				`order ${ paid.orderId } carries ${ refunds.length } refunds; a lost dispute must produce exactly one capped local refund.`
			);
		}
		lastSeen = `${ dispute.status }, ${ closed.length } closed note(s), ${ refunds.length } refund(s)`;

		if ( dispute.status === terminalStatus && closed.length === 1 ) {
			const signature = `${ dispute.id }|${ dispute.status }|${ dispute.amountMinor }|${ dispute.currency }`;
			const refundsSettled =
				! options.expectsLocalRefund || refunds.length === 1;
			if ( signature === previousSignature && refundsSettled ) {
				return { terminal: dispute, statuses, notes, refunds };
			}
			previousSignature = signature;
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`dispute ${ created.id } did not reach two stable ${ terminalStatus } reads with its native effects within ${ ADJUDICATION_BUDGET_MS }ms (last seen: ${ lastSeen }). Dispute closure reaches this store as a provider event; a run whose platform event listener is not forwarding charge.dispute.closed will always stop here.`
			);
		}
		await delay( Math.min( ADJUDICATION_INTERVAL_MS, remaining ) );
	}
}

/**
 * The dispute visited exactly the states its case lists, in order.
 *
 * The first observation is the submission response itself — a read of the same
 * dispute taken the moment the provider accepted the evidence — followed by
 * every distinct state the convergence poll saw. A skipped `under_review`, or a
 * detour through any state the case does not list, fails here.
 */
function expectChallengeStatusSequence(
	submittedStatus: string,
	polledStatuses: readonly string[],
	terminalStatus: string
): void {
	const observed = [ submittedStatus, ...polledStatuses ].filter(
		( status, index, all ) => index === 0 || all[ index - 1 ] !== status
	);
	expect(
		observed,
		`a challenged dispute must pass through ${ UNDER_REVIEW_STATUS } on its way to ${ terminalStatus }, and visit nothing else`
	).toEqual( [ UNDER_REVIEW_STATUS, terminalStatus ] );
}

/** The capped local refund a lost dispute leaves behind, and nothing else. */
function expectCappedLocalDisputeRefund(
	refunds: readonly WooRefundRecord[]
): void {
	expect(
		refunds,
		'a lost dispute must leave exactly one capped local refund'
	).toHaveLength( 1 );
	expect( refunds[ 0 ].amount ).toBe( PRICE );
	expect( refunds[ 0 ].reason ).toBe( 'Dispute lost.' );
	// Local only: the provider already took the money with the dispute, so a
	// refund carrying a provider refund ID would mean it was returned twice.
	expect(
		refunds[ 0 ].providerRefundId,
		'the dispute-lost refund is local; it must not carry a provider refund'
	).toBe( '' );
}

/* -------------------------------------------------------------------------
 * The merchant's dispute actions, through native's own routes
 * ---------------------------------------------------------------------- */

async function closeDisputeOnce(
	session: ProviderWriteSession,
	dispute: ProviderDisputeRecord
): Promise< ProviderDisputeRecord > {
	session.requireApprovedProviderFixture( CAPABILITY_ACCEPT );

	return session.withProviderSubmissionJournal(
		`dispute-lifecycle-close-${ dispute.id }`,
		async () => {
			const response = await session.performWrite( () =>
				session.adminApi.post(
					`/wp-json/wc/v3/payments/disputes/${ encodeURIComponent(
						dispute.id
					) }/close`
				)
			);
			return toDisputeRecord(
				await readJson< unknown >(
					response,
					`Dispute ${ dispute.id } close`
				)
			);
		}
	);
}

async function submitDisputeEvidenceOnce(
	session: ProviderWriteSession,
	dispute: ProviderDisputeRecord,
	coverLetter: string
): Promise< ProviderDisputeRecord > {
	session.requireApprovedProviderFixture( CAPABILITY_EVIDENCE );

	return session.withProviderSubmissionJournal(
		`dispute-lifecycle-evidence-${ dispute.id }`,
		async () => {
			const response = await session.performWrite( () =>
				session.adminApi.post(
					`/wp-json/wc/v3/payments/disputes/${ encodeURIComponent(
						dispute.id
					) }`,
					{
						data: {
							evidence: {
								product_description: PRODUCT_DESCRIPTION,
								uncategorized_text: coverLetter,
							},
							metadata: {
								[ PRODUCT_TYPE_METADATA_KEY ]: PHYSICAL_PRODUCT,
							},
							submit: true,
						},
					}
				)
			);
			return toDisputeRecord(
				await readJson< unknown >(
					response,
					`Dispute ${ dispute.id } evidence submission`
				)
			);
		}
	);
}

/* -------------------------------------------------------------------------
 * `DP-nav`: the disputed order's notice, followed to dispute details
 * ---------------------------------------------------------------------- */

function parseVersion( value: string ): number[] {
	return value
		.split( '-', 1 )[ 0 ]
		.split( '.' )
		.map( ( part ) => Number.parseInt( part, 10 ) || 0 );
}

function isAtLeastVersion( observed: string, required: string ): boolean {
	const left = parseVersion( observed );
	const right = parseVersion( required );
	for (
		let index = 0;
		index < Math.max( left.length, right.length );
		index++
	) {
		const difference = ( left[ index ] ?? 0 ) - ( right[ index ] ?? 0 );
		if ( difference !== 0 ) {
			return difference > 0;
		}
	}
	return true;
}

async function readWooCommerceVersion(
	restApi: APIRequestContext
): Promise< string > {
	const status = await readJson< { environment?: unknown } >(
		await restApi.get( SYSTEM_STATUS_API ),
		'System status read'
	);
	const environment = requiredObject(
		status.environment,
		'the system status environment'
	);
	return requiredString( environment.version, 'WooCommerce version' );
}

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

/*
 * The claim's Shared creation row is a precondition of all four cases rather
 * than a case of its own, and `DP-nav` is the one that runs "after shared
 * creation" under the same 180-second budget. It therefore creates the three
 * disputes and hands them to the three adjudication cases, exactly as the
 * `refund-settlement` suite hands `R1`'s settled refund to `R1v`. If creation
 * records nothing, the later cases fail rather than inventing a dispute.
 */
let createdGraphs: DisputedGraph[] | undefined;

function requireCreatedGraph( index: number ): DisputedGraph {
	const graph = createdGraphs?.[ index ];
	if ( ! graph ) {
		fail(
			`case ${
				index + 1
			} adjudicates a dispute the shared creation never recorded; there is nothing to act on.`
		);
	}
	return graph;
}

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
			// Three checkouts, three 180-second creation convergences and three
			// navigations, with headroom: the claim's budgets alone add up to
			// most of a quarter of an hour before a single assertion is slow.
			test.setTimeout( 1_200_000 );
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
							// The version this case runs against, stated rather
							// than discovered: the client row it replaces
							// returned silently when the notice was absent, and
							// that silence is what this case exists to remove.
							const version = await readWooCommerceVersion(
								adminApi
							);
							test.info().annotations.push( {
								type: 'woopayments-native-version',
								description: version,
							} );
							expect(
								isAtLeastVersion(
									version,
									NATIVE_DISPUTE_NOTICE_SINCE
								),
								`the dispute-created notice and its native route redirect ship with WooCommerce ${ NATIVE_DISPUTE_NOTICE_SINCE }; this store reports ${ version }`
							).toBe( true );

							const graphs: DisputedGraph[] = [];
							for (
								let index = 0;
								index < DISPUTE_COUNT;
								index++
							) {
								const product =
									await pilotRuntime.createOwnedProduct(
										PRICE
									);
								const orderId =
									await completeDisputedCardCheckout(
										pilotRuntime,
										page,
										product,
										index + 1
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
								graphs.push( { paid, dispute } );
							}

							// Three independent disputes, not one seen three
							// times.
							expect(
								new Set(
									graphs.map( ( graph ) => graph.dispute.id )
								).size
							).toBe( DISPUTE_COUNT );
							expect(
								new Set(
									graphs.map(
										( graph ) => graph.paid.orderId
									)
								).size
							).toBe( DISPUTE_COUNT );
							createdGraphs = graphs;

							await pilotRuntime.logInAsAdmin( page );
							await page.waitForURL( '**/wp-admin/**' );
							for ( const graph of graphs ) {
								await followDisputeNotice( page, graph );
							}

							// Looking at a dispute changed nothing: each order
							// still carries exactly one creation effect and no
							// duplicate of any other.
							for ( const graph of graphs ) {
								const notes = await readOrderNotes(
									adminApi,
									graph.paid.orderId
								);
								expect(
									createdDisputeNotes(
										notes,
										graph.paid.chargeId
									)
								).toHaveLength( 1 );
								expectNoDuplicatedDisputeEffect( notes );
							}

							// The run's shopper sessions and their carts go with
							// the cookies; the run-owned products are removed by
							// the pilot runtime's own cleanup.
							await page.context().clearCookies();
						}
					);
				}
			);
		}
	);

	test(
		'Accepting one dispute closes that exact dispute as lost with one closed effect and one capped local dispute refund, and submits no evidence',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DP1,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, pilotRuntime } ) => {
			test.setTimeout( 900_000 );
			requireCapabilities( pilotRuntime, [
				CAPABILITY_FAMILY,
				CAPABILITY_ACCEPT,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			const graph = requireCreatedGraph( 0 );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'dispute-lifecycle-dp1' },
				async () => {
					await withUnchangedStoreConfiguration(
						adminApi,
						async () => {
							// Re-read cold: the case acts on the dispute as it
							// stands now, not on what creation remembered.
							const before = await readDispute(
								adminApi,
								graph.dispute.id
							);
							expect( before.chargeId ).toBe(
								graph.paid.chargeId
							);
							expect( before.orderId ).toBe( graph.paid.orderId );
							expect( before.status ).toBe( CREATED_STATUS );
							// Deadline and amount correlation, alongside the
							// exact IDs: this is the same dispute the shared
							// creation recorded, not a second one raised on
							// the same charge.
							expect( before.dueBy ).toBe( graph.dispute.dueBy );
							expect( before.amountMinor ).toBe( AMOUNT_MINOR );
							expect( before.currency ).toBe( CURRENCY );

							const closed = await closeDisputeOnce(
								pilotRuntime,
								before
							);

							// The close response is about the same record.
							expect( closed.id ).toBe( before.id );
							expect( closed.chargeId ).toBe( before.chargeId );
							expect( closed.orderId ).toBe( graph.paid.orderId );
							expect( closed.amountMinor ).toBe( AMOUNT_MINOR );
							expect( closed.currency ).toBe( CURRENCY );
							expect(
								closed.status,
								'accepting a dispute is a voluntary loss'
							).toBe( LOST_STATUS );

							const outcome = await waitForAdjudication(
								adminApi,
								graph.paid,
								before,
								LOST_STATUS,
								{ expectsLocalRefund: true }
							);

							expect( outcome.terminal.id ).toBe( before.id );
							expect( outcome.terminal.chargeId ).toBe(
								before.chargeId
							);
							expect( outcome.terminal.orderId ).toBe(
								graph.paid.orderId
							);
							expect( outcome.terminal.amountMinor ).toBe(
								AMOUNT_MINOR
							);
							expect( outcome.terminal.currency ).toBe(
								CURRENCY
							);

							// Accepted, not challenged: no evidence was ever
							// submitted for this dispute, and the provider's own
							// counter says so.
							expect(
								outcome.terminal.submissionCount,
								'an accepted dispute must carry no evidence submission'
							).toBe( 0 );
							expect( outcome.terminal.hasEvidence ).toBe(
								false
							);
							// The update-family note count is deliberately not
							// asserted here. The claim fixes one closed effect
							// and one capped refund for this case and says
							// nothing about whether closing a dispute also emits
							// an update event; the evidence-submission counter
							// above is what proves no evidence was sent.

							// One closed effect, one capped local refund, and no
							// event applied twice.
							expect(
								closedDisputeNotes( outcome.notes, LOST_STATUS )
							).toHaveLength( 1 );
							expectCappedLocalDisputeRefund( outcome.refunds );
							expectNoDuplicatedDisputeEffect( outcome.notes );
						}
					);
				}
			);
		}
	);

	test(
		'One winning-evidence submission carries the same dispute through under review to won with one update effect and one closed-won effect',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DP2,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, pilotRuntime } ) => {
			test.setTimeout( 900_000 );
			requireCapabilities( pilotRuntime, [
				CAPABILITY_FAMILY,
				CAPABILITY_EVIDENCE,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			const graph = requireCreatedGraph( 1 );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'dispute-lifecycle-dp2' },
				async () => {
					await withUnchangedStoreConfiguration(
						adminApi,
						async () => {
							const before = await readDispute(
								adminApi,
								graph.dispute.id
							);
							expect( before.chargeId ).toBe(
								graph.paid.chargeId
							);
							expect( before.orderId ).toBe( graph.paid.orderId );
							expect( before.status ).toBe( CREATED_STATUS );
							// Deadline and amount correlation, alongside the
							// exact IDs: this is the same dispute the shared
							// creation recorded, not a second one raised on
							// the same charge.
							expect( before.dueBy ).toBe( graph.dispute.dueBy );
							expect( before.amountMinor ).toBe( AMOUNT_MINOR );
							expect( before.currency ).toBe( CURRENCY );

							const submitted = await submitDisputeEvidenceOnce(
								pilotRuntime,
								before,
								WINNING_EVIDENCE
							);

							expect( submitted.id ).toBe( before.id );
							expect( submitted.chargeId ).toBe(
								before.chargeId
							);
							expect( submitted.orderId ).toBe(
								graph.paid.orderId
							);
							// The readback the claim asserts, and no more than
							// it: the evidence this run sent is the evidence the
							// provider holds, right now.
							expect( submitted.coverLetter ).toBe(
								WINNING_EVIDENCE
							);
							expect( submitted.productType ).toBe(
								PHYSICAL_PRODUCT
							);
							expect( submitted.submissionCount ).toBe( 1 );
							expect( submitted.hasEvidence ).toBe( true );
							expect(
								submitted.status,
								'a submitted challenge is under review before it is decided'
							).toBe( UNDER_REVIEW_STATUS );

							const outcome = await waitForAdjudication(
								adminApi,
								graph.paid,
								before,
								WON_STATUS,
								{ expectsLocalRefund: false }
							);

							expect( outcome.terminal.id ).toBe( before.id );
							expect( outcome.terminal.chargeId ).toBe(
								before.chargeId
							);
							expect( outcome.terminal.orderId ).toBe(
								graph.paid.orderId
							);
							expect( outcome.terminal.amountMinor ).toBe(
								AMOUNT_MINOR
							);
							expect( outcome.terminal.currency ).toBe(
								CURRENCY
							);
							expect( outcome.terminal.submissionCount ).toBe(
								1
							);

							// The intermediate state was traversed, not skipped.
							expectChallengeStatusSequence(
								submitted.status,
								outcome.statuses,
								WON_STATUS
							);

							// One update effect, one closed-won effect, nothing
							// applied twice.
							expect(
								updatedDisputeNotes( outcome.notes )
							).toHaveLength( 1 );
							expect(
								closedDisputeNotes( outcome.notes, WON_STATUS )
							).toHaveLength( 1 );
							expectNoDuplicatedDisputeEffect( outcome.notes );

							// A won dispute returns the money at the provider;
							// nothing local is refunded.
							expect(
								outcome.refunds,
								'a won dispute must create no local refund'
							).toHaveLength( 0 );
						}
					);
				}
			);
		}
	);

	test(
		'One losing-evidence submission carries the same dispute through under review to lost with one update effect and one capped local dispute refund',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_DP3,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, pilotRuntime } ) => {
			test.setTimeout( 900_000 );
			requireCapabilities( pilotRuntime, [
				CAPABILITY_FAMILY,
				CAPABILITY_EVIDENCE,
			] );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			const graph = requireCreatedGraph( 2 );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'dispute-lifecycle-dp3' },
				async () => {
					await withUnchangedStoreConfiguration(
						adminApi,
						async () => {
							const before = await readDispute(
								adminApi,
								graph.dispute.id
							);
							expect( before.chargeId ).toBe(
								graph.paid.chargeId
							);
							expect( before.orderId ).toBe( graph.paid.orderId );
							expect( before.status ).toBe( CREATED_STATUS );
							// Deadline and amount correlation, alongside the
							// exact IDs: this is the same dispute the shared
							// creation recorded, not a second one raised on
							// the same charge.
							expect( before.dueBy ).toBe( graph.dispute.dueBy );
							expect( before.amountMinor ).toBe( AMOUNT_MINOR );
							expect( before.currency ).toBe( CURRENCY );

							const submitted = await submitDisputeEvidenceOnce(
								pilotRuntime,
								before,
								LOSING_EVIDENCE
							);

							expect( submitted.id ).toBe( before.id );
							expect( submitted.chargeId ).toBe(
								before.chargeId
							);
							expect( submitted.orderId ).toBe(
								graph.paid.orderId
							);
							expect( submitted.coverLetter ).toBe(
								LOSING_EVIDENCE
							);
							expect( submitted.productType ).toBe(
								PHYSICAL_PRODUCT
							);
							expect( submitted.submissionCount ).toBe( 1 );
							expect( submitted.hasEvidence ).toBe( true );
							expect(
								submitted.status,
								'a submitted challenge is under review before it is decided'
							).toBe( UNDER_REVIEW_STATUS );

							const outcome = await waitForAdjudication(
								adminApi,
								graph.paid,
								before,
								LOST_STATUS,
								{ expectsLocalRefund: true }
							);

							expect( outcome.terminal.id ).toBe( before.id );
							expect( outcome.terminal.chargeId ).toBe(
								before.chargeId
							);
							expect( outcome.terminal.orderId ).toBe(
								graph.paid.orderId
							);
							expect( outcome.terminal.amountMinor ).toBe(
								AMOUNT_MINOR
							);
							expect( outcome.terminal.currency ).toBe(
								CURRENCY
							);
							expect( outcome.terminal.submissionCount ).toBe(
								1
							);

							expectChallengeStatusSequence(
								submitted.status,
								outcome.statuses,
								LOST_STATUS
							);

							expect(
								updatedDisputeNotes( outcome.notes )
							).toHaveLength( 1 );
							expect(
								closedDisputeNotes( outcome.notes, LOST_STATUS )
							).toHaveLength( 1 );
							expectCappedLocalDisputeRefund( outcome.refunds );
							expectNoDuplicatedDisputeEffect( outcome.notes );
						}
					);
				}
			);
		}
	);
} );
