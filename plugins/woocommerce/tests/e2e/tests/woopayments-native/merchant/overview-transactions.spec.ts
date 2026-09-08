import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';
import { FixtureManualCaptureScope } from '../../../utils/woopayments-native/fixture-settings';

/**
 * Native WooPayments overview and transactions
 * (merchant-overview-transactions-spec).
 *
 * Three provider-free merchant contracts against the Core-owned native
 * WooPayments admin surfaces: the overview route
 * (client/admin/client/woopayments/admin/routes.tsx → /woopayments/overview,
 * rendered by admin/overview/page.tsx), its balance card
 * (admin/overview/components/account-balances-card.tsx, fed by
 * /wc/v3/payments/deposits/overview-all), and the transactions list
 * (admin/money-movement/transactions-page.tsx, fed by
 * /wc/v3/payments/transactions and its summary route).
 *
 * Oracle discipline: every rendered figure is joined to the authoritative REST
 * response the store itself serves, read independently through the admin API
 * before the surface is opened and re-read afterwards, so a stale, wrong-row or
 * wrong-currency render cannot pass and a store whose data moved mid-test fails
 * loudly instead of matching by accident.
 *
 * Native field-set bound (transactions): the client suite's row also carried an
 * optional WooCommerce Subscriptions compatibility clause — a "Subscription
 * number" column when Subscriptions is active. The native transactions list
 * declares exactly seven fields (date, type, amount, fees, net, source,
 * customer; transactions-page.tsx) and no subscription identifier exists
 * anywhere under admin/money-movement/. This spec therefore proves the honest
 * native contract — the list loads and its rows and totals equal the
 * authoritative transactions — and deliberately asserts nothing about a
 * subscription column, which is recorded as a parity gap rather than faked or
 * failed here.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_IDS = {
	overviewLoad:
		'default::basic::tests/e2e/specs/basic.spec.ts:16::A basic set of tests to ensure WP, wp-admin and my-account load › Sign in as admin › Load Payments Overview',
	accountBalance:
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-account-balance.spec.ts:18::Merchant account balance overview › View the total and available account balance for a single deposit currency',
	transactionsLoad:
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-transactions.spec.ts:14::Admin transactions › page should load without errors',
} as const;

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const DEPOSITS_OVERVIEW_API = '/wp-json/wc/v3/payments/deposits/overview-all';
const TRANSACTIONS_API = '/wp-json/wc/v3/payments/transactions';
const TRANSACTIONS_SUMMARY_API = '/wp-json/wc/v3/payments/transactions/summary';
const TRANSACTIONS_TEST_TITLE =
	'An authorized merchant loads the native WooPayments transactions list without errors and its rows and totals equal the authoritative transactions the store reports';
const fixtureManualCapture = new FixtureManualCaptureScope();

// The transactions list's own default query (transactions-page.tsx): page 1,
// 25 rows, newest dispatch first. The surface additionally sends
// `user_timezone`, which only shapes date-filtered reads; this contract makes
// no date filter, so the unfiltered row set is identical.
const TRANSACTIONS_DEFAULT_QUERY =
	'page=1&pagesize=25&sort=date&direction=desc';

// The transactions list's declared field set, in the order the surface renders
// it. Asserting the order is what licenses the cell-index lookups below.
const TRANSACTION_COLUMNS = [
	'Date / time',
	'Type',
	'Amount',
	'Fees',
	'Net',
	'Payment method',
	'Customer',
] as const;
const TRANSACTION_AMOUNT_CELL_INDEX = 2;
const TRANSACTION_NET_CELL_INDEX = 4;

const TRANSACTIONS_TERMINAL =
	/^(Transactions loaded\.|No transactions found\.)$/;

// The failure shapes these release smokes exist to catch, matching the frozen
// payouts-disputes smoke.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

interface MoneyAmount {
	amount?: unknown;
	currency?: unknown;
}

interface DepositsOverview {
	balance?: {
		available?: MoneyAmount[];
		pending?: MoneyAmount[];
		instant?: MoneyAmount[];
	};
	deposit?: {
		last_paid?: MoneyAmount[];
	};
	account?: {
		default_currency?: unknown;
	};
}

interface BalanceSnapshot {
	currency: string;
	available: number;
	pending: number;
	total: number;
}

interface TransactionRecord {
	transaction_id?: unknown;
	id?: unknown;
	amount?: unknown;
	net?: unknown;
	currency?: unknown;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

function escapeRegExp( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

async function readJson< Result = Record< string, unknown > >(
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

async function readManualCapture(
	adminApi: APIRequestContext
): Promise< boolean > {
	const settings = await readJson< {
		is_manual_capture_enabled?: unknown;
	} >(
		await adminApi.get( PAYMENTS_SETTINGS_API ),
		'Manual capture settings read'
	);
	if ( typeof settings.is_manual_capture_enabled !== 'boolean' ) {
		throw new Error(
			'Manual capture settings did not contain a boolean state.'
		);
	}
	return settings.is_manual_capture_enabled;
}

async function writeManualCapture(
	adminApi: APIRequestContext,
	enabled: boolean
): Promise< void > {
	const settings = await readJson< {
		is_manual_capture_enabled?: unknown;
	} >(
		await adminApi.post( PAYMENTS_SETTINGS_API, {
			data: { is_manual_capture_enabled: enabled },
		} ),
		'Manual capture settings write'
	);
	expect( settings.is_manual_capture_enabled ).toBe( enabled );
}

async function logInAsAdmin( page: Page ): Promise< void > {
	// Clear first, matching the harness's own admin login: a stale session
	// cookie would redirect wp-login.php to wp-admin and leave the form fill
	// hunting a field that is not there.
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await waitForWordPressLoginReady( page );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USERNAME );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( ADMIN_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( '**/wp-admin/**' );
}

/**
 * The store's own Payments admin menu: the top-level item plus the native
 * WooPayments routes registered under it by
 * WooPaymentsAdminNavigationController. Every navigation below starts here
 * rather than from a hard-coded route, so route discovery is part of what these
 * contracts prove.
 */
function paymentsMenu( page: Page ): Locator {
	return page.locator( '#adminmenu > li' ).filter( {
		has: page.getByRole( 'link', { name: 'Payments', exact: true } ),
	} );
}

/**
 * Follow a Payments menu item to the surface it offers.
 *
 * The item is located and its destination read from the product's own
 * navigation, then followed. wp-admin renders fly-out submenu items off-canvas
 * until the parent is hovered, and Chromium refuses to click them there, so
 * following the offered destination is the reliable way to assert both that the
 * item is on offer and that what it offers renders.
 */
async function followPaymentsMenuItem(
	page: Page,
	name: string
): Promise< string > {
	const item = paymentsMenu( page ).getByRole( 'link', {
		name,
		exact: true,
	} );
	await expect( item ).toHaveCount( 1 );
	const href = await item.getAttribute( 'href' );
	if ( ! href ) {
		throw new Error( `The Payments menu item "${ name }" offers no link.` );
	}
	await page.goto( href );
	return href;
}

/**
 * Collect failed same-origin REST responses, so a surface that renders its
 * chrome while its data fetches quietly error is not mistaken for a healthy
 * load. Mirrors the frozen payouts-disputes release smoke's oracle.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): { failures: () => string[]; observed: () => number } {
	const failures: string[] = [];
	// Derived from the store's own base path rather than assumed absolute, so a
	// subdirectory install does not silently drop every REST response and leave
	// the oracle passing on an empty set.
	const restPrefix = `${ new URL( baseUrl ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	let observedRestResponses = 0;
	const storeRestPath = ( url: string ): string | null => {
		if ( ! url.startsWith( baseUrl ) ) {
			return null;
		}
		const { pathname, searchParams } = new URL( url );
		if (
			! pathname.startsWith( restPrefix ) &&
			! searchParams.has( 'rest_route' )
		) {
			return null;
		}
		return pathname;
	};

	page.on( 'response', ( response ) => {
		const path = storeRestPath( response.url() );
		if ( ! path ) {
			return;
		}
		observedRestResponses++;
		if ( response.status() >= 400 ) {
			failures.push( `${ response.status() } ${ path }` );
		}
	} );
	// A fetch that dies at the network layer never produces a response, so the
	// status listener above cannot see it. Requests the client itself cancelled
	// are ordinary navigation rather than failures.
	page.on( 'requestfailed', ( request ) => {
		const errorText = request.failure()?.errorText ?? 'unknown';
		if ( errorText === 'net::ERR_ABORTED' ) {
			return;
		}
		const path = storeRestPath( request.url() );
		if ( path ) {
			failures.push( `failed ${ path } (${ errorText })` );
		}
	} );

	return {
		failures: () => [ ...failures ],
		observed: () => observedRestResponses,
	};
}

// Assets belonging to plugins other than the one under test. The standing store
// carries unrelated third-party plugins — an installed woocommerce-subscriptions
// build 404s on its admin stylesheet on every admin screen — and Chromium echoes
// each such network failure as a console error. Those say nothing about the
// native payments surfaces. The exclusion is scoped by owner rather than being a
// blanket console filter: every same-origin failure that is not another plugin's
// asset still counts, and uncaught exceptions always count regardless of source.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;

function isAmbientForeignResource( url: string, baseUrl: string ): boolean {
	if ( ! url.startsWith( baseUrl ) ) {
		// Cross-origin resources (gravatars, w.org emoji) are never these
		// surfaces' responsibility.
		return true;
	}
	try {
		return THIRD_PARTY_PLUGIN_ASSET.test( new URL( url ).pathname );
	} catch {
		return false;
	}
}

/**
 * Collect uncaught page exceptions and console errors attributable to the
 * surface under test. The transactions contract is literally "page should load
 * without errors", so a rendered heading alone is not the oracle.
 */
function trackPageErrors( page: Page, baseUrl: string ): () => string[] {
	const errors: string[] = [];
	page.on( 'pageerror', ( error ) => {
		errors.push( `pageerror: ${ error.message }` );
	} );
	page.on( 'console', ( message ) => {
		if ( message.type() !== 'error' ) {
			return;
		}
		const source = message.location().url;
		if ( source && isAmbientForeignResource( source, baseUrl ) ) {
			return;
		}
		errors.push(
			`console: ${ message.text() } (${ source || 'no source' })`
		);
	} );
	return () => [ ...errors ];
}

/**
 * Assert the current document carries none of the release-smoke failure shapes.
 * Checked before any heading assertion, so a wp_die denial or a PHP fatal —
 * both of which replace the document — reports as what it is rather than as a
 * missing heading.
 */
async function expectNoFailureShapes( page: Page ): Promise< void > {
	await expect( page.getByText( DENIAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( FATAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( MIGRATION_TEXT ) ).toHaveCount( 0 );
}

/**
 * The precondition every contract here shares: a connected account behind an
 * enabled native gateway. A degraded store must fail here rather than pass by
 * rendering an onboarding shell that happens to carry the right heading.
 */
async function expectConnectedNativeStore(
	adminApi: APIRequestContext
): Promise< void > {
	const runtimeStatus = await readJson(
		await adminApi.get( RUNTIME_STATUS_API ),
		'Runtime status read'
	);
	expect(
		{
			account_connected: runtimeStatus.account_connected,
			gateway_enabled: runtimeStatus.gateway_enabled,
		},
		'the store must report a connected account and an enabled gateway'
	).toEqual( { account_connected: true, gateway_enabled: true } );
	const paymentsSettings = await readJson(
		await adminApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
}

/**
 * Format a minor-unit amount the way a merchant reads it, evaluated inside the
 * page so the browser's own locale and currency data are used. This is an
 * oracle over the surface's *inputs* — which amount and which currency it chose
 * — not over ECMA-402 itself: a card that rendered the pending balance, another
 * account's currency, or an unformatted integer cannot satisfy it.
 */
async function formatMinorUnits(
	page: Page,
	minorUnits: number,
	currency: string
): Promise< string > {
	return page.evaluate(
		( [ amount, code ]: [ number, string ] ) =>
			new Intl.NumberFormat( undefined, {
				style: 'currency',
				currency: code,
			} ).format( amount / 100 ),
		[ minorUnits, currency.toUpperCase() ] as [ number, string ]
	);
}

function amountForCurrency(
	amounts: MoneyAmount[] | undefined,
	currency: string
): number {
	const match = amounts?.find(
		( entry ) =>
			typeof entry.currency === 'string' &&
			entry.currency.toLowerCase() === currency
	);
	return typeof match?.amount === 'number' ? match.amount : 0;
}

/**
 * Reduce the authoritative account-balance response to the figures the balance
 * card claims to show, using the same currency inventory the card builds its
 * options from (available, pending, instant and last-paid payout currencies).
 *
 * The single-deposit-currency premise of this contract is enforced here: a
 * multi-currency account must fail loudly rather than quietly reconcile against
 * whichever currency happened to sort first.
 */
function readBalanceSnapshot( overview: DepositsOverview ): BalanceSnapshot {
	const currencies = new Set< string >();
	for ( const amounts of [
		overview.deposit?.last_paid,
		overview.balance?.available,
		overview.balance?.pending,
		overview.balance?.instant,
	] ) {
		for ( const entry of amounts ?? [] ) {
			if ( typeof entry.currency === 'string' && entry.currency ) {
				currencies.add( entry.currency.toLowerCase() );
			}
		}
	}

	if ( currencies.size !== 1 ) {
		throw new Error(
			`This contract covers a single deposit currency; the account reports ${
				currencies.size
			}: ${ [ ...currencies ].toSorted().join( ', ' ) }.`
		);
	}

	const [ currency ] = [ ...currencies ];
	const defaultCurrency = overview.account?.default_currency;
	if (
		typeof defaultCurrency !== 'string' ||
		defaultCurrency.toLowerCase() !== currency
	) {
		throw new Error(
			`The account's default currency (${ String(
				defaultCurrency
			) }) is not its only deposit currency (${ currency }).`
		);
	}

	const available = amountForCurrency(
		overview.balance?.available,
		currency
	);
	const pending = amountForCurrency( overview.balance?.pending, currency );

	return { currency, available, pending, total: available + pending };
}

async function readBalance(
	adminApi: APIRequestContext,
	description: string
): Promise< BalanceSnapshot > {
	return readBalanceSnapshot(
		await readJson< DepositsOverview >(
			await adminApi.get( DEPOSITS_OVERVIEW_API ),
			description
		)
	);
}

/**
 * Narrow one authoritative transaction to the identity and figures its row must
 * display, failing loudly rather than reconciling a rendered figure against
 * `undefined`.
 */
function requireTransactionFigures( transaction: TransactionRecord ): {
	id: string;
	amount: number;
	net: number;
	currency: string;
} {
	const id =
		typeof transaction.id === 'string' && transaction.id
			? transaction.id
			: String( transaction.transaction_id ?? '' );

	if ( ! id ) {
		throw new Error(
			'The authoritative transactions response carries no identifier for its first row.'
		);
	}
	if (
		typeof transaction.amount !== 'number' ||
		typeof transaction.net !== 'number' ||
		typeof transaction.currency !== 'string'
	) {
		throw new Error(
			'The authoritative transactions response carries no amount, net and currency for its first row.'
		);
	}

	return {
		id,
		amount: transaction.amount,
		net: transaction.net,
		currency: transaction.currency,
	};
}

/**
 * Narrow the authoritative transactions summary to the figures the surface
 * prints above its grid.
 */
function requireSummaryFigures( summary: {
	count?: unknown;
	total?: unknown;
	currency?: unknown;
} ): { count: number; total: number; currency: string } {
	if (
		typeof summary.count !== 'number' ||
		typeof summary.total !== 'number' ||
		typeof summary.currency !== 'string'
	) {
		throw new Error(
			'The authoritative transactions summary carries no count, total and currency.'
		);
	}

	return {
		count: summary.count,
		total: summary.total,
		currency: summary.currency,
	};
}

/**
 * The premise the transactions contract needs: a store with transactions to
 * render. An empty list cannot prove that rows join to authoritative data.
 */
function requireTransactionRows(
	rows: TransactionRecord[]
): TransactionRecord[] {
	if ( rows.length === 0 ) {
		throw new Error(
			'The store reports no transactions; this contract cannot prove that rows render.'
		);
	}
	return rows;
}

/**
 * Assert a money-movement list rendered its columns in the order its surface
 * declares them, which is what licenses the positional cell lookups the row
 * assertions use.
 */
async function expectColumnOrder(
	page: Page,
	columns: readonly string[]
): Promise< void > {
	const headers = page.getByRole( 'columnheader' );
	await expect( headers ).toHaveCount( columns.length );
	for ( const [ index, column ] of columns.entries() ) {
		// Case-insensitive because the table styles its headers in uppercase,
		// and prefix-anchored because the sorted column appends a direction
		// indicator to its label.
		await expect( headers.nth( index ) ).toHaveText(
			new RegExp( `^\\s*${ escapeRegExp( column ) }`, 'i' )
		);
	}
}

test.beforeEach( async ( { adminApi }, testInfo ) => {
	await fixtureManualCapture.before( {
		fixtureEnabled:
			process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE === 'true' &&
			testInfo.title === TRANSACTIONS_TEST_TITLE,
		read: () => readManualCapture( adminApi ),
		write: ( enabled ) => writeManualCapture( adminApi, enabled ),
	} );
} );

test.afterEach( async ( { adminApi } ) => {
	await fixtureManualCapture.after( {
		read: () => readManualCapture( adminApi ),
		write: ( enabled ) => writeManualCapture( adminApi, enabled ),
	} );
} );

test(
	"An authorized merchant reaches the native WooPayments overview from the store's own Payments navigation and the surface renders its account cards without denial, fatal, migration, or failed data-fetch errors",
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.overviewLoad,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await expectConnectedNativeStore( adminApi );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );

		// Route discovery, not a typed deep link: the store's Payments menu
		// must offer the native WooPayments surfaces at all, and its top-level
		// item must lead to the overview.
		await page.goto( '/wp-admin/index.php' );
		const menu = paymentsMenu( page );
		await expect( menu ).toHaveCount( 1 );
		for ( const item of [ 'Overview', 'Payouts', 'Transactions' ] ) {
			await expect(
				menu.getByRole( 'link', { name: item, exact: true } )
			).toHaveCount( 1 );
		}
		await menu
			.getByRole( 'link', { name: 'Payments', exact: true } )
			.click();

		// Contract: the primary payments administration surface renders.
		await expectNoFailureShapes( page );
		await expect(
			page.getByRole( 'heading', { name: 'Overview', exact: true } )
		).toBeVisible();
		// The route the navigation actually landed on is the native one, not a
		// legacy plugin path or a redirect back to the providers list.
		expect( page.url() ).toContain( 'path=%2Fwoopayments%2Foverview' );

		// Rendered, not an empty shell: the overview's own account cards are
		// present, and the balance card reached a settled state rather than
		// leaving its loading placeholder behind. The figures themselves belong
		// to the account-balance contract below.
		const balanceCard = page.locator( 'section' ).filter( {
			has: page.getByRole( 'heading', { name: 'Balance', exact: true } ),
		} );
		await expect( balanceCard ).toHaveCount( 1 );
		await expect(
			balanceCard.getByLabel( 'Total balance', { exact: true } )
		).toBeVisible();
		await expect( balanceCard ).toHaveAttribute( 'aria-busy', 'false' );
		await expect( page.getByText( 'Loading balance…' ) ).toHaveCount( 0 );
		for ( const card of [ 'Payouts', 'Account details' ] ) {
			await expect(
				page.getByRole( 'heading', { name: card, exact: true } )
			).toBeVisible();
		}

		// No store REST request behind the surface failed, and nothing this
		// surface owns threw or logged an error. The observed count guards the
		// oracle itself: an empty failure set means nothing if the collector
		// never matched a single request.
		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
	}
);

test(
	"An authorized merchant sees total and available balances on the native WooPayments overview that equal the authoritative account balance for the account's single deposit currency",
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.accountBalance,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await expectConnectedNativeStore( adminApi );

		// The authoritative truth this render must equal, read before the
		// surface is opened.
		const before = await readBalance( adminApi, 'Account balance read' );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );
		await page.goto( '/wp-admin/index.php' );
		await followPaymentsMenuItem( page, 'Overview' );
		await expectNoFailureShapes( page );
		await expect(
			page.getByRole( 'heading', { name: 'Overview', exact: true } )
		).toBeVisible();

		const balanceCard = page.locator( 'section' ).filter( {
			has: page.getByRole( 'heading', { name: 'Balance', exact: true } ),
		} );
		await expect( balanceCard ).toHaveCount( 1 );

		// Settled, not mid-flight: the card reports loading and failure through
		// its own live region, and keeps its heading either way.
		const totalBalance = balanceCard.getByLabel( 'Total balance', {
			exact: true,
		} );
		const availableFunds = balanceCard.getByLabel( 'Available funds', {
			exact: true,
		} );
		await expect( totalBalance ).toBeVisible();
		await expect( balanceCard ).toHaveAttribute( 'aria-busy', 'false' );
		await expect( balanceCard.getByRole( 'status' ) ).toHaveText( '' );

		// Single deposit currency, made observable: the card only offers a
		// balance-currency selector when the account reports more than one, so
		// its absence is the surface agreeing with the authoritative response.
		await expect(
			balanceCard.getByRole( 'combobox', { name: 'Balance currency' } )
		).toHaveCount( 0 );

		// Contract: both figures are comprehensible currency amounts for the
		// account's own currency, and they are the authoritative ones —
		// available funds is the available balance, total balance is available
		// plus pending.
		await expect( availableFunds ).toHaveText(
			await formatMinorUnits( page, before.available, before.currency )
		);
		await expect( totalBalance ).toHaveText(
			await formatMinorUnits( page, before.total, before.currency )
		);

		// One observation window: if the account's balance moved while the
		// surface was being read, the reconciliation above proved nothing and
		// this fails rather than passing on a coincidence.
		const after = await readBalance( adminApi, 'Account balance re-read' );
		expect( after ).toEqual( before );

		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
	}
);

test(
	TRANSACTIONS_TEST_TITLE,
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.transactionsLoad,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await expectConnectedNativeStore( adminApi );
		// The authoritative truth this list must equal, read with the surface's
		// own default query.
		const readTransactions = async ( description: string ) =>
			readJson< { data?: TransactionRecord[] } >(
				await adminApi.get(
					`${ TRANSACTIONS_API }?${ TRANSACTIONS_DEFAULT_QUERY }`
				),
				description
			);
		const readSummary = async ( description: string ) =>
			readJson< {
				count?: unknown;
				total?: unknown;
				currency?: unknown;
			} >(
				await adminApi.get(
					`${ TRANSACTIONS_SUMMARY_API }?${ TRANSACTIONS_DEFAULT_QUERY }`
				),
				description
			);

		const beforeList = await readTransactions( 'Transactions list read' );
		const expectedRows = requireTransactionRows( beforeList.data ?? [] );
		const firstTransaction = requireTransactionFigures( expectedRows[ 0 ] );
		const beforeSummary = requireSummaryFigures(
			await readSummary( 'Transactions summary read' )
		);

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );
		await page.goto( '/wp-admin/index.php' );
		await followPaymentsMenuItem( page, 'Transactions' );

		// Contract, first half: the transactions surface loads.
		await expectNoFailureShapes( page );
		await expect(
			page.getByRole( 'heading', {
				name: 'Transactions',
				exact: true,
			} )
		).toBeVisible();
		expect( page.url() ).toContain( 'path=%2Fwoopayments%2Ftransactions' );
		// Loaded or empty, both terminal — the surface keeps its heading and an
		// empty grid while a fetch is pending and when one fails without an
		// HTTP error status, and reports which through this live region.
		await expect( page.getByRole( 'status' ) ).toHaveText(
			TRANSACTIONS_TERMINAL
		);

		// Contract, second half: the rows a merchant reads are the store's own
		// transactions. The native list declares seven fields and no
		// subscription identifier; only what it does declare is asserted.
		await expectColumnOrder( page, TRANSACTION_COLUMNS );
		const transactionRows = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'link', { name: /transaction \S+$/ } ),
		} );
		await expect( transactionRows ).toHaveCount( expectedRows.length );

		// Row identity: the first authoritative transaction has its own row,
		// found by the provider identifier the surface publishes in that row's
		// accessible name, and that row carries its authoritative amount and
		// net.
		const firstRow = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'link', {
				name: new RegExp(
					`transaction ${ escapeRegExp( firstTransaction.id ) }$`
				),
			} ),
		} );
		await expect( firstRow ).toHaveCount( 1 );
		const firstRowCells = firstRow.getByRole( 'cell' );
		await expect( firstRowCells ).toHaveCount( TRANSACTION_COLUMNS.length );
		await expect(
			firstRowCells.nth( TRANSACTION_AMOUNT_CELL_INDEX )
		).toHaveText(
			await formatMinorUnits(
				page,
				firstTransaction.amount,
				firstTransaction.currency
			)
		);
		await expect(
			firstRowCells.nth( TRANSACTION_NET_CELL_INDEX )
		).toHaveText(
			await formatMinorUnits(
				page,
				firstTransaction.net,
				firstTransaction.currency
			)
		);

		// The list's own totals come from the authoritative summary route, so a
		// page that rendered 25 correct rows over a wrong result set still
		// fails.
		const summary = page.locator(
			'.woocommerce-woopayments-money-movement__summary'
		);
		await expect( summary ).toContainText(
			`${ beforeSummary.count } transactions`
		);
		await expect( summary ).toContainText(
			await formatMinorUnits(
				page,
				beforeSummary.total,
				beforeSummary.currency
			)
		);

		// One observation window: a store whose transactions moved mid-test
		// fails here instead of matching by accident.
		const afterList = await readTransactions( 'Transactions list re-read' );
		const afterSummary = requireSummaryFigures(
			await readSummary( 'Transactions summary re-read' )
		);
		expect( afterList.data ?? [] ).toHaveLength( expectedRows.length );
		expect( afterSummary ).toEqual( beforeSummary );

		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
	}
);
