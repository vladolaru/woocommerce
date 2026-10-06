/**
 * External dependencies
 */
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsTransactionsList } from '../money-movement/transactions-list';
import { WooPaymentsDisputesPage } from '../money-movement/disputes-page';
import { WooPaymentsPayouts } from '../payouts';
import { buildMoneyMovementRoutePath } from '../money-movement/query';
import {
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

// The real component, so a test drives the filter chips a merchant sees.
jest.mock( '@wordpress/dataviews/wp', () =>
	jest.requireActual( '@wordpress/dataviews' )
);

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

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

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
} ) );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	// The payouts page notices' requests; left pending, so no notice shows.
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );

const lastQuery = ( mock: unknown ) => {
	const { calls } = ( mock as jest.Mock ).mock;

	return calls[ calls.length - 1 ][ 0 ] as Record< string, unknown >;
};

/**
 * Get the applied filter chip whose name starts the given text, and its remove button.
 *
 * @param name The chip's field and rule, as DataViews shows them ("Loan is:").
 */
const getChip = ( name: string ) => {
	const chip = Array.from(
		document.querySelectorAll(
			'.dataviews-filters__summary-chip-container'
		)
	).find(
		( container ) =>
			container
				.querySelector( '.dataviews-filters__summary-filter-text-name' )
				?.textContent?.startsWith( name )
	) as HTMLElement | undefined;

	return {
		chip,
		value: chip?.querySelector(
			'.dataviews-filters__summary-filter-text-value'
		)?.textContent,
		remove: chip?.querySelector(
			'.dataviews-filters__summary-chip-remove'
		) as HTMLElement | null,
	};
};

beforeEach( () => {
	setMockUserPreferences( {} );
	window.wcSettings = {
		adminUrl: 'http://example.com/wp-admin/',
		admin: {
			woopaymentsSettings: {
				accountLoans: {
					loans: [ 'flxln_active|active', 'flxln_paid|paid' ],
				},
			},
		},
	} as typeof window.wcSettings;
	( getWooPaymentsTransactions as jest.Mock ).mockResolvedValue( {
		data: [],
	} );
	( getWooPaymentsTransactionsSummary as jest.Mock ).mockResolvedValue( {
		count: 0,
	} );
	( getWooPaymentsDisputes as jest.Mock ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	( getWooPaymentsDisputesSummary as jest.Mock ).mockResolvedValue( {
		count: 0,
	} );
	( getWooPaymentsDeposits as jest.Mock ).mockResolvedValue( {
		data: [],
		total_count: 0,
	} );
	( getWooPaymentsDepositsSummary as jest.Mock ).mockResolvedValue( {} );
} );

describe( 'WooPayments list filters in the real DataViews', () => {
	// N-318, client 11.1.0 `transactions/filters/config.ts:555-598`: the loan link opens with a visible, clearable filter.
	it( 'shows the transactions loan filter as a chip and drops it from the next request when removed', async () => {
		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/transactions?type=charge&filter=advanced&loan_id_is=flxln_paid',
				] }
			>
				<SettingsShellHistoryBridge />
				<WooPaymentsTransactionsList
					buildRoute={ ( query ) =>
						buildMoneyMovementRoutePath(
							'/woopayments/transactions',
							query
						)
					}
				/>
			</MemoryRouter>
		);
		await screen.findByText( 'No transactions found.' );

		const loan = getChip( 'Loan is' );
		expect( loan.value ).toBe( 'ID: flxln_paid | Paid in Full' );
		expect( lastQuery( getWooPaymentsTransactions ) ).toMatchObject( {
			loan_id_is: 'flxln_paid',
		} );

		fireEvent.click( loan.remove as HTMLElement );

		await waitFor( () =>
			expect(
				lastQuery( getWooPaymentsTransactions )
			).not.toHaveProperty( 'loan_id_is' )
		);
		expect( getChip( 'Loan is' ).chip ).toBeUndefined();
	} );

	it( 'shows the disputes status filter as a chip and drops it from the next request when removed', async () => {
		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/disputes?filter=advanced&status_is=under_review',
				] }
			>
				<SettingsShellHistoryBridge />
				<WooPaymentsDisputesPage />
			</MemoryRouter>
		);
		await waitFor( () =>
			expect( lastQuery( getWooPaymentsDisputes ) ).toMatchObject( {
				status_is: 'under_review',
			} )
		);

		const status = getChip( 'Status is' );
		expect( status.chip ).toBeDefined();

		fireEvent.click( status.remove as HTMLElement );

		await waitFor( () =>
			expect( lastQuery( getWooPaymentsDisputes ) ).not.toHaveProperty(
				'status_is'
			)
		);
	} );

	// Client 11.1.0 `deposits/filters/config.js:89-181`: the payout Date and Status filters under Advanced filters.
	it( 'shows the payouts date filter as a chip and drops it from the next request when removed', async () => {
		render(
			<MemoryRouter
				initialEntries={ [
					'/woopayments/payouts?filter=advanced&date_before=2026-07-08',
				] }
			>
				<SettingsShellHistoryBridge />
				<WooPaymentsPayouts />
			</MemoryRouter>
		);
		await waitFor( () =>
			expect( lastQuery( getWooPaymentsDeposits ).date_before ).toEqual(
				expect.any( String )
			)
		);

		const date = getChip( 'Date is before' );
		expect( date.chip ).toBeDefined();

		fireEvent.click( date.remove as HTMLElement );

		await waitFor( () =>
			expect(
				lastQuery( getWooPaymentsDeposits ).date_before
			).toBeUndefined()
		);
	} );
} );
