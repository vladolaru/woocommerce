/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import {
	buildWooPaymentsDocumentUrl,
	getWooPaymentsDocuments,
	getWooPaymentsDocumentsAccount,
	getWooPaymentsDocumentsSummary,
	saveWooPaymentsVatDetails,
	validateWooPaymentsVatNumber,
} from '../documents/data';
import {
	buildDocumentsRoutePath,
	dataViewsViewToDocumentsQuery,
	documentsQueryToDataViewsView,
	parseDocumentsQuery,
} from '../documents/query';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

describe( 'WooPayments Documents data helpers', () => {
	// Client 11.1.0 `formatDateValue()` takes the merchant's day; pin the browser at +03:00 so the UTC boundaries are fixed.
	let timezoneSpy: jest.SpyInstance;
	beforeEach( () => {
		timezoneSpy = jest
			.spyOn( Date.prototype, 'getTimezoneOffset' )
			.mockReturnValue( -180 );
	} );
	afterEach( () => {
		timezoneSpy.mockRestore();
	} );

	beforeEach( () => {
		mockApiFetch.mockReset();
		mockApiFetch.mockResolvedValue( {} );
		( global as unknown as { wpApiSettings?: unknown } ).wpApiSettings = {
			root: 'https://site.test/wp-json/',
			nonce: 'rest-nonce',
		};
	} );

	it( 'reads the preloaded account Documents block without a request, as the client reads accountStatus', async () => {
		const settingsWindow = window as typeof window & {
			wcSettings?: Record< string, unknown >;
		};
		const previous = settingsWindow.wcSettings;
		const documents = {
			enabled: true,
			has_submitted_vat_data: false,
			country: 'CH',
		};
		settingsWindow.wcSettings = {
			admin: { woopaymentsSettings: { accountDocuments: documents } },
		};

		try {
			await expect( getWooPaymentsDocumentsAccount() ).resolves.toEqual( {
				documents,
			} );
			expect( mockApiFetch ).not.toHaveBeenCalled();
		} finally {
			settingsWindow.wcSettings = previous;
		}
	} );

	it( 'loads account document metadata from the native account summary when nothing was preloaded', async () => {
		await getWooPaymentsDocumentsAccount();

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc-admin/settings/payments/woopayments/account',
			method: 'GET',
		} );
	} );

	it( 'preserves Documents list endpoint paths and query names', async () => {
		await getWooPaymentsDocuments( {
			page: 2,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			match: 'all',
			date_before: '2026-06-18',
			date_after: '2026-06-01',
			date_between: [ '2026-06-01', '2026-06-18' ],
			type_is: 'vat_invoice',
			type_is_not: 'unknown',
			ignored: 'drop-me',
		} );

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/documents?page=2&pagesize=25&sort=date&direction=desc&match=all&date_before=2026-06-18+20%3A59%3A59&date_after=2026-05-31+21%3A00%3A00&date_between%5B%5D=2026-05-31+21%3A00%3A00&date_between%5B%5D=2026-06-18+20%3A59%3A59&type_is=vat_invoice&type_is_not=unknown',
			method: 'GET',
		} );
	} );

	it( 'preserves Documents summary filter-only endpoint paths', async () => {
		await getWooPaymentsDocumentsSummary( {
			page: 9,
			pagesize: 100,
			sort: 'date',
			direction: 'asc',
			match: 'any',
			type_is: 'vat_invoice',
			date_after: '2026-06-01',
		} );

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/documents/summary?match=any&date_after=2026-05-31+21%3A00%3A00&type_is=vat_invoice',
			method: 'GET',
		} );
	} );

	it( "keeps the list sort in the client's `orderby`/`order` URL params", () => {
		// Client 11.1.0 `documents/filters/config.ts:38` and `data/documents/hooks.ts:59-60`.
		expect(
			parseDocumentsQuery( '?paged=2&orderby=date&order=asc' )
		).toEqual(
			expect.objectContaining( { sort: 'date', direction: 'asc' } )
		);

		const routePath = buildDocumentsRoutePath( '/woopayments/documents', {
			page: 2,
			sort: 'date',
			direction: 'asc',
		} );
		const params = new URLSearchParams( routePath.split( '?' )[ 1 ] );

		expect( params.get( 'orderby' ) ).toBe( 'date' );
		expect( params.get( 'order' ) ).toBe( 'asc' );
		expect( params.has( 'sort' ) ).toBe( false );
		expect( params.has( 'direction' ) ).toBe( false );
	} );

	it( 'maps DataViews filters to preserved REST query params', () => {
		expect(
			dataViewsViewToDocumentsQuery( {
				type: 'table',
				filters: [
					{
						field: 'date',
						operator: 'before',
						value: '2026-06-18',
					},
					{
						field: 'date',
						operator: 'after',
						value: '2026-06-01',
					},
					{
						field: 'type',
						operator: 'isNot',
						value: 'vat_invoice',
					},
				],
			} )
		).toEqual(
			expect.objectContaining( {
				date_before: '2026-06-18',
				date_after: '2026-06-01',
				type_is_not: 'vat_invoice',
			} )
		);
	} );

	it( 'builds reference-compatible document download URLs', () => {
		expect( buildWooPaymentsDocumentUrl( 'vat_invoice_123' ) ).toBe(
			'https://site.test/wp-json/wc/v3/payments/documents/vat_invoice_123?_wpnonce=rest-nonce'
		);
	} );

	it( 'preserves VAT validation and save endpoint paths', async () => {
		await validateWooPaymentsVatNumber( 'DE 123456789' );
		await saveWooPaymentsVatDetails( {
			vat_number: 'DE 123456789',
			name: 'Ada Bakery',
			address: '1 Market Street',
		} );

		expect( mockApiFetch ).toHaveBeenNthCalledWith( 1, {
			path: '/wc/v3/payments/vat/DE%20123456789',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenNthCalledWith( 2, {
			path: '/wc/v3/payments/vat',
			method: 'POST',
			data: {
				vat_number: 'DE 123456789',
				name: 'Ada Bakery',
				address: '1 Market Street',
			},
		} );
	} );

	it( 'leaves the VAT number out when saving without one, as the client does', async () => {
		await saveWooPaymentsVatDetails( {
			vat_number: null,
			name: 'Ada Bakery',
			address: '1 Market Street',
		} );

		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/vat',
			method: 'POST',
			data: {
				name: 'Ada Bakery',
				address: '1 Market Street',
			},
		} );
	} );

	it.each( [
		[ '?date_before=2026-06-18', 'before', '2026-06-18' ],
		[ '?date_after=2026-06-01', 'after', '2026-06-01' ],
		[
			'?date_between%5B%5D=2026-06-01&date_between%5B%5D=2026-06-18',
			'between',
			[ '2026-06-01', '2026-06-18' ],
		],
	] )(
		'round-trips a %s Date filter between the URL and the view',
		( search, operator, value ) => {
			const view = documentsQueryToDataViewsView(
				parseDocumentsQuery( search )
			);

			expect( view.filters ).toEqual( [
				{ field: 'date', operator, value },
			] );
			expect(
				documentsQueryToDataViewsView(
					dataViewsViewToDocumentsQuery( view, {} )
				).filters
			).toEqual( view.filters );
		}
	);
} );
