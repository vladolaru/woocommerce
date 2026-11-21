/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Internal dependencies
 */
import { WooPaymentsPayoutsNotices } from '../payouts-notices';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsOverviewShell,
} from '../overview/data';
import { saveOption } from '../../settings/data/actions';

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
} ) );
jest.mock( '../../settings/data/actions', () => ( {
	saveOption: jest.fn(),
} ) );
jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const mockGetOverview = getWooPaymentsDepositsOverview as jest.Mock;
const mockGetShell = getWooPaymentsOverviewShell as jest.Mock;
const mockSaveOption = saveOption as jest.Mock;

const readRecordedResponse = ( file: string ) =>
	JSON.parse(
		fs.readFileSync( path.join( __dirname, 'fixtures', file ), 'utf8' )
	).response;

// Recorded native :8889 responses; see each file's `_meta`. The recorded account pays out daily, unrestricted, past its waiting period.
const RECORDED_SHELL = readRecordedResponse( 'recorded-overview-shell.json' );
const RECORDED_OVERVIEW = readRecordedResponse(
	'recorded-deposits-overview-all.json'
);

// The nonce-protected dashboard login the shell sends for an account that is not a test drive.
const ACCOUNT_LINK =
	'https://store.example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=/woopayments/overview&wcpay-login=1&_wpnonce=abc123';

const mockResponses = ( {
	deposits = {},
	accountLink = RECORDED_SHELL.account_status.account_link,
	overviewAccount = {},
}: {
	deposits?: Record< string, unknown >;
	accountLink?: string;
	overviewAccount?: Record< string, unknown >;
} = {} ) => {
	mockGetShell.mockResolvedValue( {
		...RECORDED_SHELL,
		account_status: {
			...RECORDED_SHELL.account_status,
			account_link: accountLink,
			deposits: {
				...RECORDED_SHELL.account_status.deposits,
				...deposits,
			},
		},
	} );
	mockGetOverview.mockResolvedValue( {
		...RECORDED_OVERVIEW,
		account: { ...RECORDED_OVERVIEW.account, ...overviewAccount },
	} );
};

const setPreloadedDismissal = ( isDismissed?: boolean ) =>
	Object.defineProperty( window, 'wcSettings', {
		configurable: true,
		writable: true,
		value: {
			adminUrl: 'https://store.example.com/wp-admin/',
			admin: {
				woopaymentsSettings:
					isDismissed === undefined
						? {}
						: { isNextDepositNoticeDismissed: isDismissed },
			},
		},
	} );

const SCHEDULE_SENTENCE =
	'Available funds are automatically dispatched every day.';

// Waits for both requests, then for any render they cause.
const settle = async () => {
	await waitFor( () => expect( mockGetOverview ).toHaveBeenCalled() );
	await waitFor( () => expect( mockGetShell ).toHaveBeenCalled() );
	await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
};

// Client 11.1.0 `deposits/index.tsx:28-145`.
describe( 'WooPaymentsPayoutsNotices', () => {
	beforeEach( () => {
		mockGetOverview.mockReset();
		mockGetShell.mockReset();
		mockSaveOption.mockReset();
		setPreloadedDismissal( false );
	} );

	it( 'shows the payout schedule notice for an unrestricted account with an automatic schedule', async () => {
		mockResponses();

		render( <WooPaymentsPayoutsNotices /> );

		const sentence = await screen.findByText( 'every day' );
		expect( sentence.tagName ).toBe( 'STRONG' );
		expect( sentence.closest( '.components-notice' ) ).toHaveTextContent(
			SCHEDULE_SENTENCE
		);
		expect( sentence.closest( '.components-notice' ) ).toHaveClass(
			'is-info'
		);
		expect(
			screen.getByRole( 'button', { name: 'Payout schedule tooltip' } )
		).toBeInTheDocument();
	} );

	it.each( [
		[
			'the payout restriction is not deposits_unrestricted',
			{ deposits: { restrictions: 'schedule_restricted' } },
		],
		[
			'the waiting period is not complete',
			{ deposits: { completed_waiting_period: false } },
		],
		[
			'payouts are manual',
			{ overviewAccount: { deposits_schedule: { interval: 'manual' } } },
		],
		[
			'a payout bank account errored',
			{
				overviewAccount: {
					default_external_accounts: [
						{ currency: 'usd', status: 'errored' },
					],
				},
			},
		],
	] )(
		'hides the payout schedule notice when %s',
		async ( _label, scenario ) => {
			mockResponses( scenario );

			render( <WooPaymentsPayoutsNotices /> );
			await settle();

			expect( screen.queryByText( 'every day' ) ).not.toBeInTheDocument();
		}
	);

	it( 'hides the payout schedule notice once it was dismissed', async () => {
		setPreloadedDismissal( true );
		mockResponses();

		render( <WooPaymentsPayoutsNotices /> );
		await settle();

		expect( screen.queryByText( 'every day' ) ).not.toBeInTheDocument();
	} );

	it( 'stores the dismissal and keeps the notice hidden for the rest of the visit', async () => {
		mockResponses();

		const { unmount } = render( <WooPaymentsPayoutsNotices /> );
		await screen.findByText( 'every day' );
		// The schedule notice is the only notice, so its close button is the only one.
		await userEvent.click(
			screen.getByRole( 'button', { name: /Close/ } )
		);

		expect( mockSaveOption ).toHaveBeenCalledWith(
			'wcpay_next_deposit_notice_dismissed',
			true
		);
		expect( screen.queryByText( 'every day' ) ).not.toBeInTheDocument();

		unmount();
		render( <WooPaymentsPayoutsNotices /> );
		await settle();

		expect( screen.queryByText( 'every day' ) ).not.toBeInTheDocument();
	} );

	it( 'shows the failed-payout notice with the account link when a payout bank account errored', async () => {
		mockResponses( {
			accountLink: ACCOUNT_LINK,
			overviewAccount: {
				default_external_accounts: [
					{ currency: 'usd', status: 'errored' },
				],
			},
		} );

		render( <WooPaymentsPayoutsNotices /> );

		const link = await screen.findByRole( 'link', {
			name: /^update your bank account details/,
		} );
		expect( link.closest( '.components-notice' ) ).toHaveTextContent(
			'Payouts are currently paused because a recent payout failed. Please update your bank account details'
		);
		expect( link ).toHaveAttribute( 'target', '_blank' );
		const href = new URL( link.getAttribute( 'href' ) ?? '' );
		expect( href.searchParams.get( 'from' ) ).toBe( 'WCPAY_PAYOUTS' );
		expect( href.searchParams.get( 'source' ) ).toBe(
			'wcpay-payout-failure-notice'
		);
		expect( href.searchParams.get( 'wcpay-login' ) ).toBe( '1' );
	} );

	// Client 11.1.0 `deposits/index.tsx:109`: no notice without an account link, as for a test drive.
	it( 'shows no failed-payout notice without an account link', async () => {
		mockResponses( {
			overviewAccount: {
				default_external_accounts: [
					{ currency: 'usd', status: 'errored' },
				],
			},
		} );

		render( <WooPaymentsPayoutsNotices /> );
		await settle();

		// Notices also reach the a11y speak regions, so look for the notice itself.
		expect(
			document.querySelector( '.components-notice' )
		).not.toBeInTheDocument();
	} );
} );
