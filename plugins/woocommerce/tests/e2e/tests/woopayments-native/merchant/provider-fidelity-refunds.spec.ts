import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import { getChargeWithTransportRetry } from '../../../utils/woopayments-native/provider-evidence';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

/**
 * The `refund-settlement` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * T.1 batch 5a (`data/t1-provider-family-audit.md` §3, §4 Batch 5) moved every
 * assertion below the browser to PHPUnit and Jest and trimmed this file to its
 * one retained smoke, `R1`: the real round trip from a card checkout to a
 * merchant refund to a settled provider refund whose identity WooCommerce
 * stores. `R1v`, the partial/multi-line/foreign-currency/redirect-method
 * cases (`R2`-`R7`), the same-key replay probe (`RP`), and the native
 * transaction-view observations moved to
 * `WooPaymentsProviderGatewayAdapterTest`, `WC_AJAX_Test`,
 * `WooPaymentsRefundEventHandlerTest`, `WooPaymentsEventIngestorTest` and
 * `money-movement-pages.test.tsx`.
 *
 * The refund itself always goes through core's own
 * `woocommerce_refund_line_items` admin-AJAX action with `api_refund` as the
 * *string* `'true'` — `WC_AJAX::refund_line_items()` compares strictly, and a
 * boolean silently downgrades to a local-only refund, which is exactly the
 * failure this family exists to detect.
 */

/** Grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:refund-settlement';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/** The journey capability every case in this family needs. */
const CAPABILITY_FAMILY = 'refund-settlement';
/** The capability for the action that actually returns money. */
const CAPABILITY_REFUND = 'refund-settlement-action';

const CONTRACT_R1 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-full-refund.spec.ts:38::WooCommerce Payments - Full Refund › should process a full refund for an order';

/**
 * The merchant-supplied refund reason.
 *
 * This is the exact value native's own transaction-details refund modal
 * offers, so it is a real merchant choice rather than a fixture convenience.
 */
const REFUND_REASON = 'requested_by_customer';

// R1: one captured `4242` USD 10.99 charge, refunded in full.
const R1_PRICE = '10.99';
const R1_MINOR = 1099;

/** Convergence, as fixed by the claim. */
const SETTLE_INTERVAL_MS = 3_000;
const SETTLE_BUDGET_MS = 180_000;

interface ProviderRefund {
	id: string;
	status: string;
	amountMinor: number;
	currency: string;
	reason: string;
	merchantReason: string;
	balanceTransactionId: string;
}

/**
 * The refund-relevant half of the provider charge, read straight from the
 * platform charge route rather than from timeline events. The timeline is built
 * from provider events and can lag; `amount_refunded` and the expanded refund
 * collection are the charge's own current state, so "one refund settled" cannot
 * pass merely because an event has not arrived yet.
 */
interface ChargeRefundState {
	chargeId: string;
	captured: boolean;
	status: string;
	refunded: boolean;
	amountRefundedMinor: number;
	currency: string;
	balanceTransactionId: string;
	refunds: ProviderRefund[];
}

interface WooRefundLine {
	orderItemId: number;
	total: string;
	quantity: number;
}

interface WooRefundRecord {
	id: number;
	amount: string;
	reason: string;
	providerRefundId: string;
	lines: WooRefundLine[];
}

interface OrderRecord {
	status: string;
	total: string;
	currency: string;
	refundIds: number[];
	refundStatusMeta: string;
	lineItems: Array< { id: number; total: string; quantity: number } >;
}

function fail( message: string ): never {
	throw new Error( `WooPayments refund fidelity ${ message }` );
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function readJson< Result >(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
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

function optionalString( value: unknown ): string {
	return typeof value === 'string' ? value : '';
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

function relatedObjectId( value: unknown ): string {
	if (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		'id' in value
	) {
		return optionalString( ( value as { id?: unknown } ).id );
	}
	return optionalString( value );
}

function metadataValue( value: unknown, key: string ): string {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		return '';
	}
	return optionalString( ( value as Record< string, unknown > )[ key ] );
}

function toProviderRefund( item: unknown, index: number ): ProviderRefund {
	if ( typeof item !== 'object' || item === null || Array.isArray( item ) ) {
		fail( `provider refund occurrence ${ index + 1 } is not an object.` );
	}
	const refund = item as Record< string, unknown >;
	return {
		id: requiredString( refund.id, 'provider refund ID' ),
		status: requiredString( refund.status, 'provider refund status' ),
		amountMinor: requiredNumber( refund.amount, 'provider refund amount' ),
		currency: requiredString(
			refund.currency,
			'provider refund currency'
		).toUpperCase(),
		reason: optionalString( refund.reason ),
		merchantReason: metadataValue(
			refund.metadata,
			'merchant_refund_reason'
		),
		balanceTransactionId: relatedObjectId( refund.balance_transaction ),
	};
}

async function readChargeRefundState(
	restApi: APIRequestContext,
	chargeId: string
): Promise< ChargeRefundState > {
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

	// The platform expands `refunds.data`, so the collection is authoritative
	// rather than a truncated summary. A charge with no refunds still carries
	// the list object; anything else is an unknown shape and must fail loudly
	// instead of being read as "no refunds".
	const refundsValue = charge.refunds;
	if (
		typeof refundsValue !== 'object' ||
		refundsValue === null ||
		Array.isArray( refundsValue ) ||
		! ( 'data' in refundsValue ) ||
		! Array.isArray( refundsValue.data )
	) {
		fail( `charge ${ chargeId } carried no expanded refund collection.` );
	}

	return {
		chargeId,
		captured: requiredBoolean( charge.captured, 'charge captured flag' ),
		status: requiredString( charge.status, 'charge status' ),
		refunded: requiredBoolean( charge.refunded, 'charge refunded flag' ),
		amountRefundedMinor: requiredNumber(
			charge.amount_refunded,
			'charge refunded amount'
		),
		currency: requiredString(
			charge.currency,
			'charge currency'
		).toUpperCase(),
		balanceTransactionId: relatedObjectId( charge.balance_transaction ),
		refunds: refundsValue.data.map( toProviderRefund ),
	};
}

async function readOrderRecord(
	restApi: APIRequestContext,
	orderId: number
): Promise< OrderRecord > {
	const order = await readJson< {
		status?: unknown;
		total?: unknown;
		currency?: unknown;
		refunds?: unknown;
		meta_data?: unknown;
		line_items?: unknown;
	} >(
		await restApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`Order ${ orderId } read`
	);
	const refunds = Array.isArray( order.refunds ) ? order.refunds : [];
	const metaData = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const refundStatusMeta = metaData.find(
		( entry ) => entry.key === '_wcpay_refund_status'
	)?.value;
	const lineItems = Array.isArray( order.line_items ) ? order.line_items : [];

	return {
		status: requiredString( order.status, 'order status' ),
		total: requiredString( order.total, 'order total' ),
		currency: requiredString(
			order.currency,
			'order currency'
		).toUpperCase(),
		refundIds: refunds.map( ( refund, index ) =>
			requiredNumber(
				( refund as { id?: unknown } ).id,
				`refund ${ index + 1 } ID`
			)
		),
		refundStatusMeta: optionalString( refundStatusMeta ),
		lineItems: lineItems.map( ( line, index ) => {
			const item = line as {
				id?: unknown;
				total?: unknown;
				quantity?: unknown;
			};
			return {
				id: requiredNumber( item.id, `line item ${ index + 1 } ID` ),
				total: requiredString(
					item.total,
					`line item ${ index + 1 } total`
				),
				quantity: requiredNumber(
					item.quantity,
					`line item ${ index + 1 } quantity`
				),
			};
		} ),
	};
}

async function readWooRefundRecords(
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
			line_items?: unknown;
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
		const lines = Array.isArray( refund.line_items )
			? refund.line_items
			: [];

		return {
			id: requiredNumber(
				refund.id,
				`WooCommerce refund ${ index + 1 } ID`
			),
			amount: requiredString(
				refund.amount,
				`WooCommerce refund ${ index + 1 } amount`
			),
			reason: optionalString( refund.reason ),
			providerRefundId: optionalString( providerRefundId ),
			lines: lines.map( ( line, lineIndex ) => {
				const refundLine = line as {
					meta_data?: unknown;
					total?: unknown;
					quantity?: unknown;
				};
				const lineMeta = Array.isArray( refundLine.meta_data )
					? ( refundLine.meta_data as Array< {
							key?: unknown;
							value?: unknown;
					  } > )
					: [];
				// A refund line points at the parent order item it offsets
				// through `_refunded_item_id`; that is the only durable line
				// identity, and it is what the claim means by "by order-item
				// ID".
				const refundedItemId = lineMeta.find(
					( entry ) => entry.key === '_refunded_item_id'
				)?.value;
				return {
					orderItemId: Number( refundedItemId ),
					total: requiredString(
						refundLine.total,
						`refund line ${ lineIndex + 1 } total`
					),
					quantity: requiredNumber(
						refundLine.quantity,
						`refund line ${ lineIndex + 1 } quantity`
					),
				};
			} ),
		};
	} );
}

/**
 * Open the classic order edit screen. Stores keeping orders in the dedicated
 * tables edit them under `wc-orders`; stores still on the posts table edit them
 * through `post.php`. The order-items meta box is the screen's identity either
 * way.
 */
async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< void > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	const itemsBox = page.locator( '#woocommerce-order-items' );
	if ( ( await itemsBox.count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( itemsBox ).toBeVisible();
}

function refundPanel( page: Page ): Locator {
	// The refund editor is a toggled data row inside the items meta box; it
	// exposes no landmark or accessible name, so it is addressed structurally
	// and everything read out of it is addressed semantically.
	return page.locator( '.wc-order-refund-items' );
}

async function openRefundEditor( page: Page ): Promise< void > {
	// Exact, because "Refund %s manually" also starts with the same word.
	await page.getByRole( 'button', { name: 'Refund', exact: true } ).click();
	await expect( refundPanel( page ) ).toBeVisible();
}

/**
 * Fill one of the refund editor's inputs.
 *
 * The per-line inputs carry no label and no accessible name — a real gap in the
 * classic screen's markup — but they do carry the `name` attribute the request
 * is built from, which addresses the exact line under test without depending on
 * row order. `change` is dispatched explicitly because the admin script
 * recomputes the aggregate refund amount on that event and Playwright's fill
 * does not guarantee one.
 */
async function fillRefundInput(
	page: Page,
	inputName: string,
	value: string
): Promise< void > {
	const input = page.locator( `input[name="${ inputName }"]` );
	await expect( input ).toBeVisible();
	await input.fill( value );
	await input.dispatchEvent( 'change' );
}

/**
 * Allocate a refund amount to one order item, addressed by its order-item ID.
 *
 * Amount-only, deliberately: `#refund_amount` is rendered `readonly` whenever
 * the store has taxes enabled, so typing the aggregate directly is not a
 * driveable merchant action on every store, while the per-line amount fields
 * always are. The admin script recomputes the aggregate from these fields on
 * `change`, which is why the event is dispatched explicitly.
 */
async function allocateRefundToLine(
	page: Page,
	orderItemId: number,
	amount: string
): Promise< void > {
	await fillRefundInput(
		page,
		`refund_line_total[${ orderItemId }]`,
		amount
	);
}

interface DispatchedRefund {
	status: number;
}

/**
 * Dispatch exactly one gateway refund from the merchant's own order screen and
 * prove what was sent.
 *
 * The gateway control is the semantic "Refund <amount> via <gateway>" button
 * core renders only when the order's gateway supports refunds; its absence is
 * therefore itself a finding, not a locator problem. Core confirms the action
 * with `window.confirm()` and reloads the screen on success, so the response
 * body is deliberately not read — the browser discards the body of a request
 * whose page is gone, and reading it races the very success it would confirm.
 * The outcome is proven below from the provider's refund record and
 * WooCommerce's stored refund instead.
 */
async function dispatchGatewayRefund(
	session: ProviderWriteSession,
	page: Page,
	journalDescription: string
): Promise< DispatchedRefund > {
	const panel = refundPanel( page );
	const gatewayButton = panel.getByRole( 'button', {
		name: /^Refund .+ via .+$/,
	} );
	await expect(
		gatewayButton,
		'the order screen must offer a gateway refund; its absence means the order carries no refundable provider charge'
	).toHaveCount( 1 );

	let confirmations = 0;
	const onDialog = ( dialog: {
		type: () => string;
		accept: () => Promise< void >;
	} ) => {
		if ( dialog.type() === 'confirm' ) {
			confirmations += 1;
		}
		void dialog.accept();
	};
	page.on( 'dialog', onDialog );

	try {
		return await session.withProviderSubmissionJournal(
			journalDescription,
			async () => {
				const refundResponse = page.waitForResponse(
					( response ) =>
						response.url().includes( 'admin-ajax.php' ) &&
						response.request().method() === 'POST' &&
						( response.request().postData() ?? '' ).includes(
							'action=woocommerce_refund_line_items'
						)
				);
				await session.performWrite( () => gatewayButton.click() );
				const response = await refundResponse;

				expect(
					response.status(),
					'the refund request must be accepted by the server'
				).toBe( 200 );
				expect(
					confirmations,
					'the merchant must have confirmed exactly one refund'
				).toBe( 1 );

				return {
					status: response.status(),
				};
			}
		);
	} finally {
		page.off( 'dialog', onDialog );
	}
}

/**
 * Poll the exact provider refund on the exact source charge until it reaches
 * `succeeded` twice in a row, exactly as the claim's Convergence row fixes it.
 *
 * Two identical reads, not one: a single read can catch a value mid-propagation
 * and a settled refund never regresses, so stability is the cheap discriminator
 * between "settled" and "briefly reported settled".
 */
async function waitForSettledRefund(
	restApi: APIRequestContext,
	chargeId: string,
	budgetMs: number,
	intervalMs: number
): Promise< ProviderRefund > {
	const deadline = Date.now() + budgetMs;
	let previousSignature = '';
	let lastSeen = 'no provider refund yet';

	for (;;) {
		const charge = await readChargeRefundState( restApi, chargeId );
		if ( charge.refunds.length > 1 ) {
			fail(
				`charge ${ chargeId } carries ${ charge.refunds.length } provider refunds; exactly one is required.`
			);
		}
		const refund = charge.refunds[ 0 ];
		// Described even when no refund has appeared. "No refund yet" alone
		// cannot distinguish provider propagation lag from a refund that was
		// never created, and this poll runs for minutes before failing -- so the
		// charge's own refunded flag and refunded total are carried too, which
		// move as soon as the provider has accepted anything at all.
		lastSeen = refund
			? `${ refund.id } ${ refund.status }`
			: `no provider refund yet (charge refunds=${
					charge.refunds.length
			  }, refunded=${ String( charge.refunded ) }, amount refunded=${
					charge.amountRefundedMinor
			  } ${ charge.currency })`;
		if ( refund ) {
			if ( refund.status === 'succeeded' ) {
				const signature = `${ refund.id }|${ refund.status }|${ refund.amountMinor }|${ refund.currency }`;
				if ( signature === previousSignature ) {
					return refund;
				}
				previousSignature = signature;
			} else if (
				refund.status === 'failed' ||
				refund.status === 'canceled'
			) {
				fail(
					`provider refund ${ refund.id } reached terminal ${ refund.status } instead of succeeded.`
				);
			}
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`provider refund on charge ${ chargeId } did not reach two stable succeeded reads within ${ budgetMs }ms (last seen: ${ lastSeen }).`
			);
		}
		await delay( Math.min( intervalMs, remaining ) );
	}
}

/**
 * Poll WooCommerce's own record of the refund's provider status until it
 * reaches `successful`.
 *
 * The provider refund reaching `succeeded` and WooCommerce recording
 * `successful` are two different events, and for a redirect method they are not
 * simultaneous. Native writes `_wcpay_refund_status` from the refund response
 * it gets back synchronously; a card refund settles inside that call, while a
 * redirect-method refund comes back `pending` and only becomes successful when
 * the provider's refund event reaches
 * `WooPaymentsRefundEventHandler`. Reading the order once, straight after the
 * provider side converged, therefore samples a value that is still in flight.
 *
 * This is a convergence, not a relaxation: `pending` is never accepted as a
 * terminal answer. The budget is the case's own, so a method whose refund never
 * reports successful fails with its exact last-seen value rather than passing.
 */
async function waitForWooRefundStatus(
	restApi: APIRequestContext,
	orderId: number,
	budgetMs: number,
	intervalMs: number
): Promise< void > {
	const deadline = Date.now() + budgetMs;
	let lastSeen = '';

	for (;;) {
		const order = await readOrderRecord( restApi, orderId );
		lastSeen = order.refundStatusMeta;
		if ( lastSeen === 'successful' ) {
			return;
		}
		if ( lastSeen === 'failed' ) {
			fail(
				`WooCommerce recorded refund status failed on order ${ orderId }.`
			);
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`WooCommerce did not record refund status successful on order ${ orderId } within ${ budgetMs }ms (last seen: ${
					lastSeen || 'no status recorded'
				} ).`
			);
		}
		await delay( Math.min( intervalMs, remaining ) );
	}
}

/**
 * Assert the settled refund graph both sides agree on: one provider refund, one
 * WooCommerce refund, and the join between them.
 */
async function expectSingleJoinedRefund(
	restApi: APIRequestContext,
	paid: PaymentEvidence,
	expected: {
		amountMinor: number;
		currency: string;
		amountText: string;
		fullyRefunded: boolean;
	},
	settledRefund: ProviderRefund
): Promise< WooRefundRecord > {
	const charge = await readChargeRefundState( restApi, paid.chargeId );
	expect(
		charge.refunds,
		'exactly one provider refund must exist on the source charge'
	).toHaveLength( 1 );
	expect( charge.refunds[ 0 ].id ).toBe( settledRefund.id );
	expect( charge.refunds[ 0 ].status ).toBe( 'succeeded' );
	expect( charge.refunds[ 0 ].amountMinor ).toBe( expected.amountMinor );
	expect( charge.refunds[ 0 ].currency ).toBe( expected.currency );
	expect( charge.amountRefundedMinor ).toBe( expected.amountMinor );
	expect( charge.refunded ).toBe( expected.fullyRefunded );
	// The charge was refunded, not reversed: it stays captured.
	expect( charge.captured ).toBe( true );
	expect( charge.status ).toBe( 'succeeded' );

	const wooRefunds = await readWooRefundRecords( restApi, paid.orderId );
	expect(
		wooRefunds,
		'exactly one WooCommerce refund must exist'
	).toHaveLength( 1 );
	expect( wooRefunds[ 0 ].amount ).toBe( expected.amountText );
	// The join between the two records. Without it, "one Woo refund" and "one
	// provider refund" could be about different money.
	expect(
		wooRefunds[ 0 ].providerRefundId,
		'WooCommerce must store the exact provider refund ID once'
	).toBe( settledRefund.id );

	const order = await readOrderRecord( restApi, paid.orderId );
	expect( order.refundIds ).toEqual( [ wooRefunds[ 0 ].id ] );
	expect( order.refundStatusMeta ).toBe( 'successful' );
	expect( order.currency ).toBe( expected.currency );

	return wooRefunds[ 0 ];
}

/**
 * Drive one paid card order and hand back its proven payment identity, so this
 * family differs from a plain card purchase only in what happens afterwards.
 */
async function createPaidCardOrder(
	session: ProviderWriteSession,
	page: Page,
	runId: string,
	adminApi: APIRequestContext,
	expected: { amountMinor: number; currency: string; price: string }
): Promise< PaymentEvidence > {
	const product = await session.createOwnedProduct( expected.price );
	const orderId = await completeCardCheckout( session, page, product, runId );
	const paid = await getPaymentEvidence( adminApi, orderId );

	// The fixture every assertion below is derived from. A store that priced,
	// captured or settled this differently must fail here rather than silently
	// move the contract.
	expect( paid.amountMinor ).toBe( expected.amountMinor );
	expect( paid.currency ).toBe( expected.currency );
	expect( paid.orderStatus ).toBe( 'processing' );
	expect( paid.providerStatus ).toBe( 'succeeded' );
	expect( paid.chargeStatus ).toBe( 'succeeded' );
	expect( paid.chargeCaptured ).toBe( true );
	expect( paid.occurrenceCount ).toBe( 1 );
	expect( paid.captureOccurrenceCount ).toBe( 1 );
	await readProviderCardEvidence( adminApi, paid );

	return paid;
}

/* -------------------------------------------------------------------------
 * Cases
 * ---------------------------------------------------------------------- */

/**
 * `R1`: the retained refund-settlement smoke, trimmed to audit
 * `data/t1-provider-family-audit.md` §3. The real round trip from a card
 * checkout to a merchant refund to a settled provider refund whose identity
 * WooCommerce stores is the one assertion below the browser cannot make.
 * Everything else this case used to assert -- submitted form fields, request
 * count, note count, reason/metadata echo, the money-format precheck, and the
 * `R1v` transaction-view hand-off -- now lives in PHPUnit and Jest (T.1 batch
 * 5a) and is dropped here rather than proved twice.
 */
test(
	'A full card refund returns the exact paid amount as one succeeded provider refund whose identity WooCommerce stores once against the source charge',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_R1,
			},
		],
		tag: FAMILY_TAGS,
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		test.setTimeout( 420_000 );
		pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
		pilotRuntime.requireApprovedProviderFixture( CAPABILITY_REFUND );
		await pilotRuntime.assertCurrentRuntimeReady( 'native' );

		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'refund-settlement-r1' },
			async () => {
				const paid = await createPaidCardOrder(
					pilotRuntime,
					page,
					runId,
					adminApi,
					{
						amountMinor: R1_MINOR,
						currency: 'USD',
						price: R1_PRICE,
					}
				);
				const orderBefore = await readOrderRecord(
					adminApi,
					paid.orderId
				);

				await pilotRuntime.logInAsAdmin( page );
				await page.waitForURL( '**/wp-admin/**' );
				await openOrderEditScreen( page, paid.orderId );
				await openRefundEditor( page );
				await allocateRefundToLine(
					page,
					orderBefore.lineItems[ 0 ].id,
					R1_PRICE
				);
				await fillRefundInput( page, 'refund_reason', REFUND_REASON );

				await dispatchGatewayRefund(
					pilotRuntime,
					page,
					'refund-settlement-r1-full-refund'
				);

				const providerRefund = await waitForSettledRefund(
					adminApi,
					paid.chargeId,
					SETTLE_BUDGET_MS,
					SETTLE_INTERVAL_MS
				);
				await waitForWooRefundStatus(
					adminApi,
					paid.orderId,
					SETTLE_BUDGET_MS,
					SETTLE_INTERVAL_MS
				);
				await expectSingleJoinedRefund(
					adminApi,
					paid,
					{
						amountMinor: R1_MINOR,
						currency: 'USD',
						amountText: R1_PRICE,
						fullyRefunded: true,
					},
					providerRefund
				);

				const order = await readOrderRecord( adminApi, paid.orderId );
				expect( order.status ).toBe( 'refunded' );
			}
		);
	}
);
