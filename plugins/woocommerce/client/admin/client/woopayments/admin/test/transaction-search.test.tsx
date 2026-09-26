/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import {
	WooPaymentsTransactionSearch,
	wooPaymentsTransactionSearchCompleter,
} from '../money-movement/transaction-search';
import { getWooPaymentsTransactionSearch } from '../money-movement/data';

const mockSearch = jest.fn( () => null );

jest.mock( '@woocommerce/components', () => ( {
	Search: ( props: Record< string, unknown > ) => mockSearch( props ),
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsTransactionSearch: jest.fn(),
} ) );

const mockGetTransactionSearch =
	getWooPaymentsTransactionSearch as jest.MockedFunction<
		typeof getWooPaymentsTransactionSearch
	>;

const getSearchProps = () =>
	mockSearch.mock.calls[ mockSearch.mock.calls.length - 1 ][ 0 ] as Record<
		string,
		unknown
	>;

const getCompleterOptions = () =>
	wooPaymentsTransactionSearchCompleter.options as (
		query: string
	) => Promise< Array< { label: string } > >;

describe( 'WooPaymentsTransactionSearch', () => {
	beforeEach( () => {
		mockSearch.mockClear();
		mockGetTransactionSearch.mockReset();
	} );

	it( 'uses canonical server suggestions and recovers from lookup failures', async () => {
		mockGetTransactionSearch.mockResolvedValueOnce( [
			{ label: 'Order #1520' },
		] );

		await expect( getCompleterOptions()( '1520' ) ).resolves.toEqual( [
			{ label: 'Order #1520' },
		] );
		expect( mockGetTransactionSearch ).toHaveBeenCalledWith( '1520' );
		expect(
			wooPaymentsTransactionSearchCompleter.getOptionCompletion( {
				label: 'Order #1520',
			} )
		).toEqual( {
			key: 'Order #1520',
			label: 'Order #1520',
		} );

		mockGetTransactionSearch.mockRejectedValueOnce(
			new Error( 'Lookup failed' )
		);
		await expect( getCompleterOptions()( '1520' ) ).resolves.toEqual( [] );
	} );

	it( 'offers free text customer name and billing email search', () => {
		const options =
			wooPaymentsTransactionSearchCompleter.getFreeTextOptions?.(
				'MA05 Searchable'
			);

		expect( options ).toHaveLength( 1 );
		render( options?.[ 0 ].label );
		expect(
			screen.getByText(
				'All transactions with customer names or billing emails that include MA05 Searchable'
			)
		).toBeInTheDocument();
	} );

	it( 'exposes an accessible controlled search and keeps only the latest selection', () => {
		const onChange = jest.fn();

		render(
			<WooPaymentsTransactionSearch value="Ada" onChange={ onChange } />
		);

		expect( getSearchProps() ).toEqual(
			expect.objectContaining( {
				ariaLabel: 'Search transactions',
				placeholder:
					'Search by order number, customer name, or billing email',
				selected: [ { key: 'Ada', label: 'Ada' } ],
				showClearButton: true,
				inlineTags: true,
				allowFreeTextSearch: true,
				type: 'custom',
				autocompleter: wooPaymentsTransactionSearchCompleter,
			} )
		);

		const handleChange = getSearchProps().onChange as (
			values: Array< { key: string; label: string } >
		) => void;
		handleChange( [
			{ key: 'Ada', label: 'Ada' },
			{ key: 'Order #1520', label: 'Order #1520' },
		] );
		handleChange( [] );

		expect( onChange ).toHaveBeenNthCalledWith( 1, 'Order #1520' );
		expect( onChange ).toHaveBeenNthCalledWith( 2, '' );
	} );
} );
