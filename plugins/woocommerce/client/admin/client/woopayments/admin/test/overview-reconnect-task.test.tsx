/**
 * External dependencies
 */
import { fireEvent, render, screen, within } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import { recordEvent } from '@woocommerce/tracks';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsDisputeReadiness,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';
import { createRecordedOverviewShell } from './helpers/overview-shell';

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock(
	'../overview/components/stripe-notifications-banner',
	() => () => null
);

jest.mock( '../overview/data', () => ( {
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsDisputeReadiness: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

// The shape `WooPaymentsOverviewService` sends, as client 11.1.0 `WC_Payments_Account::get_wpcom_reconnect_url()` builds it.
const RECONNECT_URL =
	'http://example.com/wp-admin/admin.php?wcpay-reconnect-wpcom=1&_wpnonce=abc123';

// Client 11.1.0 `overview/task-list/tasks/reconnect-task.tsx:13-53`.
describe( 'WooPayments Overview reconnect task', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		( useSelect as jest.Mock ).mockReturnValue( {
			isError: false,
			isLoading: false,
			notes: [],
		} );
		( getWooPaymentsDepositsOverview as jest.Mock ).mockReturnValue(
			new Promise( () => {} )
		);
		( getWooPaymentsRecentDeposits as jest.Mock ).mockResolvedValue( {
			data: [],
		} );
		( getWooPaymentsDisputeReadiness as jest.Mock ).mockResolvedValue( {
			overview: { enabled: false },
		} );
	} );

	it( 'shows the task with the client copy and link when the shell sends a reconnect URL', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell(
				{},
				{ wpcom_reconnect_url: RECONNECT_URL }
			)
		);

		render( <WooPaymentsOverviewPage /> );

		const task = (
			await screen.findByText( 'Reconnect WooPayments', {
				selector: '.woocommerce-task-list__item-title',
			} )
		).closest( 'li' );
		if ( ! task ) {
			throw new Error( 'No reconnect task item.' );
		}
		expect(
			within( task ).getByText(
				'WooPayments is missing a connected WordPress.com account. Some functionality will be limited without a connected account.'
			)
		).toBeInTheDocument();

		// Client 11.1.0 `reconnect-task.tsx:20-27`: the action button sends the merchant to the reconnect URL.
		const originalLocation = window.location;
		const mockAssign = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: {
				href: originalLocation.href,
				search: '',
				assign: mockAssign,
			},
		} );
		try {
			fireEvent.click(
				within( task ).getByRole( 'button', { name: 'Reconnect' } )
			);
		} finally {
			Object.defineProperty( window, 'location', {
				configurable: true,
				value: originalLocation,
			} );
		}

		const href = new URL( mockAssign.mock.calls[ 0 ][ 0 ] );
		expect( href.pathname ).toBe( '/wp-admin/admin.php' );
		expect( Object.fromEntries( href.searchParams ) ).toEqual( {
			'wcpay-reconnect-wpcom': '1',
			_wpnonce: 'abc123',
			from: 'WCPAY_OVERVIEW',
			source: 'wcpay-reconnect-wpcom-user-task',
		} );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_task_click',
			{
				task: 'reconnect-wpcom',
				source: 'wcpay-reconnect-wpcom-task',
			}
		);
	} );

	it( 'shows no task without a reconnect URL', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell()
		);

		render( <WooPaymentsOverviewPage /> );

		await screen.findByRole( 'heading', { name: 'Account details' } );
		expect(
			screen.queryByText( 'Reconnect WooPayments' )
		).not.toBeInTheDocument();
	} );
} );
