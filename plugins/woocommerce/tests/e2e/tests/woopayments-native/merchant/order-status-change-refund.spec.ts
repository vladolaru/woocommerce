import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

/**
 * The four order-edit status-change contracts, against the native confirmation
 * flow.
 *
 * Core's own "Refunded" status records a refund locally and never asks the
 * gateway for anything, so before this feature a WooPayments order could read
 * as refunded while the customer's money stayed put. Native now intercepts the
 * status dropdown: "Refunded" confirms and issues a real provider refund, and
 * "Cancelled" on a still-refundable order asks whether a refund was meant.
 *
 * The ledger rows these tests close all record the same weakness in the client
 * suite's originals — they asserted the rendered order status and nothing about
 * the provider, so a refund that escaped (or failed to happen) was invisible to
 * them. Every assertion about money here is therefore read from the provider's
 * own charge object, not from the screen.
 *
 * Each test owns one paid order end to end. The client suite threaded a single
 * order through three cases in sequence, which the ledger records as making
 * every case order-dependent and any leakage cumulative; independent orders
 * cost provider objects on a test account and buy back a suite where one
 * failure strands nothing else.
 */

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;

const DROPDOWN_PAID_STATUS = 'wc-processing';
const DROPDOWN_CANCELLED = 'wc-cancelled';
const DROPDOWN_REFUNDED = 'wc-refunded';

const REFUND_DIALOG_TITLE = 'Refund order in full';
const CANCEL_DIALOG_TITLE = 'Cancel order';

// The provider settles a card refund immediately, but "immediately" is still
// asynchronous; this bounds the wait for `succeeded` instead of assuming it.
const REFUND_SETTLEMENT_TIMEOUT_MS = 30_000;

/** The journey capability every one of these tests needs. */
const JOURNEY_CAPABILITY = 'order-status-change';
/** The extra capability for the one test that actually returns money. */
const REFUND_CAPABILITY = 'order-status-change-refund';

/**
 * The config the controller hands the browser, as the browser sees it. Its
 * shape is the contract between `WooPaymentsOrderStatusChangeProjectionService`
 * and the wp-admin script, and it is also the projection service's own answer
 * for "what is still refundable on this order" — which is exactly the
 * refundable balance row :116 says must not move.
 */
interface OrderStatusChangeConfig {
	order_status: string;
	can_refund: boolean;
	refund_amount: number;
	formatted_refund_amount: string;
	refunded_amount: number;
}

interface ProviderRefund {
	id: string;
	status: string;
	amountMinor: number;
}

/**
 * The refund-relevant half of the provider charge, read straight from the
 * platform charge route rather than from timeline events. The timeline is built
 * from provider events and can lag; `amount_refunded` and the expanded refund
 * collection are the charge's own current state, so "no refund happened" cannot
 * pass merely because an event has not arrived yet.
 */
interface ChargeRefundState {
	chargeId: string;
	captured: boolean;
	status: string;
	refunded: boolean;
	amountRefundedMinor: number;
	refunds: ProviderRefund[];
}

interface OrderRecord {
	status: string;
	total: string;
	refundIds: number[];
	refundStatusMeta: string;
}

interface WooRefundRecord {
	id: number;
	amount: string;
	providerRefundId: string;
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
		throw new Error(
			`Order status change evidence requires a ${ label }.`
		);
	}
	return value;
}

function requiredNumber( value: unknown, label: string ): number {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		throw new Error(
			`Order status change evidence requires a ${ label }.`
		);
	}
	return value;
}

function requiredBoolean( value: unknown, label: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		throw new Error(
			`Order status change evidence requires a ${ label }.`
		);
	}
	return value;
}

/**
 * Read the projected config out of the loaded order screen.
 *
 * Its presence is itself part of the contract: the controller only localizes it
 * on the order-edit screen for a native WooPayments order, so an absent global
 * means the confirmation flow is not wired at all and every DOM assertion below
 * would be vacuous.
 */
async function readStatusChangeConfig(
	page: Page
): Promise< OrderStatusChangeConfig > {
	const raw = await page.evaluate(
		() =>
			(
				window as unknown as {
					woocommerceWooPaymentsOrderStatusChange?: unknown;
				}
			 ).woocommerceWooPaymentsOrderStatusChange
	);
	if ( typeof raw !== 'object' || raw === null || Array.isArray( raw ) ) {
		throw new Error(
			'The order-edit screen did not expose the WooPayments status-change config.'
		);
	}
	const record = raw as Record< string, unknown >;

	return {
		order_status: requiredString( record.order_status, 'dropdown status' ),
		can_refund: requiredBoolean( record.can_refund, 'refundability flag' ),
		refund_amount: requiredNumber( record.refund_amount, 'refund amount' ),
		formatted_refund_amount: requiredString(
			record.formatted_refund_amount,
			'formatted refund amount'
		),
		refunded_amount: requiredNumber(
			record.refunded_amount,
			'refunded amount'
		),
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
		throw new Error(
			`Provider charge identity mismatch: expected ${ chargeId }, received ${ String(
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
		throw new Error(
			`Provider charge ${ chargeId } carried no expanded refund collection.`
		);
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
		refunds: refundsValue.data.map( ( item: unknown, index: number ) => {
			if (
				typeof item !== 'object' ||
				item === null ||
				Array.isArray( item )
			) {
				throw new Error(
					`Provider refund occurrence ${
						index + 1
					} is not an object.`
				);
			}
			const refund = item as Record< string, unknown >;
			return {
				id: requiredString( refund.id, 'refund ID' ),
				status: requiredString( refund.status, 'refund status' ),
				amountMinor: requiredNumber( refund.amount, 'refund amount' ),
			};
		} ),
	};
}

async function readOrderRecord(
	restApi: APIRequestContext,
	orderId: number
): Promise< OrderRecord > {
	const order = await readJson< {
		status?: unknown;
		total?: unknown;
		refunds?: unknown;
		meta_data?: unknown;
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

	return {
		status: requiredString( order.status, 'order status' ),
		total: requiredString( order.total, 'order total' ),
		refundIds: refunds.map( ( refund, index ) =>
			requiredNumber(
				( refund as { id?: unknown } ).id,
				`refund ${ index + 1 } ID`
			)
		),
		refundStatusMeta:
			typeof refundStatusMeta === 'string' ? refundStatusMeta : '',
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
			id: requiredNumber(
				refund.id,
				`WooCommerce refund ${ index + 1 } ID`
			),
			amount: requiredString(
				refund.amount,
				`WooCommerce refund ${ index + 1 } amount`
			),
			providerRefundId:
				typeof providerRefundId === 'string' ? providerRefundId : '',
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
 * Record every browser-side attempt to move money on this order.
 *
 * The confirmation modal refunds by posting core's `woocommerce_refund_line_items`
 * admin-AJAX action, and the transaction-details surface refunds through the
 * native `payments/refund` route. Watching both turns "no refund was issued"
 * from an inference about end state into an observation of what the merchant's
 * browser actually asked for — and, on the confirming case, proves the merchant
 * asked exactly once.
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
 * Open the classic order edit screen and return the URL that worked.
 *
 * Stores keeping orders in the dedicated tables edit them under `wc-orders`;
 * stores still on the posts table edit them through `post.php`. The status
 * dropdown is the screen's identity either way.
 */
async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< string > {
	const hposUrl = `wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`;
	await page.goto( hposUrl );
	let url = hposUrl;
	if ( ( await page.locator( '#order_status' ).count() ) === 0 ) {
		url = `wp-admin/post.php?post=${ orderId }&action=edit`;
		await page.goto( url );
	}
	await expect( page.locator( '#order_status' ) ).toBeVisible();

	return url;
}

function orderStatusField( page: Page ): Locator {
	// The status control is a `wc-enhanced-select`; selectWoo keeps the real
	// `<select>` in the accessibility tree and it remains the element the form
	// submits and the script listens to, so it is both the honest target and
	// the one the feature itself addresses.
	return page.locator( '#order_status' );
}

/**
 * What the merchant actually reads as the order's status.
 *
 * `resetOrderStatus()` restores the underlying `<select>` and dispatches a
 * native `change` so selectWoo repaints. That repaint is the merchant-visible
 * half of "the dropdown went back": if it ever stopped happening, the enhanced
 * select would still say Refunded while the field said Processing, and the
 * merchant could believe a refund is pending that was never requested.
 * Addressed structurally because selectWoo's rendered label carries no
 * accessible name of its own — it *is* the accessible name of the widget.
 */
function renderedOrderStatus( page: Page ): Locator {
	return page.locator( '#select2-order_status-container' );
}

/**
 * Assert the field and the widget the merchant reads agree on the status.
 */
async function expectOrderStatusShown(
	page: Page,
	value: string,
	label: string
): Promise< void > {
	await expect( orderStatusField( page ) ).toHaveValue( value );
	await expect( renderedOrderStatus( page ) ).toHaveText( label );
}

/**
 * Drive one paid WooPayments order and hand back its proven payment identity.
 *
 * Reuses the suite's card checkout driver rather than re-implementing one, so
 * this journey differs from a plain card purchase only in what happens after
 * the order exists.
 */
async function createPaidWooPaymentsOrder(
	session: ProviderWriteSession,
	page: Page,
	runId: string,
	adminApi: APIRequestContext
): Promise< PaymentEvidence > {
	const product = await session.createOwnedProduct( PRICE );
	const orderId = await completeCardCheckout( session, page, product, runId );
	const paid = await getPaymentEvidence( adminApi, orderId );

	// The fixture every assertion below is derived from. A store that priced,
	// captured or settled this differently must fail here rather than silently
	// move the contract.
	expect( paid.amountMinor ).toBe( AMOUNT_MINOR );
	expect( paid.currency ).toBe( 'USD' );
	expect( paid.orderStatus ).toBe( 'processing' );
	expect( paid.providerStatus ).toBe( 'succeeded' );
	expect( paid.chargeStatus ).toBe( 'succeeded' );
	expect( paid.chargeCaptured ).toBe( true );
	expect( paid.occurrenceCount ).toBe( 1 );
	expect( paid.captureOccurrenceCount ).toBe( 1 );

	return paid;
}

/**
 * Open the merchant's order screen for a paid order and prove the confirmation
 * flow is armed on it, returning the projected config.
 */
async function openArmedOrderScreen(
	session: ProviderWriteSession,
	page: Page,
	orderId: number
): Promise< { editUrl: string; config: OrderStatusChangeConfig } > {
	await session.logInAsAdmin( page );
	await page.waitForURL( '**/wp-admin/**' );
	const editUrl = await openOrderEditScreen( page, orderId );
	const config = await readStatusChangeConfig( page );

	expect( config.order_status ).toBe( DROPDOWN_PAID_STATUS );
	// The modal is only allowed to promise a real refund when the gateway can
	// make one, which for native means the order carries a provider charge.
	expect( config.can_refund ).toBe( true );
	expect( config.refunded_amount ).toBe( 0 );
	expect( config.refund_amount ).toBeCloseTo( Number( PRICE ), 2 );
	await expectOrderStatusShown( page, DROPDOWN_PAID_STATUS, 'Processing' );

	return { editUrl, config };
}

/**
 * The provider-side no-op oracle: the charge is exactly as the successful
 * payment left it. Shared by all three non-refunding contracts, which is where
 * the ledger says their originals were blind.
 */
async function expectUntouchedCapturedCharge(
	adminApi: APIRequestContext,
	paid: PaymentEvidence,
	reason: string
): Promise< void > {
	const charge = await readChargeRefundState( adminApi, paid.chargeId );

	expect(
		charge.captured,
		`${ reason }: the charge must stay captured`
	).toBe( true );
	expect( charge.status, `${ reason }: the charge must stay succeeded` ).toBe(
		'succeeded'
	);
	expect(
		charge.refunded,
		`${ reason }: the charge must stay unrefunded`
	).toBe( false );
	expect(
		charge.amountRefundedMinor,
		`${ reason }: no amount may have been returned`
	).toBe( 0 );
	expect(
		charge.refunds,
		`${ reason }: no provider refund may exist`
	).toEqual( [] );

	// The intent, too: a captured charge cannot be voided, but its intent can
	// be canceled, and that is the shape a stray "cancel at the provider" would
	// take. Reading it through the shared payment evidence also re-proves the
	// intent/charge/payment-method relationship and that no second capture ran.
	const current = await getPaymentEvidence( adminApi, paid.orderId );
	expect( current.intentId ).toBe( paid.intentId );
	expect( current.chargeId ).toBe( paid.chargeId );
	expect(
		current.providerStatus,
		`${ reason }: the intent must stay succeeded, not canceled`
	).toBe( 'succeeded' );
	expect( current.chargeCaptured ).toBe( true );
	expect( current.occurrenceCount ).toBe( 1 );
	expect( current.captureOccurrenceCount ).toBe( 1 );
}

test.describe( 'WooPayments native order status change confirmation', () => {
	test(
		'Backing out of a full-refund status change preserves order status, refundable balance, and provider charge without issuing a refund',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description:
						'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-status-change.spec.ts:116::Order › Status Change › Change Status of order to Refunded › Show Refund Confirmation modal, do not change status if Cancel clicked',
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			pilotRuntime.requireApprovedProviderFixture( JOURNEY_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'order-status-refund-dismissed' },
				async () => {
					const refundRequests = trackRefundRequests( page );
					const paid = await createPaidWooPaymentsOrder(
						pilotRuntime,
						page,
						runId,
						adminApi
					);
					const { editUrl, config } = await openArmedOrderScreen(
						pilotRuntime,
						page,
						paid.orderId
					);

					const dialog = page.getByRole( 'dialog', {
						name: REFUND_DIALOG_TITLE,
					} );

					// Escape is a way out of the modal, so it is a way of
					// backing out of the status change and must restore the
					// dropdown exactly like the button does.
					await orderStatusField( page ).selectOption(
						DROPDOWN_REFUNDED
					);
					await expect( dialog ).toBeVisible();
					// The merchant is looking at the pending selection while the
					// confirmation asks — which is what makes the restoration
					// assertions below mean something rather than passing on a
					// widget that never moved.
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Refunded'
					);
					await page.keyboard.press( 'Escape' );
					await expect( dialog ).toBeHidden();
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);

					await orderStatusField( page ).selectOption(
						DROPDOWN_REFUNDED
					);
					await expect( dialog ).toBeVisible();
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Refunded'
					);

					// The amount offered is the order's own remaining
					// refundable amount, spelled in the order's currency.
					await expect(
						dialog.getByRole( 'button', {
							name: `Refund ${ config.formatted_refund_amount }`,
							exact: true,
						} )
					).toBeVisible();
					expect( config.formatted_refund_amount ).toContain( PRICE );

					await dialog
						.getByRole( 'button', { name: 'Cancel', exact: true } )
						.click();
					await expect( dialog ).toBeHidden();
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);

					// Nothing was even asked of the money surfaces. This is the
					// direct answer to the recorded residual risk that the
					// original could pass while a refund had been sent.
					expect(
						refundRequests(),
						'backing out must dispatch no refund request at all'
					).toEqual( [] );

					// And the server agrees, on a freshly rendered screen.
					await page.goto( editUrl );
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);
					await expect(
						page.locator( '#order_refunds tr.refund' )
					).toHaveCount( 0 );

					// The refundable balance, as the projection service itself
					// reports it after the dismissal.
					const reprojected = await readStatusChangeConfig( page );
					expect( reprojected.order_status ).toBe(
						DROPDOWN_PAID_STATUS
					);
					expect( reprojected.can_refund ).toBe( true );
					expect( reprojected.refund_amount ).toBeCloseTo(
						config.refund_amount,
						2
					);
					expect( reprojected.refunded_amount ).toBe( 0 );

					const order = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect( order.status ).toBe( 'processing' );
					expect( order.refundIds ).toEqual( [] );
					expect(
						await readWooRefundRecords( adminApi, paid.orderId )
					).toEqual( [] );

					await expectUntouchedCapturedCharge(
						adminApi,
						paid,
						'a dismissed refund confirmation'
					);
				}
			);
		}
	);

	test(
		'A merchant can back out of cancelling a paid order, and neither persisted order status nor payment state changes',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description:
						'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-status-change.spec.ts:53::Order › Status Change › Change Status of order to Cancelled › Show Cancel Confirmation modal, do not change status if Do Nothing selected',
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			pilotRuntime.requireApprovedProviderFixture( JOURNEY_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'order-status-cancel-dismissed' },
				async () => {
					const refundRequests = trackRefundRequests( page );
					const paid = await createPaidWooPaymentsOrder(
						pilotRuntime,
						page,
						runId,
						adminApi
					);
					const { editUrl } = await openArmedOrderScreen(
						pilotRuntime,
						page,
						paid.orderId
					);
					const notesBefore = await readOrderNotes(
						adminApi,
						paid.orderId
					);

					const dialog = page.getByRole( 'dialog', {
						name: CANCEL_DIALOG_TITLE,
					} );

					await orderStatusField( page ).selectOption(
						DROPDOWN_CANCELLED
					);
					await expect( dialog ).toBeVisible();
					// The merchant is looking at the pending selection while the
					// confirmation asks — which is what makes the restoration
					// assertions below mean something rather than passing on a
					// widget that never moved.
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Cancelled'
					);
					await page.keyboard.press( 'Escape' );
					await expect( dialog ).toBeHidden();
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);

					await orderStatusField( page ).selectOption(
						DROPDOWN_CANCELLED
					);
					await expect( dialog ).toBeVisible();
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Cancelled'
					);

					// The confirmation asks the question this row is about:
					// did the merchant mean to refund instead?
					await expect(
						dialog.getByText(
							/Are you trying to issue a refund for this order\?/
						)
					).toBeVisible();

					await dialog
						.getByRole( 'button', {
							name: 'Do nothing',
							exact: true,
						} )
						.click();
					await expect( dialog ).toBeHidden();
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);

					expect(
						refundRequests(),
						'declining the cancellation must dispatch no refund request'
					).toEqual( [] );

					// Persisted state, on a freshly rendered screen and in the
					// record behind it.
					await page.goto( editUrl );
					await expectOrderStatusShown(
						page,
						DROPDOWN_PAID_STATUS,
						'Processing'
					);

					const order = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect( order.status ).toBe( 'processing' );
					expect( order.total ).toBe( PRICE );
					expect( order.refundIds ).toEqual( [] );
					expect(
						await readWooRefundRecords( adminApi, paid.orderId )
					).toEqual( [] );
					// No status transition was recorded at all: the order never
					// left Processing, not even briefly.
					expect(
						await readOrderNotes( adminApi, paid.orderId )
					).toEqual( notesBefore );

					await expectUntouchedCapturedCharge(
						adminApi,
						paid,
						'a declined cancellation'
					);
				}
			);
		}
	);

	test(
		'Confirming cancellation of a paid order changes the order status to cancelled exactly once and takes no provider action, leaving the captured charge unrefunded and not voided',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description:
						'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-status-change.spec.ts:80::Order › Status Change › Change Status of order to Cancelled › When Order Status changed to Cancel, show Cancel Confirmation modal, change status to Cancel if confirmed',
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			pilotRuntime.requireApprovedProviderFixture( JOURNEY_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'order-status-cancel-confirmed' },
				async () => {
					const refundRequests = trackRefundRequests( page );
					const paid = await createPaidWooPaymentsOrder(
						pilotRuntime,
						page,
						runId,
						adminApi
					);
					await openArmedOrderScreen(
						pilotRuntime,
						page,
						paid.orderId
					);
					const notesBefore = await readOrderNotes(
						adminApi,
						paid.orderId
					);

					const dialog = page.getByRole( 'dialog', {
						name: CANCEL_DIALOG_TITLE,
					} );
					await orderStatusField( page ).selectOption(
						DROPDOWN_CANCELLED
					);
					await expect( dialog ).toBeVisible();
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Cancelled'
					);

					// Confirming submits the order form, so the assertion that
					// follows is against a server-rendered document: the status
					// transition note only exists once the save has happened.
					await dialog
						.getByRole( 'button', {
							name: 'Cancel order',
							exact: true,
						} )
						.click();
					await expect(
						page
							.locator( 'ul.order_notes' )
							.getByText(
								'Order status changed from Processing to Cancelled.'
							)
					).toBeVisible();
					await expectOrderStatusShown(
						page,
						DROPDOWN_CANCELLED,
						'Cancelled'
					);

					const order = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect( order.status ).toBe( 'cancelled' );
					expect( order.total ).toBe( PRICE );

					// Exactly once: the save produced one status transition,
					// into Cancelled, and nothing walked the order through a
					// second transition behind it.
					const newNotes = (
						await readOrderNotes( adminApi, paid.orderId )
					).filter( ( note ) => ! notesBefore.includes( note ) );
					expect(
						newNotes.filter( ( note ) =>
							/^Order status changed from .+ to .+\.$/.test(
								note
							)
						),
						'confirming must record exactly one status transition'
					).toEqual( [
						'Order status changed from Processing to Cancelled.',
					] );

					// The documented payment-side effect, resolved from the
					// implementation rather than left open: native only cancels
					// at the provider when the order carries an open
					// authorization (`_intention_status` is `requires_capture`).
					// This order's charge is captured, so cancelling it must
					// take no provider action whatsoever — no refund, no void,
					// no second capture.
					expect(
						refundRequests(),
						'confirming a cancellation must dispatch no refund request'
					).toEqual( [] );
					expect( order.refundIds ).toEqual( [] );
					expect(
						await readWooRefundRecords( adminApi, paid.orderId )
					).toEqual( [] );
					expect(
						newNotes.filter( ( note ) => /refund/i.test( note ) ),
						'confirming a cancellation must add no refund note'
					).toEqual( [] );

					await expectUntouchedCapturedCharge(
						adminApi,
						paid,
						'a confirmed cancellation of a captured order'
					);
				}
			);
		}
	);

	test(
		'Confirming Refunded on a paid order issues exactly one full refund for the order total and persists the Refunded order state',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description:
						'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-status-change.spec.ts:141::Order › Status Change › Change Status of order to Refunded › Show Refund Confirmation modal, process Refund if confirmed',
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			pilotRuntime.requireApprovedProviderFixture( JOURNEY_CAPABILITY );
			pilotRuntime.requireApprovedProviderFixture( REFUND_CAPABILITY );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'order-status-refund-confirmed' },
				async () => {
					const refundRequests = trackRefundRequests( page );
					const paid = await createPaidWooPaymentsOrder(
						pilotRuntime,
						page,
						runId,
						adminApi
					);
					const { config } = await openArmedOrderScreen(
						pilotRuntime,
						page,
						paid.orderId
					);
					const notesBefore = await readOrderNotes(
						adminApi,
						paid.orderId
					);

					const dialog = page.getByRole( 'dialog', {
						name: REFUND_DIALOG_TITLE,
					} );
					await orderStatusField( page ).selectOption(
						DROPDOWN_REFUNDED
					);
					await expect( dialog ).toBeVisible();
					await expect( renderedOrderStatus( page ) ).toHaveText(
						'Refunded'
					);

					const submitted =
						await pilotRuntime.withProviderSubmissionJournal(
							'order-status-change-full-refund',
							async () => {
								const refundResponse = page.waitForResponse(
									( response ) =>
										response
											.url()
											.includes( 'admin-ajax.php' ) &&
										response.request().method() ===
											'POST' &&
										(
											response.request().postData() ?? ''
										).includes(
											'action=woocommerce_refund_line_items'
										)
								);
								await pilotRuntime.performWrite( () =>
									dialog
										.getByRole( 'button', {
											name: `Refund ${ config.formatted_refund_amount }`,
											exact: true,
										} )
										.click()
								);
								const response = await refundResponse;

								// The response body is deliberately not read.
								// A successful refund reloads the order screen
								// immediately, and the browser discards the
								// body of a request whose page is gone, so
								// reading it races the very success it is
								// meant to confirm. The outcome is proven
								// below from the provider's own refund record
								// and WooCommerce's stored refund instead,
								// which are authoritative where a rendered
								// response is not. The request itself is still
								// inspected, because what was sent is exactly
								// what decides whether money moved.
								expect(
									response.status(),
									'the refund request must be accepted by the server'
								).toBe( 200 );

								return new URLSearchParams(
									response.request().postData() ?? ''
								);
							}
						);

					// The one detail that decides whether money actually moved:
					// `WC_AJAX::refund_line_items()` compares `api_refund`
					// against the string 'true', and anything else silently
					// downgrades to a local-only refund — the exact failure this
					// feature exists to remove.
					expect( submitted.get( 'api_refund' ) ).toBe( 'true' );
					expect( submitted.get( 'refund_amount' ) ).toBe(
						String( config.refund_amount )
					);
					expect( submitted.get( 'order_id' ) ).toBe(
						String( paid.orderId )
					);

					// The confirmation reloads the screen, so the refund row is
					// the sync point as well as an assertion: it is rendered by
					// the server from the persisted refund.
					await expect(
						page.locator( '#order_refunds tr.refund' )
					).toHaveCount( 1 );
					await expectOrderStatusShown(
						page,
						DROPDOWN_REFUNDED,
						'Refunded'
					);

					// Exactly one, from the merchant's browser outwards.
					expect(
						refundRequests(),
						'the merchant must have asked for exactly one refund'
					).toEqual( [ 'admin-ajax:woocommerce_refund_line_items' ] );

					// Exactly one, in WooCommerce's own record. Core's
					// `wc_order_fully_refunded()` runs again when the status
					// lands on refunded and must find nothing left to refund;
					// a second record here is the regression this row guards.
					const wooRefunds = await readWooRefundRecords(
						adminApi,
						paid.orderId
					);
					expect(
						wooRefunds,
						'exactly one WooCommerce refund must exist'
					).toHaveLength( 1 );
					expect( wooRefunds[ 0 ].amount ).toBe( PRICE );

					// Exactly one, at the provider — and settled, not merely
					// requested. This is what the ledger says the rendered line
					// cost could never prove, so it is asserted before anything
					// WooCommerce recorded about itself: a refund that never
					// reached the provider must fail here, on the money, rather
					// than on a local bookkeeping field that happens to notice.
					await expect
						.poll(
							async () =>
								(
									await readChargeRefundState(
										adminApi,
										paid.chargeId
									)
								).refunds.map( ( refund ) => refund.status ),
							{
								message:
									'the single provider refund must reach succeeded',
								timeout: REFUND_SETTLEMENT_TIMEOUT_MS,
							}
						)
						.toEqual( [ 'succeeded' ] );

					const charge = await readChargeRefundState(
						adminApi,
						paid.chargeId
					);
					expect( charge.refunds ).toHaveLength( 1 );
					expect( charge.refunds[ 0 ].amountMinor ).toBe(
						AMOUNT_MINOR
					);
					expect( charge.refunded ).toBe( true );
					expect( charge.amountRefundedMinor ).toBe( AMOUNT_MINOR );
					// The charge was refunded, not reversed: it stays captured.
					expect( charge.captured ).toBe( true );

					// And the Refunded order state persists.
					const order = await readOrderRecord(
						adminApi,
						paid.orderId
					);
					expect( order.status ).toBe( 'refunded' );
					expect( order.refundIds ).toEqual( [ wooRefunds[ 0 ].id ] );
					expect( order.refundStatusMeta ).toBe( 'successful' );

					// The join between the two records. Without it, "one Woo
					// refund" and "one provider refund" could be about
					// different money.
					expect( wooRefunds[ 0 ].providerRefundId ).toBe(
						charge.refunds[ 0 ].id
					);

					// The merchant is told, once.
					const newNotes = (
						await readOrderNotes( adminApi, paid.orderId )
					).filter( ( note ) => ! notesBefore.includes( note ) );
					expect(
						newNotes.filter( ( note ) =>
							note.includes( charge.refunds[ 0 ].id )
						),
						'the refund must be journaled on the order exactly once'
					).toHaveLength( 1 );
				}
			);
		}
	);
} );
