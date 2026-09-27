/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import { useSelect } from '@wordpress/data';
import { recordEvent } from '@woocommerce/tracks';
import { loadConnectAndInitialize } from '@stripe/connect-js';
import type { ReactNode } from 'react';

/**
 * Internal dependencies
 */
import { WooPaymentsOverviewPage } from '../overview/page';
import {
	createWooPaymentsAccountSession,
	getWooPaymentsDepositsOverview,
	getWooPaymentsOverviewShell,
	getWooPaymentsRecentDeposits,
} from '../overview/data';

const mockCreateSuccessNotice = jest.fn();
const mockConnectInstance = { id: 'connect-instance' };
let mockBannerProps: Record< string, jest.Mock | unknown > | null = null;
let mockBannerError: Error | null = null;

jest.mock( '@stripe/connect-js', () => ( {
	loadConnectAndInitialize: jest.fn( () => mockConnectInstance ),
} ) );

jest.mock( '@stripe/react-connect-js', () => ( {
	ConnectComponentsProvider: ( {
		connectInstance,
		children,
	}: {
		connectInstance: unknown;
		children: ReactNode;
	} ) =>
		connectInstance === mockConnectInstance ? <>{ children }</> : null,
	ConnectNotificationBanner: ( props: Record< string, unknown > ) => {
		mockBannerProps = props;
		if ( mockBannerError ) {
			throw mockBannerError;
		}
		return <div data-testid="stripe-notification-banner" />;
	},
} ) );

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );
	return {
		...actual,
		// @woocommerce/components dispatches to other stores while it loads.
		dispatch: jest.fn( ( storeName ) =>
			storeName === 'core/notices'
				? { createSuccessNotice: mockCreateSuccessNotice }
				: actual.dispatch( storeName )
		),
		useSelect: jest.fn(),
	};
} );

jest.mock( '~/woopayments/settings/account-settings', () => ( {
	WooPaymentsAccountSettings: () => null,
} ) );

jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

jest.mock( '../overview/data', () => ( {
	createWooPaymentsAccountSession: jest.fn(),
	getWooPaymentsDepositsOverview: jest.fn(),
	getWooPaymentsDisputeReadiness: jest.fn(),
	getWooPaymentsOverviewDisputes: jest.fn(),
	getWooPaymentsOverviewShell: jest.fn(),
	getWooPaymentsRecentDeposits: jest.fn(),
} ) );

jest.mock( '@woocommerce/tracks', () => ( { recordEvent: jest.fn() } ) );

const mockCreateAccountSession =
	createWooPaymentsAccountSession as jest.MockedFunction<
		typeof createWooPaymentsAccountSession
	>;
const mockGetShell = getWooPaymentsOverviewShell as jest.MockedFunction<
	typeof getWooPaymentsOverviewShell
>;

const FINISH_SETUP_TASK = 'Finish setting up WooPayments';
const SESSION_ERROR =
	'Unable to start onboarding. If this problem persists, please contact support.';

const createShell = ( connected = true ) =>
	( {
		account: {
			connected,
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
	} ) as unknown as Awaited<
		ReturnType< typeof getWooPaymentsOverviewShell >
	>;

// The latest render's props, as Stripe's React wrapper forwards updated callbacks.
const bannerProps = () =>
	mockBannerProps as {
		onLoadError: jest.Mock;
		onNotificationsChange: jest.Mock;
	};

const renderWithBanner = async () => {
	render( <WooPaymentsOverviewPage /> );
	await screen.findByTestId( 'stripe-notification-banner' );
	return bannerProps();
};

const notifyChange = ( total: number, actionRequired: number ) =>
	act( () =>
		bannerProps().onNotificationsChange( { total, actionRequired } )
	);

const getBannerWrapper = () =>
	screen
		.getByTestId( 'stripe-notification-banner' )
		.closest( '.stripe-notifications-banner-wrapper' ) as HTMLElement;

describe( 'WooPayments Overview Stripe notifications banner', () => {
	beforeEach( () => {
		jest.clearAllMocks();
		mockBannerProps = null;
		mockBannerError = null;
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
		mockGetShell.mockResolvedValue( createShell() );
		mockCreateAccountSession.mockResolvedValue( {
			clientSecret: 'cs_test',
			publishableKey: 'pk_test',
			locale: 'fr_FR',
		} );
		Object.defineProperty( window, 'wcSettings', {
			configurable: true,
			value: { adminUrl: 'https://example.com/wp-admin/' },
		} );
	} );

	it( 'initializes Connect from the account session and renders the banner', async () => {
		const props = await renderWithBanner();

		expect( mockCreateAccountSession ).toHaveBeenCalledTimes( 1 );
		const options = ( loadConnectAndInitialize as jest.Mock ).mock
			.calls[ 0 ][ 0 ];
		expect( options ).toMatchObject( {
			publishableKey: 'pk_test',
			locale: 'fr-FR',
			appearance: {
				overlays: 'drawer',
				variables: { colorPrimary: '#873EFF' },
			},
		} );
		await expect( options.fetchClientSecret() ).resolves.toBe( 'cs_test' );
		expect( props ).toMatchObject( {
			collectionOptions: {
				fields: 'eventually_due',
				futureRequirements: 'omit',
			},
		} );
		// Client parity: hidden until Stripe reports notifications.
		expect( getBannerWrapper() ).not.toBeVisible();
	} );

	it( 'hides the update-details task while the banner renders and shows it after a load error', async () => {
		const props = await renderWithBanner();

		expect( screen.queryByText( FINISH_SETUP_TASK ) ).toBeNull();

		act( () => {
			props.onLoadError( {
				elementTagName: 'stripe-connect-notification-banner',
				error: { type: 'invalid_request_error', message: 'HTTPS' },
			} );
		} );

		expect( screen.getByText( FINISH_SETUP_TASK ) ).toBeInTheDocument();
		expect(
			screen.getByText( /require HTTPS and cannot be displayed/, {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
	} );

	it( 'shows the update-details task and creates no session without a connected account', async () => {
		mockGetShell.mockResolvedValue( createShell( false ) );

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByText( FINISH_SETUP_TASK )
		).toBeInTheDocument();
		expect( mockCreateAccountSession ).not.toHaveBeenCalled();
		expect(
			screen.queryByTestId( 'stripe-notification-banner' )
		).toBeNull();
	} );

	it( 'records the update and action-completed events from notification changes', async () => {
		await renderWithBanner();

		notifyChange( 0, 0 );
		expect( recordEvent ).not.toHaveBeenCalledWith(
			'wcpay_overview_stripe_notifications_banner_action_completed'
		);

		notifyChange( 2, 1 );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_stripe_notifications_banner_update',
			{ action_required_count: 1, total_count: 2 }
		);
		expect( getBannerWrapper() ).toBeVisible();

		notifyChange( 0, 0 );
		expect( recordEvent ).toHaveBeenCalledWith(
			'wcpay_overview_stripe_notifications_banner_action_completed'
		);
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Updates take a moment to appear. Please refresh the page in a minute.',
			expect.objectContaining( {
				explicitDismiss: true,
				actions: [
					expect.objectContaining( {
						label: 'Refresh',
						url: expect.stringContaining(
							'woopayments%2Foverview'
						),
					} ),
				],
			} )
		);
		expect( getBannerWrapper() ).not.toBeVisible();
		expect( screen.queryByText( FINISH_SETUP_TASK ) ).toBeNull();
	} );

	it( 'shows the error notice and no banner when the account session fails', async () => {
		mockCreateAccountSession.mockRejectedValue( {
			code: 'woocommerce_woopayments_account_session_error',
			message: 'Unable to create the WooPayments account session.',
		} );

		render( <WooPaymentsOverviewPage /> );

		expect(
			await screen.findByText( SESSION_ERROR, {
				selector: '.woopayments-banner-notice__content',
			} )
		).toBeInTheDocument();
		await waitFor( () =>
			expect( loadConnectAndInitialize ).not.toHaveBeenCalled()
		);
		expect(
			screen.queryByTestId( 'stripe-notification-banner' )
		).toBeNull();
	} );

	describe( 'when the banner fails', () => {
		let consoleErrorSpy: jest.SpyInstance;

		beforeEach( () => {
			// React logs caught render errors; keep the output clean.
			consoleErrorSpy = jest
				.spyOn( console, 'error' )
				.mockImplementation( () => {} );
		} );

		afterEach( () => {
			consoleErrorSpy.mockRestore();
		} );

		it( 'keeps the Overview and shows the update-details task when the banner throws', async () => {
			mockBannerError = new Error( 'Stripe wrapper failed' );

			render( <WooPaymentsOverviewPage /> );

			expect(
				await screen.findByText( FINISH_SETUP_TASK )
			).toBeInTheDocument();
			expect(
				screen.getByRole( 'heading', { level: 1, name: 'Overview' } )
			).toBeInTheDocument();
			expect(
				document.querySelector( '.stripe-notifications-banner-loader' )
			).toBeNull();
		} );
	} );
} );
