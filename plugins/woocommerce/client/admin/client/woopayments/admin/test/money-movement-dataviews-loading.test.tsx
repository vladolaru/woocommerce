/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { WooPaymentsMoneyMovementDataViews } from '../money-movement/dataviews';

// The real DataViews, from its CommonJS build: these tests read its loading states.
jest.mock( '@wordpress/dataviews/wp', () =>
	jest.requireActual( '@wordpress/dataviews' )
);

type Row = { id: string; date: string };

const fields = [ { id: 'date', label: 'Date' } ];
const firstPageRows: Row[] = [
	{ id: 'txn_1', date: 'First page row' },
	{ id: 'txn_2', date: 'Another row' },
];

const renderList = (
	page: number,
	rows: Row[],
	isLoading: boolean,
	summary?: Array< { label: string; value: string } >
) => (
	<WooPaymentsMoneyMovementDataViews
		summary={ summary }
		fields={ fields }
		rows={ rows }
		view={ {
			type: 'table',
			page,
			perPage: 2,
			fields: [ 'date' ],
		} }
		onChangeView={ jest.fn() }
		total={ 4 }
		isLoading={ isLoading }
		title="Transactions"
		searchLabel="Search transactions"
	/>
);

describe( 'WooPaymentsMoneyMovementDataViews loading a new page', () => {
	let scrollTo: jest.SpyInstance;
	let getBoundingClientRect: jest.SpyInstance;

	beforeEach( () => {
		scrollTo = jest
			.spyOn( window, 'scrollTo' )
			.mockImplementation( () => undefined );
		// The list card's top has scrolled above the viewport.
		getBoundingClientRect = jest
			.spyOn( window.Element.prototype, 'getBoundingClientRect' )
			.mockReturnValue( { top: -400 } as ReturnType<
				typeof window.Element.prototype.getBoundingClientRect
			> );
	} );

	afterEach( () => {
		scrollTo.mockRestore();
		getBoundingClientRect.mockRestore();
	} );

	it( 'replaces the previous rows with the spinner while the new page loads', () => {
		const { container, rerender } = render(
			renderList( 1, firstPageRows, false )
		);

		expect( screen.getByText( 'First page row' ) ).toBeInTheDocument();

		rerender( renderList( 2, firstPageRows, true ) );

		expect(
			screen.queryByText( 'First page row' )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { name: 'Transactions' } )
		).toBeInTheDocument();
		expect(
			container.querySelector( '.dataviews-loading .components-spinner' )
		).toBeInTheDocument();
		expect(
			container.querySelector( '.dataviews-loading-more' )
		).not.toBeInTheDocument();
	} );

	// Client 11.1.0 `@woocommerce/components` TableCard: while loading, the footer shows
	// TableSummaryPlaceholder instead of the summary (packages/js/components/src/table/index.tsx:230-231).
	it( 'replaces the previous totals with the summary placeholder while the list reloads', () => {
		const loadedSummary = [ { label: 'transactions', value: '4' } ];
		const { container, rerender } = render(
			renderList( 1, firstPageRows, false, loadedSummary )
		);

		expect( screen.getByText( 'transactions' ) ).toBeInTheDocument();

		rerender( renderList( 1, firstPageRows, true, loadedSummary ) );

		expect( screen.queryByText( 'transactions' ) ).not.toBeInTheDocument();
		expect(
			container.querySelector( '.woocommerce-table__summary.is-loading' )
		).toBeInTheDocument();
	} );

	it( 'scrolls the list top into view on a page change, not on the first load', () => {
		const { rerender } = render( renderList( 1, [], true ) );
		rerender( renderList( 1, firstPageRows, false ) );

		expect( scrollTo ).not.toHaveBeenCalled();

		rerender( renderList( 2, firstPageRows, true ) );

		expect( scrollTo ).toHaveBeenCalledTimes( 1 );
	} );
} );
