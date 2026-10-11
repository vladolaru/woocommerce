/**
 * External dependencies
 */
import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { getHistory } from '@woocommerce/navigation';
import { MemoryRouter } from 'react-router-dom';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { woopaymentsProviderRoutes } from '../routes';
import { SettingsPaymentsWooPaymentsWrapper } from '~/settings-payments';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '../overview', () => () => 'Overview route loaded' );
jest.mock(
	'../money-movement/transactions',
	() => () => 'Transactions route loaded'
);
jest.mock( '../money-movement/disputes', () => () => 'Disputes route loaded' );
jest.mock( '../../settings', () => () => 'Settings route loaded' );
jest.mock( '../../settings/settings-page', () => {
	const React = jest.requireActual( 'react' );

	return {
		WooPaymentsSettingsPage: () =>
			React.createElement(
				'section',
				{ 'aria-labelledby': 'woopayments-settings-page-heading' },
				'Settings page loaded'
			),
	};
} );
jest.mock(
	'../../settings/express-checkout',
	() => () => 'Express settings route loaded'
);
jest.mock(
	'../../settings/fraud-protection/advanced',
	() => () => 'Fraud settings route loaded'
);

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const unavailableMessage = 'This WooPayments admin area is unavailable.';

const protectedRouteAvailability = {
	gatewayEnabled: true,
	accountState: 'full',
	allowedRoutes: {
		'/woopayments/settings': true,
		'/woopayments/overview': true,
		'/woopayments/payouts': true,
		'/woopayments/payouts/details': true,
		'/woopayments/transactions': true,
		'/woopayments/transactions/details': true,
		'/woopayments/disputes': true,
		'/woopayments/disputes/details': true,
		'/woopayments/disputes/challenge': true,
		'/woopayments/reports': true,
		'/woopayments/card-readers': true,
		'/woopayments/loans': true,
		'/woopayments/documents': true,
	},
};

const setAdminRouteAvailability = (
	allowedRoutes: Record< string, boolean >
) => {
	window.wcSettings = {
		adminUrl: 'http://example.com/wp-admin',
		admin: {
			woopaymentsSettings: {
				adminRouteAvailability: {
					...protectedRouteAvailability,
					allowedRoutes: {
						...protectedRouteAvailability.allowedRoutes,
						...allowedRoutes,
					},
				},
			},
		},
	};
};

const getRouteElement = ( routePath: string ) => {
	const route = woopaymentsProviderRoutes.find(
		( { path: registeredPath } ) => registeredPath === routePath
	);

	expect( route ).toBeDefined();
	if ( ! route ) {
		throw new Error(
			`Expected the WooPayments route ${ routePath } to exist.`
		);
	}

	return route.element;
};

const expectRouteUnavailable = ( routePath: string ) => {
	render( getRouteElement( routePath ) );

	expect( screen.getByRole( 'status' ) ).toHaveTextContent(
		unavailableMessage
	);
	// Monitor row L13: the message sits in a card, not as bare text on the page.
	expect(
		screen.getByRole( 'status' ).closest( '.components-card' )
	).not.toBeNull();
	expect(
		screen.queryByText( 'Loading WooPayments…' )
	).not.toBeInTheDocument();
	expect( mockApiFetch ).not.toHaveBeenCalled();
};

describe( 'WooPayments Settings Payments routes', () => {
	beforeEach( () => {
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
		};
		delete (
			window as typeof window & {
				wcpaySettings?: unknown;
			}
		 ).wcpaySettings;
		mockApiFetch.mockReset();
	} );

	it( 'lists the WooPayments routes the Payments settings app reads, in order', () => {
		expect(
			woopaymentsProviderRoutes.map(
				( { path: routePath } ) => routePath
			)
		).toEqual( [
			'/woopayments/settings',
			'/woopayments/settings/express-checkout/:methodId',
			'/woopayments/settings/fraud-protection',
			'/woopayments/overview',
			'/woopayments/payouts',
			'/woopayments/payouts/details',
			'/woopayments/transactions',
			'/woopayments/transactions/details',
			'/woopayments/reports',
			'/woopayments/disputes',
			'/woopayments/disputes/details',
			'/woopayments/disputes/challenge',
			'/woopayments/card-readers',
			'/woopayments/loans',
			'/woopayments/documents',
		] );
		woopaymentsProviderRoutes.forEach( ( route ) => {
			expect( route.element ).toBeDefined();
		} );
		expect( JSON.stringify( woopaymentsProviderRoutes ) ).not.toContain(
			'wc-pay-welcome-page'
		);
	} );

	it( 'does not load the Reports chunk when the Reports feature flag is disabled', () => {
		window.wcSettings = {
			adminUrl: 'http://example.com/wp-admin',
			admin: {
				woopaymentsSettings: {
					featureFlags: {
						reportsArea: false,
					},
					adminRouteAvailability: protectedRouteAvailability,
				},
			},
		};

		const route = woopaymentsProviderRoutes.find(
			( { path: routePath } ) => routePath === '/woopayments/reports'
		);

		expect( route ).toBeDefined();
		if ( ! route ) {
			throw new Error(
				'Expected the WooPayments Reports route to exist.'
			);
		}

		render( route.element );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Reports are unavailable.'
		);
		expect(
			screen.queryByText( 'Loading WooPayments…' )
		).not.toBeInTheDocument();
	} );

	it( 'uses the legacy Reports feature flag when native settings do not provide one', () => {
		(
			window as typeof window & {
				wcpaySettings?: {
					featureFlags?: {
						reportsArea?: boolean;
					};
				};
			}
		 ).wcpaySettings = {
			featureFlags: {
				reportsArea: false,
			},
		};
		setAdminRouteAvailability( {
			'/woopayments/reports': true,
		} );

		render( getRouteElement( '/woopayments/reports' ) );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Reports are unavailable.'
		);
		expect(
			screen.queryByText( 'Loading WooPayments…' )
		).not.toBeInTheDocument();
	} );

	it.each( [
		[ 'Capital', '/woopayments/loans' ],
		[ 'Documents', '/woopayments/documents' ],
		[ 'Card Readers', '/woopayments/card-readers' ],
		[ 'Reports', '/woopayments/reports' ],
		[ 'Payout details', '/woopayments/payouts/details' ],
		[ 'Transaction details', '/woopayments/transactions/details' ],
		[ 'Dispute details', '/woopayments/disputes/details' ],
		[ 'Dispute challenge', '/woopayments/disputes/challenge' ],
	] )(
		'renders an unavailable status for the %s route when route availability denies access',
		( _routeName, routePath ) => {
			setAdminRouteAvailability( {
				[ routePath ]: false,
			} );

			expectRouteUnavailable( routePath );
		}
	);

	it.each( [
		[ 'Overview', '/woopayments/overview' ],
		[ 'Transactions', '/woopayments/transactions' ],
		[ 'Disputes', '/woopayments/disputes' ],
	] )(
		'renders an unavailable status for the %s route when route availability is missing',
		( _routeName, routePath ) => {
			expectRouteUnavailable( routePath );
		}
	);

	it.each( [
		[ 'Overview', '/woopayments/overview' ],
		[ 'Transactions', '/woopayments/transactions' ],
		[ 'Disputes', '/woopayments/disputes' ],
	] )(
		'renders an unavailable status for the %s route when route availability does not name the route',
		( _routeName, routePath ) => {
			window.wcSettings = {
				adminUrl: 'http://example.com/wp-admin',
				admin: {
					woopaymentsSettings: {
						adminRouteAvailability: {
							...protectedRouteAvailability,
							allowedRoutes: {
								'/woopayments/settings': true,
							},
						},
					},
				},
			};

			expectRouteUnavailable( routePath );
		}
	);

	it( 'renders an overview fallback link for denied protected routes when overview is allowed', () => {
		setAdminRouteAvailability( {
			'/woopayments/payouts': false,
			'/woopayments/overview': true,
		} );

		render( getRouteElement( '/woopayments/payouts' ) );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			unavailableMessage
		);
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Your current account status does not allow access to this page.'
		);
		expect(
			screen.getByRole( 'link', {
				name: 'Go to WooPayments overview',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Foverview'
		);
	} );

	it( 'renders a settings fallback link for denied protected routes when overview is unavailable', () => {
		setAdminRouteAvailability( {
			'/woopayments/payouts': false,
			'/woopayments/overview': false,
		} );

		render( getRouteElement( '/woopayments/payouts' ) );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			unavailableMessage
		);
		expect(
			screen.getByRole( 'link', {
				name: 'Go to WooPayments settings',
			} )
		).toHaveAttribute(
			'href',
			'http://example.com/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fsettings'
		);
	} );

	it.each( [
		[ 'settings', '/woopayments/settings', 'Settings route loaded' ],
		[
			'express checkout settings',
			'/woopayments/settings/express-checkout/:methodId',
			'Express settings route loaded',
		],
		[
			'fraud protection settings',
			'/woopayments/settings/fraud-protection',
			'Fraud settings route loaded',
		],
	] )(
		'keeps the %s route loadable when protected admin routes are denied',
		async ( _routeName, routePath, loadedText ) => {
			setAdminRouteAvailability( {
				'/woopayments/overview': false,
				'/woopayments/payouts': false,
				'/woopayments/payouts/details': false,
				'/woopayments/transactions': false,
				'/woopayments/transactions/details': false,
				'/woopayments/disputes': false,
				'/woopayments/disputes/details': false,
				'/woopayments/disputes/challenge': false,
				'/woopayments/reports': false,
				'/woopayments/card-readers': false,
				'/woopayments/loans': false,
				'/woopayments/documents': false,
			} );

			render( getRouteElement( routePath ) );

			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				'Loading WooPayments…'
			);
			expect(
				screen.queryByText( unavailableMessage )
			).not.toBeInTheDocument();
			expect( await screen.findByText( loadedText ) ).toBeInTheDocument();
		}
	);

	it.each( [
		[ 'settings', '/woopayments/settings', 'Settings route loaded' ],
		[
			'express checkout settings',
			'/woopayments/settings/express-checkout/:methodId',
			'Express settings route loaded',
		],
		[
			'fraud protection settings',
			'/woopayments/settings/fraud-protection',
			'Fraud settings route loaded',
		],
	] )(
		'keeps the %s route loadable when route availability is missing',
		async ( _routeName, routePath, loadedText ) => {
			render( getRouteElement( routePath ) );

			expect(
				screen.queryByText( unavailableMessage )
			).not.toBeInTheDocument();
			expect( await screen.findByText( loadedText ) ).toBeInTheDocument();
		}
	);

	it( 'keeps restricted-account routes available only for reduced-access surfaces', async () => {
		setAdminRouteAvailability( {
			'/woopayments/overview': true,
			'/woopayments/transactions': true,
			'/woopayments/disputes': true,
			'/woopayments/payouts': false,
			'/woopayments/reports': false,
			'/woopayments/card-readers': false,
			'/woopayments/loans': false,
			'/woopayments/documents': false,
		} );

		const availableRoutes = [
			{
				path: '/woopayments/overview',
				loadedText: 'Overview route loaded',
			},
			{
				path: '/woopayments/transactions',
				loadedText: 'Transactions route loaded',
			},
			{
				path: '/woopayments/disputes',
				loadedText: 'Disputes route loaded',
			},
		];

		for ( const { loadedText, path: routePath } of availableRoutes ) {
			render( getRouteElement( routePath ) );
			expect( screen.getByRole( 'status' ) ).toHaveTextContent(
				'Loading WooPayments…'
			);
			expect(
				screen.queryByText( unavailableMessage )
			).not.toBeInTheDocument();
			expect( await screen.findByText( loadedText ) ).toBeInTheDocument();
			cleanup();
		}

		[
			'/woopayments/payouts',
			'/woopayments/reports',
			'/woopayments/card-readers',
			'/woopayments/loans',
			'/woopayments/documents',
		].forEach( ( routePath ) => {
			mockApiFetch.mockReset();
			expectRouteUnavailable( routePath );
			cleanup();
		} );
	} );

	it( 'announces lazy route loading through a status fallback', async () => {
		setAdminRouteAvailability( {
			'/woopayments/loans': true,
		} );
		mockApiFetch
			.mockResolvedValueOnce( {} )
			.mockResolvedValueOnce( {} )
			.mockResolvedValueOnce( {
				data: [],
			} );

		const route = woopaymentsProviderRoutes.find(
			( { path: routePath } ) => routePath === '/woopayments/loans'
		);

		expect( route ).toBeDefined();
		if ( ! route ) {
			throw new Error(
				'Expected the WooPayments Capital route to exist.'
			);
		}

		render( route.element );

		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'Loading WooPayments…'
		);
		expect( screen.getByRole( 'status' ) ).toHaveAttribute(
			'aria-busy',
			'true'
		);
		expect(
			await screen.findByText( 'No Capital loans found.', undefined, {
				timeout: 3000,
			} )
		).toBeInTheDocument();
		expect( screen.getByRole( 'status' ) ).toHaveTextContent(
			'No Capital loans found.'
		);
	} );

	it( 'renders the native settings page under the Payments settings header', async () => {
		const push = jest
			.spyOn( getHistory(), 'push' )
			.mockImplementation( () => undefined );

		try {
			render(
				<MemoryRouter>
					<SettingsPaymentsWooPaymentsWrapper />
				</MemoryRouter>
			);

			const heading = screen.getByRole( 'heading', {
				level: 1,
				name: 'WooPayments',
			} );
			expect(
				await screen.findByRole( 'region', { name: 'WooPayments' } )
			).toBeInTheDocument();

			await userEvent.click(
				within( heading ).getByRole( 'button', { name: 'WooPayments' } )
			);
			expect( push ).toHaveBeenCalledWith(
				'admin.php?page=wc-settings&tab=checkout'
			);
		} finally {
			push.mockRestore();
		}
	} );
} );
