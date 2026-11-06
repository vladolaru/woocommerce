/**
 * External dependencies
 */
import type * as Hooks from '@wordpress/hooks';

let applyFilters: typeof Hooks.applyFilters;
let removeFilter: typeof Hooks.removeFilter;

const settings: Record< string, unknown > = {
	// Shapes the server registers: label and code of each currency customers ordered in (client 11.1.0
	// includes/multi-currency/Analytics.php:125-154), and symbols by code (AnalyticsService::get_currency_symbols()).
	customerCurrencies: [
		{ label: 'US dollar', value: 'USD' },
		{ label: 'Euro', value: 'EUR' },
	],
	customerCurrencySymbols: { USD: '$', EUR: '€' },
};

jest.mock( '@woocommerce/settings', () => ( {
	getSetting: ( name: string, fallback: unknown ) =>
		name in settings ? settings[ name ] : fallback,
} ) );

describe( 'multi-currency-analytics', () => {
	// Fresh copies of the hooks and of the module for each test, so every test starts with all the filters the module
	// adds on load: one test removes its report-table callback.
	beforeEach( async () => {
		jest.resetModules();
		( { applyFilters, removeFilter } = await import( '@wordpress/hooks' ) );
		await import( '../index' );
	} );

	it( 'adds a customer currency advanced filter listing the currencies by name', () => {
		const result = applyFilters(
			'woocommerce_admin_orders_report_advanced_filters',
			{ title: 'Orders', filters: { status: { labels: {} } } }
		) as {
			filters: Record<
				string,
				{ input?: { options: { value: string }[] } }
			>;
		};

		expect( Object.keys( result.filters ) ).toEqual( [
			'currency',
			'status',
		] );
		expect(
			result.filters.currency.input?.options.map( ( o ) => o.value )
		).toEqual( [ 'EUR', 'USD' ] );
	} );

	it( 'adds a customer currency column to the orders table only', () => {
		// The table data wc-admin's ReportTable passes to the filter (client/analytics/components/report-table/index.js:184-191).
		const table = {
			endpoint: 'orders',
			headers: [ { key: 'date' } ],
			rows: [ [ { display: '1 May', value: '2026-05-01' } ] ],
			items: { data: [ { order_currency: 'EUR' } ] },
		};

		const result = applyFilters(
			'woocommerce_admin_report_table',
			table
		) as typeof table;

		expect( result.headers.map( ( h ) => h.key ) ).toEqual( [
			'date',
			'customer_currency',
		] );
		expect( result.rows[ 0 ][ 1 ] ).toEqual( {
			display: 'EUR',
			value: 'EUR',
		} );

		const products = { ...table, endpoint: 'products' };
		expect(
			applyFilters( 'woocommerce_admin_report_table', products )
		).toBe( products );
	} );

	it( 'adds a customer currency report filter with an all-currencies choice first', () => {
		const result = applyFilters(
			'woocommerce_admin_orders_report_filters',
			[ { param: 'filter' } ]
		) as { param: string; filters?: { value: string }[] }[];

		expect( result.map( ( f ) => f.param ) ).toEqual( [
			'currency',
			'filter',
		] );
		expect( result[ 0 ].filters?.map( ( f ) => f.value ) ).toEqual( [
			'all',
			'EUR',
			'USD',
		] );
	} );

	it( 'shows the symbol of the currency the report is filtered to', () => {
		const config = { symbol: '$', precision: 2 };

		expect(
			applyFilters( 'woocommerce_admin_report_currency', config, {
				currency: 'EUR',
			} )
		).toEqual( { symbol: '€', precision: 2 } );
		expect(
			applyFilters( 'woocommerce_admin_report_currency', config, {} )
		).toBe( config );
		expect(
			applyFilters( 'woocommerce_admin_report_currency', config, {
				currency: 'JPY',
			} )
		).toBe( config );
	} );

	it( 'keeps the plugin namespace, so code that removes the plugin callbacks still can', () => {
		// The client registers its report-table callback under 'woocommerce-payments' (includes/multi-currency/client/analytics/index.js:67-69).
		removeFilter(
			'woocommerce_admin_report_table',
			'woocommerce-payments'
		);
		const table = {
			endpoint: 'orders',
			headers: [],
			rows: [ [] ],
			items: { data: [ { order_currency: 'EUR' } ] },
		};

		expect( applyFilters( 'woocommerce_admin_report_table', table ) ).toBe(
			table
		);
	} );
} );
