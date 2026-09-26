import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import { fillBillingCheckoutBlocks } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { random } from '../../../utils/helpers';
import { logIn } from '../../../utils/login';
import {
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * `merchant-transaction-navigation` (T.4 Batch P2 rewrite). No client
 * contract row targets this case directly, but it is the only retained case
 * that renders the transaction details page for a real provider charge - the
 * money-movement Jest suites mock that REST data instead of a live route.
 *
 * Proves navigation to one exact provider-created transaction: from the
 * WooPayments admin menu, through the Transactions list, to the details
 * surface for the exact intent, charge, order and currency this journey
 * created.
 */

const PRICE = '15.50';
const AMOUNT_MINOR = 1550;
const CURRENCY = 'USD';
const RECEIPT_TIMEOUT_MS = 60_000;

const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';

interface PaidOrder {
	productId: number;
	orderId: number;
	intentId: string;
	chargeId: string;
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
 * Drive one guest Blocks checkout, prove it is the one order this submission
 * created, and converge on the settled provider graph (order/intent/charge
 * amount and currency, the linkage, the payment method, one charge
 * occurrence, one capture).
 */
async function createPaidCardOrder(
	page: Page,
	restApi: ApiClient
): Promise< PaidOrder > {
	const productId = (
		(
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments transaction navigation ${ random() }`,
				type: 'simple',
				virtual: true,
				regular_price: PRICE,
				status: 'publish',
			} )
		).data as { id: number }
	 ).id;
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
	} );
	return {
		productId,
		orderId,
		intentId: settled.intentId,
		chargeId: settled.chargeId,
	};
}

/** Navigate from the WooPayments admin menu to the exact transaction this journey created. */
async function openExactMerchantTransaction(
	page: Page,
	paid: PaidOrder
): Promise< void > {
	await page.goto( 'wp-admin/' );
	await page
		.getByRole( 'link', { name: 'Payments', exact: true } )
		.first()
		.click();
	const transactionsLink = page
		.getByRole( 'link', { name: 'Transactions', exact: true } )
		.first();
	await expect( transactionsLink ).toHaveAttribute( 'href', /transactions/ );
	const transactionsUrl = await transactionsLink.getAttribute( 'href' );
	if ( ! transactionsUrl ) {
		throw new Error(
			'The WooPayments Transactions menu link has no destination.'
		);
	}
	await page.goto( transactionsUrl );
	await page
		.locator( `a[href*="id=${ encodeURIComponent( paid.intentId ) }"]` )
		.first()
		.click();
}

test.beforeAll( async ( { restApi } ) => {
	await requireTestModeAccount( restApi );
} );

test(
	'merchant reaches the exact transaction created by this atomic journey',
	{
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_PR,
		],
	},
	async ( { page, restApi } ) => {
		const paid = await createPaidCardOrder( page, restApi );
		try {
			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password );
			await openExactMerchantTransaction( page, paid );

			await expect(
				page.getByRole( 'heading', {
					name: /^(Payment details|Transaction details)$/,
				} )
			).toBeVisible();
			await expect(
				page.getByRole( 'link', {
					name: `Order #${ paid.orderId }`,
					exact: true,
				} )
			).toBeVisible();
			await expect(
				page.getByText( paid.intentId, { exact: true } )
			).toBeVisible();
			await expect(
				page.getByText( paid.chargeId, { exact: true } )
			).toBeVisible();
			await expect(
				page.getByText( CURRENCY, { exact: true } )
			).toBeVisible();
			// F-TX (N-085): native renders "Authorized"/title-cased provider
			// status labels here where client 11.1.0 renders "Payment
			// authorized"/"Paid"; asserting native's current label would pin
			// the divergence, left for Task T.7 Step 5.
		} finally {
			await restApi.delete( `${ PRODUCTS_ROUTE }/${ paid.productId }`, {
				force: true,
			} );
		}
	}
);
