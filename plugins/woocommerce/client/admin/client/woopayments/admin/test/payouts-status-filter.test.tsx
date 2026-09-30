/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsPayouts } from '../payouts';
import { PayoutsOverviewCard } from '../overview/components/payouts-overview-card';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
} from '../overview/types';
import { setMockUserPreferences } from './helpers/user-preferences';

type MockField = {
	id: string;
	elements?: Array< { value: string; label: ReactNode } >;
	filterBy?: false | { operators: string[] };
};

type MockView = Record< string, unknown > & {
	filters?: Array< { field: string; operator: string; value?: unknown } >;
};

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );

// Exposes the status field's filter config and applies a status filter the way the DataViews filter UI does.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		fields = [],
		view,
		onChangeView,
	}: {
		fields?: MockField[];
		view: MockView;
		onChangeView: ( view: MockView ) => void;
	} ) => {
		const status = fields.find( ( field ) => field.id === 'status' );
		const applyFilter = ( operator: string, value: string ) =>
			onChangeView( {
				...view,
				page: 1,
				filters: [ { field: 'status', operator, value } ],
			} );

		return (
			<div>
				<ul data-testid="status-filter-options">
					{ ( status?.elements || [] ).map( ( element ) => (
						<li key={ element.value } data-value={ element.value }>
							{ element.label }
						</li>
					) ) }
				</ul>
				<div data-testid="status-filter-operators">
					{ status?.filterBy
						? status.filterBy.operators.join( ',' )
						: 'none' }
				</div>
				<div data-testid="view-filters">
					{ JSON.stringify( view.filters || [] ) }
				</div>
				<button onClick={ () => applyFilter( 'is', 'paid' ) }>
					Status is Completed
				</button>
				<button onClick={ () => applyFilter( 'isNot', 'failed' ) }>
					Status is not Failed
				</button>
			</div>
		);
	},
} ) );

const mockGetDeposits = getWooPaymentsDeposits as jest.MockedFunction<
	typeof getWooPaymentsDeposits
>;
const mockGetSummary = getWooPaymentsDepositsSummary as jest.MockedFunction<
	typeof getWooPaymentsDepositsSummary
>;

const renderPayouts = ( path = '/woopayments/payouts' ) =>
	render(
		<MemoryRouter initialEntries={ [ path ] }>
			<WooPaymentsPayouts />
		</MemoryRouter>
	);

describe( 'WooPayments payouts status filter', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
		window.wcSettings = {
			adminUrl: 'https://example.com/wp-admin/',
		} as typeof window.wcSettings;
		mockGetDeposits.mockReset();
		mockGetSummary.mockReset();
		mockGetDeposits.mockResolvedValue( { data: [], total_count: 0 } );
		mockGetSummary.mockResolvedValue( {} );
	} );

	it( 'offers the client status options, with paid shown as "Completed"', async () => {
		renderPayouts();

		await waitFor( () => expect( mockGetDeposits ).toHaveBeenCalled() );

		// Client 11.1.0 `deposits/filters/config.js:12-23`, in `deposits/strings.ts` order, without `deducted`.
		const options = Array.from(
			screen.getByTestId( 'status-filter-options' ).children
		).map( ( option ) => [
			option.getAttribute( 'data-value' ),
			option.textContent,
		] );

		expect( options ).toEqual( [
			[ 'paid', 'Completed' ],
			[ 'pending', 'Pending' ],
			[ 'in_transit', 'In transit' ],
			[ 'canceled', 'Canceled' ],
			[ 'failed', 'Failed' ],
		] );
		// Client 11.1.0 `deposits/filters/config.js:160-174`: rules "Is" and "Is not".
		expect(
			screen.getByTestId( 'status-filter-operators' )
		).toHaveTextContent( /^is,isNot$/ );
	} );

	it( 'sends status_is when the merchant filters on a status', async () => {
		renderPayouts();

		await waitFor( () => expect( mockGetDeposits ).toHaveBeenCalled() );
		expect( mockGetDeposits.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty(
			'status_is',
			expect.anything()
		);

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Status is Completed' } )
		);

		await waitFor( () =>
			expect( mockGetDeposits ).toHaveBeenLastCalledWith(
				expect.objectContaining( { status_is: 'paid', page: 1 } )
			)
		);
		expect( mockGetSummary ).toHaveBeenLastCalledWith(
			expect.objectContaining( { status_is: 'paid' } )
		);
		expect( mockGetDeposits.mock.lastCall?.[ 0 ]?.status_is_not ).toBe(
			undefined
		);
	} );

	it( 'sends status_is_not for the "Is not" rule', async () => {
		renderPayouts();

		await waitFor( () => expect( mockGetDeposits ).toHaveBeenCalled() );

		fireEvent.click(
			screen.getByRole( 'button', { name: 'Status is not Failed' } )
		);

		await waitFor( () =>
			expect( mockGetDeposits ).toHaveBeenLastCalledWith(
				expect.objectContaining( { status_is_not: 'failed' } )
			)
		);
		expect( mockGetDeposits.mock.lastCall?.[ 0 ]?.status_is ).toBe(
			undefined
		);
	} );

	it( 'shows a status_is link as the applied status filter', async () => {
		renderPayouts( '/woopayments/payouts?status_is=in_transit' );

		await waitFor( () =>
			expect( mockGetDeposits ).toHaveBeenCalledWith(
				expect.objectContaining( { status_is: 'in_transit' } )
			)
		);
		expect( screen.getByTestId( 'view-filters' ) ).toHaveTextContent(
			JSON.stringify( [
				{ field: 'status', operator: 'is', value: 'in_transit' },
			] )
		);
	} );
} );

const createOverview = (): WooPaymentsDepositsOverview => ( {
	balance: {
		available: [ { amount: 1000, currency: 'usd' } ],
		pending: [ { amount: 0, currency: 'usd' } ],
		instant: [],
	},
	account: {
		default_currency: 'usd',
		deposits_enabled: true,
		deposits_blocked: false,
		deposits_schedule: {
			delay_days: 7,
			interval: 'weekly',
			weekly_anchor: 'monday',
		},
		completed_waiting_period: true,
		minimum_scheduled_deposit_amounts: { usd: 500 },
		default_external_accounts: [],
	},
	deposit: { last_paid: [] },
} );

const createDeposit = (
	overrides: Partial< WooPaymentsDeposit >
): WooPaymentsDeposit => ( {
	id: 'po_test',
	date: 1781740800000,
	type: 'deposit',
	amount: 1000,
	status: 'paid',
	bankAccount: 'TEST BANK **** 1234 (USD)',
	currency: 'usd',
	automatic: true,
	fee: 0,
	fee_percentage: 0,
	created: 1781740800,
	...overrides,
} );

describe( 'PayoutsOverviewCard status copy', () => {
	// Client 11.1.0 `components/deposits-overview/recent-deposits-list.tsx:53` renders `DepositStatusChip`.
	// Chip colours from client 11.1.0 `components/deposit-status-chip/index.tsx:18-24`.
	it.each( [
		[ 'deposit', 'paid', 'Completed (paid)', 'success' ],
		[ 'withdrawal', 'paid', 'Completed (deducted)', 'success' ],
		[ 'deposit', 'pending', 'Pending', 'warning' ],
		[ 'deposit', 'in_transit', 'In transit', 'primary' ],
		[ 'deposit', 'failed', 'Failed', 'error' ],
		[ 'deposit', 'canceled', 'Canceled', 'info' ],
	] )(
		'shows a recent %s with status %s as a "%s" %s chip',
		( type, status, label, chipType ) => {
			render(
				<PayoutsOverviewCard
					isLoading={ false }
					errorMessage={ null }
					overview={ createOverview() }
					recentPayouts={ [ createDeposit( { type, status } ) ] }
				/>
			);

			const chip = screen.getByText( label );

			expect( chip ).toHaveClass(
				'woocommerce-status-badge',
				`woocommerce-status-badge--${ chipType }`
			);
			expect( chip ).toHaveTextContent(
				new RegExp( `^${ label.replace( /[()]/g, '\\$&' ) }$` )
			);
		}
	);
} );
