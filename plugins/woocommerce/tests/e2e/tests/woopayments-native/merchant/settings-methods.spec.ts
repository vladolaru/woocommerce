import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

test.use( { storageState: ADMIN_STATE_PATH } );

const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const FIXTURE_AUDIT_API = 'wc-native-payments-e2e/v1/provider-fixture-audit';
const RUNTIME_STATUS_API = 'wc-native-payments-e2e/v1/status';
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

test(
	'An authorized merchant opens native WooPayments settings and sees a loaded surface without errors',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { restApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const paymentSettings = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
			.data as Record< string, unknown >;
		expect( paymentSettings.is_wcpay_enabled ).toBe( true );

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
				name: 'Enable manual capture',
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

test(
	'A business name saved through the WooPayments settings route reaches the platform account',
	{ tag: [ tags.WOOPAYMENTS_NATIVE ] },
	async ( { restApi } ) => {
		// Only the secretless CI fixture records the platform requests the store sends.
		test.skip(
			process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE !== 'true',
			'Needs the secretless CI provider fixture, which records platform requests.'
		);
		const blogId = (
			( await restApi.get( RUNTIME_STATUS_API ) ).data as Record<
				string,
				unknown
			>
		 ).wpcom_blog_id;
		expect(
			blogId,
			'the store must report its WordPress.com blog id'
		).toEqual( expect.any( Number ) );
		expect( blogId ).toBeGreaterThan( 0 );

		const originalName = (
			( await restApi.get( PAYMENTS_SETTINGS_API ) ).data as Record<
				string,
				unknown
			>
		 ).account_business_name;
		expect(
			originalName,
			'the fixture settings must expose an account business name'
		).toEqual( expect.stringMatching( /\S/ ) );
		const changedName = 'Native CI REST provider proof';
		try {
			await restApi.post( PAYMENTS_SETTINGS_API, {
				account_business_name: changedName,
			} );
			const reread = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
				.data as Record< string, unknown >;
			expect( reread.account_business_name ).toBe( changedName );
			const audit = ( await restApi.get( FIXTURE_AUDIT_API ) )
				.data as Record< string, unknown >;
			expect( audit.requests ).toContainEqual( {
				method: 'POST',
				path: `/wpcom/v2/sites/${ blogId }/wcpay/accounts`,
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
		}

		const restored = ( await restApi.get( PAYMENTS_SETTINGS_API ) )
			.data as Record< string, unknown >;
		expect( restored.account_business_name ).toBe( originalName );
	}
);
