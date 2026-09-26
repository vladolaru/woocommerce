import type { Locator, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import {
	expectSettledCardPayment,
	fillCardDetails,
	getCharge,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `refund-settlement` provider-fidelity family (T.4 Batch P2 rewrite),
 * as fixed in `FIDELITY-CLAIMS.md`.
 *
 * `R1`, the family's one retained browser smoke: the real round trip from a
 * card checkout to a merchant refund to a settled provider refund whose
 * identity WooCommerce stores. `R1v` and the partial/multi-line/foreign-
 * currency/redirect-method/replay cases moved below the browser in T.1 batch
 * 5a; see `data/t1-provider-family-audit.md` §3, §4 Batch 5.
 *
 * The refund itself goes through core's own `woocommerce_refund_line_items`
 * admin-AJAX action with `api_refund` as the *string* `'true'` -
 * `WC_AJAX::refund_line_items()` compares strictly, and a boolean silently
 * downgrades to a local-only refund, which is exactly the failure this
 * family exists to detect.
 */

const CONTRACT_R1 =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-full-refund.spec.ts:38::WooCommerce Payments - Full Refund › should process a full refund for an order';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:refund-settlement',
];

/** The merchant-supplied refund reason, the exact value native's own transaction-details refund modal offers. */
const REFUND_REASON = 'requested_by_customer';

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const SETTLE_INTERVAL_MS = 3_000;
const SETTLE_BUDGET_MS = 180_000;
const RECEIPT_TIMEOUT_MS = 60_000;

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

interface PaidOrder {
	productId: number;
	orderId: number;
	lineItemId: number;
	intentId: string;
	chargeId: string;
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	return String(
		entries.find( ( entry ) => entry.key === key )?.value ?? ''
	);
}

async function createRunProduct( restApi: ApiClient ): Promise< number > {
	return (
		(
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments refund fidelity ${ random() }`,
				type: 'simple',
				virtual: true,
				regular_price: PRICE,
				status: 'publish',
			} )
		).data as { id: number }
	 ).id;
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

async function readNewOrders(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< Array< { id: number } > > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number } >;
	return orders.filter( ( order ) => order.id > baselineOrderId );
}

/**
 * Drive one guest Blocks checkout with the basic card, prove it is the one
 * order this submission created, and converge on the settled provider graph
 * (R1: order/intent/charge amount and currency, the linkage, the payment
 * method, one charge occurrence, one capture, and the charged card). Hands
 * back the identities `R1` acts on next: the order, its one line item, and
 * the exact intent and charge the provider recorded.
 */
async function createPaidCardOrder(
	page: Page,
	restApi: ApiClient
): Promise< PaidOrder > {
	const productId = await createRunProduct( restApi );
	const baselineOrderId = await readHighestOrderId( restApi );
	await page.goto( `?post_type=product&p=${ productId }` );
	await page
		.getByRole( 'button', { name: 'Add to cart', exact: true } )
		.click();
	await page.goto( 'checkout/' );
	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ random() }@example.com` );
	await fillBillingCheckoutBlocks( page, {
		country: 'US',
		firstName: 'E2E',
		lastName: 'WooPayments',
		address: '123 Test Street',
		city: 'San Francisco',
		state: 'CA',
		zip: '94107',
		phone: '5555550100',
	} );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.check();
	await fillCardDetails( page, TEST_CARDS.basic, 'blocks' );
	await page.getByRole( 'button', { name: /place order/i } ).click();
	await page.waitForURL( /\/order-received\/[1-9]\d*/, {
		timeout: RECEIPT_TIMEOUT_MS,
	} );
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i )
	).toBeVisible();
	const orderId = Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );

	expect(
		( await readNewOrders( restApi, baselineOrderId ) ).map(
			( order ) => order.id
		),
		'one checkout submission must create exactly one order'
	).toEqual( [ orderId ] );

	const settled = await expectSettledCardPayment( restApi, orderId, {
		amountMinor: AMOUNT_MINOR,
		currency: CURRENCY,
		card: { brand: 'visa', last4: '4242' },
	} );
	expect(
		settled.orderStatus,
		'the order must be processing before it is refunded'
	).toBe( 'processing' );

	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
	const lineItems = order.line_items as Array< { id: number } >;
	return {
		productId,
		orderId,
		lineItemId: lineItems[ 0 ].id,
		intentId: settled.intentId,
		chargeId: settled.chargeId,
	};
}

/**
 * Open the classic order edit screen. Stores keeping orders in the dedicated
 * tables edit them under `wc-orders`; stores still on the posts table edit
 * them through `post.php`. The order-items meta box is the screen's identity
 * either way.
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

/** The refund editor is a toggled data row inside the items meta box with no accessible name; addressed structurally. */
function refundPanel( page: Page ): Locator {
	return page.locator( '.wc-order-refund-items' );
}

async function openRefundEditor( page: Page ): Promise< void > {
	// Exact, because "Refund %s manually" also starts with the same word.
	await page.getByRole( 'button', { name: 'Refund', exact: true } ).click();
	await expect( refundPanel( page ) ).toBeVisible();
}

/**
 * Fill one of the refund editor's inputs, addressed by its `name` attribute
 * since the per-line inputs carry no accessible name. `change` is dispatched
 * explicitly because the admin script recomputes the aggregate refund amount
 * on that event and `fill` does not guarantee one.
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
 * Dispatch exactly one gateway refund from the merchant's own order screen
 * and prove the request was accepted and confirmed exactly once. The refund
 * button's absence is itself a finding: core renders it only when the
 * order's gateway supports refunds.
 */
async function dispatchGatewayRefund( page: Page ): Promise< void > {
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
		const refundResponse = page.waitForResponse(
			( response ) =>
				response.url().includes( 'admin-ajax.php' ) &&
				response.request().method() === 'POST' &&
				( response.request().postData() ?? '' ).includes(
					'action=woocommerce_refund_line_items'
				)
		);
		await gatewayButton.click();
		const response = await refundResponse;
		expect(
			response.status(),
			'the refund request must be accepted by the server'
		).toBe( 200 );
		expect(
			confirmations,
			'the merchant must have confirmed exactly one refund'
		).toBe( 1 );
	} finally {
		page.off( 'dialog', onDialog );
	}
}

/**
 * Whether `error` is the provider's transient "another request holds this
 * object" answer (routine while the refund is being adjudicated).
 */
function isLockTimeout( error: unknown ): boolean {
	const status =
		typeof error === 'object' && error !== null && 'response' in error
			? ( error as { response?: { status?: unknown } } ).response?.status
			: undefined;
	return status === 429;
}

/**
 * Poll the exact provider charge until it carries exactly one succeeded
 * refund, twice in a row - a single read can catch a value mid-propagation,
 * and a settled refund never regresses. Tolerates 429 `lock_timeout` on the
 * read by continuing the poll until the deadline.
 */
async function waitForSettledRefund(
	restApi: ApiClient,
	chargeId: string
): Promise< { id: string; amountMinor: number; currency: string } > {
	const deadline = Date.now() + SETTLE_BUDGET_MS;
	let previous = '';

	for (;;) {
		try {
			const charge = await getCharge( restApi, chargeId );
			const refunds = (
				charge.refunds as { data?: unknown[] } | undefined
			 )?.data;
			if ( Array.isArray( refunds ) ) {
				if ( refunds.length > 1 ) {
					throw new Error(
						`Charge ${ chargeId } carries ${ refunds.length } provider refunds; exactly one is required.`
					);
				}
				const refund = refunds[ 0 ] as
					| {
							id?: unknown;
							status?: unknown;
							amount?: unknown;
							currency?: unknown;
					  }
					| undefined;
				if ( refund?.status === 'succeeded' ) {
					const serialized = JSON.stringify( {
						id: refund.id,
						amount: refund.amount,
						currency: refund.currency,
						captured: charge.captured,
						status: charge.status,
						amountRefunded: charge.amount_refunded,
						refunded: charge.refunded,
					} );
					if ( serialized === previous ) {
						expect( charge.captured ).toBe( true );
						expect( charge.status ).toBe( 'succeeded' );
						expect( charge.amount_refunded ).toBe( AMOUNT_MINOR );
						expect( charge.refunded ).toBe( true );
						return {
							id: String( refund.id ),
							amountMinor: Number( refund.amount ),
							currency: String( refund.currency ).toUpperCase(),
						};
					}
					previous = serialized;
				}
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}

		if ( Date.now() >= deadline ) {
			throw new Error(
				`Provider refund on charge ${ chargeId } did not settle within ${ SETTLE_BUDGET_MS }ms.`
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, SETTLE_INTERVAL_MS )
		);
	}
}

/**
 * Poll WooCommerce's own `_wcpay_refund_status` order meta until it reaches
 * `successful`. A card refund settles inside the AJAX call, but the meta is
 * written from that response and is worth confirming rather than assuming.
 * Tolerates 429 `lock_timeout` on the read by continuing the poll.
 */
async function waitForWooRefundStatus(
	restApi: ApiClient,
	orderId: number
): Promise< void > {
	const deadline = Date.now() + SETTLE_BUDGET_MS;
	let lastSeen = '';
	for (;;) {
		try {
			const order = (
				await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` )
			).data as Record< string, unknown >;
			lastSeen = orderMeta( order, '_wcpay_refund_status' );
			if ( lastSeen === 'successful' ) {
				return;
			}
			if ( lastSeen === 'failed' ) {
				throw new Error(
					`WooCommerce recorded refund status failed on order ${ orderId }.`
				);
			}
		} catch ( error ) {
			if ( ! isLockTimeout( error ) || Date.now() >= deadline ) {
				throw error;
			}
		}

		if ( Date.now() >= deadline ) {
			throw new Error(
				`WooCommerce did not record refund status successful on order ${ orderId } within ${ SETTLE_BUDGET_MS }ms (last seen: ${
					lastSeen || 'no status recorded'
				}).`
			);
		}
		await new Promise( ( resolve ) =>
			setTimeout( resolve, SETTLE_INTERVAL_MS )
		);
	}
}

test.beforeAll( async ( { restApi } ) => {
	await requireTestModeAccount( restApi );
} );

test(
	'A full card refund returns the exact paid amount as one succeeded provider refund whose identity WooCommerce stores once against the source charge',
	{
		annotation: [
			{ type: 'woopayments-contract', description: CONTRACT_R1 },
		],
		tag: FAMILY_TAGS,
	},
	async ( { page, restApi } ) => {
		test.setTimeout( 420_000 );

		const paid = await createPaidCardOrder( page, restApi );
		try {
			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password );
			await openOrderEditScreen( page, paid.orderId );
			await openRefundEditor( page );
			await fillRefundInput(
				page,
				`refund_line_total[${ paid.lineItemId }]`,
				PRICE
			);
			await fillRefundInput( page, 'refund_reason', REFUND_REASON );
			await dispatchGatewayRefund( page );

			const settledRefund = await waitForSettledRefund(
				restApi,
				paid.chargeId
			);
			await waitForWooRefundStatus( restApi, paid.orderId );

			// The join between the two sides: one WooCommerce refund, carrying
			// the exact provider refund identity, against the source charge.
			const wooRefunds = (
				await restApi.get(
					`${ ORDERS_ROUTE }/${ paid.orderId }/refunds?context=edit&per_page=100`
				)
			).data as Array< {
				id: number;
				amount: string;
				meta_data?: Array< { key?: unknown; value?: unknown } >;
			} >;
			expect(
				wooRefunds,
				'exactly one WooCommerce refund must exist'
			).toHaveLength( 1 );
			expect( wooRefunds[ 0 ].amount ).toBe( PRICE );
			const providerRefundId = wooRefunds[ 0 ].meta_data?.find(
				( entry ) => entry.key === '_wcpay_refund_id'
			)?.value;
			expect(
				providerRefundId,
				'WooCommerce must store the exact provider refund ID once'
			).toBe( settledRefund.id );
			expect( settledRefund.amountMinor ).toBe( AMOUNT_MINOR );
			expect( settledRefund.currency ).toBe( CURRENCY );

			const order = (
				await restApi.get( `${ ORDERS_ROUTE }/${ paid.orderId }` )
			).data as Record< string, unknown >;
			expect(
				( order.refunds as Array< { id: number } > ).map(
					( refund ) => refund.id
				)
			).toEqual( [ wooRefunds[ 0 ].id ] );
			expect( String( order.currency ).toUpperCase() ).toBe( CURRENCY );
			expect( order.status ).toBe( 'refunded' );
		} finally {
			await restApi.delete( `${ PRODUCTS_ROUTE }/${ paid.productId }`, {
				force: true,
			} );
		}
	}
);
