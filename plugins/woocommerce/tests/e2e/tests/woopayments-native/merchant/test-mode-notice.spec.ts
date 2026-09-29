import { randomUUID } from 'node:crypto';

import type { BrowserContext, Locator, Page, Route } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * Native WooPayments test-mode notice (finding F-T60-3).
 *
 * Every copy string below is client 11.1.0's, not native's:
 * `components/test-mode-notice/index.tsx` for the admin pages and
 * `order/test-mode-notice/index.tsx` for the order edit screen. The client
 * has no browser case for this notice, so no ledger contract is annotated.
 *
 * The admin notice reads only the preloaded `woopaymentsSettings.testMode` and
 * `devMode`, and the client renders it above each page whether or not the
 * page's data loads. So the case answers every store `wc/v3/payments` read
 * with a 503 in the browser: no provider request leaves the store, and a
 * notice that waited for data would fail here. Jest owns the loaded pages.
 *
 * Stores in a development environment show the dev-mode sentence everywhere,
 * so the list and details sentences are checked a second time on a page that
 * receives `devMode: false` in the same bootstrap. PHPUnit owns how the store
 * derives `devMode`; this case owns what the pages render from it.
 */

const RUNTIME_STATUS_API = 'wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';

// The suite's run-ownership stamp, the same key other specs write on the
// orders they create.
const RUN_META_KEY = '_e2e_woopayments_run_id';

const NOTICE_SELECTOR = '.woocommerce-woopayments-test-mode-notice';
const ORDER_NOTICE_MOUNT_SELECTOR =
	'#woocommerce-woopayments-order-payment-details';
const ORDER_SCRIPT_CONTAINER_SELECTOR =
	'.woocommerce-woopayments-order-status-change';

const TEST_ACCOUNTS_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/test-accounts/';
const ORDER_TEST_MODE_URL =
	'https://woocommerce.com/document/woopayments/testing-and-troubleshooting/testing/';

// No page's data is read, so no id has to exist. The payout id is the
// secretless CI fixture's (`envs/woopayments-native/ci-provider-fixture.php`);
// the payment id only has to look like one (`pi_`), since native shows the
// details notice for a payment id before, or without, the payment loading.
const FIXTURE_PAYOUT_ID = 'po_ci_paid';
const ABSENT_DISPUTE_ID = 'dp_e2e_test_mode_notice';
const ABSENT_PAYMENT_ID = 'pi_e2e_test_mode_notice';

// The message the withheld reads carry, which the details error view shows.
const WITHHELD_MESSAGE =
	'Payments data is withheld by the test-mode notice case.';

type NoticePage = 'deposits' | 'disputes' | 'payments' | 'transactions';

interface Surface {
	name: string;
	/** Native route, as the pages link to it. */
	route: string;
	currentPage: NoticePage;
	isDetailsView: boolean;
	/** Whether the page shows the withheld read's message as its error view. */
	showsLoadError?: boolean;
}

// Client 11.1.0 test-mode-notice/index.tsx:33-48.
const NOUNS: Record< NoticePage, string > = {
	deposits: 'payout',
	disputes: 'dispute',
	payments: 'order',
	transactions: 'order',
};
const VERBS: Record< NoticePage, string > = {
	deposits: 'created',
	disputes: 'created',
	payments: 'placed',
	transactions: 'placed',
};

// Client index.tsx:173-175 and 221-223: deposits read as payouts.
function resourceLabel( currentPage: NoticePage ): string {
	return currentPage === 'deposits' ? 'payouts' : currentPage;
}

interface ExpectedCopy {
	sentence: string;
	link: { name: string; href: string | RegExp };
}

/**
 * The client's copy for one page, in its own order: dev mode first, then the
 * details view, then the list sentence (client index.tsx:163-236).
 */
function expectedCopy( surface: Surface, devMode: boolean ): ExpectedCopy {
	const resource = resourceLabel( surface.currentPage );
	if ( devMode ) {
		return {
			sentence: `Viewing test ${ resource }. Test mode is active because your store is in a development or staging environment. Learn more`,
			link: { name: 'Learn more', href: TEST_ACCOUNTS_URL },
		};
	}

	const settingsLink = {
		name: 'WooPayments settings',
		href: /[?&]page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings$/,
	};
	if ( surface.isDetailsView ) {
		const noun = NOUNS[ surface.currentPage ];
		const verb = VERBS[ surface.currentPage ];
		// `_n()` with a count of 2 for deposits, 1 otherwise.
		const subject =
			surface.currentPage === 'deposits'
				? `these ${ noun }s were`
				: `this ${ noun } was`;
		return {
			sentence: `WooPayments was in test mode when ${ subject } ${ verb }. To view live ${ noun }s, disable test mode in WooPayments settings.`,
			link: settingsLink,
		};
	}

	return {
		sentence: `Viewing test ${ resource }. To view live ${ resource }, disable test mode in WooPayments settings.`,
		link: settingsLink,
	};
}

/**
 * Build the admin URL for a native route the way
 * `getSettingsPaymentsProviderAdminPath()` does: the route rides in `path`, and
 * its own query follows, with `page` renamed to `paged`.
 */
function adminPath( route: string ): string {
	const [ routePath, routeQuery = '' ] = route.split( '?' );
	const params = new URLSearchParams( {
		page: 'wc-settings',
		tab: 'checkout',
		path: routePath,
	} );
	new URLSearchParams( routeQuery ).forEach( ( value, key ) => {
		params.append( key === 'page' ? 'paged' : key, value );
	} );
	return `wp-admin/admin.php?${ params.toString() }`;
}

const SURFACES: Surface[] = [
	{
		name: 'transaction details',
		// `getTransactionDetailsRoute()` for a payment intent row.
		route: `/woopayments/transactions/details?id=${ encodeURIComponent(
			ABSENT_PAYMENT_ID
		) }`,
		currentPage: 'payments',
		isDetailsView: true,
		showsLoadError: true,
	},
	{
		name: 'transactions',
		route: '/woopayments/transactions',
		currentPage: 'transactions',
		isDetailsView: false,
	},
	{
		name: 'uncaptured transactions',
		route: '/woopayments/transactions?view=uncaptured',
		currentPage: 'transactions',
		isDetailsView: false,
	},
	{
		name: 'blocked transactions',
		route: '/woopayments/transactions?view=blocked',
		currentPage: 'transactions',
		isDetailsView: false,
	},
	{
		name: 'payouts',
		route: '/woopayments/payouts',
		currentPage: 'deposits',
		isDetailsView: false,
	},
	{
		name: 'payout details',
		route: `/woopayments/payouts/details?id=${ encodeURIComponent(
			FIXTURE_PAYOUT_ID
		) }`,
		currentPage: 'deposits',
		isDetailsView: true,
	},
	{
		name: 'disputes',
		route: '/woopayments/disputes',
		currentPage: 'disputes',
		isDetailsView: false,
	},
	{
		name: 'dispute challenge',
		route: `/woopayments/disputes/challenge?id=${ encodeURIComponent(
			ABSENT_DISPUTE_ID
		) }`,
		currentPage: 'disputes',
		isDetailsView: true,
	},
];

function isPaymentsRestRead( url: URL ): boolean {
	return (
		url.pathname.includes( '/wp-json/wc/v3/payments/' ) ||
		( url.searchParams.get( 'rest_route' ) ?? '' ).startsWith(
			'/wc/v3/payments/'
		)
	);
}

async function answerPaymentsReadWithOutage( route: Route ): Promise< void > {
	await route.fulfill( {
		status: 503,
		json: {
			code: 'e2e_test_mode_notice_isolation',
			message: WITHHELD_MESSAGE,
		},
	} );
}

interface WooPaymentsBootstrap {
	testMode?: unknown;
	devMode?: unknown;
}

async function readBootstrap( page: Page ): Promise< WooPaymentsBootstrap > {
	return page.evaluate( () => {
		const settings = (
			window as unknown as {
				wcSettings?: {
					admin?: { woopaymentsSettings?: Record< string, unknown > };
				};
			}
		 ).wcSettings?.admin?.woopaymentsSettings;
		return { testMode: settings?.testMode, devMode: settings?.devMode };
	} );
}

/**
 * Hand every later document in this page a bootstrap with `devMode: false`,
 * so a development store renders the list and details sentences. The inline
 * `var wcSettings = …` assignment runs through this setter.
 */
async function presentProductionBootstrap( page: Page ): Promise< void > {
	await page.addInitScript( () => {
		let settings: unknown;
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			get: () => settings,
			set: ( value: unknown ) => {
				const woopayments = (
					value as {
						admin?: { woopaymentsSettings?: { devMode?: unknown } };
					} | null
				 )?.admin?.woopaymentsSettings;
				if ( woopayments && typeof woopayments === 'object' ) {
					woopayments.devMode = false;
				}
				settings = value;
			},
		} );
	} );
}

/**
 * The text a sighted reader sees: without the screen-reader-only labels
 * (the notice's status, a link's "opens in a new tab") and the external-link
 * arrow, so the client's sentence can be compared whole.
 */
async function sightedText( element: Locator ): Promise< string > {
	return element.evaluate( ( node ) => {
		const clone = node.cloneNode( true ) as HTMLElement;
		clone
			.querySelectorAll(
				'.components-visually-hidden, [aria-hidden="true"]'
			)
			.forEach( ( hidden ) => hidden.remove() );
		return ( clone.textContent ?? '' )
			.replace( /↗/g, '' )
			.replace( /\s+/g, ' ' )
			.trim();
	} );
}

async function expectNotice(
	page: Page,
	surface: Surface,
	devMode: boolean
): Promise< void > {
	const copy = expectedCopy( surface, devMode );
	const notice = page.locator( NOTICE_SELECTOR );
	await expect( notice, `${ surface.name } shows one notice` ).toHaveCount(
		1
	);
	const link = notice.getByRole( 'link', { name: copy.link.name } );
	await expect( link ).toHaveAttribute( 'href', copy.link.href );
	await expect
		.poll( () => sightedText( notice ), {
			message: `${ surface.name } shows the client's copy`,
		} )
		.toBe( copy.sentence );
}

async function visitSurface(
	page: Page,
	surface: Surface,
	devMode: boolean
): Promise< void > {
	await page.goto( adminPath( surface.route ) );
	const bootstrap = await readBootstrap( page );
	expect(
		bootstrap,
		`${ surface.name } receives the test-mode bootstrap`
	).toEqual( { testMode: true, devMode } );
	await expectNotice( page, surface, devMode );
	if ( surface.showsLoadError ) {
		await expect(
			page.getByText( WITHHELD_MESSAGE ).first(),
			`${ surface.name } shows its error view`
		).toBeVisible();
	}
}

async function expectConnectedNativeStore(
	restApi: ApiClient
): Promise< void > {
	const runtimeStatus = ( await restApi.get( RUNTIME_STATUS_API ) )
		.data as Record< string, unknown >;
	expect(
		{
			account_connected: runtimeStatus.account_connected,
			gateway_enabled: runtimeStatus.gateway_enabled,
		},
		'the store must report a connected account and an enabled gateway'
	).toEqual( { account_connected: true, gateway_enabled: true } );
	const paymentsSettings = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
		.data as Record< string, unknown >;
	expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
}

async function createWooPaymentsOrder(
	restApi: ApiClient,
	runId: string,
	mode: 'test' | 'live' | null
): Promise< number > {
	const metaData = [ { key: RUN_META_KEY, value: runId } ];
	if ( mode ) {
		metaData.push( { key: '_wcpay_mode', value: mode } );
	}
	const order = (
		await restApi.post< { id?: unknown } >( 'wc/v3/orders', {
			status: 'on-hold',
			payment_method: 'woocommerce_payments',
			payment_method_title: 'Credit card / debit card',
			meta_data: metaData,
		} )
	).data;
	if ( typeof order.id !== 'number' ) {
		throw new Error( 'The run-owned order response carried no id.' );
	}
	return order.id;
}

/**
 * Open the order edit screen and wait for the WooPayments order script to
 * finish its first render, so an absent notice is absent rather than late.
 */
async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< Locator > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	if ( ( await page.locator( '#woocommerce-order-items' ).count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( page.locator( '#woocommerce-order-items' ) ).toBeVisible();
	await expect(
		page.locator( ORDER_SCRIPT_CONTAINER_SELECTOR ).first()
	).toBeAttached();
	await page.evaluate(
		() =>
			new Promise< void >( ( resolve ) =>
				requestAnimationFrame( () => setTimeout( resolve, 0 ) )
			)
	);
	const mount = page.locator( ORDER_NOTICE_MOUNT_SELECTOR );
	await expect( mount ).toHaveCount( 1 );
	return mount;
}

async function withRoutedPaymentsReads(
	context: BrowserContext,
	visit: () => Promise< void >
): Promise< void > {
	await context.route( isPaymentsRestRead, answerPaymentsReadWithOutage );
	try {
		await visit();
	} finally {
		await context.unroute( isPaymentsRestRead );
	}
}

/**
 * Create one WooPayments order per `_wcpay_mode` value (null leaves the meta
 * out), hand their ids to `exercise`, and delete them whatever happens.
 */
async function withRunOwnedOrders(
	restApi: ApiClient,
	modes: Array< 'test' | 'live' | null >,
	exercise: ( orderIds: number[] ) => Promise< void >
): Promise< void > {
	const runId = randomUUID();
	const orderIds: number[] = [];
	let primaryError: unknown;
	try {
		for ( const mode of modes ) {
			orderIds.push(
				await createWooPaymentsOrder( restApi, runId, mode )
			);
		}
		await exercise( orderIds );
	} catch ( error ) {
		primaryError = error;
		throw error;
	} finally {
		const cleanupErrors: unknown[] = [];
		for ( const orderId of orderIds ) {
			try {
				await restApi.delete( `wc/v3/orders/${ orderId }`, {
					force: true,
				} );
			} catch ( cleanupError ) {
				cleanupErrors.push( cleanupError );
			}
		}
		if ( cleanupErrors.length > 0 && primaryError === undefined ) {
			throw new Error( 'Run-owned order cleanup failed.', {
				cause: cleanupErrors[ 0 ],
			} );
		}
	}
}

const ORDER_NOTICE_TEXT =
	'WooPayments was in test mode when this order was placed. Learn more about test mode';

test(
	'A merchant in test mode sees the client test-mode notice on native WooPayments money pages and on test-mode orders only',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { restApi, page, context } ) => {
		await expectConnectedNativeStore( restApi );
		await page.goto( adminPath( '/woopayments/transactions' ) );
		const served = await readBootstrap( page );
		expect( served.testMode, 'the store must be in test mode' ).toBe(
			true
		);
		expect( typeof served.devMode ).toBe( 'boolean' );

		const productionPage = await context.newPage();
		await presentProductionBootstrap( productionPage );

		for ( const [ label, viewer, devMode ] of [
			[ 'as served', page, served.devMode === true ],
			[ 'outside a development environment', productionPage, false ],
		] as const ) {
			await test.step( `Admin pages, ${ label }`, async () => {
				await withRoutedPaymentsReads( context, async () => {
					for ( const surface of SURFACES ) {
						await visitSurface( viewer, surface, devMode );
					}
				} );
			} );
		}
		await productionPage.close();

		await test.step( 'Order edit screen', async () => {
			await withRunOwnedOrders(
				restApi,
				[ 'test', 'live', null ],
				async ( [ testOrderId, liveOrderId, unmarkedOrderId ] ) => {
					const testMount = await openOrderEditScreen(
						page,
						testOrderId
					);
					await expect
						.poll( () => sightedText( testMount ) )
						.toBe( ORDER_NOTICE_TEXT );
					await expect(
						testMount.getByRole( 'link', {
							name: 'Learn more about test mode',
						} )
					).toHaveAttribute( 'href', ORDER_TEST_MODE_URL );
					// The admin screen, not the screen-reader announcement
					// region the notice also speaks into.
					await expect(
						page
							.locator( '#wpbody-content' )
							.getByText( /was in test mode when this order/ )
					).toHaveCount( 1 );

					for ( const orderId of [ liveOrderId, unmarkedOrderId ] ) {
						const mount = await openOrderEditScreen(
							page,
							orderId
						);
						await expect( mount ).toBeEmpty();
						await expect(
							page.getByText( /was in test mode when this order/ )
						).toHaveCount( 0 );
					}
				}
			);
		} );
	}
);
