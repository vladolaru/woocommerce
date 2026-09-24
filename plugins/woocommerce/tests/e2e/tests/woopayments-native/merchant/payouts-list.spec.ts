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
 * One provider-free navigation and load smoke against the Core-owned native
 * payouts route.
 *
 * REST-controller PHPUnit tests own the payout list, summary, and status-query
 * payloads; component Jest tests own their rendered figures and filter state.
 * This smoke covers installed navigation, settled loading, and page/REST errors.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_IDS = {
	payoutsLoad:
		'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-deposits.spec.ts:11::Merchant deposits › Load the deposits list page',
} as const;

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';

const PAYOUTS_TERMINAL = /^(Payout history loaded\.|No payouts found\.)$/;

// The failure shapes these release smokes exist to catch, matching the frozen
// payouts-disputes smoke.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
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

test(
	'An authorized merchant opens native payout history from Payments navigation and sees a loaded surface without errors',
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
		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );

		await logInAsAdmin( page );
		await page.goto( '/wp-admin/index.php' );
		await page.goto( await paymentsMenuItemUrl( page, 'Payouts' ) );

		await expectNoFailureShapes( page );
		await expect(
			page.getByRole( 'heading', { name: 'Payout history', exact: true } )
		).toBeVisible();
		expect( page.url() ).toContain( 'path=%2Fwoopayments%2Fpayouts' );
		await expect( page.getByRole( 'status' ) ).toHaveText(
			PAYOUTS_TERMINAL
		);
		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
	}
);
