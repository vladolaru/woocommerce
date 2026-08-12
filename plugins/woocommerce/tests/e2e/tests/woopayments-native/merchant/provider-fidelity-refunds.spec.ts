import { execFile } from 'node:child_process';
import { stripVTControlCharacters } from 'node:util';

import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';
import {
	AFFIRM,
	AFTERPAY,
	ALIPAY,
	BANCONTACT,
	completeRedirectCheckout,
	withEnabledPaymentMethod,
	withForeignCurrency,
	type RedirectMethod,
} from '../../../utils/woopayments-native/drivers/redirect-methods';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import { ResourceQuarantineRequiredError } from '../../../utils/woopayments-native/resource-locks';

/**
 * The `refund-settlement` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * The claim this suite drives: one refund request against the listed source
 * charge for the listed amount and currency produces exactly one provider
 * refund that reaches `succeeded`; WooCommerce stores that refund's identity
 * and a matching refunded total; a deliberate byte-identical replay under the
 * same idempotency key returns that same provider refund rather than creating
 * another; and, for `R1`'s refund, the native transaction view presents its
 * semantic amount, refunded status, and merchant-supplied reason within a
 * bounded propagation window.
 *
 * Every case here is one row of that claim's fixed run contract, and every
 * test title is the contract sentence the ledger records. What makes these
 * different from the client suite's originals is where the money assertion is
 * read: the provider's own refund collection on the exact source charge, joined
 * to WooCommerce's refund by `_wcpay_refund_id`, rather than a rendered order
 * status that can say "Refunded" while nothing left the account.
 *
 * The refund itself always goes through core's own
 * `woocommerce_refund_line_items` admin-AJAX action with `api_refund` as the
 * *string* `'true'` — `WC_AJAX::refund_line_items()` compares strictly, and a
 * boolean silently downgrades to a local-only refund, which is exactly the
 * failure this family exists to detect.
 *
 * Cost note: one full pass of this suite creates eight source charges (four
 * card, four redirect) and eight provider refunds, plus one deliberate
 * same-key replay POST that must return an existing refund rather than create
 * a ninth. Nothing here is retried: `--retries=0` is mandatory, and the replay
 * is the only repeated financial request in the file.
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
/** The capability for mutating the enabled-currency configuration. */
const CAPABILITY_CURRENCY = 'refund-settlement-currency';
/** The capability for mutating the enabled-payment-method set. */
const CAPABILITY_METHOD = 'refund-settlement-method';
/** The capability for the deliberate same-key replay probe. */
const CAPABILITY_REPLAY = 'refund-settlement-replay';

const CONTRACT_R1 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-full-refund.spec.ts:38::WooCommerce Payments - Full Refund › should process a full refund for an order';
const CONTRACT_R1V =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-full-refund.spec.ts:62::WooCommerce Payments - Full Refund › should be able to view a refunded transaction';
const CONTRACT_R2_AMOUNT_ONLY =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-partial-refund.spec.ts:118::Order › Partial refund › Partially refund one product of two product order';
const CONTRACT_R2_MULTI_LINE =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-partial-refund.spec.ts:118::Order › Partial refund › Refund two products of three product order';
const CONTRACT_R3_CURRENCY =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-multi-currency.spec.ts:114::Admin Multi-Currency Orders › can refund in correct currency';
const CONTRACT_R3_TRANSACTION =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-multi-currency.spec.ts:181::Admin Multi-Currency Orders › refund displays correctly on transaction page';
const CONTRACT_R4 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/alipay-checkout-purchase.spec.ts:74::Alipay Checkout › merchant can see and refund an Alipay order';
const CONTRACT_R5 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:113::BNPL checkout › merchant can see and refund a Affirm order';
const CONTRACT_R7 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-bnpls-checkout.spec.ts:113::BNPL checkout › merchant can see and refund a Cash App Afterpay order';
const CONTRACT_R6 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-with-upe-methods.spec.ts:131::Local payment method checkout with card testing › merchant can see and refund a Bancontact order';

/**
 * The merchant-supplied refund reason.
 *
 * `WooPaymentsApiClient::refund_charge()` forwards a free-text reason only as
 * `metadata.merchant_refund_reason`; it sets the provider's own `reason` field
 * only for the three values the provider enumerates. `R1v` has to read the
 * reason back off the native transaction view, and the view's refund timeline
 * entry renders the provider event's `reason`, so a free-text reason would be
 * unreadable there through no fault of native. This is also the exact value
 * native's own transaction-details refund modal offers, so it is a real
 * merchant choice rather than a fixture convenience.
 */
const REFUND_REASON = 'requested_by_customer';
/** How `formatLabel()` renders `REFUND_REASON` on the transaction view. */
const REFUND_REASON_LABEL = 'Requested by customer';

// R1: one captured `4242` USD 10.99 charge, refunded in full.
const R1_PRICE = '10.99';
const R1_MINOR = 1099;

// R2: a three-line USD 10.99 order whose first two lines total USD 3.33,
// leaving USD 7.66 refundable. The lines are addressed by order-item ID, never
// by position.
const R2_LINE_PRICES = [ '1.11', '2.22', '7.66' ] as const;
const R2_REFUND_TOTAL = '3.33';
const R2_REFUND_MINOR = 333;
const R2_ORDER_TOTAL = '10.99';
const R2_REMAINING_REFUNDABLE = 7.66;

// R3/R6: EUR 12.34, priced through a pinned manual rate of 1.0 so the
// converted amount is exact and independent of any provider-fetched rate. The
// rate pin itself lives in the shared redirect-method driver, which owns the
// enabled-currency snapshot both families restore from.
const EUR_PRICE = '12.34';
const EUR_MINOR = 1234;

// R4-R7 drive the `A*` redirect-method inputs from the sibling
// `redirect-method-provider-outcome` contract. The method catalog, the billing
// addresses, the enabled-method snapshot, the foreign-currency snapshot and the
// classic redirect journey are shared with that family through
// `utils/woopayments-native/drivers/redirect-methods.ts`: both families drive
// the same five methods and must restore the same store state byte-for-byte,
// so there is one implementation of that and two sets of assertions over it.

/** Convergence, as fixed by the claim. */
const SETTLE_INTERVAL_MS = 3_000;
const SETTLE_BUDGET_MS = 180_000;
/** `R7` carries an explicitly extended budget for Cash App Afterpay. */
const AFTERPAY_SETTLE_INTERVAL_MS = 5_000;
const AFTERPAY_SETTLE_BUDGET_MS = 600_000;
/** After the replay POST. */
const REPLAY_INTERVAL_MS = 3_000;
const REPLAY_BUDGET_MS = 60_000;
/** `R1v`'s bounded propagation window for the transaction view. */
const TRANSACTION_VIEW_BUDGET_MS = 120_000;

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

/** What one settled refund case hands to whatever observes it afterwards. */
interface SettledRefund {
	paid: PaymentEvidence;
	wooRefund: WooRefundRecord;
	providerRefund: ProviderRefund;
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
		await restApi.get(
			`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
				chargeId
			) }`
		),
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

/**
 * Record every browser-side attempt to move money on this order, so "exactly
 * one refund was asked for" is an observation of what the merchant's browser
 * dispatched rather than an inference from the end state.
 */
function trackRefundRequests( page: Page ): () => string[] {
	const observed: string[] = [];

	page.on( 'request', ( request ) => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		const postData = request.postData() ?? '';
		if (
			request.url().includes( 'admin-ajax.php' ) &&
			postData.includes( 'action=woocommerce_refund_line_items' )
		) {
			observed.push( 'admin-ajax:woocommerce_refund_line_items' );
			return;
		}
		if ( /\/wc\/v3\/payments\/refund\b/.test( request.url() ) ) {
			observed.push( 'rest:payments/refund' );
		}
	} );

	return () => [ ...observed ];
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

const DECIMAL_SEPARATOR_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_decimal_sep';
const NUM_DECIMALS_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_num_decimals';

/**
 * Assert the store formats money the way this suite's amounts assume.
 *
 * Every amount typed into the refund editor is a two-decimal string in a
 * `wc_input_price` field, which the admin script parses with the store's own
 * monetary decimal separator, and every amount read back out of `#refund_amount`
 * is compared as a string. A store configured differently must fail here,
 * loudly, rather than submit a value that means something else.
 */
async function assertMoneyFormatAssumptions(
	restApi: APIRequestContext
): Promise< void > {
	const decimalSeparator = await readJson< { value?: unknown } >(
		await restApi.get( DECIMAL_SEPARATOR_API ),
		'Price decimal separator read'
	);
	expect(
		decimalSeparator.value,
		'this suite types two-decimal amounts with a dot separator'
	).toBe( '.' );
	const numDecimals = await readJson< { value?: unknown } >(
		await restApi.get( NUM_DECIMALS_API ),
		'Price decimals read'
	);
	expect(
		String( numDecimals.value ),
		'this suite states its amounts at two decimal places'
	).toBe( '2' );
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
 * The value cell of one refund-editor summary row, addressed by the row's own
 * label rather than by position.
 */
function refundSummaryValue( page: Page, label: string ): Locator {
	return refundPanel( page )
		.getByRole( 'row' )
		.filter( { hasText: label } )
		.getByRole( 'cell' )
		.last();
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
	submitted: URLSearchParams;
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
		type(): string;
		accept(): Promise< void >;
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
					submitted: new URLSearchParams(
						response.request().postData() ?? ''
					),
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
		if ( refund ) {
			lastSeen = `${ refund.id } ${ refund.status }`;
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
	expect( wooRefunds[ 0 ].reason ).toBe( REFUND_REASON );
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

/**
 * Resolve `R2`'s two intended lines, and the one that must stay refundable, by
 * their prices, and hand back the order-item identities the refund is addressed
 * with. Positional selection is exactly what the ledger records as able to bind
 * the wrong line, so the identity resolved here is the order-item ID and
 * nothing else.
 */
function resolveRefundedLines(
	order: OrderRecord
): [
	OrderRecord[ 'lineItems' ][ number ],
	OrderRecord[ 'lineItems' ][ number ],
	OrderRecord[ 'lineItems' ][ number ]
] {
	const lineByTotal = new Map(
		order.lineItems.map( ( line ) => [ line.total, line ] )
	);
	const firstLine = lineByTotal.get( R2_LINE_PRICES[ 0 ] );
	const secondLine = lineByTotal.get( R2_LINE_PRICES[ 1 ] );
	const untouchedLine = lineByTotal.get( R2_LINE_PRICES[ 2 ] );
	if ( ! firstLine || ! secondLine || ! untouchedLine ) {
		fail(
			`R2 requires lines priced ${ R2_LINE_PRICES.join(
				', '
			) }; the order carries ${ order.lineItems
				.map( ( line ) => line.total )
				.join( ', ' ) }.`
		);
	}
	return [ firstLine, secondLine, untouchedLine ];
}

/* -------------------------------------------------------------------------
 * `R1v` and `R3v`: the native transaction view
 * ---------------------------------------------------------------------- */

/** The semantic facts the view cases read, matched flexibly rather than by copy. */
const R1_AMOUNT_TEXT = /10\.99/;
const EUR_AMOUNT_TEXT = /12[.,]34/;
const REFUNDED_STATUS_TEXT = /refunded/i;
// The transaction summary prints the charge's own currency code beside the
// amount, which is what makes "in the original charge currency" observable
// without depending on symbol placement or locale formatting.
const EUR_CURRENCY_TEXT = 'EUR';

/** One semantic fact the transaction view must present, and how to name it. */
interface TransactionViewFact {
	label: string;
	pattern: RegExp | string;
}

/**
 * One visible occurrence of a transaction-view fact. The details page renders
 * hidden duplicates in collapsed regions, so a bare `first()` can land on a
 * node no merchant can read.
 */
function visibleTransactionFact(
	page: Page,
	pattern: RegExp | string
): Locator {
	return page.getByText( pattern ).filter( { visible: true } ).first();
}

async function countVisibleTransactionFact(
	page: Page,
	pattern: RegExp | string
): Promise< number > {
	return page.getByText( pattern ).filter( { visible: true } ).count();
}

/**
 * Poll the loaded transaction view until it presents every named fact at once,
 * reloading between attempts because the view is rendered from provider events
 * that propagate asynchronously. The failure names which facts were missing, so
 * a view that shows the amount but never the status fails legibly rather than
 * as a bare timeout.
 */
async function waitForRefundOnTransactionView(
	page: Page,
	facts: readonly TransactionViewFact[]
): Promise< void > {
	const deadline = Date.now() + TRANSACTION_VIEW_BUDGET_MS;

	for (;;) {
		const missing: string[] = [];
		for ( const fact of facts ) {
			const shown = await countVisibleTransactionFact(
				page,
				fact.pattern
			);
			if ( shown === 0 ) {
				missing.push( fact.label );
			}
		}
		if ( missing.length === 0 ) {
			return;
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			fail(
				`the native transaction view did not present ${ missing.join(
					', '
				) } within ${ TRANSACTION_VIEW_BUDGET_MS }ms.`
			);
		}
		await delay( Math.min( SETTLE_INTERVAL_MS, remaining ) );
		await page.reload();
	}
}

/**
 * The native transaction-details route for one charge.
 *
 * Keyed on the charge, which is native's own deep-link key for this route —
 * `WooPaymentsDisputeEventHandler` builds
 * `path=/payments/transactions/details&id=<charge_id>&transaction_id=<balance_txn>`
 * and the admin navigation controller maps that legacy path onto this native
 * one. The details page accepts either identifier (`isPaymentIntentId( id )`
 * picks the branch), so the charge is a choice rather than a necessity — but it
 * is the identifier native itself links with, and one ledger row already
 * records the difference as native behaviour: "Native UI may not expose the
 * PaymentIntent as an order-page link; adapter must use a supported transaction
 * relationship."
 *
 * Navigating the route directly, rather than hunting a row in the transactions
 * list, is deliberate. What `R1v` and `R3v` are about is what the details view
 * presents about a settled refund; how a merchant reaches that view is the
 * transaction-navigation family's contract, not this one's, and binding to the
 * list's link keying made this family fail for a reason that has nothing to do
 * with refunds.
 *
 * The host page is `wc-settings&tab=checkout`, not `wc-admin`. Native's routes
 * live under the Core-owned Settings > Payments surface — `Settings\Utils::
 * wc_payments_settings_url()` is the one builder every native link goes
 * through — and `wc-admin` answers an unregistered path with a "Not allowed"
 * screen rather than a 404, so getting this wrong reads as a missing heading
 * rather than as a bad address.
 */
function transactionDetailsPath( chargeId: string ): string {
	const query = new URLSearchParams( {
		page: 'wc-settings',
		tab: 'checkout',
		path: '/woopayments/transactions/details',
		id: chargeId,
	} );
	return `wp-admin/admin.php?${ query.toString() }`;
}

/**
 * Open the merchant's transaction view for a settled refund and prove the
 * record it is a view of is still exactly one succeeded refund.
 *
 * Shared by `R1v` and `R3v`: the view assertions each of them makes are only
 * meaningful against a refund that has not changed since the case that created
 * it, and neither view case is allowed to move money.
 */
async function openSettledRefundTransactionView(
	session: ProviderWriteSession,
	page: Page,
	restApi: APIRequestContext,
	settled: SettledRefund
): Promise< void > {
	const charge = await readChargeRefundState(
		restApi,
		settled.paid.chargeId
	);
	expect( charge.refunds ).toHaveLength( 1 );
	expect( charge.refunds[ 0 ].id ).toBe( settled.providerRefund.id );
	expect( charge.refunds[ 0 ].status ).toBe( 'succeeded' );

	await session.assertCanWrite();
	await session.logInAsAdmin( page );
	await page.waitForURL( '**/wp-admin/**' );
	await page.goto( transactionDetailsPath( settled.paid.chargeId ) );

	await expect(
		page.getByRole( 'heading', {
			name: /^(Payment details|Transaction details)$/,
		} )
	).toBeVisible();
	// The view is of this order's payment, not merely of some transaction: an
	// `id` the route could not resolve renders an error instead of this link.
	await expect(
		page.getByRole( 'link', {
			name: `Order #${ settled.paid.orderId }`,
			exact: true,
		} )
	).toBeVisible();
}

/**
 * Prove that looking at a refund changed nothing: still one refund, on both
 * sides.
 */
async function expectRefundUnchangedAfterViewing(
	restApi: APIRequestContext,
	settled: SettledRefund
): Promise< void > {
	const charge = await readChargeRefundState(
		restApi,
		settled.paid.chargeId
	);
	expect( charge.refunds ).toHaveLength( 1 );
	expect( charge.refunds[ 0 ].id ).toBe( settled.providerRefund.id );
	expect(
		await readWooRefundRecords( restApi, settled.paid.orderId )
	).toHaveLength( 1 );
}

/* -------------------------------------------------------------------------
 * `RP`: the deliberate same-key replay
 * ---------------------------------------------------------------------- */

/**
 * The replay probe, executed on the store itself.
 *
 * There is no HTTP surface that can re-send a refund under a chosen
 * idempotency key: `PaymentProcessingService::process_refund()` derives the key
 * itself, and `resolve_refund_instance_id()` deliberately skips refunds that
 * already carry `_wcpay_refund_id`, so a second refund through any WooCommerce
 * path resolves to a *different* refund instance and therefore a different key.
 * That is correct product behaviour — it is what stops two equal refunds from
 * colliding — and it is precisely why the replay has to be issued through the
 * same API client the first refund used, with the key re-derived by core's own
 * `PaymentOperationIdempotency` service from the same order, provider, amount,
 * currency, reason and refund instance.
 *
 * Safety: the case this probe runs against has already been refunded in full,
 * so a key that failed to match is refused by the provider as an over-refund
 * rather than creating a second refund. The probe therefore cannot move money a
 * second time; it can only report which of the two happened.
 *
 * That second branch is not hypothetical, and the first authorized run took it.
 * Both native and the WooPayments client strip `idempotency_key` out of the
 * request body and send it as an `Idempotency-Key` HTTP header, while the
 * platform reads it back with
 * `get_param( 'idempotency-key' ) ?? get_param( 'idempotency_key' )` — a
 * *parameter* lookup that never sees a header. So the key does not reach
 * Stripe, the request is evaluated fresh, and the over-refund guard is what
 * stands between a replay and a duplicate refund. The probe therefore reports a
 * refusal as an outcome rather than dying on it; see the `refund-settlement`
 * correction in FIDELITY-CLAIMS.md.
 *
 * Automatic transport retries are disabled for the probe by throwing out of the
 * client's own per-attempt response filter, so the second attempt the retry loop
 * would make never leaves the machine and the run fails instead.
 */
const REPLAY_PHP = String.raw`<?php
function wcpay_rp_fail() { throw new RuntimeException( 'WooPayments E2E refund replay failed.' ); }
function wcpay_rp_exact_keys( $value, $keys ) { if ( ! is_array( $value ) ) { wcpay_rp_fail(); } $actual = array_keys( $value ); sort( $actual ); sort( $keys ); if ( $actual !== $keys ) { wcpay_rp_fail(); } }
function wcpay_rp_emit( $payload ) { echo wp_json_encode( array( 'home' => get_home_url(), 'site' => get_site_url(), 'payload' => $payload ), JSON_UNESCAPED_SLASHES ) . "\n"; }
try {
	$input_json = base64_decode( $wcpay_rp_input_base64, true );
	if ( false === $input_json ) { wcpay_rp_fail(); }
	$input = json_decode( $input_json, true, 32, JSON_THROW_ON_ERROR );
	wcpay_rp_exact_keys( $input, array( 'amountMinor', 'baseURL', 'chargeId', 'currency', 'orderId', 'providerRefundId', 'reason', 'refundId', 'runId' ) );
	if ( get_home_url() !== $input['baseURL'] || get_site_url() !== $input['baseURL'] ) { wcpay_rp_fail(); }

	$order = wc_get_order( (int) $input['orderId'] );
	if ( ! $order instanceof WC_Order ) { wcpay_rp_fail(); }
	if ( (string) $order->get_meta( '_e2e_woopayments_run_id', true ) !== (string) $input['runId'] ) { wcpay_rp_fail(); }
	if ( (string) $order->get_meta( '_charge_id', true ) !== (string) $input['chargeId'] ) { wcpay_rp_fail(); }
	if ( strtoupper( (string) $order->get_currency() ) !== (string) $input['currency'] ) { wcpay_rp_fail(); }

	$refunds = $order->get_refunds();
	if ( 1 !== count( $refunds ) ) { wcpay_rp_fail(); }
	$refund = $refunds[0];
	if ( ! $refund instanceof WC_Order_Refund ) { wcpay_rp_fail(); }
	if ( (int) $refund->get_id() !== (int) $input['refundId'] ) { wcpay_rp_fail(); }
	if ( (string) $refund->get_reason() !== (string) $input['reason'] ) { wcpay_rp_fail(); }
	if ( (string) $refund->get_meta( '_wcpay_refund_id', true ) !== (string) $input['providerRefundId'] ) { wcpay_rp_fail(); }

	$container = wc_get_container();
	$provider = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsProvider::class );
	$idempotency = $container->get( Automattic\WooCommerce\Internal\Payments\PaymentOperationIdempotency::class );
	$order_data_service = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsOrderDataService::class );
	$api_client = $container->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiClient::class );

	$amount = (float) $refund->get_amount();
	$amount_minor = $order_data_service->prepare_amount( $amount, (string) $order->get_currency() );
	if ( (int) $amount_minor !== (int) $input['amountMinor'] ) { wcpay_rp_fail(); }

	$key = $idempotency->derive_key( $order, $provider->get_id(), 'refund', $amount, (string) $order->get_currency(), (string) $refund->get_reason(), (string) $refund->get_id() );
	if ( ! is_string( $key ) || '' === $key ) { wcpay_rp_fail(); }

	$attempts = 0;
	add_filter(
		'wcpay_api_request_response',
		function ( $response, $method, $url, $api ) use ( &$attempts ) {
			if ( 'refunds' !== $api || 'POST' !== $method ) { return $response; }
			++$attempts;
			if ( 1 < $attempts || is_wp_error( $response ) ) { throw new RuntimeException( 'WooPayments E2E refund replay refuses an automatic transport retry.' ); }
			return $response;
		},
		10,
		4
	);

	$refusal = '';
	$result = array();
	try {
		$result = $api_client->refund_charge( (string) $input['chargeId'], (int) $amount_minor, (string) $refund->get_reason(), 'woocommerce_native', $key );
	} catch ( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\Api\WooPaymentsApiException $api_error ) {
		// The provider answered, and its answer is the observation. Only a
		// transport or harness failure is a failure of this probe; a refusal
		// is a result the case has to be able to report and assert on.
		$refusal = $api_error->getMessage();
	}
	if ( 1 !== $attempts ) { wcpay_rp_fail(); }
	if ( '' === $refusal && ! is_array( $result ) ) { wcpay_rp_fail(); }

	wcpay_rp_emit(
		array(
			'attempts' => $attempts,
			'outcome' => '' === $refusal ? 'replayed' : 'refused',
			'refusal' => $refusal,
			'refundId' => isset( $result['id'] ) ? (string) $result['id'] : '',
			'status' => isset( $result['status'] ) ? (string) $result['status'] : '',
			'amount' => isset( $result['amount'] ) ? (int) $result['amount'] : -1,
			'currency' => isset( $result['currency'] ) ? strtoupper( (string) $result['currency'] ) : '',
			'charge' => isset( $result['charge'] ) ? ( is_array( $result['charge'] ) ? (string) ( $result['charge']['id'] ?? '' ) : (string) $result['charge'] ) : '',
		)
	);
} catch ( Throwable $error ) {
	fwrite( STDERR, "WooPayments E2E refund replay failed.\n" );
	exit( 1 );
}
`;

interface ReplayInput {
	baseURL: string;
	orderId: number;
	refundId: number;
	chargeId: string;
	providerRefundId: string;
	amountMinor: number;
	currency: string;
	reason: string;
	runId: string;
}

interface ReplayResult {
	attempts: number;
	/**
	 * What the provider did with the replayed request.
	 *
	 * `replayed` means the same-key request came back as the original refund —
	 * idempotency in force. `refused` means the provider evaluated it as a
	 * fresh request and turned it down. Both are answers; only a transport or
	 * harness failure is a non-answer.
	 */
	outcome: 'replayed' | 'refused';
	/** The provider's refusal text, empty when it replayed. */
	refusal: string;
	refundId: string;
	status: string;
	amount: number;
	currency: string;
	charge: string;
}

function replayPhp( input: ReplayInput ): string {
	const encoded = Buffer.from( JSON.stringify( input ), 'utf8' ).toString(
		'base64'
	);
	return REPLAY_PHP.replace(
		'<?php',
		`$wcpay_rp_input_base64 = '${ encoded }';`
	);
}

function executeFile(
	command: string,
	args: string[],
	options: { cwd: string }
): Promise< string > {
	return new Promise( ( resolve, reject ) => {
		execFile(
			command,
			args,
			{ cwd: options.cwd, encoding: 'utf8' },
			( error, stdout ) => {
				if ( error ) {
					reject( error );
					return;
				}
				resolve( stdout );
			}
		);
	} );
}

/**
 * Issue the named replay and return what the provider answered.
 *
 * The command shape matches the harness's established raw-state driver:
 * `wp-env run cli wp --user=1 eval` from the native store directory, with the
 * payload passed as base64 bindings so no shell quoting can reshape it.
 */
async function replayRefundUnderSameKey(
	session: ProviderWriteSession,
	input: ReplayInput
): Promise< ReplayResult > {
	session.requireApprovedProviderFixture( CAPABILITY_REPLAY );
	const storeDirectory = process.env.E2E_WOOPAYMENTS_NATIVE_STORE_DIR ?? '';
	if ( ! storeDirectory ) {
		fail(
			'E2E_WOOPAYMENTS_NATIVE_STORE_DIR is required for the same-key replay probe.'
		);
	}

	return session.withProviderSubmissionJournal(
		'refund-settlement-same-key-replay',
		async () => {
			let stdout: string;
			try {
				stdout = await session.performWrite( () =>
					executeFile(
						'pnpm',
						[
							'exec',
							'wp-env',
							'run',
							'cli',
							'wp',
							'--user=1',
							'eval',
							replayPhp( input ),
						],
						{ cwd: storeDirectory }
					)
				);
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					'The WooPayments same-key refund replay has no proven outcome.',
					'uncertain-provider-write',
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}

			const jsonLine = stdout
				.split( /\r?\n/ )
				.map( ( line ) => stripVTControlCharacters( line ).trim() )
				.toReversed()
				.find( ( line ) => line.startsWith( '{' ) );
			if ( ! jsonLine ) {
				throw new ResourceQuarantineRequiredError(
					'The WooPayments same-key refund replay produced no JSON result line.',
					'uncertain-provider-write'
				);
			}
			const envelope = JSON.parse( jsonLine ) as {
				home?: unknown;
				site?: unknown;
				payload?: unknown;
			};
			if (
				envelope.home !== session.baseURL ||
				envelope.site !== session.baseURL
			) {
				fail(
					'same-key replay URL proof did not match the selected store.'
				);
			}
			return envelope.payload as ReplayResult;
		}
	);
}

/* -------------------------------------------------------------------------
 * Cases
 * ---------------------------------------------------------------------- */

/**
 * `R1`'s settled refund, shared with `R1v`.
 *
 * `R1v` is fixed by the claim as an observation of `R1`'s refund — "the
 * merchant loads the native transaction view for `R1`'s charge" — so the two
 * run serially and `R1v` reads what `R1` proved rather than creating a second
 * charge and a second refund to look at. If `R1` does not record a result,
 * `R1v` fails rather than inventing one.
 */
let r1Settled: SettledRefund | undefined;

function requireR1Settled(): SettledRefund {
	if ( ! r1Settled ) {
		fail(
			'R1v observes R1’s refund, and R1 recorded none; there is nothing to view.'
		);
	}
	return r1Settled;
}

test.describe.serial( 'refund-settlement R1', () => {
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
					await assertMoneyFormatAssumptions( adminApi );
					const refundRequests = trackRefundRequests( page );
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
					const notesBefore = await readOrderNotes(
						adminApi,
						paid.orderId
					);

					const orderBefore = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect(
						orderBefore.lineItems,
						'R1 refunds one single-line order in full'
					).toHaveLength( 1 );
					expect( orderBefore.lineItems[ 0 ].total ).toBe( R1_PRICE );

					await pilotRuntime.logInAsAdmin( page );
					await page.waitForURL( '**/wp-admin/**' );
					await openOrderEditScreen( page, paid.orderId );
					await openRefundEditor( page );
					await allocateRefundToLine(
						page,
						orderBefore.lineItems[ 0 ].id,
						R1_PRICE
					);
					await fillRefundInput(
						page,
						'refund_reason',
						REFUND_REASON
					);
					// The aggregate the editor derived, which is what the
					// request carries.
					await expect(
						page.locator( '#refund_amount' )
					).toHaveValue( R1_PRICE );

					const dispatched = await dispatchGatewayRefund(
						pilotRuntime,
						page,
						'refund-settlement-r1-full-refund'
					);

					// The one detail that decides whether money actually moved:
					// `WC_AJAX::refund_line_items()` compares `api_refund`
					// against the string 'true', and anything else silently
					// downgrades to a local-only refund.
					expect( dispatched.submitted.get( 'api_refund' ) ).toBe(
						'true'
					);
					expect( dispatched.submitted.get( 'refund_amount' ) ).toBe(
						R1_PRICE
					);
					expect(
						JSON.parse(
							dispatched.submitted.get( 'line_item_totals' ) ??
								'{}'
						)
					).toEqual( {
						[ orderBefore.lineItems[ 0 ].id ]: Number( R1_PRICE ),
					} );
					expect( dispatched.submitted.get( 'refund_reason' ) ).toBe(
						REFUND_REASON
					);
					expect( dispatched.submitted.get( 'order_id' ) ).toBe(
						String( paid.orderId )
					);
					// Exactly one, from the merchant's browser outwards.
					expect(
						refundRequests(),
						'the merchant must have asked for exactly one refund'
					).toEqual( [ 'admin-ajax:woocommerce_refund_line_items' ] );

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
					const wooRefund = await expectSingleJoinedRefund(
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

					// The merchant-supplied reason survived to the provider,
					// both as the enumerated reason and as the metadata field
					// native always sends.
					expect( providerRefund.reason ).toBe( REFUND_REASON );
					expect( providerRefund.merchantReason ).toBe(
						REFUND_REASON
					);

					const order = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect( order.status ).toBe( 'refunded' );
					expect( order.total ).toBe( R1_PRICE );

					// The merchant is told, once.
					const newNotes = (
						await readOrderNotes( adminApi, paid.orderId )
					 ).filter( ( note ) => ! notesBefore.includes( note ) );
					expect(
						newNotes.filter( ( note ) =>
							note.includes( providerRefund.id )
						),
						'the refund must be journaled on the order exactly once'
					).toHaveLength( 1 );

					r1Settled = { paid, wooRefund, providerRefund };
				}
			);
		}
	);

	test(
		"The native transaction view presents the settled refund's amount, refunded status, and merchant-supplied reason within the bounded propagation window",
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_R1V,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime } ) => {
			test.setTimeout( 300_000 );
			pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			const settled = requireR1Settled();

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'refund-settlement-r1v' },
				async () => {
					// The record R1 left, re-read cold: the view assertions
					// below are only meaningful against a refund that is still
					// exactly one succeeded refund for the full amount.
					await openSettledRefundTransactionView(
						pilotRuntime,
						page,
						adminApi,
						settled
					);

					// The refund's semantic facts, with bounded polling for
					// propagation, because the view renders provider events
					// that arrive asynchronously.
					await waitForRefundOnTransactionView( page, [
						{ label: 'the refund amount', pattern: R1_AMOUNT_TEXT },
						{
							label: 'the refunded status',
							pattern: REFUNDED_STATUS_TEXT,
						},
						{
							label: 'the merchant-supplied refund reason',
							pattern: REFUND_REASON_LABEL,
						},
					] );

					// Named, not merely counted, so the passing state is the
					// one a merchant would actually read.
					await expect(
						visibleTransactionFact( page, R1_AMOUNT_TEXT )
					).toBeVisible();
					await expect(
						visibleTransactionFact( page, REFUNDED_STATUS_TEXT )
					).toBeVisible();
					await expect(
						visibleTransactionFact( page, REFUND_REASON_LABEL )
					).toBeVisible();

					await expectRefundUnchangedAfterViewing(
						adminApi,
						settled
					);
				}
			);
		}
	);
} );

test(
	'A partial refund allocated to two order items by item ID settles as one succeeded provider refund and leaves the exact remaining refundable amount',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_R2_AMOUNT_ONLY,
			},
			{
				type: 'woopayments-contract',
				description: CONTRACT_R2_MULTI_LINE,
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
			{ recordEvent: 'refund-settlement-r2' },
			async () => {
				await assertMoneyFormatAssumptions( adminApi );
				const refundRequests = trackRefundRequests( page );

				// A three-line order whose first two lines total USD 3.33 and
				// whose third holds the USD 7.66 that must stay refundable.
				const products: OwnedProduct[] = [];
				for ( const price of R2_LINE_PRICES ) {
					products.push(
						await pilotRuntime.createOwnedProduct( price )
					);
				}
				// The card driver adds the last product itself and then drives
				// the checkout, so only the first two are added here; adding
				// all three would silently double the third line.
				for ( const product of products.slice( 0, -1 ) ) {
					await page.goto( `?post_type=product&p=${ product.id }` );
					await pilotRuntime.performWrite( () =>
						page
							.getByRole( 'button', {
								name: 'Add to cart',
								exact: true,
							} )
							.click()
					);
				}

				const orderId = await completeCardCheckout(
					pilotRuntime,
					page,
					products[ products.length - 1 ],
					runId
				);
				const paid = await getPaymentEvidence( adminApi, orderId );
				expect( paid.currency ).toBe( 'USD' );
				expect( paid.providerStatus ).toBe( 'succeeded' );
				expect( paid.chargeCaptured ).toBe( true );
				expect( paid.occurrenceCount ).toBe( 1 );

				const orderBefore = await readOrderRecord( adminApi, orderId );
				// The fixture the whole case is derived from: three lines, the
				// exact prices, and a total the two refunded lines leave
				// USD 7.66 of.
				expect(
					orderBefore.lineItems,
					'R2 requires a three-line order'
				).toHaveLength( 3 );
				expect( orderBefore.total ).toBe( R2_ORDER_TOTAL );
				expect( paid.amountMinor ).toBe( 1099 );
				const [ firstLine, secondLine, untouchedLine ] =
					resolveRefundedLines( orderBefore );

				await pilotRuntime.logInAsAdmin( page );
				await page.waitForURL( '**/wp-admin/**' );
				await openOrderEditScreen( page, orderId );
				await openRefundEditor( page );

				// The two intended lines, selected by order-item ID. Positional
				// selection is exactly what the ledger records as able to bind
				// the wrong line.
				await allocateRefundToLine(
					page,
					firstLine.id,
					R2_LINE_PRICES[ 0 ]
				);
				await allocateRefundToLine(
					page,
					secondLine.id,
					R2_LINE_PRICES[ 1 ]
				);
				await fillRefundInput( page, 'refund_reason', REFUND_REASON );

				// The aggregate the editor derived from those two lines, and
				// nothing else.
				await expect( page.locator( '#refund_amount' ) ).toHaveValue(
					R2_REFUND_TOTAL
				);

				const dispatched = await dispatchGatewayRefund(
					pilotRuntime,
					page,
					'refund-settlement-r2-partial-refund'
				);
				expect( dispatched.submitted.get( 'api_refund' ) ).toBe(
					'true'
				);
				expect( dispatched.submitted.get( 'refund_amount' ) ).toBe(
					R2_REFUND_TOTAL
				);
				// The admin script submits an entry for every line, so the
				// assertion is that the two intended lines carry their exact
				// amounts and every other line carries nothing.
				expect(
					JSON.parse(
						dispatched.submitted.get( 'line_item_totals' ) ?? '{}'
					),
					'the submitted allocation must name exactly the two intended order items'
				).toEqual( {
					[ firstLine.id ]: Number( R2_LINE_PRICES[ 0 ] ),
					[ secondLine.id ]: Number( R2_LINE_PRICES[ 1 ] ),
					[ untouchedLine.id ]: 0,
				} );
				// No quantity was refunded on any line: this is an amount-only
				// allocation, so nothing is restocked and the parent line
				// quantities are untouched.
				expect(
					JSON.parse(
						dispatched.submitted.get( 'line_item_qtys' ) ?? '{}'
					)
				).toEqual( {} );
				expect( refundRequests() ).toEqual( [
					'admin-ajax:woocommerce_refund_line_items',
				] );

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
				const wooRefund = await expectSingleJoinedRefund(
					adminApi,
					paid,
					{
						amountMinor: R2_REFUND_MINOR,
						currency: 'USD',
						amountText: R2_REFUND_TOTAL,
						fullyRefunded: false,
					},
					providerRefund
				);

				// Per-line allocation, across exactly those two order items.
				expect(
					Object.fromEntries(
						wooRefund.lines.map( ( line ) => [
							String( line.orderItemId ),
							line.total,
						] )
					),
					'the stored refund must allocate across exactly the two intended order items'
				).toEqual( {
					[ firstLine.id ]: `-${ R2_LINE_PRICES[ 0 ] }`,
					[ secondLine.id ]: `-${ R2_LINE_PRICES[ 1 ] }`,
				} );

				// The order stays partially refunded, and the remaining
				// refundable amount is the untouched line.
				const orderAfter = await readOrderRecord( adminApi, orderId );
				expect( orderAfter.status ).toBe( 'processing' );
				expect( orderAfter.total ).toBe( R2_ORDER_TOTAL );

				expect(
					orderAfter.lineItems.map( ( line ) => line.quantity ),
					'an amount-only refund must leave every parent line quantity untouched'
				).toEqual(
					orderBefore.lineItems.map( ( line ) => line.quantity )
				);

				await page.reload();
				await openRefundEditor( page );
				await expect(
					refundSummaryValue( page, 'Amount already refunded' )
				).toContainText( R2_REFUND_TOTAL );
				await expect(
					refundSummaryValue( page, 'Total available to refund' ),
					'the remaining refundable amount must be exactly the untouched line'
				).toContainText( String( R2_REMAINING_REFUNDABLE ) );
			}
		);
	}
);

/**
 * `R3`'s settled refund, shared with `R3v`.
 *
 * The same shape as `R1`/`R1v`, and for the same reason: `R3v` is fixed as an
 * observation of `R3`'s refund on the native transaction view, so the two run
 * serially and `R3v` looks at what `R3` proved rather than paying for a second
 * foreign-currency charge and a second refund to look at. `R3v` deliberately
 * runs after `R3` has restored the currency configuration: the transaction view
 * renders the charge's own currency, so proving it there needs no multi-currency
 * state at all.
 */
let r3Settled: SettledRefund | undefined;

function requireR3Settled(): SettledRefund {
	if ( ! r3Settled ) {
		fail(
			'R3v observes R3’s refund, and R3 recorded none; there is nothing to view.'
		);
	}
	return r3Settled;
}

test.describe.serial( 'refund-settlement R3', () => {
	test(
		"A foreign-currency refund settles in the source charge's own currency as one succeeded provider refund that reconciles to its balance transaction",
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_R3_CURRENCY,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			test.setTimeout( 480_000 );
			pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
			pilotRuntime.requireApprovedProviderFixture( CAPABILITY_REFUND );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'multi-currency',
					recordEvent: 'refund-settlement-r3',
				},
				async () => {
					await assertMoneyFormatAssumptions( adminApi );
					await withForeignCurrency(
						pilotRuntime,
						'EUR',
						CAPABILITY_CURRENCY,
						async () => {
							const refundRequests = trackRefundRequests( page );
							const product =
								await pilotRuntime.createOwnedProduct(
									EUR_PRICE
								);

							// The shopper's active currency, set the way the storefront
							// itself sets it, and proved to have taken before anything
							// is bought. The selection persists in the shopper session,
							// so the card driver's own navigation keeps it.
							await page.goto( 'shop/?currency=EUR' );
							await expect(
								page
									.getByText( /€/ )
									.filter( { visible: true } )
									.first(),
								'the shopper currency must be EUR before the purchase; a USD storefront means the switch never took'
							).toBeVisible();

							const orderId = await completeCardCheckout(
								pilotRuntime,
								page,
								product,
								runId
							);
							const paid = await getPaymentEvidence(
								adminApi,
								orderId
							);
							expect(
								paid.currency,
								'R3 requires a EUR source charge; a USD order means the shopper currency never took'
							).toBe( 'EUR' );
							expect( paid.amountMinor ).toBe( EUR_MINOR );
							expect( paid.providerStatus ).toBe( 'succeeded' );
							expect( paid.chargeCaptured ).toBe( true );
							expect( paid.occurrenceCount ).toBe( 1 );

							const chargeBefore = await readChargeRefundState(
								adminApi,
								paid.chargeId
							);
							expect( chargeBefore.currency ).toBe( 'EUR' );
							expect(
								chargeBefore.balanceTransactionId,
								'R3 reconciles the refund to the source balance transaction, so the charge must carry one'
							).not.toBe( '' );

							const orderBefore = await readOrderRecord(
								adminApi,
								orderId
							);
							expect( orderBefore.lineItems ).toHaveLength( 1 );
							expect( orderBefore.lineItems[ 0 ].total ).toBe(
								EUR_PRICE
							);

							await pilotRuntime.logInAsAdmin( page );
							await page.waitForURL( '**/wp-admin/**' );
							await openOrderEditScreen( page, orderId );
							await openRefundEditor( page );
							await allocateRefundToLine(
								page,
								orderBefore.lineItems[ 0 ].id,
								EUR_PRICE
							);
							await fillRefundInput(
								page,
								'refund_reason',
								REFUND_REASON
							);
							await expect(
								page.locator( '#refund_amount' )
							).toHaveValue( EUR_PRICE );

							const dispatched = await dispatchGatewayRefund(
								pilotRuntime,
								page,
								'refund-settlement-r3-eur-refund'
							);
							expect(
								dispatched.submitted.get( 'api_refund' )
							).toBe( 'true' );
							expect( refundRequests() ).toEqual( [
								'admin-ajax:woocommerce_refund_line_items',
							] );

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
							const wooRefund = await expectSingleJoinedRefund(
								adminApi,
								paid,
								{
									amountMinor: EUR_MINOR,
									currency: 'EUR',
									amountText: EUR_PRICE,
									fullyRefunded: true,
								},
								providerRefund
							);

							// Currency stays EUR on both sides, and the settlement
							// facts reconcile to the source balance transaction.
							expect( providerRefund.currency ).toBe( 'EUR' );
							expect(
								providerRefund.balanceTransactionId,
								'the refund must carry its own settlement balance transaction'
							).not.toBe( '' );
							expect(
								providerRefund.balanceTransactionId,
								'the refund must settle against a balance transaction of its own, not reuse the charge’s'
							).not.toBe( chargeBefore.balanceTransactionId );

							const order = await readOrderRecord(
								adminApi,
								orderId
							);
							expect( order.status ).toBe( 'refunded' );
							expect( order.currency ).toBe( 'EUR' );
							expect( order.total ).toBe( EUR_PRICE );

							r3Settled = { paid, wooRefund, providerRefund };
						}
					);
				}
			);
		}
	);

	test(
		"The native transaction view presents the foreign-currency refund's original-currency amount and refunded status within the bounded propagation window",
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_R3_TRANSACTION,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime } ) => {
			test.setTimeout( 300_000 );
			pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			const settled = requireR3Settled();

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'refund-settlement-r3v' },
				async () => {
					// The record R3 left, re-read cold, then the merchant's own
					// view of it.
					await openSettledRefundTransactionView(
						pilotRuntime,
						page,
						adminApi,
						settled
					);

					// The refund's semantic facts in the source charge's own
					// currency, with bounded polling for propagation. The
					// currency code is asserted beside the amount because that
					// is what makes "original-currency" observable without
					// depending on symbol placement or locale formatting; the
					// match is copy-flexible, not exact.
					await waitForRefundOnTransactionView( page, [
						{
							label: 'the refund amount',
							pattern: EUR_AMOUNT_TEXT,
						},
						{
							label: 'the original charge currency',
							pattern: EUR_CURRENCY_TEXT,
						},
						{
							label: 'the refunded status',
							pattern: REFUNDED_STATUS_TEXT,
						},
					] );

					await expect(
						visibleTransactionFact( page, EUR_AMOUNT_TEXT )
					).toBeVisible();
					await expect(
						visibleTransactionFact( page, EUR_CURRENCY_TEXT )
					).toBeVisible();
					await expect(
						visibleTransactionFact( page, REFUNDED_STATUS_TEXT )
					).toBeVisible();

					// The view is of the exact EUR refund R3 settled, not of
					// some other money: the provider record still says EUR for
					// the exact refund ID this page was opened for.
					expect( settled.providerRefund.currency ).toBe( 'EUR' );
					expect( settled.providerRefund.amountMinor ).toBe(
						EUR_MINOR
					);
					expect( settled.wooRefund.providerRefundId ).toBe(
						settled.providerRefund.id
					);

					await expectRefundUnchangedAfterViewing(
						adminApi,
						settled
					);
				}
			);
		}
	);
} );

interface RedirectRefundCase {
	method: RedirectMethod;
	recordEvent: string;
	settleBudgetMs: number;
	settleIntervalMs: number;
	/** Whether the `RP` same-key replay applies to this case. */
	replay: boolean;
}

interface RedirectRefundFixtures {
	adminApi: APIRequestContext;
	page: Page;
	pilotRuntime: ProviderWriteSession;
	runId: string;
}

/**
 * Refund one freshly created redirect-method source charge in full and prove
 * the settled graph, then — for the one case the claim binds it to — issue the
 * deliberate same-key replay.
 */
async function driveRedirectRefund(
	{ adminApi, page, pilotRuntime, runId }: RedirectRefundFixtures,
	redirectCase: RedirectRefundCase
): Promise< SettledRefund > {
	const { method } = redirectCase;
	await assertMoneyFormatAssumptions( adminApi );
	const refundRequests = trackRefundRequests( page );
	const product = await pilotRuntime.createOwnedProduct( method.price );
	pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
	const paid = await withClassicCheckoutPage(
		pilotRuntime,
		runId,
		( scope ) =>
			completeRedirectCheckout(
				pilotRuntime,
				page,
				product,
				runId,
				method,
				method.currency,
				scope.classicCheckout,
				`refund-settlement-${ method.id }-checkout`
			)
	);

	const orderBefore = await readOrderRecord( adminApi, paid.orderId );
	expect(
		orderBefore.lineItems,
		`${ method.id } refunds one single-line order in full`
	).toHaveLength( 1 );
	expect( orderBefore.lineItems[ 0 ].total ).toBe( method.price );

	await pilotRuntime.logInAsAdmin( page );
	await page.waitForURL( '**/wp-admin/**' );
	await openOrderEditScreen( page, paid.orderId );
	await openRefundEditor( page );
	await allocateRefundToLine(
		page,
		orderBefore.lineItems[ 0 ].id,
		method.price
	);
	await fillRefundInput( page, 'refund_reason', REFUND_REASON );
	await expect( page.locator( '#refund_amount' ) ).toHaveValue(
		method.price
	);

	const dispatched = await dispatchGatewayRefund(
		pilotRuntime,
		page,
		`${ redirectCase.recordEvent }-refund`
	);
	// `WC_AJAX::refund_line_items()` compares `api_refund` against the string
	// 'true'; anything else silently downgrades to a local-only refund.
	expect( dispatched.submitted.get( 'api_refund' ) ).toBe( 'true' );
	expect( dispatched.submitted.get( 'refund_amount' ) ).toBe( method.price );
	expect( dispatched.submitted.get( 'refund_reason' ) ).toBe( REFUND_REASON );
	expect( refundRequests() ).toEqual( [
		'admin-ajax:woocommerce_refund_line_items',
	] );

	const providerRefund = await waitForSettledRefund(
		adminApi,
		paid.chargeId,
		redirectCase.settleBudgetMs,
		redirectCase.settleIntervalMs
	);
	await waitForWooRefundStatus(
		adminApi,
		paid.orderId,
		redirectCase.settleBudgetMs,
		redirectCase.settleIntervalMs
	);
	const wooRefund = await expectSingleJoinedRefund(
		adminApi,
		paid,
		{
			amountMinor: method.amountMinor,
			currency: method.currency,
			amountText: method.price,
			fullyRefunded: true,
		},
		providerRefund
	);

	const order = await readOrderRecord( adminApi, paid.orderId );
	expect( order.status ).toBe( 'refunded' );
	expect( order.currency ).toBe( method.currency );

	if ( ! redirectCase.replay ) {
		return { paid, wooRefund, providerRefund };
	}

	// `RP`. The first refund already has two stable succeeded reads; this is
	// the second and last refund POST of the case, byte-identical to the first
	// and carrying the same derived idempotency key. It is an intentional
	// financial idempotency probe, never a Playwright, assertion, helper or
	// transport retry.
	const replay = await replayRefundUnderSameKey( pilotRuntime, {
		baseURL: pilotRuntime.baseURL,
		orderId: paid.orderId,
		refundId: wooRefund.id,
		chargeId: paid.chargeId,
		providerRefundId: providerRefund.id,
		amountMinor: method.amountMinor,
		currency: method.currency,
		reason: REFUND_REASON,
		runId,
	} );

	expect(
		replay.attempts,
		'the replay must reach the provider exactly once, with automatic retries disabled'
	).toBe( 1 );

	// What the replay is really for is that a second same-key refund request
	// cannot move money a second time. There are two ways the provider can
	// deliver that, and this run established which one it is here — see the
	// `refund-settlement` correction in FIDELITY-CLAIMS.md.
	if ( replay.outcome === 'replayed' ) {
		expect(
			replay.refundId,
			'a replayed request must return the same provider refund, not a new one'
		).toBe( providerRefund.id );
		expect( replay.status ).toBe( 'succeeded' );
		expect( replay.amount ).toBe( method.amountMinor );
		expect( replay.currency ).toBe( method.currency );
		expect( replay.charge ).toBe( paid.chargeId );
	} else {
		// Refused, which is what this platform does: it reads the idempotency
		// key from a request *parameter*, and both native and the client send
		// it as an `Idempotency-Key` header, so the key never reaches Stripe
		// and the request is evaluated fresh. The over-refund guard is then
		// the thing standing between a replay and a duplicate refund. A
		// refusal for any other reason is not this contract and fails.
		expect(
			replay.refusal,
			'a refused replay must be refused as an over-refund of this exact charge'
		).toContain( 'has already been refunded' );
		expect( replay.refusal ).toContain( paid.chargeId );
		expect(
			replay.refundId,
			'a refused replay must not report a provider refund of its own'
		).toBe( '' );
	}

	// Post-replay convergence, then one final provider list read and one final
	// Woo order read proving both counts stay at one and the refunded total is
	// unchanged.
	const settledAgain = await waitForSettledRefund(
		adminApi,
		paid.chargeId,
		REPLAY_BUDGET_MS,
		REPLAY_INTERVAL_MS
	);
	expect( settledAgain.id ).toBe( providerRefund.id );

	const finalCharge = await readChargeRefundState( adminApi, paid.chargeId );
	expect(
		finalCharge.refunds,
		'the replay must leave exactly one provider refund on the charge'
	).toHaveLength( 1 );
	expect( finalCharge.refunds[ 0 ].id ).toBe( providerRefund.id );
	expect( finalCharge.amountRefundedMinor ).toBe( method.amountMinor );

	const finalWooRefunds = await readWooRefundRecords(
		adminApi,
		paid.orderId
	);
	expect(
		finalWooRefunds,
		'the replay must leave exactly one WooCommerce refund'
	).toHaveLength( 1 );
	expect( finalWooRefunds[ 0 ].id ).toBe( wooRefund.id );
	expect( finalWooRefunds[ 0 ].providerRefundId ).toBe( providerRefund.id );
	expect( finalWooRefunds[ 0 ].amount ).toBe( method.price );

	const finalOrder = await readOrderRecord( adminApi, paid.orderId );
	expect( finalOrder.refundIds ).toEqual( [ wooRefund.id ] );
	expect( finalOrder.status ).toBe( 'refunded' );

	return { paid, wooRefund, providerRefund };
}

/**
 * Run one redirect-method refund case inside its own owned provider interval,
 * with the method — and, where the case is denominated in a foreign currency,
 * the currency — snapshotted before and byte-restored afterwards.
 */
async function runRedirectRefundCase(
	fixtures: RedirectRefundFixtures,
	redirectCase: RedirectRefundCase
): Promise< SettledRefund > {
	const { method } = redirectCase;
	const { pilotRuntime } = fixtures;

	pilotRuntime.requireApprovedProviderFixture( CAPABILITY_FAMILY );
	pilotRuntime.requireApprovedProviderFixture( CAPABILITY_REFUND );
	pilotRuntime.requireApprovedProviderFixture( CAPABILITY_METHOD );
	if ( redirectCase.replay ) {
		pilotRuntime.requireApprovedProviderFixture( CAPABILITY_REPLAY );
	}
	await pilotRuntime.assertCurrentRuntimeReady( 'native' );

	return pilotRuntime.withProviderWriteLocks(
		{
			featureSetting: `payment-method-${ method.id }`,
			recordEvent: redirectCase.recordEvent,
		},
		async () => {
			const drive = () => driveRedirectRefund( fixtures, redirectCase );

			if ( method.currency === 'USD' ) {
				return withEnabledPaymentMethod(
					pilotRuntime,
					method,
					CAPABILITY_METHOD,
					drive
				);
			}

			return withForeignCurrency(
				pilotRuntime,
				method.currency,
				CAPABILITY_CURRENCY,
				() =>
					withEnabledPaymentMethod(
						pilotRuntime,
						method,
						CAPABILITY_METHOD,
						drive
					)
			);
		}
	);
}

test(
	'A full refund of an Alipay charge settles as one succeeded provider refund stored on the exact WooCommerce refund',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_R4 },
		],
		tag: FAMILY_TAGS,
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		test.setTimeout( 480_000 );
		const settled = await runRedirectRefundCase(
			{ adminApi, page, pilotRuntime, runId },
			{
				method: ALIPAY,
				recordEvent: 'refund-settlement-r4',
				settleBudgetMs: SETTLE_BUDGET_MS,
				settleIntervalMs: SETTLE_INTERVAL_MS,
				replay: false,
			}
		);

		// The case's own contract sentence, restated where the ledger row
		// is annotated: one succeeded provider refund for the exact amount
		// and currency, stored on the exact WooCommerce refund.
		expect( settled.providerRefund.status ).toBe( 'succeeded' );
		expect( settled.providerRefund.amountMinor ).toBe( ALIPAY.amountMinor );
		expect( settled.providerRefund.currency ).toBe( ALIPAY.currency );
		expect(
			settled.wooRefund.providerRefundId,
			'the Alipay refund must be stored on the exact WooCommerce refund'
		).toBe( settled.providerRefund.id );
	}
);

test(
	'A full refund of an Affirm charge settles as one succeeded provider refund stored on the exact WooCommerce refund',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_R5 },
		],
		tag: FAMILY_TAGS,
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		test.setTimeout( 480_000 );
		const settled = await runRedirectRefundCase(
			{ adminApi, page, pilotRuntime, runId },
			{
				method: AFFIRM,
				recordEvent: 'refund-settlement-r5',
				settleBudgetMs: SETTLE_BUDGET_MS,
				settleIntervalMs: SETTLE_INTERVAL_MS,
				replay: false,
			}
		);

		// The case's own contract sentence, restated where the ledger row
		// is annotated: one succeeded provider refund for the exact amount
		// and currency, stored on the exact WooCommerce refund.
		expect( settled.providerRefund.status ).toBe( 'succeeded' );
		expect( settled.providerRefund.amountMinor ).toBe( AFFIRM.amountMinor );
		expect( settled.providerRefund.currency ).toBe( AFFIRM.currency );
		expect(
			settled.wooRefund.providerRefundId,
			'the Affirm refund must be stored on the exact WooCommerce refund'
		).toBe( settled.providerRefund.id );
	}
);

test(
	'A full refund of a Bancontact charge settles in EUR as one succeeded provider refund stored on the exact WooCommerce refund',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_R6 },
		],
		tag: FAMILY_TAGS,
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		test.setTimeout( 540_000 );
		const settled = await runRedirectRefundCase(
			{ adminApi, page, pilotRuntime, runId },
			{
				method: BANCONTACT,
				recordEvent: 'refund-settlement-r6',
				settleBudgetMs: SETTLE_BUDGET_MS,
				settleIntervalMs: SETTLE_INTERVAL_MS,
				replay: false,
			}
		);

		// The case's own contract sentence, restated where the ledger row
		// is annotated: one succeeded provider refund for the exact amount
		// and currency, stored on the exact WooCommerce refund.
		expect( settled.providerRefund.status ).toBe( 'succeeded' );
		expect( settled.providerRefund.amountMinor ).toBe(
			BANCONTACT.amountMinor
		);
		expect( settled.providerRefund.currency ).toBe( BANCONTACT.currency );
		expect(
			settled.wooRefund.providerRefundId,
			'the Bancontact refund must be stored on the exact WooCommerce refund'
		).toBe( settled.providerRefund.id );
	}
);

test(
	'A full refund of a Cash App Afterpay charge settles as one succeeded provider refund, and a byte-identical same-key replay creates no second refund on either side',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_R7 },
		],
		tag: FAMILY_TAGS,
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		// The extended budget the claim fixes for Cash App Afterpay, plus the
		// checkout, the replay, and the post-replay convergence.
		test.setTimeout( 1_200_000 );
		const settled = await runRedirectRefundCase(
			{ adminApi, page, pilotRuntime, runId },
			{
				method: AFTERPAY,
				recordEvent: 'refund-settlement-r7',
				settleBudgetMs: AFTERPAY_SETTLE_BUDGET_MS,
				settleIntervalMs: AFTERPAY_SETTLE_INTERVAL_MS,
				replay: true,
			}
		);

		// The case's own contract sentence, restated where the ledger row
		// is annotated: one succeeded provider refund for the exact amount
		// and currency, stored on the exact WooCommerce refund.
		expect( settled.providerRefund.status ).toBe( 'succeeded' );
		expect( settled.providerRefund.amountMinor ).toBe(
			AFTERPAY.amountMinor
		);
		expect( settled.providerRefund.currency ).toBe( AFTERPAY.currency );
		expect(
			settled.wooRefund.providerRefundId,
			'the Cash App Afterpay refund must be stored on the exact WooCommerce refund'
		).toBe( settled.providerRefund.id );
	}
);
