/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsTransactionsList } from '../money-movement/transactions-list';
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import { WooPaymentsPayouts } from '../payouts';
import { summaryItem } from './helpers/table-summary';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/dataviews/wp', () =>
	jest.requireActual( '@wordpress/dataviews' )
);

describe.each( [
	{
		page: 'payouts',
		element: <WooPaymentsPayouts />,
		resource: 'deposits',
		footer: '0 payouts',
	},
	{
		page: 'transactions',
		element: (
			<WooPaymentsTransactionsList
				buildRoute={ () => '/woopayments/transactions' }
			/>
		),
		resource: 'transactions',
		footer: '0 transactions',
	},
	{
		page: 'transactions?view=uncaptured',
		element: <WooPaymentsTransactionsPage />,
		resource: 'authorizations',
		footer: '0 authorization(s)',
	},
	{
		page: 'disputes',
		element: <WooPaymentsDisputesPage />,
		resource: 'disputes',
		footer: '0 disputes',
	},
] )( '$resource summary', ( { page, element, resource, footer } ) => {
	it.each( [
		[ 'empty', undefined ],
		[ 'full', { count: 0, total: 0, currency: 'usd' } ],
	] as const )(
		'renders the list with a fulfilled %s response',
		async ( _, summary ) => {
			const listPath = `/wc/v3/payments/${ resource }`;
			jest.mocked( apiFetch ).mockImplementation( async ( { path } ) => {
				const endpoint = path?.split( '?' )[ 0 ];
				if ( endpoint === `${ listPath }/summary` ) {
					return summary;
				}
				if ( endpoint === listPath ) {
					return { data: [], total_count: 0 };
				}
				return {};
			} );

			render(
				<MemoryRouter initialEntries={ [ `/woopayments/${ page }` ] }>
					{ element }
				</MemoryRouter>
			);

			expect(
				await screen.findByText( 'No data to display' )
			).toBeInTheDocument();
			expect(
				screen.queryAllByText( summaryItem( footer ) )
			).toHaveLength( summary ? 1 : 0 );
		}
	);
} );
