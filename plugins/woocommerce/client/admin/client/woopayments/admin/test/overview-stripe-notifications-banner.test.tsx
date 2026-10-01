/**
 * External dependencies
 */
import { act, render, screen, within } from '@testing-library/react';
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
const ONBOARDING_FAILURE =
	'Unable to start onboarding. If this problem persists, please contact support.';

const createShell = ( connected = true, status = 'restricted' ) =>
	( {
		account: {
			connected,
			working: false,
			can_process_payments: true,
			test_mode_onboarding: false,
		},
		account_status: {
			status,
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
	// The banner is a React.lazy chunk; the first test in the file pays its cold import, which exceeds the
	// default 1s wait on loaded CI runners.
	await screen.findByTestId(
		'stripe-notification-banner',
		{},
		{ timeout: 10000 }
	);
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
		// Client parity: a non-dismissible warning BannerNotice with its status icon.
		const httpsWarning = screen.getByText(
			/require HTTPS and cannot be displayed/,
			{ selector: '.woopayments-banner-notice__content' }
		);
		expect(
			httpsWarning.parentElement?.querySelector(
				'.woopayments-banner-notice__icon'
			)
		).not.toBeNull();
		expect(
			httpsWarning.parentElement?.querySelector(
				'.woopayments-banner-notice__dismiss'
			)
		).toBeNull();
		expect(
			within( httpsWarning ).getByRole( 'link', { name: /See details/ } )
		).toHaveAttribute(
			'href',
			'https://woocommerce.com/document/woopayments/startup-guide/#requirements'
		);
	} );

	// Client 11.1.0 overview/index.js:72-74: the task waits for the banner's onLoadError, which never fires without the banner.
	it( 'hides the update-details task and creates no session without a connected account', async () => {
		mockGetShell.mockResolvedValue( createShell( false ) );

		render( <WooPaymentsOverviewPage /> );
		await act( () => Promise.resolve() );

		expect( mockGetShell ).toHaveBeenCalled();
		expect( screen.queryByText( FINISH_SETUP_TASK ) ).toBeNull();
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

	// Client 11.1.0 embedded-components/index.tsx:203-207 renders the session error inside the hidden wrapper
	// (overview/index.js:318-325), so the merchant never sees onboarding copy on the Overview. Unlike its
	// endless loader card, native stops loading and shows the update-details task (owner rule, inbox N-139).
	it.each( [
		[
			'the account session read fails',
			() =>
				mockCreateAccountSession.mockRejectedValue( {
					code: 'woocommerce_woopayments_account_session_error',
					message: 'Internal Server Error',
				} ),
		],
		[
			'the account session has no publishable key',
			() =>
				mockCreateAccountSession.mockResolvedValue( {
					clientSecret: 'cs_test',
					publishableKey: '',
					locale: 'fr_FR',
				} ),
		],
	] )(
		'never renders onboarding-failure copy when %s',
		async ( _label, arrange ) => {
			arrange();

			render( <WooPaymentsOverviewPage /> );

			expect(
				await screen.findByText( FINISH_SETUP_TASK )
			).toBeInTheDocument();
			expect( screen.queryByText( ONBOARDING_FAILURE ) ).toBeNull();
			expect(
				screen.queryByText( /Unable to start onboarding/ )
			).toBeNull();
			expect( document.querySelector( '.stripe-spinner' ) ).toBeNull();
			expect(
				document.querySelector( '.stripe-notifications-banner-loader' )
			).toBeNull();
			expect(
				screen.queryByTestId( 'stripe-notification-banner' )
			).toBeNull();
			expect( loadConnectAndInitialize ).not.toHaveBeenCalled();
		}
	);

	it.each( [ 'rejected.fraud', 'under_review' ] )(
		'hides the update-details task and creates no session for account status %s',
		async ( status ) => {
			mockGetShell.mockResolvedValue( createShell( true, status ) );

			render( <WooPaymentsOverviewPage /> );
			await act( () => Promise.resolve() );

			expect( mockGetShell ).toHaveBeenCalled();
			expect( screen.queryByText( FINISH_SETUP_TASK ) ).toBeNull();
			expect( mockCreateAccountSession ).not.toHaveBeenCalled();
		}
	);

	it( 'shows the loader card until Stripe reports notifications', async () => {
		await renderWithBanner();

		expect(
			document.querySelector( '.stripe-notifications-banner-loader' )
		).not.toBeNull();

		notifyChange( 0, 0 );

		expect(
			document.querySelector( '.stripe-notifications-banner-loader' )
		).toBeNull();
	} );

	it( 'shows no loader card for a complete account', async () => {
		mockGetShell.mockResolvedValue( createShell( true, 'complete' ) );

		await renderWithBanner();

		expect(
			document.querySelector( '.stripe-notifications-banner-loader' )
		).toBeNull();
	} );

	it( 'shows the HTTPS warning only for an invalid request load error', async () => {
		const props = await renderWithBanner();

		act( () => {
			props.onLoadError( {
				elementTagName: 'stripe-connect-notification-banner',
				error: { type: 'api_error', message: 'Stripe is down' },
			} );
		} );

		expect( screen.getByText( FINISH_SETUP_TASK ) ).toBeInTheDocument();
		// Scoped to the notice: an earlier test's a11y-speak region keeps the spoken copy.
		expect(
			screen.queryByText( /require HTTPS and cannot be displayed/, {
				selector: '.woopayments-banner-notice__content',
			} )
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
