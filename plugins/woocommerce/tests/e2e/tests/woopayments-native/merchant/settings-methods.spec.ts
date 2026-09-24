import type { APIRequestContext, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const FIXTURE_AUDIT_API =
	'/wp-json/wc-native-payments-e2e/v1/provider-fixture-audit';
const SETTINGS_PAGE_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments';

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for the settings smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

function storeRestPath( url: string, storeBase: string ): string | null {
	if ( ! url.startsWith( storeBase ) ) {
		return null;
	}
	const { pathname, searchParams } = new URL( url );
	const restPrefix = `${ new URL( storeBase ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	if (
		! pathname.startsWith( restPrefix ) &&
		! searchParams.has( 'rest_route' )
	) {
		return null;
	}
	return pathname;
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

async function proveSecretlessProviderSettingsRoundTrip(
	adminApi: APIRequestContext,
	paymentSettings: Record< string, unknown >
): Promise< void > {
	if ( process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE !== 'true' ) {
		return;
	}
	const originalName = paymentSettings.account_business_name;
	if ( typeof originalName !== 'string' || originalName === '' ) {
		throw new Error( 'Fixture settings exposed no account business name.' );
	}
	const changedName = 'Native CI REST provider proof';
	try {
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { account_business_name: changedName },
			} ),
			'Fixture provider-backed settings update'
		);
		const reread = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Fixture provider-backed settings re-read'
		);
		expect( reread.account_business_name ).toBe( changedName );
		const audit = await readJson(
			await adminApi.get( FIXTURE_AUDIT_API ),
			'Fixture provider request audit'
		);
		const requests = audit.requests;
		expect( Array.isArray( requests ) ).toBe( true );
		expect( requests ).toContainEqual( {
			method: 'POST',
			path: '/wpcom/v2/sites/777/wcpay/accounts',
			query: expect.objectContaining( {
				token: expect.any( String ),
				timestamp: expect.any( String ),
				nonce: expect.any( String ),
				signature: expect.any( String ),
			} ),
			body: {
				business_name: changedName,
				test_mode: true,
			},
		} );
	} finally {
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { account_business_name: originalName },
			} ),
			'Fixture provider-backed settings restoration'
		);
		const restored = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Fixture provider-backed settings restored-state read'
		);
		expect( restored.account_business_name ).toBe( originalName );
	}
}

async function logInAsAdmin( page: Page ): Promise< void > {
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

test(
	'An authorized merchant opens native WooPayments settings and sees a loaded surface without errors',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const paymentSettings = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( paymentSettings.is_wcpay_enabled ).toBe( true );
		await proveSecretlessProviderSettingsRoundTrip(
			adminApi,
			paymentSettings
		);

		const failures: string[] = [];
		let observedRestResponses = 0;
		page.on( 'response', ( response ) => {
			const path = storeRestPath( response.url(), storeBase );
			if ( ! path ) {
				return;
			}
			observedRestResponses++;
			if ( response.status() >= 400 ) {
				failures.push( `${ response.status() } ${ path }` );
			}
		} );
		page.on( 'requestfailed', ( request ) => {
			const path = storeRestPath( request.url(), storeBase );
			const errorText = request.failure()?.errorText ?? 'unknown';
			if ( path && errorText !== 'net::ERR_ABORTED' ) {
				failures.push( `failed ${ path } (${ errorText })` );
			}
		} );
		page.on( 'pageerror', ( error ) => failures.push( error.message ) );

		await logInAsAdmin( page );
		await page.goto( SETTINGS_PAGE_PATH );

		await expect(
			page.getByRole( 'region', { name: 'General' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'checkbox', {
				name: 'Issue an authorization on checkout and capture later',
			} )
		).toBeVisible();
		await expect(
			page.getByRole( 'region', { name: 'Express checkouts' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Save changes' } )
		).toBeVisible();
		expect( observedRestResponses ).toBeGreaterThan( 0 );
		expect( failures ).toEqual( [] );
	}
);
