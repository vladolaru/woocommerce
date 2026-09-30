/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { fireEvent, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { PayoutsOverviewCard } from '../overview/components/payouts-overview-card';
import type {
	WooPaymentsDeposit,
	WooPaymentsDepositsOverview,
	WooPaymentsOverviewAccountDeposits,
	WooPaymentsOverviewAccountStatus,
} from '../overview/types';

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const readRecordedResponse = ( file: string ) =>
	JSON.parse(
		fs.readFileSync( path.join( __dirname, 'fixtures', file ), 'utf8' )
	).response;

// Recorded native :8889 responses; see each file's `_meta`.
const RECORDED_OVERVIEW = readRecordedResponse(
	'recorded-deposits-overview-all.json'
) as WooPaymentsDepositsOverview;
const RECORDED_ACCOUNT_STATUS = (
	readRecordedResponse( 'recorded-overview-shell.json' ) as {
		account_status: WooPaymentsOverviewAccountStatus;
	}
 ).account_status;

// The recorded `deposits/overview-all` response in the scenario most tests share: $10.00 available, nothing pending, weekly Monday payouts.
const createOverview = (
	overrides: Partial< WooPaymentsDepositsOverview > = {}
): WooPaymentsDepositsOverview => ( {
	...RECORDED_OVERVIEW,
	balance: {
		...RECORDED_OVERVIEW.balance,
		available: [ { amount: 1000, currency: 'usd' } ],
		pending: [ { amount: 0, currency: 'usd' } ],
	},
	account: {
		...RECORDED_OVERVIEW.account,
		deposits_schedule: {
			delay_days: 7,
			interval: 'weekly',
			weekly_anchor: 'monday',
		},
	},
	...overrides,
} );

const createDeposit = (
	overrides: Partial< WooPaymentsDeposit > = {}
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

// The recorded Overview shell `account_status`, which carries what client 11.1.0 reads from `wcpaySettings.accountStatus` (`class-wc-payments-account.php:378,382`).
const createAccountStatus = (
	deposits: Partial< WooPaymentsOverviewAccountDeposits > = {},
	accountLink = RECORDED_ACCOUNT_STATUS.account_link
): Pick< WooPaymentsOverviewAccountStatus, 'account_link' | 'deposits' > => ( {
	account_link: accountLink,
	deposits: {
		...( RECORDED_ACCOUNT_STATUS.deposits as WooPaymentsOverviewAccountDeposits ),
		...deposits,
	},
} );

// Notices also reach the a11y speak regions, so read the card itself.
const inCard = () =>
	within( screen.getByRole( 'region', { name: 'Payouts' } ) );
const getScheduleSummary = () =>
	document.querySelector( '.woocommerce-woopayments-overview__schedule' );

describe( 'PayoutsOverviewCard', () => {
	beforeEach( () => {
		jest.mocked( recordEvent ).mockClear();
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: {
				adminUrl: 'https://example.com/wp-admin/',
			},
		} );
	} );

	it( 'renders the loading state', () => {
		render(
			<PayoutsOverviewCard
				isLoading
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ null }
				recentPayouts={ [] }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Payouts' } )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading payouts…'
		);
		expect( screen.getByRole( 'region' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
	} );

	// Client 11.1.0 `components/deposits-overview/index.tsx:133-142` hides the card whatever the payout history holds.
	it( 'hides the card for a new account with no pending or available funds', () => {
		const { container } = render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus( {
					completed_waiting_period: false,
				} ) }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 0, currency: 'usd' } ],
						pending: [ { amount: 0, currency: 'usd' } ],
						instant: [],
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect( container ).toBeEmptyDOMElement();
	} );

	it( 'shows waiting-period guidance when pending funds exist for a new account', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus( {
					completed_waiting_period: false,
				} ) }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 0, currency: 'usd' } ],
						pending: [ { amount: 1500, currency: 'usd' } ],
						instant: [],
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			inCard().getByText( /standard 7-day waiting period/i )
		).toBeInTheDocument();
		// Client 11.1.0 `index.tsx:105-108,207-244`: no schedule change before the waiting period ends.
		expect(
			screen.queryByRole( 'link', { name: 'Change payout schedule' } )
		).not.toBeInTheDocument();
		// Client 11.1.0 `deposits-overview/index.tsx:136-246`: the balances live in the Balance card only.
		expect( inCard().queryByText( '$15.00' ) ).not.toBeInTheDocument();
		expect( inCard().queryByText( 'Pending' ) ).not.toBeInTheDocument();
		expect( inCard().queryByText( 'Available' ) ).not.toBeInTheDocument();
	} );

	it( 'shows suspended-payouts guidance without schedule actions', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						deposits_enabled: false,
						deposits_blocked: true,
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			inCard().getByText( /Your payouts are temporarily suspended/i )
		).toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: /change payout schedule/i } )
		).not.toBeInTheDocument();
	} );

	// Client 11.1.0 `index.tsx:161-194`: a blocked account gets the suspended notice instead of every other payout notice.
	it( 'shows only the suspended notice when payouts are blocked', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus(
					{},
					'https://example.com/account-link'
				) }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 100, currency: 'usd' } ],
						pending: [ { amount: 0, currency: 'usd' } ],
						instant: [],
					},
					account: {
						...createOverview().account,
						deposits_blocked: true,
						default_external_accounts: [
							{
								currency: 'usd',
								status: 'errored',
							},
						],
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			inCard().getByText( /Your payouts are temporarily suspended/i )
		).toBeInTheDocument();
		expect(
			inCard().queryByText( /a recent payout failed/i )
		).not.toBeInTheDocument();
		expect(
			inCard().queryByText( /remains below/i )
		).not.toBeInTheDocument();
	} );

	// Client 11.1.0 `index.tsx:107-108,162`: only `deposits_blocked` suspends payouts on the card.
	it( 'keeps the schedule change and shows no suspended notice when only payouts_enabled is off', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						deposits_enabled: false,
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			inCard().queryByText( /Your payouts are temporarily suspended/i )
		).not.toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'Change payout schedule' } )
		).toBeInTheDocument();
	} );

	it( 'shows minimum-payout and negative-balance notices', () => {
		const { rerender } = render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 50, currency: 'eur' } ],
						pending: [ { amount: 0, currency: 'eur' } ],
						instant: [],
					},
				} ) }
				selectedCurrency="eur"
				recentPayouts={ [] }
			/>
		);

		// The recorded platform minimum is €1.00 for EUR (and none for USD).
		expect(
			inCard().getByText(
				/Payouts are paused while your available funds balance remains below €1.00/i
			)
		).toBeInTheDocument();

		rerender(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					balance: {
						available: [ { amount: -100, currency: 'usd' } ],
						pending: [ { amount: 0, currency: 'usd' } ],
						instant: [],
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect(
			inCard().getByText( /WooPayments balance remains negative/i )
		).toBeInTheDocument();
	} );

	it( 'shows failed-payout account recovery when the selected bank account is errored', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus(
					{},
					'https://example.com/account-link'
				) }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						default_external_accounts: [
							{
								currency: 'usd',
								status: 'errored',
							},
						],
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect(
			inCard().getByText(
				/Payouts are currently paused because a recent payout failed/i
			)
		).toBeInTheDocument();

		const updateLink = screen.getByRole( 'link', {
			name: /^update your bank account details/,
		} );
		expect( updateLink ).toHaveAttribute(
			'href',
			'https://example.com/account-link?from=WCPAY_PAYOUTS&source=wcpay-payout-failure-notice'
		);
		// Client 11.1.0 `deposit-notices.tsx:205-216` renders an `ExternalLink`.
		expect( updateLink ).toHaveAttribute( 'target', '_blank' );

		fireEvent.click( updateLink );

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_account_details_link_clicked',
			{
				from: 'WCPAY_PAYOUTS',
				source: 'wcpay-payout-failure-notice',
			}
		);
	} );

	it( 'keeps overview-derived payout details visible when the recent-payout list fails', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage="Unable to load recent payouts."
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).toHaveTextContent(
			'Available funds are automatically dispatched every Monday.'
		);
		expect( screen.getByRole( 'alert' ) ).toHaveTextContent(
			'Unable to load recent payouts.'
		);
	} );

	it( 'announces when there are no recent payouts', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'No recent payouts.'
		);
	} );

	it( 'keeps the payout status region mounted from loading to loaded', () => {
		const { rerender } = render(
			<PayoutsOverviewCard
				isLoading
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ null }
				recentPayouts={ [] }
			/>
		);
		const statusRegion = screen.getByRole( 'status' );

		rerender(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect( screen.getByRole( 'status' ) ).toBe( statusRegion );
		expect( statusRegion ).toHaveTextContent( 'No recent payouts.' );
	} );

	it( 'keeps recent payout history visible when overview data is unavailable', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ null }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			screen.getByRole( 'heading', { name: 'Payouts' } )
		).toBeInTheDocument();
		expect( inCard().getByText( 'Dispatch date' ) ).toBeInTheDocument();
		expect( screen.getAllByText( '$10.00' ).length ).toBeGreaterThan( 0 );
	} );

	it( 'renders recent payout rows and tracks history navigation', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect( inCard().getByText( 'Dispatch date' ) ).toBeInTheDocument();
		expect( inCard().getByText( 'Status' ) ).toBeInTheDocument();
		expect( inCard().getByText( 'Amount' ) ).toBeInTheDocument();
		expect( screen.getAllByText( '$10.00' ).length ).toBeGreaterThan( 0 );
		// Client 11.1.0 `components/deposit-status-chip`: paid is a success chip.
		expect( inCard().getByText( 'Completed (paid)' ) ).toHaveClass(
			'woocommerce-status-badge',
			'woocommerce-status-badge--success'
		);
		// Client 11.1.0 `recent-deposits-list.tsx:48` shows the date in the site format.
		expect(
			screen.getByRole( 'link', { name: 'View payout po_test details' } )
		).toHaveTextContent( 'June 18, 2026' );

		expect(
			screen.getByRole( 'link', { name: 'View payout po_test details' } )
		).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts%2Fdetails&id=po_test'
		);

		const historyLink = screen.getByRole( 'link', {
			name: 'View full payout history',
		} );
		expect( historyLink ).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fpayouts'
		);

		fireEvent.click( historyLink );

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_deposits_view_history_click'
		);
	} );

	it( 'uses reference payout schedule copy for daily and weekly payouts', () => {
		const { rerender } = render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						deposits_schedule: {
							interval: 'daily',
						},
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).toHaveTextContent(
			'Available funds are automatically dispatched every day.'
		);
		// Client 11.1.0 `deposit-schedule.tsx:33-44` sets the interval in bold.
		expect( inCard().getByText( 'every day' ).tagName ).toBe( 'STRONG' );

		rerender(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).toHaveTextContent(
			'Available funds are automatically dispatched every Monday.'
		);
	} );

	it( 'uses reference payout schedule copy for monthly and month-end payouts', () => {
		const { rerender } = render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						deposits_schedule: {
							interval: 'monthly',
							monthly_anchor: 15,
						},
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).toHaveTextContent(
			'Available funds are automatically dispatched on the 15th of every month.'
		);

		rerender(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					account: {
						...createOverview().account,
						deposits_schedule: {
							interval: 'monthly',
							monthly_anchor: 31,
						},
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).toHaveTextContent(
			'Available funds are automatically dispatched on the last day of every month.'
		);
	} );

	it( 'keeps the payout schedule help behind the schedule help icon', async () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect(
			screen.queryByText( /The timing and amount of your payouts/ )
		).not.toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Payout schedule tooltip' } )
		);

		expect(
			screen.getByText(
				/The timing and amount of your payouts may vary due to several factors\./
			)
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: /payout schedule guide/ } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/payouts/payout-schedule/'
		);
	} );

	it( 'shows the reference no-funds notice when funds are still pending', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 0, currency: 'usd' } ],
						pending: [ { amount: 1500, currency: 'usd' } ],
						instant: [],
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		expect(
			inCard().getByText( /You have no funds available/i )
		).toBeInTheDocument();
		expect( screen.getByRole( 'link', { name: /Why\?/ } ) ).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/payouts/payout-schedule/#pending-funds'
		);
	} );

	it( 'renders change payout schedule when the schedule can be changed', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus() }
				overview={ createOverview() }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		const scheduleLink = screen.getByRole( 'link', {
			name: 'Change payout schedule',
		} );
		expect( scheduleLink ).toHaveAttribute(
			'href',
			'https://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings#payout-schedule'
		);

		fireEvent.click( scheduleLink );

		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_deposits_change_schedule_click'
		);
	} );
	// Client 11.1.0 `components/deposits-overview/index.tsx:82-84,152-158`.
	it.each( [ 'schedule_restricted', 'deposits_blocked', '' ] )(
		'hides the payout schedule sentence when the payout restriction is "%s"',
		( restrictions ) => {
			render(
				<PayoutsOverviewCard
					isLoading={ false }
					errorMessage={ null }
					accountStatus={ createAccountStatus( { restrictions } ) }
					overview={ createOverview() }
					recentPayouts={ [] }
				/>
			);

			expect( getScheduleSummary() ).not.toBeInTheDocument();
		}
	);

	// Client 11.1.0 `index.tsx:82-84,105-106` reads an absent `accountStatus.deposits` as restricted and still waiting.
	it( 'hides the schedule sentence and shows the waiting period without account payout data', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				overview={ createOverview() }
				recentPayouts={ [] }
			/>
		);

		expect( getScheduleSummary() ).not.toBeInTheDocument();
		expect(
			inCard().getByText( /standard 7-day waiting period/i )
		).toBeInTheDocument();
	} );

	it( 'reads the waiting period and minimum payout from the account status, not the payouts overview response', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus( {
					completed_waiting_period: false,
					minimum_scheduled_deposit_amounts: {},
				} ) }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 100, currency: 'usd' } ],
						pending: [ { amount: 0, currency: 'usd' } ],
						instant: [],
					},
					account: {
						...createOverview().account,
						// The `deposits/overview-all` response never carries these; a stale copy here must not count.
						...( {
							completed_waiting_period: true,
							minimum_scheduled_deposit_amounts: { usd: 500 },
						} as Record< string, unknown > ),
					},
				} ) }
				recentPayouts={ [ createDeposit() ] }
			/>
		);

		expect(
			inCard().getByText( /standard 7-day waiting period/i )
		).toBeInTheDocument();
		expect(
			inCard().queryByText( /remains below/i )
		).not.toBeInTheDocument();
		expect(
			screen.queryByRole( 'link', { name: 'Change payout schedule' } )
		).not.toBeInTheDocument();
	} );

	// Client 11.1.0 `index.tsx:165-191`: waiting period, no funds, negative balance, failed payout, then minimum balance.
	it( 'orders the payout notices as the client does', () => {
		render(
			<PayoutsOverviewCard
				isLoading={ false }
				errorMessage={ null }
				accountStatus={ createAccountStatus(
					{},
					'https://example.com/account-link'
				) }
				overview={ createOverview( {
					balance: {
						available: [ { amount: 0, currency: 'usd' } ],
						pending: [ { amount: 1500, currency: 'usd' } ],
						instant: [],
					},
					account: {
						...createOverview().account,
						default_external_accounts: [
							{ currency: 'usd', status: 'errored' },
						],
					},
				} ) }
				recentPayouts={ [] }
			/>
		);

		const notices = document.querySelector(
			'.woocommerce-woopayments-overview__notices'
		)?.textContent;
		expect(
			notices?.indexOf( 'You have no funds available.' )
		).toBeLessThan( notices?.indexOf( 'a recent payout failed' ) ?? -1 );
		expect(
			notices?.indexOf( 'You have no funds available.' )
		).toBeGreaterThan( -1 );
	} );
} );
