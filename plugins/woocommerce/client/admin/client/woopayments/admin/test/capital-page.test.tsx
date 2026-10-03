/**
 * External dependencies
 */
import { render, screen, waitFor } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { WooPaymentsCapitalPage } from '../capital/page';
import {
	getTestModeNoticeText,
	mockAccountMode,
} from './helpers/test-mode-account';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const ACCOUNT_PATH = '/wc-admin/settings/payments/woopayments/account';
const SUMMARY_PATH = '/wc/v3/payments/capital/active_loan_summary';
const LOANS_PATH = '/wc/v3/payments/capital/loans';

const activeLoanSummary = {
	details: {
		advance_amount: 100000,
		advance_paid_out_at: 1729505500,
		currency: 'usd',
		current_repayment_interval: {
			due_at: 1735294500,
			paid_amount: 20000,
			remaining_amount: 30000,
		},
		fee_amount: 10000,
		paid_amount: 20000,
		remaining_amount: 90000,
		repayments_begin_at: 1730110500,
		withhold_rate: 0.15,
	},
};

const activeLoan = {
	stripe_loan_id: 'loan_test',
	amount: 100000,
	currency: 'usd',
	fee_amount: 10000,
	withhold_rate: 0.15,
	paid_out_at: '2024-10-28 10:11:40',
	first_paydown_at: null,
	fully_paid_at: null,
};

const paidLoan = {
	stripe_loan_id: 'loan_paid',
	amount: 50000,
	currency: 'usd',
	fee_amount: 5000,
	withhold_rate: 0.1,
	paid_out_at: '2024-09-01 10:11:40',
	first_paydown_at: '2024-09-08 00:00:00',
	fully_paid_at: '2024-10-01 00:00:00',
};

const mockCapitalApi = ( {
	summary = activeLoanSummary,
	loans = [ activeLoan ],
	account = {
		account: {
			connected: true,
			live: true,
			mode: 'live',
			test_mode: false,
			test_drive: false,
			sandbox: false,
		},
		urls: {},
	},
}: {
	summary?: Record< string, unknown >;
	loans?: Array< Record< string, unknown > >;
	account?: Record< string, unknown >;
} = {} ) => {
	mockApiFetch.mockImplementation( ( ( options ) => {
		const path = String( options?.path || '' );

		if ( path === SUMMARY_PATH ) {
			return Promise.resolve( summary );
		}

		if ( path === LOANS_PATH ) {
			return Promise.resolve( { data: loans } );
		}

		if ( path === ACCOUNT_PATH ) {
			return Promise.resolve( account );
		}

		return Promise.reject( new Error( `Unexpected path ${ path }` ) );
	} ) as typeof apiFetch );
};

// The account's Capital block as the admin preload sends it (client 11.1.0 `wcpaySettings.accountLoans`).
const setHasActiveLoan = ( hasActiveLoan: boolean ) => {
	window.wcSettings = {
		...window.wcSettings,
		admin: {
			woopaymentsSettings: {
				accountLoans: { loans: [], has_active_loan: hasActiveLoan },
			},
		},
	};
};

describe( 'WooPaymentsCapitalPage', () => {
	beforeEach( () => {
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
		mockApiFetch.mockReset();
	} );

	it( 'loads Capital loans and active loan summary from preserved endpoints', async () => {
		setHasActiveLoan( true );
		mockCapitalApi();

		render( <WooPaymentsCapitalPage /> );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading Capital Loans…'
		);

		expect(
			await screen.findByRole( 'heading', {
				name: 'Active loan overview',
			} )
		).toBeInTheDocument();
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/capital/active_loan_summary',
			method: 'GET',
		} );
		expect( mockApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/capital/loans',
			method: 'GET',
		} );
		// The repaid amount is its own large figure, so read the whole value.
		expect(
			screen.getByText( 'Total repaid' ).nextElementSibling
		).toHaveTextContent( '$200.00 of $1,100.00' );
		expect( screen.getAllByText( '15%' ) ).toHaveLength( 2 );
		expect(
			screen.getByRole( 'link', {
				name: 'View transactions for loan loan_test',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&loan_id_is=loan_test'
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Capital Loans loaded.'
		);
	} );

	it( 'renders the reference Capital loan summary and row affordances', async () => {
		setHasActiveLoan( true );
		mockCapitalApi( {
			loans: [ activeLoan, paidLoan ],
		} );

		render( <WooPaymentsCapitalPage /> );

		expect(
			await screen.findByRole( 'heading', {
				name: 'Active loan overview',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'View transactions' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&loan_id_is=loan_test'
		);
		expect(
			screen.getByText( /Repaid this period \(until / )
		).toBeInTheDocument();
		expect(
			screen.getByText( /Repaid this period \(until / ).nextElementSibling
		).toHaveTextContent( '$200.00 of $500.00 minimum' );
		expect( screen.getByText( '2 loans' ) ).toBeInTheDocument();
		expect( screen.getByText( '$1,500.00 total' ) ).toBeInTheDocument();
		expect( screen.getByText( '$150.00 fixed fees' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Active' ) ).toBeInTheDocument();
		expect( screen.getByText( /Paid off:/ ) ).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', {
				name: 'View transactions for loan loan_test',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Ftransactions&loan_id_is=loan_test'
		);
		expect(
			screen.queryByRole( 'link', {
				name: '$1,000.00 - view transactions for loan loan_test',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'requests no active loan summary for an account without an active loan', async () => {
		setHasActiveLoan( false );
		mockCapitalApi();

		render( <WooPaymentsCapitalPage /> );

		await waitFor( () =>
			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				'Capital Loans loaded.'
			)
		);
		// Client 11.1.0 mounts the active loan card only when `accountLoans.has_active_loan` (capital/index.tsx:216).
		expect( mockApiFetch ).not.toHaveBeenCalledWith( {
			path: SUMMARY_PATH,
			method: 'GET',
		} );
		expect(
			screen.queryByRole( 'heading', { name: 'Active loan overview' } )
		).not.toBeInTheDocument();
	} );

	it( 'shows a test mode notice for Capital loans from the preloaded test mode flag', async () => {
		mockCapitalApi();
		mockAccountMode( true );

		render( <WooPaymentsCapitalPage /> );

		// Client 11.1.0 capital/index.tsx:214.
		expect( await getTestModeNoticeText() ).toBe(
			'Viewing test loans. To view live loans, disable test mode in WooPayments settings.'
		);
		expect(
			screen.getByRole( 'link', { name: 'WooPayments settings' } )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings'
		);
		// The client reads injected settings; the notice must not request the account.
		expect( mockApiFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( { path: ACCOUNT_PATH } )
		);
	} );

	it( 'shows no test mode notice while the store is in live mode', async () => {
		mockCapitalApi();
		mockAccountMode( false );

		render( <WooPaymentsCapitalPage /> );

		await screen.findByRole( 'heading', { name: 'Capital Loans' } );
		expect( await getTestModeNoticeText() ).toBeNull();
	} );

	it( 'normalizes provider date-time strings before rendering dates', async () => {
		const RealDate = Date;
		class RejectSpaceSeparatedDate extends RealDate {
			constructor( value?: string | number | Date ) {
				if (
					typeof value === 'string' &&
					/^\d{4}-\d{2}-\d{2} /.test( value )
				) {
					super( Number.NaN );
					return;
				}

				if ( undefined === value ) {
					super();
					return;
				}

				super( value );
			}
		}

		global.Date = RejectSpaceSeparatedDate as DateConstructor;
		mockCapitalApi( {
			summary: {},
			loans: [
				{
					...activeLoan,
					stripe_loan_id: 'loan_date_string',
					first_paydown_at: '2024-11-04 00:00:00',
				},
			],
		} );

		try {
			render( <WooPaymentsCapitalPage /> );

			expect(
				await screen.findByRole( 'link', {
					name: 'View transactions for loan loan_date_string',
				} )
			).toBeInTheDocument();
			// Client 11.1.0 `capital/index.tsx:157`: the site date format.
			expect(
				screen.getByText( 'November 4, 2024' )
			).toBeInTheDocument();
		} finally {
			global.Date = RealDate;
		}
	} );

	it( 'announces empty Capital loan results exactly once', async () => {
		mockCapitalApi( { summary: {}, loans: [] } );

		render( <WooPaymentsCapitalPage /> );

		expect(
			await screen.findByText( 'No Capital loans found.' )
		).toBeInTheDocument();
		// The empty-state message is rendered once, in the polite status
		// region, so screen readers announce it a single time.
		expect( screen.getAllByText( 'No Capital loans found.' ) ).toHaveLength(
			1
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'No Capital loans found.'
		);
	} );

	it( 'announces loading errors', async () => {
		mockApiFetch.mockRejectedValue( new Error( 'Capital unavailable.' ) );

		render( <WooPaymentsCapitalPage /> );

		expect( await screen.findByRole( 'alert' ) ).toHaveTextContent(
			'Capital unavailable.'
		);
	} );
} );
