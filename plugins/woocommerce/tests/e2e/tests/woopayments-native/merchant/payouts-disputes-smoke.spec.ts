import type { APIRequestContext, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

// The transactions list contract is deliberately absent: it is claimed and
// annotated by the ledger's own target for that row,
// merchant/overview-transactions.spec.ts. This smoke still loads that surface,
// because the disputes contract's
// no-failed-fetch oracle is only meaningful across the payments admin app as
// a whole, but it does not claim the contract.
const DISPUTES_CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-disputes.spec.ts:15::Merchant disputes › Load the disputes list page';
const SUBSCRIPTIONS_CONTRACT_ID =
	'default::chromium::tests/e2e/specs/subscriptions/merchant/merchant-subscriptions-settings.spec.ts:13::WooCommerce › Settings › Subscriptions › Merchant should be able to load WooCommerce Subscriptions settings tab';

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const DISPUTES_API = '/wp-json/wc/v3/payments/disputes';
const TRANSACTIONS_PATH =
	'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Ftransactions';
const DISPUTES_PATH =
	'/wp-admin/admin.php?page=wc-admin&path=%2Fpayments%2Fdisputes';
const SUBSCRIPTIONS_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=subscriptions';

// The terminal states each payments surface reports through its live
// region. Loaded and empty are both terminal — the contracts ask for a
// terminal state, not a seeded store — while pending and error are not, and
// the error state additionally flips the region's role away from status.
const TRANSACTIONS_TERMINAL =
	/^(Transactions loaded\.|No transactions found\.)$/;
const DISPUTES_TERMINAL = /^(Disputes loaded\.|No disputes found\.)$/;

// The failure shapes these release smokes exist to catch. Each surface must
// reach a terminal, readable state without any of them.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

async function readJson(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Record< string, unknown > > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Record< string, unknown >;
}

/**
 * Collect failed REST responses these surfaces fetch from the store, so a
 * page that renders its chrome while its data fetches quietly error is not
 * mistaken for a healthy load — which is the exact failure shape the disputes
 * contract names. Scoped to the store's own REST API: third-party origins are
 * out of scope, and a missing static asset from an unrelated extension build
 * is a local packaging gap rather than a payments surface failure.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): { failures: () => string[]; observed: () => number } {
	const failures: string[] = [];
	// Derived from the store's own base path rather than assumed absolute, so
	// a subdirectory install does not silently drop every REST response and
	// leave the oracle passing on an empty set.
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
	// A fetch that dies at the network layer never produces a response, so
	// the status listener above cannot see it — and a surface that renders a
	// benign empty state around the missing data would otherwise pass on
	// exactly the silent-failure shape this oracle exists to catch. Requests
	// the client itself cancelled are excluded: leaving a surface aborts its
	// still-inflight fetches, which is ordinary navigation rather than a
	// failure. Every server-side death — reset, refused, empty response —
	// reports a different error and is still counted.
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

/**
 * Assert an admin surface reached a readable terminal state: none of the
 * release-smoke failure shapes appears, and its own heading is visible.
 * The failure texts are checked first so a wp_die denial or a fatal — which
 * replace the document entirely — reports as what it is rather than as a
 * missing heading.
 */
async function expectSurfaceLoaded(
	page: Page,
	heading: string
): Promise< void > {
	await expect( page.getByText( DENIAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( FATAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( MIGRATION_TEXT ) ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'heading', { name: heading, exact: true } )
	).toBeVisible();
}

/**
 * Assert a payments list surface actually finished loading its data, through
 * the same live region a screen-reader user consumes. This is the load proof:
 * the surface renders its heading and an empty grid while a fetch is still
 * pending, and keeps rendering them when the fetch fails in a way that never
 * produces an HTTP error status — in which case this region flips to an alert
 * role and says so. Asserting its terminal message is therefore the difference
 * between "the chrome rendered" and "the surface loaded".
 */
async function expectDataLoaded(
	page: Page,
	terminalMessage: RegExp,
	columnHeader: string
): Promise< void > {
	// Loaded or empty, both terminal — the contract asks for a terminal
	// state, not for a seeded store. What must not pass is the pending
	// state or the error state, which this region reports differently and
	// which flips its role away from status on failure.
	await expect( page.getByRole( 'status' ) ).toHaveText( terminalMessage );
	// The grid the merchant reads, keyed on a named column header rather than
	// on whichever table happens to come first in the document.
	await expect(
		page.getByRole( 'columnheader', { name: columnHeader } )
	).toBeVisible();
}

test(
	'payments admin surfaces load for an authorized merchant without denial, fatal, or failed data fetches',
	{
		annotation: [ DISPUTES_CONTRACT_ID ].map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Precondition guard: these are release page-load smokes for a
		// connected, enabled store. A store whose account or gateway
		// degraded must fail here rather than pass by loading an empty
		// surface that happens to render its heading.
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
		const restTracker = trackFailedRestResponses( page, storeBase );

		// Clear first, matching the harness's own admin login: a stale
		// session cookie would redirect wp-login.php to wp-admin and leave
		// the form fill below hunting a field that is not there.
		await page.context().clearCookies();
		await page.goto( 'wp-login.php' );
		await waitForWordPressLoginReady( page );
		await page
			.getByLabel( 'Username or Email Address' )
			.fill( ADMIN_USERNAME );
		await page
			.getByRole( 'textbox', { name: 'Password' } )
			.fill( ADMIN_PASSWORD );
		await page.getByRole( 'button', { name: 'Log In' } ).click();
		await page.waitForURL( '**/wp-admin/**' );

		// Loaded, but not claimed: the transactions surface belongs to the
		// transaction-navigation pilot's contract. It is visited so the
		// failed-fetch oracle below spans the whole payments admin app.
		await page.goto( TRANSACTIONS_PATH );
		await expectSurfaceLoaded( page, 'Transactions' );
		await expectDataLoaded( page, TRANSACTIONS_TERMINAL, 'Date' );

		// Contract: the disputes list surface loads without fatal,
		// migration, capability, or data-fetch errors.
		await page.goto( DISPUTES_PATH );
		await expectSurfaceLoaded( page, 'Disputes' );
		await expectDataLoaded( page, DISPUTES_TERMINAL, 'Dispute' );
		// The terminal state above accepts the empty list, because the
		// contract asks for a terminal state and not a seeded store — which
		// means it alone cannot tell an empty list from a malformed payload
		// that yields one. Asserting the route's own response shape closes
		// that gap independently of how many disputes the store holds.
		const disputesPayload = await readJson(
			await adminApi.get( DISPUTES_API ),
			'Disputes list read'
		);
		expect( Array.isArray( disputesPayload.data ) ).toBe( true );

		// No store REST request behind any visited surface failed. The
		// observed count guards the oracle itself: an empty failure set
		// means nothing if the collector never matched a single request.
		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
	}
);

test.describe( 'WooCommerce Subscriptions extension compatibility', () => {
	test.skip(
		process.env.E2E_WOOPAYMENTS_EXTENSION_COMPAT !== 'true',
		'E2E_WOOPAYMENTS_EXTENSION_COMPAT is required with a real WooCommerce Subscriptions installation.'
	);

	test(
		'payments admin surfaces load for an authorized merchant without denial, fatal, or failed data fetches',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: SUBSCRIPTIONS_CONTRACT_ID,
				},
				{
					type: 'profile-unavailable',
					description:
						'E2E_WOOPAYMENTS_EXTENSION_COMPAT is required with a real WooCommerce Subscriptions installation.',
				},
			],
			tag: [ tags.WOOPAYMENTS_NATIVE, '@woopayments-extension-compat' ],
		},
		async ( { adminApi, page } ) => {
			const paymentsSettings = await readJson(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'Payments settings read'
			);
			expect( paymentsSettings.is_subscriptions_plugin_active ).toBe(
				true
			);
			await page.goto( SUBSCRIPTIONS_SETTINGS_PATH );
			await expectSurfaceLoaded( page, 'Subscriptions' );
			await expect(
				page.locator( '.nav-tab-wrapper' ).getByRole( 'link', {
					name: 'Subscriptions',
					exact: true,
				} )
			).toHaveClass( /nav-tab-active/ );
			await expect(
				page.getByRole( 'button', { name: 'Save changes' } )
			).toBeVisible();
		}
	);
} );
