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
	getWooPaymentsDisputeReadiness,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';
import { createRecordedOverviewShell } from './helpers/overview-shell';

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

const MODAL_HEADING = "You're ready to accept payments!";

// Client 11.1.0 `overview/index.js:117-118,140-144`: payments enabled, `deposits.status === 'enabled'`, not test-mode onboarding.
describe( 'WooPayments Overview connection-success modal conditions', () => {
	beforeEach( () => {
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
		window.history.pushState(
			{},
			'',
			'/wp-admin/admin.php?wcpay-connection-success=1'
		);
	} );

	afterEach( () => {
		window.history.pushState( {}, '', '/' );
	} );

	it( 'shows the modal for the recorded complete account whose payouts are enabled', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell()
		);

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByRole( 'heading', { name: MODAL_HEADING } )
		).toBeInTheDocument();
	} );

	it( 'follows payments_enabled, not the stricter can_process_payments', async () => {
		const shell = createRecordedOverviewShell();
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue( {
			...shell,
			account: { ...shell.account, can_process_payments: false },
		} );

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByRole( 'heading', { name: MODAL_HEADING } )
		).toBeInTheDocument();
	} );

	it( 'hides the modal when payments are disabled', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell( { payments_enabled: false } )
		);

		render( <WooPaymentsOverviewPage /> );

		await screen.findByRole( 'heading', { name: 'Account details' } );
		expect(
			screen.queryByRole( 'heading', { name: MODAL_HEADING } )
		).not.toBeInTheDocument();
	} );

	it( 'hides the modal when payouts are not enabled', async () => {
		( getWooPaymentsOverviewShell as jest.Mock ).mockResolvedValue(
			createRecordedOverviewShell( { deposits_enabled: false } )
		);

		render( <WooPaymentsOverviewPage /> );

		await screen.findByRole( 'heading', { name: 'Account details' } );
		expect(
			screen.queryByRole( 'heading', { name: MODAL_HEADING } )
		).not.toBeInTheDocument();
	} );
} );
