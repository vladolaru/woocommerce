/**
 * Internal dependencies
 */
import * as moneyMovementQuery from '../money-movement/query';
import {
	buildMoneyMovementRoutePath,
	dataViewsViewToMoneyMovementQuery,
	moneyMovementQueryToDataViewsView,
	parseMoneyMovementQuery,
	serializeMoneyMovementQuery,
} from '../money-movement/query';

describe( 'WooPayments money movement query helpers', () => {
	it( 'parses provider subroute query state with defaults and supported filters', () => {
		const query = parseMoneyMovementQuery(
			{
				pathname: '/woopayments/transactions',
				search: '?page=2&pagesize=50&sort=amount&direction=asc&search=Ada&loan_id_is=loan_123&deposit_id=po_123&currency_is=ignored&store_currency_is=eur&type_is=charge&status_is=paid&status_is=refunded&status_is_not=failed&date_after=2026-06-01&date_before=2026-06-19&created_after=ignored&wc-admin-page=ignored',
			},
			{
				pagesize: 25,
				sort: 'date',
				direction: 'desc',
			}
		);

		expect( query ).toEqual( {
			page: 2,
			pagesize: 50,
			sort: 'amount',
			direction: 'asc',
			search: 'Ada',
			loan_id_is: 'loan_123',
			deposit_id: 'po_123',
			store_currency_is: 'eur',
			type_is: 'charge',
			status_is: [ 'paid', 'refunded' ],
			status_is_not: 'failed',
			date_after: '2026-06-01',
			date_before: '2026-06-19',
		} );
	} );

	it( 'keeps page one and maps perPage fallback to native pagesize', () => {
		const query = parseMoneyMovementQuery( '?perPage=10', {
			pagesize: 25,
			sort: 'created',
			direction: 'desc',
		} );

		expect( query ).toEqual( {
			page: 1,
			pagesize: 10,
			sort: 'created',
			direction: 'desc',
		} );
	} );

	it( 'accepts legacy per_page URLs and maps them to native pagesize', () => {
		const query = parseMoneyMovementQuery( '?per_page=50', {
			pagesize: 25,
			sort: 'created',
			direction: 'desc',
		} );

		expect( query ).toEqual( {
			page: 1,
			pagesize: 50,
			sort: 'created',
			direction: 'desc',
		} );
	} );

	it( 'prefers settings-shell paged state when reloading provider routes', () => {
		const query = parseMoneyMovementQuery(
			'?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&paged=2&pagesize=25&sort=date&direction=desc&search=Ada',
			{
				page: 1,
				pagesize: 10,
				sort: 'created',
				direction: 'asc',
			}
		);

		expect( query ).toEqual( {
			page: 2,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			search: 'Ada',
		} );
	} );

	it( 'serializes only the provider subroute query contract', () => {
		const queryString = serializeMoneyMovementQuery( {
			page: 1,
			pagesize: 25,
			sort: 'date',
			direction: 'desc',
			search: 'Ada',
			deposit_id: 'po_123',
			status_is: [ 'paid', 'refunded' ],
			wc_admin_page: 'ignored',
		} );
		const params = new URLSearchParams( queryString );

		expect( params.get( 'page' ) ).toBe( '1' );
		expect( params.get( 'pagesize' ) ).toBe( '25' );
		expect( params.get( 'sort' ) ).toBe( 'date' );
		expect( params.get( 'direction' ) ).toBe( 'desc' );
		expect( params.get( 'search' ) ).toBe( 'Ada' );
		expect( params.get( 'deposit_id' ) ).toBe( 'po_123' );
		expect( params.getAll( 'status_is' ) ).toEqual( [
			'paid',
			'refunded',
		] );
		expect( params.has( 'wc_admin_page' ) ).toBe( false );
	} );

	it( 'serializes authorizations query state without transaction-only filters', () => {
		const queryHelpers = moneyMovementQuery as unknown as Record<
			string,
			( query: Record< string, unknown > ) => string
		>;

		expect(
			typeof queryHelpers.serializeWooPaymentsAuthorizationsQuery
		).toBe( 'function' );

		const queryString =
			queryHelpers.serializeWooPaymentsAuthorizationsQuery( {
				page: 2,
				pagesize: 25,
				sort: 'capture_by',
				direction: 'desc',
				search: 'Ada',
				date_after: '2026-06-01',
				loan_id_is: 'loan_test',
				deposit_id: 'po_test',
				store_currency_is: 'usd',
				type_is: 'charge',
				status_is: 'paid',
			} );
		const params = new URLSearchParams( queryString );

		expect( params.get( 'page' ) ).toBe( '2' );
		expect( params.get( 'pagesize' ) ).toBe( '25' );
		expect( params.get( 'sort' ) ).toBe( 'created' );
		expect( params.get( 'direction' ) ).toBe( 'desc' );
		expect( params.get( 'search' ) ).toBe( 'Ada' );
		expect( params.get( 'date_after' ) ).toBe( '2026-06-01' );
		expect( params.has( 'loan_id_is' ) ).toBe( false );
		expect( params.has( 'deposit_id' ) ).toBe( false );
		expect( params.has( 'store_currency_is' ) ).toBe( false );
		expect( params.has( 'type_is' ) ).toBe( false );
		expect( params.has( 'status_is' ) ).toBe( false );
	} );

	it( 'builds route paths without resurrecting wc-admin navigation state', () => {
		const routePath = buildMoneyMovementRoutePath(
			'/woopayments/transactions',
			{
				page: 3,
				pagesize: 25,
				loan_id_is: 'loan_123',
			}
		);

		expect( routePath ).toBe(
			'/woopayments/transactions?page=3&pagesize=25&loan_id_is=loan_123'
		);
		expect( routePath ).not.toContain( 'wc-settings' );
		expect( routePath ).not.toContain( 'wc-admin' );
	} );

	it( 'converts native query state to a DataViews table view', () => {
		const view = moneyMovementQueryToDataViewsView(
			{
				page: 4,
				pagesize: 100,
				sort: 'created',
				direction: 'asc',
				search: 'Ada',
				status_is: 'paid',
				status_is_not: 'failed',
				store_currency_is: 'usd',
			},
			{
				fields: [ 'date', 'status', 'amount' ],
				titleField: 'date',
			}
		);

		expect( view ).toMatchObject( {
			type: 'table',
			page: 4,
			perPage: 100,
			search: 'Ada',
			sort: {
				field: 'created',
				direction: 'asc',
			},
			fields: [ 'date', 'status', 'amount' ],
			titleField: 'date',
		} );
		expect( view.filters ).toEqual(
			expect.arrayContaining( [
				{ field: 'status', operator: 'is', value: 'paid' },
				{ field: 'status', operator: 'isNot', value: 'failed' },
				{ field: 'currency', operator: 'is', value: 'usd' },
			] )
		);
	} );

	it.each( [
		[ 'date_after', 'after', '2026-06-01' ],
		[ 'date_before', 'before', '2026-06-19' ],
		[ 'date_between', 'between', [ '2026-06-01', '2026-06-19' ] ],
	] )(
		'maps %s between native query state and one DataViews Date filter',
		( queryParam, operator, value ) => {
			const view = moneyMovementQueryToDataViewsView( {
				[ queryParam ]: value,
			} );

			expect( view.filters ).toContainEqual( {
				field: 'date',
				operator,
				value,
			} );

			const roundTrippedQuery = dataViewsViewToMoneyMovementQuery( {
				type: 'table',
				filters: [
					{ field: 'status', operator: 'is', value: 'paid' },
					{
						field: 'date',
						operator: operator as never,
						value,
					},
				],
			} );

			expect( roundTrippedQuery ).toMatchObject( {
				status_is: 'paid',
				[ queryParam ]: value,
			} );
		}
	);

	it.each( [
		[
			'sorts a reversed range',
			'date_between',
			'between',
			[ '2026-06-19', '2026-06-01' ],
			[ '2026-06-01', '2026-06-19' ],
		],
		[
			'omits an incomplete range',
			'date_between',
			'between',
			[ '2026-06-01' ],
			undefined,
		],
		[
			'omits a malformed scalar',
			'date_after',
			'after',
			'06-01-2026',
			undefined,
		],
		[
			'omits an impossible calendar date',
			'date_before',
			'before',
			'2026-02-31',
			undefined,
		],
		[
			'omits repeated scalar values',
			'date_after',
			'after',
			[ '2026-06-01', '2026-06-02' ],
			undefined,
		],
		[
			'omits a range with extra values',
			'date_between',
			'between',
			[ '2026-06-01', '2026-06-19', '2026-06-20' ],
			undefined,
		],
	] )(
		'%s when mapping local Date filter state',
		( _case, queryParam, operator, value, expectedValue ) => {
			const query = dataViewsViewToMoneyMovementQuery( {
				type: 'table',
				filters: [
					{
						field: 'date',
						operator: operator as never,
						value,
					},
				],
			} );
			const view = moneyMovementQueryToDataViewsView( {
				[ queryParam ]: value,
			} );

			if ( expectedValue === undefined ) {
				expect( query ).not.toHaveProperty( queryParam );
				expect( view.filters ).toEqual( [] );
				return;
			}

			expect( query ).toHaveProperty( queryParam, expectedValue );
			expect( view.filters ).toContainEqual( {
				field: 'date',
				operator,
				value: expectedValue,
			} );
		}
	);

	it( 'converts DataViews table state back to native REST query params', () => {
		const query = dataViewsViewToMoneyMovementQuery( {
			type: 'table',
			page: 5,
			perPage: 10,
			search: 'Ada',
			sort: {
				field: 'created',
				direction: 'desc',
			},
			fields: [ 'date', 'amount' ],
			filters: [
				{ field: 'status', operator: 'isNot', value: 'failed' },
				{ field: 'currency', operator: 'is', value: 'usd' },
				{ field: 'deposit_id', operator: 'is', value: 'po_123' },
				{
					field: 'date_before',
					operator: 'is',
					value: '2026-06-19',
				},
			],
		} );

		expect( query ).toEqual( {
			page: 5,
			pagesize: 10,
			sort: 'created',
			direction: 'desc',
			search: 'Ada',
			status_is_not: 'failed',
			store_currency_is: 'usd',
			deposit_id: 'po_123',
			date_before: '2026-06-19',
		} );
		expect( query ).not.toHaveProperty( 'fields' );
		expect( query ).not.toHaveProperty( 'layout' );
	} );
} );
