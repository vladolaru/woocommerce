import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

/**
 * Native WooPayments payout history (merchant-payouts-spec).
 *
 * Two provider-free merchant contracts against the Core-owned native payouts
 * route (client/admin/client/woopayments/admin/routes.tsx →
 * /woopayments/payouts, rendered by admin/payouts.tsx) and the authoritative
 * routes behind it, /wc/v3/payments/deposits and its summary
 * (WooPaymentsDepositsRestController).
 *
 * Oracle discipline: every rendered figure is joined to the authoritative REST
 * response the store itself serves, read independently through the admin API
 * before the surface is opened and re-read afterwards, so a stale or wrong
 * result set cannot pass and a store whose payouts moved mid-test fails loudly
 * instead of matching by accident.
 *
 * Filter bound: the client suite's row drove an "Advanced filters" control and
 * selected Status = Pending. The native payouts surface propagates a payout
 * status predicate end to end — `status_is` is a money-movement filter param
 * (money-movement/query.ts), payouts.tsx forwards it to both the list and the
 * summary read, and moneyMovementQueryToDataViewsView reflects it back into the
 * view — but its status field declares no `elements` and no `filterBy`
 * (payouts.tsx), so DataViews offers no interactive filter control: the surface
 * renders no "Add filter" affordance at all, unlike the transactions list. The
 * filter contract below therefore proves the propagation that exists — the
 * scoped list, its totals and its inclusion/exclusion against the authoritative
 * routes for the same status — and asserts nothing about a control that is not
 * there. The absent control is recorded as a parity gap rather than faked, and
 * its absence is deliberately not asserted either, so building the control does
 * not break this test.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_IDS = {
	payoutsLoad:
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-deposits.spec.ts:11::Merchant deposits › Load the deposits list page',
	payoutsFilter:
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-deposits.spec.ts:28::Merchant deposits › Select deposits list advanced filters',
} as const;

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const DEPOSITS_API = '/wp-json/wc/v3/payments/deposits';
const DEPOSITS_SUMMARY_API = '/wp-json/wc/v3/payments/deposits/summary';

// The payouts list's own default query (payouts.tsx): page 1, 25 rows, newest
// dispatch first.
const PAYOUTS_DEFAULT_QUERY = 'page=1&pagesize=25&sort=date&direction=desc';

// The payout statuses this filter contract discriminates between. The client
// row selected Pending; proving a filter needs both a status the store has and
// one it does not, so the scoped list can be shown to include and to exclude.
const INCLUDED_STATUS = 'paid';
const EXCLUDED_STATUS = 'pending';

// The payouts list's declared field set, in the order the surface renders it.
// Asserting the order is what licenses the positional cell lookups below.
const PAYOUT_COLUMNS = [ 'Dispatch date', 'Status', 'Amount' ] as const;
const PAYOUT_STATUS_CELL_INDEX = 1;
const PAYOUT_AMOUNT_CELL_INDEX = 2;

const PAYOUTS_TERMINAL = /^(Payout history loaded\.|No payouts found\.)$/;

// The failure shapes these release smokes exist to catch, matching the frozen
// payouts-disputes smoke.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

interface PayoutRecord {
	id?: unknown;
	status?: unknown;
	amount?: unknown;
	currency?: unknown;
}

interface PayoutsSummary {
	count?: unknown;
	total?: unknown;
	currency?: unknown;
}

interface PayoutsView {
	rows: PayoutRecord[];
	summary: PayoutsSummary;
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
 * WooPaymentsAdminNavigationController.
 */
function paymentsMenu( page: Page ): Locator {
	return page.locator( '#adminmenu > li' ).filter( {
		has: page.getByRole( 'link', { name: 'Payments', exact: true } ),
	} );
}

/**
 * Read the destination the Payments menu offers for one of its items, asserting
 * on the way that the item is offered at all.
 *
 * wp-admin renders fly-out submenu items off-canvas until the parent is
 * hovered, and Chromium refuses to click them there, so the item's own offered
 * destination is followed instead of a hard-coded route.
 */
async function paymentsMenuItemUrl(
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
// payouts surface. The exclusion is scoped by owner rather than being a blanket
// console filter: every same-origin failure that is not another plugin's asset
// still counts, and uncaught exceptions always count regardless of source.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;

function isAmbientForeignResource( url: string, baseUrl: string ): boolean {
	if ( ! url.startsWith( baseUrl ) ) {
		// Cross-origin resources (gravatars, w.org emoji) are never this
		// surface's responsibility.
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
 * surface under test.
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
 * The precondition both contracts share: a connected account behind an enabled
 * native gateway. A degraded store must fail here rather than pass by rendering
 * an onboarding shell that happens to carry the right heading.
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
 * — not over ECMA-402 itself.
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

/**
 * Read the authoritative payout list and summary the surface itself requests,
 * optionally scoped to one payout status.
 */
async function readPayouts(
	adminApi: APIRequestContext,
	description: string,
	status?: string
): Promise< PayoutsView > {
	const query = status
		? `${ PAYOUTS_DEFAULT_QUERY }&status_is=${ encodeURIComponent(
				status
		  ) }`
		: PAYOUTS_DEFAULT_QUERY;
	const list = await readJson< { data?: PayoutRecord[] } >(
		await adminApi.get( `${ DEPOSITS_API }?${ query }` ),
		`${ description } list read`
	);
	const summary = await readJson< PayoutsSummary >(
		await adminApi.get( `${ DEPOSITS_SUMMARY_API }?${ query }` ),
		`${ description } summary read`
	);

	return { rows: list.data ?? [], summary };
}

function payoutId( payout: PayoutRecord ): string {
	const id = typeof payout.id === 'string' ? payout.id : '';
	if ( ! id ) {
		throw new Error(
			'The authoritative payouts response carries a payout without an identifier.'
		);
	}
	return id;
}

/**
 * Narrow one authoritative payout to the fields its row must display, failing
 * loudly rather than reconciling a rendered figure against `undefined`.
 */
function requirePayoutFigures( payout: PayoutRecord ): {
	amount: number;
	currency: string;
	status: string;
} {
	if (
		typeof payout.amount !== 'number' ||
		typeof payout.currency !== 'string' ||
		typeof payout.status !== 'string'
	) {
		throw new Error(
			'The authoritative payouts response carries no amount, currency and status for its first payout.'
		);
	}

	return {
		amount: payout.amount,
		currency: payout.currency,
		status: payout.status,
	};
}

/**
 * Narrow the authoritative payouts summary to the figures the surface prints
 * above its grid.
 */
function requireSummaryFigures( summary: PayoutsSummary ): {
	count: number;
	total: number;
	currency: string;
} {
	if (
		typeof summary.count !== 'number' ||
		typeof summary.total !== 'number' ||
		typeof summary.currency !== 'string'
	) {
		throw new Error(
			'The authoritative payouts summary carries no count, total and currency.'
		);
	}

	return {
		count: summary.count,
		total: summary.total,
		currency: summary.currency,
	};
}

/**
 * The premise the load contract needs: a store with payouts to render. An empty
 * list cannot prove that rows join to authoritative data.
 */
function requirePayoutRows( rows: PayoutRecord[] ): PayoutRecord[] {
	if ( rows.length === 0 ) {
		throw new Error(
			'The store reports no payouts; this contract cannot prove that rows render.'
		);
	}
	return rows;
}

/**
 * The premise the filter contract needs: two payout statuses that actually
 * discriminate on this store. Without it a surface that ignored the predicate
 * entirely would still satisfy every assertion below.
 */
function requireDiscriminatingScopes(
	includedIds: string[],
	excludedIds: string[]
): void {
	if ( includedIds.length === 0 ) {
		throw new Error(
			`The store reports no ${ INCLUDED_STATUS } payouts; this contract cannot prove a scoped list.`
		);
	}
	if (
		includedIds.length === excludedIds.length ||
		includedIds.some( ( id ) => excludedIds.includes( id ) )
	) {
		throw new Error(
			`The ${ INCLUDED_STATUS } and ${ EXCLUDED_STATUS } payout scopes do not discriminate on this store; the filter oracle would be vacuous.`
		);
	}
}

/**
 * The terminal announcement the payouts live region must carry for a scope of
 * the given size. Both messages are terminal — these contracts ask for a
 * settled surface, not a seeded store — but which one is correct is decided by
 * the authoritative result set, not by whatever the surface chose to say.
 */
function payoutsTerminalMessage( rowCount: number ): string {
	return rowCount === 0 ? 'No payouts found.' : 'Payout history loaded.';
}

/**
 * Assert the payouts list rendered its columns in the order its surface
 * declares them, which is what licenses the positional cell lookups below.
 */
async function expectPayoutColumnOrder( page: Page ): Promise< void > {
	const headers = page.getByRole( 'columnheader' );
	await expect( headers ).toHaveCount( PAYOUT_COLUMNS.length );
	for ( const [ index, column ] of PAYOUT_COLUMNS.entries() ) {
		// Case-insensitive because the table styles its headers in uppercase,
		// and prefix-anchored because the sorted column appends a direction
		// indicator to its label.
		await expect( headers.nth( index ) ).toHaveText(
			new RegExp( `^\\s*${ escapeRegExp( column ) }`, 'i' )
		);
	}
}

/**
 * The rows a merchant reads, keyed on the payout-details link every row
 * publishes with its provider payout identifier, rather than on document order.
 */
function payoutRows( page: Page ): Locator {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'link', { name: /view payout details for \S+$/ } ),
	} );
}

function payoutRow( page: Page, id: string ): Locator {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'link', {
			name: new RegExp(
				`view payout details for ${ escapeRegExp( id ) }$`
			),
		} ),
	} );
}

/**
 * Await the payouts surface's terminal state through the same live region a
 * screen-reader user consumes. Loaded and empty are both terminal — these
 * contracts ask for a terminal state, not a seeded store — while the pending
 * state and the error state are reported differently, and the error state flips
 * the region's role away from status.
 */
async function expectPayoutsSurfaceSettled(
	page: Page,
	authoritativeRowCount: number
): Promise< void > {
	await expectNoFailureShapes( page );
	await expect(
		page.getByRole( 'heading', { name: 'Payout history', exact: true } )
	).toBeVisible();
	// Wait for either terminal state first, so the exact-message assertion that
	// follows reports a wrong announcement rather than a still-pending one.
	await expect( page.getByRole( 'status' ) ).toHaveText( PAYOUTS_TERMINAL );
	await expect( page.getByRole( 'status' ) ).toHaveText(
		payoutsTerminalMessage( authoritativeRowCount )
	);
}

test(
	'An authorized merchant loads the native WooPayments payout history without errors and its rows and totals equal the authoritative payouts the store reports',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.payoutsLoad,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await expectConnectedNativeStore( adminApi );

		// The authoritative truth this list must equal, read with the surface's
		// own default query before the surface is opened.
		const before = await readPayouts( adminApi, 'Payouts' );
		const [ firstPayout ] = requirePayoutRows( before.rows );
		const firstPayoutId = payoutId( firstPayout );
		const firstPayoutFigures = requirePayoutFigures( firstPayout );
		const beforeSummary = requireSummaryFigures( before.summary );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );

		// Route discovery, not a typed deep link: the store's own Payments menu
		// must offer payout history, and what it offers must render.
		await page.goto( '/wp-admin/index.php' );
		await page.goto( await paymentsMenuItemUrl( page, 'Payouts' ) );

		// Contract, first half: payout history loads and reaches a terminal
		// state without a capability, fatal, migration or fetch failure.
		await expectPayoutsSurfaceSettled( page, before.rows.length );
		expect( page.url() ).toContain( 'path=%2Fwoopayments%2Fpayouts' );

		// Contract, second half: the rows a merchant reads are the store's own
		// payouts.
		await expectPayoutColumnOrder( page );
		await expect( payoutRows( page ) ).toHaveCount( before.rows.length );

		// Row identity: the first authoritative payout has its own row, found
		// by the provider identifier the surface publishes in that row's
		// accessible name, carrying that payout's own status and amount.
		const firstRow = payoutRow( page, firstPayoutId );
		await expect( firstRow ).toHaveCount( 1 );
		const firstRowCells = firstRow.getByRole( 'cell' );
		await expect( firstRowCells ).toHaveCount( PAYOUT_COLUMNS.length );
		// The status cell is compared case-insensitively against the
		// authoritative status: the surface presents it as prose, and this
		// contract is about which status is shown, not about its capitalisation.
		await expect(
			firstRowCells.nth( PAYOUT_STATUS_CELL_INDEX )
		).toHaveText(
			new RegExp(
				`^${ escapeRegExp(
					firstPayoutFigures.status.replace( /_/g, ' ' )
				) }$`,
				'i'
			)
		);
		await expect(
			firstRowCells.nth( PAYOUT_AMOUNT_CELL_INDEX )
		).toHaveText(
			await formatMinorUnits(
				page,
				firstPayoutFigures.amount,
				firstPayoutFigures.currency
			)
		);

		// The list's own totals come from the authoritative summary route, so a
		// page that rendered correct rows over a wrong result set still fails.
		const summary = page.locator(
			'.woocommerce-woopayments-money-movement__summary'
		);
		await expect( summary ).toContainText(
			`${ beforeSummary.count } payouts`
		);
		await expect( summary ).toContainText(
			await formatMinorUnits(
				page,
				beforeSummary.total,
				beforeSummary.currency
			)
		);

		// One observation window: a store whose payouts moved mid-test fails
		// here instead of matching by accident.
		const after = await readPayouts( adminApi, 'Payouts re-read' );
		expect( after.rows.map( payoutId ) ).toEqual(
			before.rows.map( payoutId )
		);
		expect( requireSummaryFigures( after.summary ) ).toEqual(
			beforeSummary
		);

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
	'A payout-status query scopes the native WooPayments payout history and its totals to that status, including and excluding exactly the payouts the authoritative routes report for it',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.payoutsFilter,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		await expectConnectedNativeStore( adminApi );

		// The authoritative truth for both scopes. The premise this contract
		// needs is that the two statuses actually discriminate: without that,
		// a surface that ignored the predicate entirely would still match.
		const included = await readPayouts(
			adminApi,
			`Payouts (${ INCLUDED_STATUS })`,
			INCLUDED_STATUS
		);
		const excluded = await readPayouts(
			adminApi,
			`Payouts (${ EXCLUDED_STATUS })`,
			EXCLUDED_STATUS
		);
		const includedIds = included.rows.map( payoutId );
		const excludedIds = excluded.rows.map( payoutId );
		requireDiscriminatingScopes( includedIds, excludedIds );
		const includedSummary = requireSummaryFigures( included.summary );
		const excludedSummary = requireSummaryFigures( excluded.summary );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );
		await page.goto( '/wp-admin/index.php' );
		const payoutsUrl = await paymentsMenuItemUrl( page, 'Payouts' );

		// Scope one: the payout-status predicate reaches the surface, and the
		// list and its totals are exactly the authoritative ones for it.
		const summary = page.locator(
			'.woocommerce-woopayments-money-movement__summary'
		);
		await page.goto( `${ payoutsUrl }&status_is=${ INCLUDED_STATUS }` );
		await expectPayoutsSurfaceSettled( page, includedIds.length );
		expect( page.url() ).toContain( `status_is=${ INCLUDED_STATUS }` );
		await expectPayoutColumnOrder( page );
		await expect( payoutRows( page ) ).toHaveCount( includedIds.length );
		for ( const id of includedIds ) {
			await expect( payoutRow( page, id ) ).toHaveCount( 1 );
		}
		await expect( summary ).toContainText(
			`${ includedSummary.count } payouts`
		);
		await expect( summary ).toContainText(
			await formatMinorUnits(
				page,
				includedSummary.total,
				includedSummary.currency
			)
		);

		// Scope two: the same predicate with a different status excludes those
		// payouts, and says so — the scoped list is the authoritative one for
		// that status, and every payout the first scope showed is gone.
		await page.goto( `${ payoutsUrl }&status_is=${ EXCLUDED_STATUS }` );
		// The terminal announcement asserted here is the one the authoritative
		// scope demands, so an empty scope must say so rather than leave a
		// merchant with a bare grid.
		await expectPayoutsSurfaceSettled( page, excludedIds.length );
		expect( page.url() ).toContain( `status_is=${ EXCLUDED_STATUS }` );
		await expect( payoutRows( page ) ).toHaveCount( excludedIds.length );
		for ( const id of includedIds ) {
			await expect( payoutRow( page, id ) ).toHaveCount( 0 );
		}
		for ( const id of excludedIds ) {
			await expect( payoutRow( page, id ) ).toHaveCount( 1 );
		}
		await expect( summary ).toContainText(
			`${ excludedSummary.count } payouts`
		);
		await expect( summary ).toContainText(
			await formatMinorUnits(
				page,
				excludedSummary.total,
				excludedSummary.currency
			)
		);

		// One observation window: a store whose payouts moved mid-test fails
		// here instead of matching by accident.
		const includedAfter = await readPayouts(
			adminApi,
			`Payouts (${ INCLUDED_STATUS }) re-read`,
			INCLUDED_STATUS
		);
		const excludedAfter = await readPayouts(
			adminApi,
			`Payouts (${ EXCLUDED_STATUS }) re-read`,
			EXCLUDED_STATUS
		);
		expect( includedAfter.rows.map( payoutId ) ).toEqual( includedIds );
		expect( excludedAfter.rows.map( payoutId ) ).toEqual( excludedIds );

		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
	}
);
