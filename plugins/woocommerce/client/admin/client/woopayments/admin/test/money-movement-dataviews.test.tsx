/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import type { ReactElement, ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { WooPaymentsMoneyMovementDataViews } from '../money-movement/dataviews';

const mockDataViews = jest.fn(
	( props: {
		header?: ReactNode;
		data?: Array< { id: string } >;
		isLoading?: boolean;
	} ) => (
		<div
			data-testid="mock-dataviews"
			aria-busy={ props.isLoading ? 'true' : 'false' }
		>
			{ props.header }
			{ props.data?.map( ( item ) => (
				<div key={ item.id }>{ item.id }</div>
			) ) }
		</div>
	)
);

jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( props: Record< string, unknown > ) =>
		mockDataViews( props as { data?: Array< { id: string } > } ),
} ) );

describe( 'WooPaymentsMoneyMovementDataViews', () => {
	beforeEach( () => {
		mockDataViews.mockClear();
	} );

	it( 'passes server-owned DataViews state through a thin wrapper', () => {
		const fields = [ { id: 'date', label: 'Date' } ];
		const rows = [ { id: 'txn_1', date: '2026-06-19' } ];
		const view = {
			type: 'table',
			page: 2,
			perPage: 25,
			search: 'Ada',
			fields: [ 'date' ],
		};
		const onChangeView = jest.fn();

		render(
			<WooPaymentsMoneyMovementDataViews
				fields={ fields }
				rows={ rows }
				view={ view }
				onChangeView={ onChangeView }
				total={ 52 }
				isLoading={ false }
				title="Transactions"
				toolbarActions={ <button type="button">Export</button> }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Transactions' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Export' } )
		).toBeInTheDocument();
		expect( screen.getByText( 'txn_1' ) ).toBeInTheDocument();
		expect( mockDataViews ).toHaveBeenCalledWith(
			expect.objectContaining( {
				fields,
				data: rows,
				view,
				onChangeView,
				isLoading: false,
				search: false,
				paginationInfo: {
					totalItems: 52,
					totalPages: 3,
				},
			} )
		);
	} );

	it( 'keeps loading and empty states in stable semantic regions', () => {
		const { rerender } = render(
			<WooPaymentsMoneyMovementDataViews
				fields={ [ { id: 'date', label: 'Date' } ] }
				rows={ [] }
				view={ {
					type: 'table',
					page: 1,
					perPage: 25,
					fields: [ 'date' ],
				} }
				onChangeView={ jest.fn() }
				total={ 0 }
				isLoading
				loadingMessage="Loading transactions"
			/>
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading transactions'
		);

		rerender(
			<WooPaymentsMoneyMovementDataViews
				fields={ [ { id: 'date', label: 'Date' } ] }
				rows={ [] }
				view={ {
					type: 'table',
					page: 1,
					perPage: 25,
					fields: [ 'date' ],
				} }
				onChangeView={ jest.fn() }
				total={ 0 }
				isLoading={ false }
				loadingMessage="Loading transactions"
			/>
		);

		// DataViews shows the empty text in its own no-results area, so the
		// wrapper passes it through rather than adding a second message.
		expect(
			screen.queryByText( 'No data to display' )
		).not.toBeInTheDocument();
		const lastProps = mockDataViews.mock.lastCall?.[ 0 ] as
			| { empty: ReactElement }
			| undefined;
		expect( lastProps ).toBeDefined();
		const { empty } = lastProps as { empty: ReactElement };
		render( empty );
		// Client 11.1.0 `TableCard` without `emptyMessage`: `@woocommerce/components` Table's default.
		expect( screen.getByText( 'No data to display' ) ).toBeInTheDocument();
	} );
} );
