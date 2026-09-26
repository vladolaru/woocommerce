import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

test.use( { storageState: ADMIN_STATE_PATH } );

const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const FIXTURE_AUDIT_API = 'wc-native-payments-e2e/v1/provider-fixture-audit';
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

async function proveSecretlessProviderSettingsRoundTrip(
	restApi: ApiClient,
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
		await restApi.post( PAYMENTS_SETTINGS_API, {
			account_business_name: changedName,
		} );
		const reread = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
			.data as Record< string, unknown >;
		expect( reread.account_business_name ).toBe( changedName );
		const audit = ( await restApi.get( FIXTURE_AUDIT_API ) ).data as Record<
			string,
			unknown
		>;
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
		await restApi.post( PAYMENTS_SETTINGS_API, {
			account_business_name: originalName,
		} );
		const restored = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
			.data as Record< string, unknown >;
		expect( restored.account_business_name ).toBe( originalName );
	}
}

test(
	'An authorized merchant opens native WooPayments settings and sees a loaded surface without errors',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { restApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const paymentSettings = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
			.data as Record< string, unknown >;
		expect( paymentSettings.is_wcpay_enabled ).toBe( true );
		await proveSecretlessProviderSettingsRoundTrip(
			restApi,
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
