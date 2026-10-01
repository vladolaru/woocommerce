/**
 * External dependencies
 */
import {
	fireEvent,
	render,
	screen,
	waitFor,
	within,
} from '@testing-library/react';
import type { ReactElement } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionsList } from '../money-movement/transactions-list';
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsTransactionsPage } from '../money-movement/transactions-page';
import { WooPaymentsPayouts } from '../payouts';
import { buildMoneyMovementRoutePath } from '../money-movement/query';
import {
	getWooPaymentsAuthorizations,
	getWooPaymentsAuthorizationsSummary,
	getWooPaymentsDisputes,
	getWooPaymentsDisputesSummary,
	getWooPaymentsTransactions,
	getWooPaymentsTransactionsSummary,
} from '../money-movement/data';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import { setMockUserPreferences } from './helpers/user-preferences';

// The real DataViews, from its CommonJS build: these tests read its View options menu.
jest.mock( '@wordpress/dataviews/wp', () =>
	jest.requireActual( '@wordpress/dataviews' )
);

const mockCreateErrorNotice = jest.fn();

// Records the notices the lists raise; other stores keep the real dispatch.
jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );

	return {
		...actual,
		dispatch: ( store: string | { name: string } ) =>
			( typeof store === 'string' ? store : store.name ) ===
			'core/notices'
				? {
						createErrorNotice: mockCreateErrorNotice,
						createSuccessNotice: jest.fn(),
				  }
				: actual.dispatch( store ),
	};
} );

jest.mock( '@woocommerce/navigation', () => ( {
	...jest.requireActual( '@woocommerce/navigation' ),
	getHistory: () => ( { push: jest.fn() } ),
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../money-movement/transaction-search', () => ( {
	WooPaymentsTransactionSearch: () => null,
} ) );

jest.mock( '../money-movement/data', () => ( {
	getWooPaymentsTransactions: jest.fn(),
	getWooPaymentsTransactionsSummary: jest.fn(),
	requestWooPaymentsTransactionsExport: jest.fn(),
	getWooPaymentsTransactionsExportUrl: jest.fn(),
	getWooPaymentsDisputes: jest.fn(),
	getWooPaymentsDisputesSummary: jest.fn(),
	getWooPaymentsDisputesExportUrl: jest.fn(),
	requestWooPaymentsDisputesExport: jest.fn(),
	getWooPaymentsAuthorizations: jest.fn(),
	getWooPaymentsAuthorizationsSummary: jest.fn(),
	captureWooPaymentsAuthorization: jest.fn(),
	cancelWooPaymentsAuthorization: jest.fn(),
} ) );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );

const mocked = < T extends ( ...args: never[] ) => unknown >( fn: T ) =>
	fn as unknown as jest.MockedFunction< T >;

const renderAt = ( route: string, element: ReactElement ) =>
	render(
		<MemoryRouter initialEntries={ [ route ] }>{ element }</MemoryRouter>
	);

const openViewOptions = async () => {
	fireEvent.click(
		await screen.findByRole( 'button', { name: 'View options' } )
	);

	return ( await screen.findByText( 'Properties' ) ).closest(
		'.components-popover'
	) as HTMLElement;
};

const setEmptyLists = () => {
	setMockUserPreferences( {} );
	mocked( getWooPaymentsTransactions ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	mocked( getWooPaymentsTransactionsSummary ).mockResolvedValue( {
		count: 0,
	} );
	mocked( getWooPaymentsDisputes ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	mocked( getWooPaymentsDisputesSummary ).mockResolvedValue( {
		count: 0,
	} );
	mocked( getWooPaymentsDeposits ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} as never );
	mocked( getWooPaymentsDepositsSummary ).mockResolvedValue( {
		count: 0,
	} as never );
	mocked( getWooPaymentsAuthorizations ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} as never );
	mocked( getWooPaymentsAuthorizationsSummary ).mockResolvedValue( {
		count: 0,
		total: 0,
	} as never );
};

const LISTS: Array< [ string, string, () => ReactElement, string ] > = [
	[
		'transactions',
		'/woopayments/transactions',
		() => (
			<WooPaymentsTransactionsList
				buildRoute={ ( query ) =>
					buildMoneyMovementRoutePath(
						'/woopayments/transactions',
						query
					)
				}
			/>
		),
		'Type',
	],
	[
		'disputes',
		'/woopayments/disputes',
		() => <WooPaymentsDisputesPage />,
		'Reason',
	],
	[ 'payouts', '/woopayments/payouts', () => <WooPaymentsPayouts />, 'Date' ],
];

describe( 'WooPayments list View options', () => {
	beforeEach( setEmptyLists );

	it.each( LISTS )(
		"offers the client's page sizes on the %s list",
		async ( _name, route, element ) => {
			renderAt( route, element() );
			const dialog = await openViewOptions();

			// Client 11.1.0 `TableCard` pagination: `DEFAULT_PER_PAGE_OPTIONS` is 25, 50, 75 and 100.
			expect(
				within( dialog )
					.getAllByRole( 'radio' )
					.map( ( option ) => option.textContent )
					.filter( ( label ) => /^\d+$/.test( label || '' ) )
			).toEqual( [ '25', '50', '75', '100' ] );
			expect(
				within( dialog ).getByRole( 'radio', { name: '25' } )
			).toBeChecked();
		}
	);

	it.each( LISTS )(
		'lists each column of the %s list once in the properties',
		async ( _name, route, element, column ) => {
			renderAt( route, element() );
			const dialog = await openViewOptions();

			expect(
				Array.from(
					dialog.querySelectorAll( '.dataviews-field-control__label' )
				).filter( ( label ) => label.textContent === column )
			).toHaveLength( 1 );
		}
	);
} );

describe( 'WooPayments list empty state', () => {
	beforeEach( setEmptyLists );

	it.each( [
		...LISTS.map( ( [ name, route, element ] ) => [
			name,
			route,
			element,
		] ),
		[
			'uncaptured',
			'/woopayments/transactions?view=uncaptured',
			() => <WooPaymentsTransactionsPage />,
		],
	] as Array< [ string, string, () => ReactElement ] > )(
		"shows the client's empty row on the %s list",
		async ( _name, route, element ) => {
			renderAt( route, element() );

			// Client 11.1.0 `TableCard` without `emptyMessage`: `@woocommerce/components` Table's default.
			expect(
				await screen.findByText( 'No data to display' )
			).toBeInTheDocument();
		}
	);
} );

describe( 'WooPayments list load errors', () => {
	// A failed read as `apiFetch` rejects it, recorded from :8889 with the route answering 500.
	const SERVER_ERROR = {
		code: 'internal_server_error',
		message: 'Internal Server Error',
	};

	beforeEach( () => {
		setEmptyLists();
		mockCreateErrorNotice.mockReset();
	} );

	it.each( [
		[
			'transactions',
			'/woopayments/transactions',
			() => LISTS[ 0 ][ 2 ](),
			() => {
				mocked( getWooPaymentsTransactions ).mockRejectedValue(
					SERVER_ERROR
				);
				mocked( getWooPaymentsTransactionsSummary ).mockRejectedValue(
					SERVER_ERROR
				);
			},
			// Client 11.1.0 `data/transactions/resolvers.js:76-81`; the summary fails silently.
			[ 'Error retrieving transactions.' ],
		],
		[
			'disputes',
			'/woopayments/disputes',
			() => <WooPaymentsDisputesPage />,
			() => {
				mocked( getWooPaymentsDisputes ).mockRejectedValue(
					SERVER_ERROR
				);
				mocked( getWooPaymentsDisputesSummary ).mockRejectedValue(
					SERVER_ERROR
				);
			},
			// Client 11.1.0 `data/disputes/resolvers.js:91-118`.
			[
				'Error retrieving disputes.',
				'Error retrieving the summary of disputes.',
			],
		],
		[
			'payouts',
			'/woopayments/payouts',
			() => <WooPaymentsPayouts />,
			() => {
				mocked( getWooPaymentsDeposits ).mockRejectedValue(
					SERVER_ERROR
				);
				mocked( getWooPaymentsDepositsSummary ).mockRejectedValue(
					SERVER_ERROR
				);
			},
			// Client 11.1.0 `data/deposits/resolvers.js:130-136`; the summary fails silently.
			[ 'Error retrieving payouts.' ],
		],
		[
			'uncaptured',
			'/woopayments/transactions?view=uncaptured',
			() => <WooPaymentsTransactionsPage />,
			() => {
				mocked( getWooPaymentsAuthorizations ).mockRejectedValue(
					SERVER_ERROR
				);
				mocked( getWooPaymentsAuthorizationsSummary ).mockRejectedValue(
					SERVER_ERROR
				);
			},
			// Client 11.1.0 `data/authorizations/resolvers.ts:56-65, :114-123`: the list, the list's
			// summary and the tab count's summary (`transactions/index.tsx:65`) each raise one.
			[
				'Error retrieving uncaptured transactions.',
				'Error retrieving uncaptured transactions.',
				'Error retrieving uncaptured transactions.',
			],
		],
	] as Array<
		[ string, string, () => ReactElement, () => void, string[] ]
	> )(
		"raises the client's error notices when the %s list fails to load",
		async ( _name, route, element, fail, notices ) => {
			fail();
			renderAt( route, element() );

			// Client 11.1.0 `TableCard` shows its neutral empty row under the notices.
			expect(
				await screen.findByText( 'No data to display' )
			).toBeInTheDocument();
			await waitFor( () =>
				expect(
					mockCreateErrorNotice.mock.calls.map( ( [ text ] ) => text )
				).toEqual( notices )
			);
			expect(
				screen.queryByText( 'Internal Server Error' )
			).not.toBeInTheDocument();
			expect( screen.queryByText( /found\.$/ ) ).not.toBeInTheDocument();
		}
	);
} );

describe( 'WooPayments uncaptured list while loading', () => {
	beforeEach( () => {
		setEmptyLists();
		mocked( getWooPaymentsAuthorizations ).mockReturnValue(
			new Promise( () => {} )
		);
		mocked( getWooPaymentsAuthorizationsSummary ).mockReturnValue(
			new Promise( () => {} )
		);
	} );

	it( 'labels its column headers before the rows load', async () => {
		renderAt(
			'/woopayments/transactions?view=uncaptured',
			<WooPaymentsTransactionsPage />
		);

		expect(
			await screen.findByRole( 'columnheader', { name: 'Authorized on' } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: 'Capture by' } )
		).toBeInTheDocument();
	} );
} );
