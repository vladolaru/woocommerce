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
import { SettingsShellHistoryBridge } from './helpers/settings-shell-history';

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
	getHistory: () =>
		jest.requireActual( './helpers/settings-shell-history' ).shellHistory,
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
		<MemoryRouter initialEntries={ [ route ] }>
			<SettingsShellHistoryBridge />
			{ element }
		</MemoryRouter>
	);

const openViewOptions = async () => {
	const toggle = await screen.findByRole( 'button', {
		name: 'View options',
	} );
	fireEvent.click( toggle );
	await screen.findByText( 'Properties' );

	return document.getElementById(
		toggle.getAttribute( 'aria-controls' ) || ''
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

	// DataViews 10.3.0 (Gutenberg #73064) lists only the columns a merchant can show or hide, as the
	// client's `TableCard` column menu leaves out its `required` columns (`table/index.tsx:154-156`).
	it.each( LISTS )(
		'lists each column of the %s list at most once and leaves out the columns it always shows',
		async ( _name, route, element, column ) => {
			renderAt( route, element() );
			const dialog = await openViewOptions();
			const properties = within( dialog )
				.getAllByRole( 'listitem' )
				.map( ( property ) => property.textContent );

			expect( properties.length ).toBeGreaterThan( 0 );
			expect( new Set( properties ).size ).toBe( properties.length );
			expect( properties ).not.toContain( column );
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

describe( 'WooPayments uncaptured list while a new page loads', () => {
	beforeEach( () => {
		setEmptyLists();
		// The row is client 11.1.0's first mock authorization (`transactions/uncaptured/__tests__/index.test.tsx:95`)
		// in its `Authorization` shape (`types/authorizations.d.ts:9-47`), without the customer fields.
		// `total_count` 30 and the summary (30 × 1455) are synthetic: they give the list a second page of 25.
		mocked( getWooPaymentsAuthorizations )
			.mockResolvedValueOnce( {
				data: [
					{
						created: '2020-01-02 17:46:02',
						captured: false,
						order_id: 24,
						risk_level: 2,
						amount: 1455,
						payment_intent_id: 'pi_4242',
						charge_id: 'ch_mock',
						currency: 'usd',
					},
				],
				total_count: 30,
			} as never )
			.mockReturnValue( new Promise( () => {} ) );
		mocked( getWooPaymentsAuthorizationsSummary ).mockResolvedValue( {
			count: 30,
			total: 43650,
		} as never );
	} );

	// The list once passed no fields while it loaded, which blanked its header. DataViews 13.0.0
	// (Gutenberg #74572, #76486) renders no table before the first rows, so a reload shows the header.
	it( 'keeps its column headers labelled while the next page loads', async () => {
		renderAt(
			'/woopayments/transactions?view=uncaptured',
			<WooPaymentsTransactionsPage />
		);
		await screen.findByText( 'Uncaptured transactions loaded.' );

		fireEvent.click( screen.getByRole( 'button', { name: 'Next page' } ) );

		await waitFor( () =>
			expect( getWooPaymentsAuthorizations ).toHaveBeenLastCalledWith(
				expect.objectContaining( { page: 2 } )
			)
		);
		expect( screen.getByRole( 'table' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
		expect(
			screen.getByRole( 'columnheader', { name: /^Authorized on/ } )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'columnheader', { name: /^Capture by/ } )
		).toBeInTheDocument();
	} );
} );
