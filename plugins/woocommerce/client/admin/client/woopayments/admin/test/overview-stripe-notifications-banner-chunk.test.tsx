/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import { useSelect } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	getWooPaymentsDepositsOverview,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';

// A failed chunk request: the page's lazy import of the banner rejects.
jest.mock( '../overview/components/stripe-notifications-banner', () => {
	throw new Error( 'Loading chunk settings-payments-woopayments failed.' );
} );

jest.mock( '@wordpress/data', () => ( {
	...jest.requireActual( '@wordpress/data' ),
	useSelect: jest.fn(),
} ) );

jest.mock( '~/woopayments/settings/account-settings', () => ( {
	WooPaymentsAccountSettings: () => null,
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../overview/data', () => ( {
	createWooPaymentsAccountSession: jest.fn(),
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

describe( 'WooPayments Overview Stripe notifications banner chunk failure', () => {
	let consoleErrorSpy: jest.SpyInstance;

	beforeEach( () => {
		// React logs the caught error; keep the output clean.
		consoleErrorSpy = jest
			.spyOn( console, 'error' )
			.mockImplementation( () => {} );
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
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue( {
			account: {
				connected: true,
				working: false,
				can_process_payments: true,
				test_mode_onboarding: false,
			},
			account_status: {
				status: 'restricted',
				current_deadline: null,
				past_due: false,
				account_link: '',
				requirements: { errors: [] },
				details_submitted: false,
				payments_enabled: true,
				deposits_enabled: true,
			},
			show_update_details_task: true,
			disputes_awaiting_response_count: 0,
			overview_tasks_visibility: {
				dismissed_todo_tasks: [],
				deleted_todo_tasks: [],
				remind_me_later_todo_tasks: {},
			},
			is_connection_success_modal_dismissed: true,
			wpcom_reconnect_url: '',
			urls: {},
		} );
	} );

	afterEach( () => {
		consoleErrorSpy.mockRestore();
	} );

	it( 'keeps the Overview and shows the update-details task', async () => {
		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByText( 'Finish setting up WooPayments' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'heading', { level: 1, name: 'Overview' } )
		).toBeInTheDocument();
		expect(
			document.querySelector( '.stripe-notifications-banner-loader' )
		).toBeNull();
	} );
} );
