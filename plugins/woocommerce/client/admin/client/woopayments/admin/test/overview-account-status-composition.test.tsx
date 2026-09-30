/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import { act, render, screen, waitFor } from '@testing-library/react';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsDisputeReadiness,
	getWooPaymentsOverviewDisputes,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => <p>Spotlight promotion</p>,
} ) );

jest.mock(
	'../overview/components/stripe-notifications-banner',
	() => () => null
);

jest.mock( '../../settings/data/actions', () => ( { saveOption: jest.fn() } ) );

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsDisputeReadiness: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

jest.mock( 'react-intersection-observer', () => ( {
	useInView: () => ( { ref: jest.fn(), inView: true } ),
} ) );

const mockUseSelect = useSelect as jest.Mock;
const mockGetDepositsOverview = getWooPaymentsDepositsOverview as jest.Mock;
const mockGetDisputeReadiness = getWooPaymentsDisputeReadiness as jest.Mock;

// A needs-response row from the recorded native :8889 disputes list, due tomorrow so the task list has a task to show.
const urgentDispute = () => ( {
	...(
		JSON.parse(
			fs.readFileSync(
				path.join( __dirname, 'fixtures/recorded-disputes-list.json' ),
				'utf8'
			)
		).response.data as Array< Record< string, unknown > >
	 )[ 0 ],
	// The cached list stores `due_by` as a UTC `Y-m-d H:i:s` string.
	due_by: new Date( Date.now() + 24 * 60 * 60 * 1000 )
		.toISOString()
		.slice( 0, 19 )
		.replace( 'T', ' ' ),
} );

const renderForStatus = async ( status: string, paymentsEnabled = true ) => {
	const shell = createRecordedOverviewShell(
		{ status, payments_enabled: paymentsEnabled },
		{ disputes_awaiting_response_count: 1 }
	);
	( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue( {
		...shell,
		account: { ...shell.account, working: paymentsEnabled },
	} );

	render( <WooPaymentsOverviewPage /> );

	// The Account details card renders for every status, once the shell is in.
	await screen.findByRole( 'heading', { name: 'Account details' } );
};

describe( 'WooPayments Overview composition by account status', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockUseSelect.mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [
				{
					id: 10,
					name: 'wcpay-test-note',
					type: 'info',
					status: 'unactioned',
					title: 'Review your WooPayments account',
					content: '<p>Account action is required.</p>',
					date_created: '2026-09-29T10:00:00',
					date_created_gmt: '2026-09-29T07:00:00',
					actions: [],
					is_deleted: false,
					is_read: false,
				},
			],
		} );
		mockGetDepositsOverview.mockResolvedValue( {
			balance: {
				available: [ { amount: 1000, currency: 'usd' } ],
				pending: [ { amount: 250, currency: 'usd' } ],
				instant: [],
			},
			account: {
				default_currency: 'usd',
				deposits_enabled: true,
				deposits_schedule: { interval: 'daily' },
				default_external_accounts: [],
			},
			deposit: { last_paid: [] },
		} );
		( getWooPaymentsRecentDeposits as jest.Mock ).mockResolvedValue( {
			data: [],
			total_count: 0,
		} );
		( getWooPaymentsOverviewDisputes as jest.Mock ).mockResolvedValue( {
			data: [ urgentDispute() ],
		} );
		mockGetDisputeReadiness.mockResolvedValue( {
			overview: {
				enabled: true,
				score: 3,
				total: 4,
				state: 'incomplete',
				isDismissed: false,
				completeSignalIds: [],
				incompleteSignalIds: [ 'terms_and_conditions' ],
				signals: [
					{
						id: 'terms_and_conditions',
						status: 'incomplete',
						label: 'Clear terms and conditions',
						description:
							'Publish terms customers can find before checkout.',
						actionLabel: 'Add terms',
						actionUrl:
							'http://example.com/wp-admin/admin.php?page=wc-settings&tab=advanced',
					},
				],
			},
		} );
	} );

	// Client 11.1.0 `overview/index.js:113-116,134-139,306-367,375-389`.
	it.each( [
		[ 'rejected.fraud' ],
		[ 'rejected.terms_of_service' ],
		[ 'rejected.listed' ],
		[ 'rejected.other' ],
		[ 'under_review' ],
	] )(
		'reduces the page for a %s account to what the client shows',
		async ( status ) => {
			await renderForStatus( status );
			// Let the disputes read and the lazy sections settle, so an absence is not just a pending render.
			await waitFor( () =>
				expect( getWooPaymentsOverviewDisputes ).toHaveBeenCalled()
			);
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve, 50 ) )
			);

			expect(
				screen.queryByRole( 'heading', { name: 'Things to do' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'heading', { name: 'Balance' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'heading', { name: 'Payouts' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByText( 'View full payout history' )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'heading', { name: 'Dispute readiness' } )
			).not.toBeInTheDocument();
			expect(
				screen.queryByRole( 'heading', { name: 'Inbox' } )
			).not.toBeInTheDocument();
			expect(
				screen.getByText( 'Spotlight promotion' )
			).toBeInTheDocument();
			// Neither card mounts in the client, so neither read runs.
			expect( mockGetDepositsOverview ).not.toHaveBeenCalled();
			expect( mockGetDisputeReadiness ).not.toHaveBeenCalled();
			expect( mockUseSelect ).not.toHaveBeenCalled();
		}
	);

	// Payments are disabled for restricted and pending-verification accounts, yet the client keeps every section.
	it.each( [
		[ 'restricted', false ],
		[ 'pending_verification', false ],
		[ 'restricted_soon', true ],
		[ 'restricted_partially', true ],
		[ 'complete', true ],
	] )(
		'keeps the full page for a %s account (payments enabled: %s)',
		async ( status, paymentsEnabled ) => {
			await renderForStatus( status, paymentsEnabled );

			expect(
				await screen.findByRole( 'heading', { name: 'Things to do' } )
			).toBeInTheDocument();
			expect(
				await screen.findByRole( 'heading', { name: 'Balance' } )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'heading', { name: 'Payouts' } )
			).toBeInTheDocument();
			expect(
				await screen.findByRole( 'heading', {
					name: 'Dispute readiness',
				} )
			).toBeInTheDocument();
			expect(
				await screen.findByRole( 'heading', { name: 'Inbox' } )
			).toBeInTheDocument();
		}
	);
} );
