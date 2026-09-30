/**
 * External dependencies
 */
import { render, screen, waitFor, within } from '@testing-library/react';
import type { ReactNode } from 'react';
import { MemoryRouter } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { formatExplicitCurrency } from '../money-movement/utils';
import { getTransactionListFields } from '../money-movement/transactions-list-fields';
import { WooPaymentsPaymentSummarySection } from '../money-movement/transaction-detail-sections';
import { WooPaymentsTransactionTimeline } from '../money-movement/transaction-timeline';
import type {
	WooPaymentsTimelineEvent,
	WooPaymentsTransaction,
} from '../money-movement/types';
import { ActiveLoanSummary } from '../capital/active-loan-summary';
import { WooPaymentsPayouts } from '../payouts';
import { WooPaymentsReportsPage } from '../reports/page';
import {
	getWooPaymentsDeposits,
	getWooPaymentsDepositsSummary,
} from '../overview/data';
import {
	getWooPaymentsReportsBalanceSummary,
	getWooPaymentsReportsFees,
	getWooPaymentsReportsFeesSummary,
} from '../reports/data';
import { setMockUserPreferences } from './helpers/user-preferences';

// Client 11.1.0 `multi-currency/client/utils/currency/index.js:232-244` `formatExplicitCurrency()`, read through
// `wcpaySettings.shouldUseExplicitPrice` (`class-wc-payments-admin.php:1040`).

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );
jest.mock( '@woocommerce/data', () => ( {
	useUserPreferences: () =>
		jest
			.requireActual( './helpers/user-preferences' )
			.useMockUserPreferences(),
} ) );
jest.mock( '../overview/data', () => ( {
	getWooPaymentsDeposits: jest.fn(),
	getWooPaymentsDepositsSummary: jest.fn(),
	// The payouts page notices' requests; left pending, so no notice shows.
	getWooPaymentsDepositsOverview: jest.fn( () => new Promise( () => {} ) ),
	getWooPaymentsOverviewShell: jest.fn( () => new Promise( () => {} ) ),
} ) );
jest.mock( '../reports/data', () => ( {
	getWooPaymentsReportsBalanceSummary: jest.fn(),
	getWooPaymentsReportsFees: jest.fn(),
	getWooPaymentsReportsFeesSummary: jest.fn(),
	requestWooPaymentsReportsFeesExport: jest.fn(),
	getWooPaymentsReportsFeesExportUrl: jest.fn(),
} ) );

type MockField = {
	id: string;
	render?: ( props: { item: Record< string, unknown > } ) => ReactNode;
};

// Renders every cell of every row as `cell-<field id>`.
jest.mock( '@wordpress/dataviews/wp', () => ( {
	DataViews: ( {
		data = [],
		fields = [],
	}: {
		data?: Array< Record< string, unknown > >;
		fields?: MockField[];
	} ) => (
		<div>
			{ data.map( ( item, index ) => (
				<div key={ index }>
					{ fields.map( ( field ) => (
						<div
							key={ field.id }
							data-testid={ `cell-${ field.id }` }
						>
							{ field.render?.( { item } ) }
						</div>
					) ) }
				</div>
			) ) }
		</div>
	),
} ) );

const setExplicitPrice = ( shouldUseExplicitPrice?: boolean ) => {
	window.wcSettings = {
		adminUrl: 'https://example.com/wp-admin/',
		dateFormat: 'F j, Y',
		admin: {
			woopaymentsSettings:
				shouldUseExplicitPrice === undefined
					? {}
					: { shouldUseExplicitPrice },
		},
	} as typeof window.wcSettings;
};

describe( 'formatExplicitCurrency', () => {
	it( 'formats like formatAmount when the flag is off or not preloaded', () => {
		setExplicitPrice( false );
		expect( formatExplicitCurrency( 1234, 'usd' ) ).toBe( '$12.34' );
		expect( formatExplicitCurrency( 1234, 'usd', true ) ).toBe( '$12.34' );

		setExplicitPrice();
		expect( formatExplicitCurrency( 1234, 'usd' ) ).toBe( '$12.34' );
	} );

	it( 'appends the upper-case currency code when the flag is on', () => {
		setExplicitPrice( true );

		expect( formatExplicitCurrency( 1234, 'usd' ) ).toBe( '$12.34 USD' );
		expect( formatExplicitCurrency( 1234, 'eur' ) ).toBe( '€12.34 EUR' );
		// The client's default currency.
		expect( formatExplicitCurrency( 1234 ) ).toBe( '$12.34 USD' );
	} );

	it( 'does not repeat a code the formatted amount already shows', () => {
		setExplicitPrice( true );

		expect( formatExplicitCurrency( 1234, 'chf' ) ).toMatch(
			/^CHF\s12\.34$/
		);
	} );

	it( 'trims the symbol with skipSymbol, like the client removeCurrencySymbol()', () => {
		setExplicitPrice( true );

		expect( formatExplicitCurrency( 123456, 'usd', true ) ).toBe(
			'1,234.56 USD'
		);
		// The client strips the minus sign with the symbol.
		expect( formatExplicitCurrency( -1234, 'eur', true ) ).toBe(
			'12.34 EUR'
		);
	} );

	it( 'lets a screen pass its own flag and keeps the no-amount dash', () => {
		setExplicitPrice( false );
		expect( formatExplicitCurrency( 1234, 'usd', false, true ) ).toBe(
			'$12.34 USD'
		);

		setExplicitPrice( true );
		expect( formatExplicitCurrency( 1234, 'usd', false, false ) ).toBe(
			'$12.34'
		);
		expect( formatExplicitCurrency( undefined, 'usd' ) ).toBe( '-' );
	} );
} );

describe( 'explicit currency codes across admin surfaces', () => {
	beforeEach( () => {
		setMockUserPreferences( {} );
	} );

	describe( 'transactions list', () => {
		const item = {
			id: 'ch_1',
			type: 'charge',
			amount: 5000,
			fees: 180,
			net: 4820,
			currency: 'usd',
			customer_currency: 'usd',
		};
		const renderCell = ( id: string ) => {
			const field = getTransactionListFields( {
				includeDeposit: true,
				includeSubscription: false,
				includeFilters: true,
			} ).find( ( candidate ) => candidate.id === id );

			return render( <div>{ field?.render( { item } ) }</div> ).container;
		};

		it.each( [
			[ true, '$50.00 USD', '$48.20 USD' ],
			[ false, '$50.00', '$48.20' ],
		] )(
			'with the flag %s shows the amount %s and net %s, and fees without a code',
			( flag, amount, net ) => {
				setExplicitPrice( flag );

				// Client 11.1.0 `transactions/list/converted-amount.tsx:61`, `index.tsx:542` and `:404`.
				expect( renderCell( 'amount' ) ).toHaveTextContent(
					new RegExp( `^\\${ amount }$` )
				);
				expect( renderCell( 'net' ) ).toHaveTextContent(
					new RegExp( `^\\${ net }$` )
				);
				expect( renderCell( 'fees' ) ).toHaveTextContent(
					/^-\$1\.80$/
				);
			}
		);
	} );

	describe( 'payment details summary', () => {
		const getSummary = () =>
			screen.getByRole( 'region', { name: 'Summary' } );

		it( 'adds the code to net and refunds but not to fees', () => {
			setExplicitPrice( true );
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							status: 'succeeded',
							amount: 5000,
							amount_refunded: 1000,
							currency: 'usd',
							fee: 180,
							net: 4820,
							captured: true,
						} as WooPaymentsTransaction
					}
				/>
			);

			// Client 11.1.0 `payment-details/summary/index.tsx:543,676` (explicit) and fees (`formatCurrency`).
			expect(
				within( getSummary() ).getByText( 'Net: $48.20 USD' )
			).toBeInTheDocument();
			expect(
				within( getSummary() ).getByText( 'Refunded: -$10.00 USD' )
			).toBeInTheDocument();
			expect(
				within( getSummary() ).getByText( 'Fees: -$1.80' )
			).toBeInTheDocument();
		} );

		it( 'does not repeat the code on a converted settlement amount', () => {
			setExplicitPrice( true );
			render(
				<WooPaymentsPaymentSummarySection
					transaction={
						{
							status: 'succeeded',
							amount: 5000,
							currency: 'eur',
							balance_transaction: {
								id: 'txn_fx',
								amount: 5532,
								fee: 180,
								net: 5352,
								currency: 'usd',
							},
						} as WooPaymentsTransaction
					}
				/>
			);

			expect(
				within( getSummary() ).getByText(
					'Converted amount: $55.32 USD'
				)
			).toBeInTheDocument();
			expect(
				within( getSummary() ).getByText( 'Net: $53.52 USD' )
			).toBeInTheDocument();
		} );
	} );

	describe( 'timeline', () => {
		const capturedEvent = {
			type: 'captured',
			datetime: 1700000000,
			amount: 6300,
			amount_captured: 6300,
			fee: 350,
			currency: 'usd',
			transaction_details: {
				customer_currency: 'USD',
				customer_amount: 6300,
				customer_amount_captured: 6300,
				customer_fee: 350,
				store_currency: 'USD',
				store_amount: 6300,
				store_amount_captured: 6300,
				store_fee: 350,
			},
		} as unknown as WooPaymentsTimelineEvent;
		const fxRefundEvent = {
			type: 'full_refund',
			datetime: 1700000100,
			amount_refunded: 1000,
			currency: 'eur',
			transaction_details: {
				customer_currency: 'EUR',
				customer_amount: 1000,
				store_currency: 'USD',
				store_amount: -1100,
			},
		} as unknown as WooPaymentsTimelineEvent;
		const disputeWonEvent = {
			type: 'dispute_won',
			datetime: 1700000200,
			amount: 6300,
			fee: 1500,
			currency: 'usd',
		} as unknown as WooPaymentsTimelineEvent;

		const envelopeDisputeEvent = {
			type: 'dispute_needs_response',
			datetime: 1700000300,
			amount: -6300,
			fee: -1500,
			currency: 'usd',
			fee_breakdown_v1: {
				totals: { net: { amount: -9900, currency: 'usd' } },
			},
		} as unknown as WooPaymentsTimelineEvent;

		it( 'adds the code to headlines, payouts, net payout and the FX line when the flag is on', () => {
			setExplicitPrice( true );
			const { container } = render(
				<WooPaymentsTransactionTimeline
					events={ [
						capturedEvent,
						fxRefundEvent,
						disputeWonEvent,
						envelopeDisputeEvent,
					] }
				/>
			);
			const text = container.textContent;

			// Client 11.1.0 `map-events.js:827` (stringWithAmount explicit), `:300` and `:939-946`.
			expect( text ).toContain(
				'A payment of $63.00 USD was successfully charged.'
			);
			expect( text ).toContain( 'Net payout: $59.50 USD' );
			expect( text ).toContain(
				'A payment of €10.00 EUR was successfully refunded.'
			);
			// Client `formatFX()`: the symbol-less source unit, then the explicit target amount.
			expect( text ).toContain( '1.00 EUR → 1.1 USD: $11.00 USD' );
			expect( text ).not.toContain( '€1.00' );
			// Client `map-events.js:1133`: the dispute won payout impact.
			expect( text ).toContain( '$78.00 USD' );
			// Client `map-events.js:1060`: the envelope's payout impact for a new dispute.
			expect( text ).toContain( '$99.00 USD' );
			// Client `map-events.js:1155-1164`: the reversal line stays plain.
			expect( text ).toContain( 'Dispute reversal: $63.00' );
			expect( text ).not.toContain( 'Dispute reversal: $63.00 USD' );
		} );

		it( 'keeps the plain amounts when the flag is off', () => {
			setExplicitPrice( false );
			const { container } = render(
				<WooPaymentsTransactionTimeline
					events={ [ capturedEvent, fxRefundEvent, disputeWonEvent ] }
				/>
			);
			const text = container.textContent;

			expect( text ).toContain(
				'A payment of $63.00 was successfully charged.'
			);
			expect( text ).toContain( 'Net payout: $59.50' );
			expect( text ).toContain( '€1.00 → 1.1 USD: $11.00' );
			expect( text ).not.toMatch( /\$\d+\.\d{2} USD/ );
		} );
	} );

	describe( 'payouts list', () => {
		it.each( [
			[ true, '$10.00 USD' ],
			[ false, '$10.00' ],
		] )( 'with the flag %s shows the amount %s', async ( flag, amount ) => {
			setExplicitPrice( flag );
			jest.mocked( getWooPaymentsDepositsSummary ).mockResolvedValue(
				{}
			);
			jest.mocked( getWooPaymentsDeposits ).mockResolvedValue( {
				data: [
					{
						id: 'po_1',
						date: 1781740800000,
						type: 'deposit',
						amount: 1000,
						status: 'paid',
						currency: 'usd',
					},
				] as never,
				total_count: 1,
			} );

			render(
				<MemoryRouter initialEntries={ [ '/woopayments/payouts' ] }>
					<WooPaymentsPayouts />
				</MemoryRouter>
			);

			// Client 11.1.0 `deposits/list/index.tsx:148`.
			expect(
				await screen.findByTestId( 'cell-amount' )
			).toHaveTextContent( new RegExp( `^\\${ amount }$` ) );
		} );
	} );

	describe( 'capital', () => {
		it.each( [
			[ true, '$1,000.00 USD', '$150.00 USD' ],
			[ false, '$1,000.00', '$150.00' ],
		] )(
			'with the flag %s shows the loan amount %s and fixed fee %s',
			( flag, loanAmount, fixedFee ) => {
				setExplicitPrice( flag );
				render(
					<ActiveLoanSummary
						details={ {
							advance_amount: 100000,
							advance_paid_out_at: 1643889167,
							currency: 'usd',
							current_repayment_interval: {
								due_at: 1644889167,
								paid_amount: 123,
								remaining_amount: 2345,
							},
							fee_amount: 15000,
							paid_amount: 1234,
							remaining_amount: 9876,
							repayments_begin_at: 1643999167,
							withhold_rate: 0.1,
						} }
						activeLoanId="flxln_123456"
						headingLevel={ 2 }
						baseClassName="woocommerce-woopayments-active-loan"
					/>
				);
				const getValue = ( term: string ) =>
					screen
						.getByText( term )
						.closest( 'div' )
						?.querySelector( 'dd' )?.textContent;

				// Client 11.1.0 `components/active-loan-summary/index.tsx:250,256`.
				expect( getValue( 'Loan amount' ) ).toBe( loanAmount );
				expect( getValue( 'Fixed fee' ) ).toBe( fixedFee );
			}
		);
	} );

	describe( 'reports', () => {
		it.each( [
			[ true, '$25.00 USD', '-$1.20 USD' ],
			[ false, '$25.00', '-$1.20' ],
		] )(
			'with the flag %s shows the fees report gross %s and fees %s',
			async ( flag, gross, fees ) => {
				setExplicitPrice( flag );
				jest.mocked(
					getWooPaymentsReportsBalanceSummary
				).mockResolvedValue( {} as never );
				jest.mocked(
					getWooPaymentsReportsFeesSummary
				).mockResolvedValue( { count: 1 } as never );
				jest.mocked( getWooPaymentsReportsFees ).mockResolvedValue( [
					{
						transaction_id: 'txn_123',
						date: '2026-06-18 10:11:12',
						type: 'charge',
						transaction_currency: 'usd',
						amount: 2500,
						deposit_currency: 'usd',
						fees: -120,
					},
				] as never );

				render(
					<MemoryRouter
						initialEntries={ [ '/woopayments/reports?tab=fees' ] }
					>
						<WooPaymentsReportsPage
							now={ new Date( '2026-06-19T12:00:00Z' ) }
						/>
					</MemoryRouter>
				);

				// Client 11.1.0 `reports/fees/fields.tsx:145,161`.
				await waitFor( () =>
					expect(
						screen.getByTestId( 'cell-amount' )
					).toHaveTextContent( new RegExp( `^\\${ gross }$` ) )
				);
				expect( screen.getByTestId( 'cell-fees' ) ).toHaveTextContent(
					new RegExp( `^${ fees.replace( '$', '\\$' ) }$` )
				);
			}
		);
	} );
} );
