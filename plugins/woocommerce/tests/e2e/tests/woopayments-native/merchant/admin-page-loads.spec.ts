import { randomUUID } from 'node:crypto';

import type { Locator, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * Native WooPayments admin pages the other readonly cases do not load (Task 6.0).
 *
 * One case per page: it loads with its key regions, no store REST request fails,
 * nothing on the page throws, and one or two pieces of content read as client
 * 11.1.0 renders them for the data behind the page.
 *
 * Detail pages read platform responses recorded from a connected test store
 * (`envs/woopayments-native/ci-provider-fixture-recorded.json`), which only the
 * secretless CI fixture serves, so they run under that fixture only. The
 * settings subpages and the order edit screen need no platform data.
 */

const FIXTURE = process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE === 'true';
const RECORDED_ONLY =
	'Recorded platform responses are served only by the secretless CI fixture.';

// Ids of the recorded responses in ci-provider-fixture-recorded.json.
const RECORDED = {
	paymentIntent: 'pi_3ULNAtBzWlxcwgpP1j0Vug6y',
	chargeId: 'ch_3ULNAtBzWlxcwgpP1mD5qOrD',
	instantPayout: 'po_1TrYnABzWlxcwgpPhhdCrkEZ',
	dispute: 'du_1UL1U2BzWlxcwgpPk8EypRUa',
} as const;

// Assets of other plugins on a standing store (an installed Subscriptions build
// 404s its admin stylesheet) say nothing about these pages.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;

/**
 * Collect failed store REST responses, uncaught exceptions, and console errors
 * from this store's own code while a page loads.
 */
function trackPageHealth(
	page: Page,
	baseURL: string | undefined
): () => { observedRest: number; failures: string[] } {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for the admin page cases.' );
	}
	const storeBase = baseURL.replace( /\/+$/, '' );
	const restPrefix = `${ new URL( storeBase ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	const failures: string[] = [];
	let observedRest = 0;
	const restPath = ( url: string ): string | null => {
		if ( ! url.startsWith( storeBase ) ) {
			return null;
		}
		const { pathname, searchParams } = new URL( url );
		return pathname.startsWith( restPrefix ) ||
			searchParams.has( 'rest_route' )
			? pathname
			: null;
	};
	page.on( 'response', ( response ) => {
		const path = restPath( response.url() );
		if ( path ) {
			observedRest++;
			if ( response.status() >= 400 ) {
				failures.push( `${ response.status() } ${ path }` );
			}
		}
	} );
	page.on( 'requestfailed', ( request ) => {
		const path = restPath( request.url() );
		const errorText = request.failure()?.errorText ?? 'unknown';
		if ( path && errorText !== 'net::ERR_ABORTED' ) {
			failures.push( `failed ${ path } (${ errorText })` );
		}
	} );
	page.on( 'pageerror', ( error ) =>
		failures.push( `pageerror: ${ error.message }` )
	);
	page.on( 'console', ( message ) => {
		const source = message.location().url;
		if (
			message.type() === 'error' &&
			source.startsWith( storeBase ) &&
			! THIRD_PARTY_PLUGIN_ASSET.test( new URL( source ).pathname )
		) {
			failures.push( `console: ${ message.text() } (${ source })` );
		}
	} );
	return () => ( { observedRest, failures: [ ...failures ] } );
}

async function expectHealthyLoad(
	health: ReturnType< typeof trackPageHealth >
): Promise< void > {
	const { observedRest, failures } = health();
	expect( observedRest ).toBeGreaterThan( 0 );
	expect( failures ).toEqual( [] );
}

/**
 * The admin URL for a native route, as `getSettingsPaymentsProviderAdminPath()`
 * builds it.
 */
function adminPath( route: string, query: Record< string, string > = {} ) {
	const params = new URLSearchParams( {
		page: 'wc-settings',
		tab: 'checkout',
		path: route,
		...query,
	} );
	return `wp-admin/admin.php?${ params.toString() }`;
}

async function texts( locator: Locator ): Promise< string[] > {
	return ( await locator.allTextContents() ).map( ( text ) =>
		text.replace( /\s+/g, ' ' ).trim()
	);
}

test(
	'A merchant opens native payment details for a paid charge and sees its client timeline',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL, restApi } ) => {
		test.skip( ! FIXTURE, RECORDED_ONLY );
		const health = trackPageHealth( page, baseURL );

		// The fixture account has Documents enabled, so a REST request carries the
		// Documents routes. Native registers REST controllers while plugins load,
		// before Jetpack can confirm the connection, so this guards that early
		// account read. The namespace index lists the route without calling it.
		const documentsRoute = '/wc/v3/payments/documents';
		const namespaceIndex = await restApi.get< { routes?: unknown } >(
			'wc/v3',
			{ _fields: `routes.${ documentsRoute }` }
		);
		expect( namespaceIndex.data.routes ).toHaveProperty( [
			documentsRoute,
		] );

		await page.goto(
			adminPath( '/woopayments/transactions/details', {
				id: RECORDED.paymentIntent,
			} )
		);

		await expect( page.getByRole( 'status' ) ).toHaveText(
			'Transaction details loaded.'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Payment details' } )
		).toBeVisible();
		await expect( page.getByText( RECORDED.chargeId ) ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Payment method' } )
		).toBeVisible();

		// Client 11.1.0 `payment-details/timeline/map-events.js:832-935` for the
		// recorded `captured`, `authorized`, and `started` events; the recorded
		// `fraud_outcome_allow` event has no item there.
		await expect(
			page.getByRole( 'heading', { name: 'Timeline' } )
		).toBeVisible();
		await expect
			.poll( () =>
				texts( page.locator( '.woocommerce-timeline-item__headline' ) )
			)
			.toEqual( [
				'Payment status changed to Paid.',
				'$23.66 USD will be added to a future payout.',
				'A payment of $24.68 USD was successfully charged.',
				'Payment status changed to Authorized.',
				'A payment of $24.68 USD was successfully authorized.',
				'Payment status changed to Started.',
			] );
		await expect(
			page
				.locator( '.woocommerce-timeline-item__body' )
				.getByText( 'Net payout: $23.66 USD', { exact: true } )
		).toBeVisible();

		await expectHealthyLoad( health );
	}
);

test(
	'A merchant opens native uncaptured and blocked transactions and sees their client columns',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL } ) => {
		test.skip( ! FIXTURE, RECORDED_ONLY );
		const health = trackPageHealth( page, baseURL );

		// Client 11.1.0 `transactions/uncaptured/index.tsx:44-107`, Email and
		// Country hidden by default. The fixture holds no authorizations.
		await page.goto(
			adminPath( '/woopayments/transactions', { view: 'uncaptured' } )
		);
		await expect(
			page.getByRole( 'heading', { name: 'Uncaptured transactions' } )
		).toBeVisible();
		await expect( page.getByRole( 'status' ) ).toHaveText(
			'No uncaptured transactions found.'
		);
		await expect( page.getByRole( 'columnheader' ) ).toHaveText( [
			/^Authorized on[↑↓]?$/,
			'Capture by',
			'Order',
			'Risk level',
			'Amount',
			'Action',
		] );
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'authorization(s)' } )
		).toHaveText( /^0\s*authorization\(s\)$/ );

		// Client 11.1.0 `transactions/blocked/columns.tsx:28-60`. The recorded
		// store had no blocked payments.
		await page.goto(
			adminPath( '/woopayments/transactions', { view: 'blocked' } )
		);
		await expect(
			page.getByRole( 'heading', { name: 'Blocked transactions' } )
		).toBeVisible();
		await expect( page.getByRole( 'columnheader' ) ).toHaveText( [
			/^Date \/ Time[↑↓]?$/,
			'Amount',
			'Customer',
			'Status',
		] );
		// The client's own label, `blocked/index.tsx:80`.
		await expect(
			page
				.getByRole( 'listitem' )
				.filter( { hasText: 'transactions(s)' } )
		).toHaveText( /^0\s*transactions\(s\)$/ );

		await expectHealthyLoad( health );
	}
);

test(
	'A merchant opens native details for an instant payout and sees the client payout overview',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL } ) => {
		test.skip( ! FIXTURE, RECORDED_ONLY );
		const health = trackPageHealth( page, baseURL );

		await page.goto(
			adminPath( '/woopayments/payouts/details', {
				id: RECORDED.instantPayout,
			} )
		);

		await expect( page.getByRole( 'status' ) ).toHaveText(
			'Payout details loaded.'
		);
		// Client 11.1.0 `deposits/details/index.tsx:106-275` for the recorded
		// payout: automatic false, 4825 usd, no fee, paid.
		await expect(
			page.getByText( /^Instant payout date: / )
		).toBeVisible();
		await expect(
			page.getByText( 'Completed (paid)', { exact: true } )
		).toBeVisible();
		for ( const [ label, value ] of [
			[ 'Payout amount', '$48.25 USD' ],
			[ '0% service fee', '$0.00' ],
			[ 'Net payout amount', '$48.25 USD' ],
		] ) {
			await expect(
				page.getByText( label, { exact: true } ).locator( 'xpath=..' )
			).toContainText( value );
		}
		await expect(
			page.getByRole( 'heading', { name: 'Payout details' } )
		).toBeVisible();
		await expect(
			page.getByText( 'STRIPE TEST BANK •••• 6789 (USD)' )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Payout transactions' } )
		).toBeVisible();
		await expect(
			page.getByText(
				"We're unable to show transaction history on instant payouts."
			)
		).toBeVisible();

		await expectHealthyLoad( health );
	}
);

test(
	'A merchant opens the native dispute challenge form and sees the client evidence steps and customer details',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL } ) => {
		test.skip( ! FIXTURE, RECORDED_ONLY );
		const health = trackPageHealth( page, baseURL );

		await page.goto(
			adminPath( '/woopayments/disputes/challenge', {
				id: RECORDED.dispute,
			} )
		);

		await expect( page.getByRole( 'status' ) ).toHaveText(
			'Dispute evidence form loaded.'
		);
		await expect(
			page.getByRole( 'heading', { name: 'Challenge dispute' } )
		).toBeVisible();
		// Client 11.1.0 `disputes/new-evidence/index.tsx:82,473-479`: the first
		// step, whose name is the first of the steps listed.
		await expect(
			page.getByRole( 'heading', { name: "Let's gather the basics" } )
		).toBeVisible();
		for ( const step of [ 'Purchase info', 'Review' ] ) {
			await expect(
				page.getByText( step, { exact: true } ).first()
			).toBeVisible();
		}
		// Client `new-evidence/customer-details.tsx:20-80`, read from the
		// recorded dispute's charge billing details.
		const customer = page
			.getByRole( 'heading', { name: 'Customer details' } )
			.locator( 'xpath=..' );
		await expect( customer ).toContainText( 'E2E WooPayments' );
		await expect( customer ).toContainText( '5555550100' );
		await expect( customer ).toContainText(
			'123 Test Street, San Francisco, CA 94107'
		);
		await expect(
			page.getByRole( 'button', { name: 'Save for later' } )
		).toBeVisible();

		await expectHealthyLoad( health );
	}
);

test(
	'A merchant opens the native express checkout settings pages and sees the client sections',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL } ) => {
		const health = trackPageHealth( page, baseURL );

		// Client 11.1.0 `settings/express-checkout-settings/index.js:26-118`.
		for ( const { methodId, title, copy } of [
			{
				methodId: 'woopay',
				title: 'WooPay',
				copy: [
					'Allow your customers to collect payments via WooPay.',
					'Configure the display of WooPay buttons on your store.',
				],
			},
			{
				methodId: 'payment_request',
				title: 'Apple Pay / Google Pay',
				copy: [
					'Allow your customers to collect payments via Apple Pay and Google Pay.',
					'Configure the display of Apple Pay and Google Pay buttons on your store.',
				],
			},
		] ) {
			await page.goto(
				adminPath(
					`/woopayments/settings/express-checkout/${ methodId }`
				)
			);
			await expect(
				page.getByRole( 'heading', { name: title, exact: true } )
			).toBeVisible();
			for ( const text of copy ) {
				await expect(
					page.getByText( text, { exact: true } )
				).toBeVisible();
			}
			await expect(
				page.getByRole( 'button', { name: 'Save changes' } )
			).toBeVisible();
		}

		// Client 11.1.0 `settings/express-checkout-settings/index.js:125-163`:
		// the Amazon Pay subpage, under its title.
		await page.goto(
			adminPath( '/woopayments/settings/express-checkout/amazon_pay' )
		);
		await expect(
			page.getByRole( 'heading', { name: 'Amazon Pay', exact: true } )
		).toBeVisible();

		await expectHealthyLoad( health );
	}
);

test(
	'A merchant opens native advanced fraud protection settings and sees the client filter cards',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL } ) => {
		const health = trackPageHealth( page, baseURL );

		await page.goto(
			adminPath( '/woopayments/settings/fraud-protection' )
		);

		await expect(
			page.getByRole( 'heading', { name: 'Advanced fraud protection' } )
		).toBeVisible();
		const filters = page
			.getByRole( 'heading', { name: 'Filter configuration' } )
			.locator( 'xpath=ancestor::*[.//h3][1]' );
		// Client 11.1.0 `settings/fraud-protection/advanced-settings/index.tsx:365-405`.
		await expect( filters.getByRole( 'heading', { level: 3 } ) ).toHaveText(
			[
				'AVS Mismatch',
				'International IP Address',
				'IP Address Mismatch',
				'Address Mismatch',
				'Purchase Price Threshold',
				'Order Items Threshold',
				'CVC Verification',
			]
		);
		await expect(
			page.getByRole( 'button', { name: 'Save changes' } )
		).toBeVisible();

		await expectHealthyLoad( health );
	}
);

/**
 * Create a run-owned WooPayments order whose meta names the recorded payment,
 * with an allowed fraud outcome and a normal risk level.
 */
async function createRunOwnedOrder( restApi: ApiClient ): Promise< number > {
	const order = (
		await restApi.post< { id?: unknown } >( 'wc/v3/orders', {
			status: 'processing',
			payment_method: 'woocommerce_payments',
			payment_method_title: 'Credit card / debit card',
			meta_data: [
				{ key: '_e2e_woopayments_run_id', value: randomUUID() },
				{ key: '_wcpay_mode', value: 'test' },
				{ key: '_intent_id', value: RECORDED.paymentIntent },
				{ key: '_charge_id', value: RECORDED.chargeId },
				{ key: '_charge_risk_level', value: 'normal' },
				{ key: '_wcpay_fraud_meta_box_type', value: 'allow' },
			],
		} )
	).data;
	if ( typeof order.id !== 'number' ) {
		throw new Error( 'The run-owned order response carried no id.' );
	}
	return order.id;
}

/**
 * Open the order edit screen on HPOS stores, or on the posts screen for stores
 * still on the posts table.
 */
async function openOrderEditScreen( page: Page, orderId: number ) {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	if ( ( await page.locator( '#woocommerce-order-items' ).count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( page.locator( '#woocommerce-order-items' ) ).toBeVisible();
}

test(
	'A merchant opens a native WooPayments order and sees the client Fraud & Risk box',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { page, baseURL, restApi } ) => {
		const orderId = await createRunOwnedOrder( restApi );
		try {
			const health = trackPageHealth( page, baseURL );
			await openOrderEditScreen( page, orderId );

			// Client 11.1.0 `includes/fraud-prevention/class-order-fraud-and-risk-meta-box.php:57-280`
			// for an allowed payment with a normal risk level.
			const box = page.locator( '#wcpay-order-fraud-and-risk-meta-box' );
			await expect(
				box.getByRole( 'heading', { name: 'Fraud & Risk' } )
			).toBeVisible();
			await expect( box ).toContainText( 'Normal' );
			await expect( box ).toContainText(
				'This payment shows a lower than normal risk of fraudulent activity.'
			);
			await expect( box ).toContainText( 'No action taken' );
			await expect( box ).toContainText(
				'The payment for this order passed your risk filtering.'
			);
			await expect(
				box.getByRole( 'link', { name: 'Adjust risk filters' } )
			).toBeVisible();

			await expectHealthyLoad( health );
		} finally {
			await restApi.delete( `wc/v3/orders/${ orderId }`, {
				force: true,
			} );
		}
	}
);
