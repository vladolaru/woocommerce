/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';

/**
 * Internal dependencies
 */
import { PayPalWalletSettingsRoute } from '../settings-route';

jest.mock( '~/settings-payments/components/header/header', () => ( {
	Header: ( { title }: { title: string } ) => (
		<div data-testid="header">{ title }</div>
	),
} ) );

const mockAppRender = jest.fn();

jest.mock( '../app', () => ( {
	__esModule: true,
	default: () => {
		mockAppRender();
		return <div data-testid="paypal-wallet-app">PayPal wallet app</div>;
	},
} ) );

describe( 'PayPalWalletSettingsRoute', () => {
	beforeEach( () => {
		mockAppRender.mockClear();
		window.ppcpSettings = {};
	} );

	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it( 'renders the settings app from its lazy chunk inside the container its styles are scoped to', async () => {
		const { container } = render( <PayPalWalletSettingsRoute /> );

		const app = await screen.findByTestId( 'paypal-wallet-app' );

		expect(
			container.querySelector(
				'.paypal-wallet-settings#ppcp-settings-container'
			)
		).toContainElement( app );
		expect( mockAppRender ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves the page header to the wallet app', async () => {
		render( <PayPalWalletSettingsRoute /> );
		await screen.findByTestId( 'paypal-wallet-app' );

		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'header' ) ).toHaveTextContent(
			'Settings'
		);
	} );

	it( 'mounts the app again on re-entry', async () => {
		const first = render( <PayPalWalletSettingsRoute /> );
		await screen.findByTestId( 'paypal-wallet-app' );
		first.unmount();

		render( <PayPalWalletSettingsRoute /> );
		await screen.findByTestId( 'paypal-wallet-app' );

		expect( screen.getAllByTestId( 'paypal-wallet-app' ) ).toHaveLength(
			1
		);
		expect( mockAppRender ).toHaveBeenCalledTimes( 2 );
	} );

	it( 'shows a notice with a link that loads the route again when the page has no settings data', () => {
		delete window.ppcpSettings;

		const { container } = render( <PayPalWalletSettingsRoute /> );

		expect(
			container.querySelector( '.paypal-wallet-settings__notice' )
		).toHaveTextContent(
			'The PayPal Wallet settings could not be loaded.'
		);
		expect(
			screen.getByRole( 'link', { name: 'Reload the page' } )
		).toHaveAttribute( 'href', window.location.href.split( '#' )[ 0 ] );
		expect( screen.queryByTestId( 'paypal-wallet-app' ) ).toBeNull();
		expect( mockAppRender ).not.toHaveBeenCalled();
	} );
} );

const registeredRouteIds = async () => {
	let ids: string[] = [];

	await jest.isolateModulesAsync( async () => {
		const { getSettingsPaymentsProviderRoutes } = await import(
			'~/settings-payments/provider-routes'
		);
		await import( '../routes' );

		ids = getSettingsPaymentsProviderRoutes().map( ( { id } ) => id );
	} );

	return ids;
};

describe( 'PayPal wallet route registration', () => {
	const originalSettings = window.wcSettings;

	afterEach( () => {
		window.wcSettings = originalSettings;
	} );

	const setOwnership = ( admin: Record< string, unknown > ) => {
		window.wcSettings = {
			...originalSettings,
			admin: admin as typeof window.wcSettings.admin,
		};
	};

	it( 'registers the route while core owns the PayPal wallet', async () => {
		setOwnership( { paypalWalletOwned: true } );

		expect( await registeredRouteIds() ).toEqual( [
			'paypal-wallet-settings',
		] );
	} );

	it.each( [
		[ 'the flag is absent', {} ],
		[ 'the flag is not exactly true', { paypalWalletOwned: 'yes' } ],
	] )(
		'does not register the route when %s',
		async ( _description, admin ) => {
			setOwnership( admin );

			expect( await registeredRouteIds() ).toEqual( [] );
		}
	);
} );
