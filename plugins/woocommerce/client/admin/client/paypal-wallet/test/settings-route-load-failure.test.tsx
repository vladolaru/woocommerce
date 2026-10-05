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

// Stands in for a chunk that fails to download: the lazy import rejects.
jest.mock( '../app', () => {
	throw new Error( 'Loading chunk paypal-wallet-settings-app failed.' );
} );

describe( 'PayPalWalletSettingsRoute when the app chunk fails to load', () => {
	beforeEach( () => {
		window.ppcpSettings = {};
		// React logs the error the boundary catches.
		jest.spyOn( console, 'error' ).mockImplementation( () => undefined );
	} );

	afterEach( () => {
		delete window.ppcpSettings;
	} );

	it( 'shows a notice with a link that loads the route again instead of a blank screen', async () => {
		const { container } = render( <PayPalWalletSettingsRoute /> );

		await screen.findByRole( 'link', { name: 'Reload the page' } );
		expect(
			container.querySelector( '.paypal-wallet-settings__notice' )
		).toHaveTextContent(
			'The PayPal Wallet settings could not be loaded.'
		);
		expect(
			screen.getByRole( 'link', { name: 'Reload the page' } )
		).toHaveAttribute( 'href', window.location.href.split( '#' )[ 0 ] );
		expect( screen.getByTestId( 'header' ) ).toHaveTextContent(
			'Settings'
		);
	} );
} );
