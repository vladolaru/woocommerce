/**
 * Internal dependencies
 */
import {
	buildReportsFeesQueryFromView,
	parseReportsFeesViewFromSearch,
	serializeReportsBalanceQuery,
	serializeReportsFeesExportQuery,
	serializeReportsFeesListQuery,
	serializeReportsFeesSummaryQuery,
	serializeReportsFeesViewToSearch,
} from '../reports/query';

describe( 'WooPayments Reports query helpers', () => {
	const timezonePattern = /^[+-]\d{2}:\d{2}$/;

	it( 'serializes Balance currency in lowercase', () => {
		expect(
			serializeReportsBalanceQuery( {
				date_start: '2026-06-01T00:00:00Z',
				date_end: '2026-06-19T23:59:59Z',
				currency: 'USD',
			} )
		).toBe(
			'date_start=2026-06-01T00%3A00%3A00Z&date_end=2026-06-19T23%3A59%3A59Z&currency=usd'
		);
	} );

	it( 'maps DataViews state to default Fees REST query params', () => {
		expect( buildReportsFeesQueryFromView( { type: 'table' } ) ).toEqual( {
			page: 1,
			per_page: 25,
			sort: 'date',
			direction: 'desc',
			user_timezone: expect.stringMatching( timezonePattern ),
		} );
	} );

	it( 'maps DataViews pagination, sorting, search, and filters to Fees query params', () => {
		expect(
			buildReportsFeesQueryFromView( {
				type: 'table',
				page: 3,
				perPage: 10,
				sort: {
					field: 'payment_method',
					direction: 'asc',
				},
				search: 'txn_123',
				filters: [
					{
						field: 'date',
						operator: 'is',
						value: [ '2026-06-01', '2026-06-19' ],
					},
					{
						field: 'payment_method',
						operator: 'is',
						value: 'card',
					},
					{
						field: 'type',
						operator: 'isAny',
						value: [ 'charge', 'refund' ],
					},
				],
			} )
		).toEqual( {
			page: 3,
			per_page: 10,
			sort: 'source',
			direction: 'asc',
			date_between: [
				'2026-06-01T00:00:00.000Z',
				'2026-06-19T23:59:59.999Z',
			],
			payment_method_type: 'card',
			type: [ 'charge', 'refund' ],
			search: [ 'txn_123' ],
			user_timezone: expect.stringMatching( timezonePattern ),
		} );
	} );

	it.each( [
		[
			'Previous month',
			[ '2026-05-01', '2026-05-31' ],
			[ '2026-05-01T00:00:00.000Z', '2026-05-31T23:59:59.999Z' ],
		],
		[
			'Previous year',
			[ '2025-01-01', '2025-12-31' ],
			[ '2025-01-01T00:00:00.000Z', '2025-12-31T23:59:59.999Z' ],
		],
	] )(
		'serializes %s date filters with inclusive UTC boundaries',
		( _, dates, expectedDates ) => {
			const query = buildReportsFeesQueryFromView( {
				type: 'table',
				filters: [
					{
						field: 'date',
						operator: 'between',
						value: dates,
					},
				],
			} );

			expect( query ).toEqual(
				expect.objectContaining( {
					date_between: expectedDates,
				} )
			);
			expect( serializeReportsFeesListQuery( query ) ).toContain(
				`date_between%5B%5D=${ encodeURIComponent(
					expectedDates[ 0 ]
				) }&date_between%5B%5D=${ encodeURIComponent(
					expectedDates[ 1 ]
				) }`
			);
		}
	);

	it( 'normalizes a scalar type filter to the Reports array schema', () => {
		expect(
			buildReportsFeesQueryFromView( {
				type: 'table',
				filters: [
					{
						field: 'type',
						operator: 'is',
						value: 'charge',
					},
				],
			} )
		).toEqual(
			expect.objectContaining( {
				type: [ 'charge' ],
			} )
		);
	} );

	it( 'keeps export-only identity params out of list and summary query strings', () => {
		const query = {
			page: 2,
			per_page: 50,
			sort: 'date',
			direction: 'desc' as const,
			type: [ 'charge' ],
			user_timezone: '+03:00',
			user_email: 'merchant@example.com',
			locale: 'en_US',
		};

		expect( serializeReportsFeesListQuery( query ) ).toBe(
			'page=2&per_page=50&sort=date&direction=desc&type%5B%5D=charge&user_timezone=%2B03%3A00'
		);
		expect( serializeReportsFeesSummaryQuery( query ) ).toBe(
			'type%5B%5D=charge&user_timezone=%2B03%3A00'
		);
		expect( serializeReportsFeesExportQuery( query ) ).toBe(
			'type%5B%5D=charge&user_timezone=%2B03%3A00&user_email=merchant%40example.com&locale=en_US'
		);
	} );

	describe( 'Fees URL state (client 11.1.0 `reports/fees/use-fees-url-sync.ts`)', () => {
		it( 'reads sort, paging, search and filters from the client link params', () => {
			// The client writes arrays through `addQueryArgs`, as `search[0]` and `date_between[0]`.
			expect(
				parseReportsFeesViewFromSearch(
					'?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Freports&report_tab=fees&orderby=amount&order=asc&paged=3&per_page=50&search%5B0%5D=txn_1&date_between%5B0%5D=2026-04-01&date_between%5B1%5D=2026-04-30&payment_method_type=card&type=charge'
				)
			).toEqual( {
				page: 3,
				perPage: 50,
				sort: { field: 'amount', direction: 'asc' },
				search: 'txn_1',
				filters: [
					{
						field: 'date',
						operator: 'between',
						value: [ '2026-04-01', '2026-04-30' ],
					},
					{ field: 'payment_method', operator: 'is', value: 'card' },
					{ field: 'type', operator: 'is', value: 'charge' },
				],
			} );
		} );

		it( 'falls back to the default view when the URL has no Fees params', () => {
			expect(
				parseReportsFeesViewFromSearch( '?report_tab=fees' )
			).toEqual( {
				page: 1,
				perPage: 25,
				sort: { field: 'date', direction: 'desc' },
				search: '',
				filters: [],
			} );
		} );

		it( 'reads a same-day range as "on" and single dates as "before" and "after"', () => {
			expect(
				parseReportsFeesViewFromSearch(
					'?date_between[]=2026-04-02&date_between[]=2026-04-02'
				).filters
			).toEqual( [
				{ field: 'date', operator: 'on', value: '2026-04-02' },
			] );
			expect(
				parseReportsFeesViewFromSearch( '?date_before=2026-04-02' )
					.filters
			).toEqual( [
				{ field: 'date', operator: 'before', value: '2026-04-02' },
			] );
			expect(
				parseReportsFeesViewFromSearch( '?date_after=2026-04-02' )
					.filters
			).toEqual( [
				{ field: 'date', operator: 'after', value: '2026-04-02' },
			] );
		} );

		it( 'writes the view with the client param names and reads it back unchanged', () => {
			const view = {
				page: 2,
				perPage: 50,
				sort: { field: 'fees', direction: 'asc' as const },
				search: 'txn_1',
				filters: [
					{ field: 'date', operator: 'on', value: '2026-04-02' },
					{ field: 'payment_method', operator: 'is', value: 'card' },
					{ field: 'type', operator: 'is', value: 'refund' },
				],
			};
			const search = serializeReportsFeesViewToSearch( view );

			expect( search ).toBe(
				'orderby=fees&order=asc&paged=2&per_page=50&search%5B%5D=txn_1&date_between%5B%5D=2026-04-02&date_between%5B%5D=2026-04-02&payment_method_type=card&type=refund'
			);
			expect( parseReportsFeesViewFromSearch( search ) ).toEqual( view );
		} );

		it( 'keeps the native "is any of" type filter across a reload', () => {
			const view = {
				page: 1,
				perPage: 25,
				sort: { field: 'date', direction: 'desc' as const },
				search: '',
				filters: [
					{
						field: 'type',
						operator: 'isAny',
						value: [ 'charge', 'refund' ],
					},
				],
			};

			expect(
				parseReportsFeesViewFromSearch(
					serializeReportsFeesViewToSearch( view )
				)
			).toEqual( view );
		} );
	} );
} );
