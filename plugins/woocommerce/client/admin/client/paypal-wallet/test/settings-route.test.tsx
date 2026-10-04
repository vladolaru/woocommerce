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

const RELOAD_MARKER_KEY = 'wc_paypal_wallet_settings_reloaded_at';

describe( 'PayPalWalletSettingsRoute', () => {
	const originalLocation = window.location;
	let reload: jest.Mock;

	const setReadyState = ( readyState: 'interactive' | 'complete' ) =>
		Object.defineProperty( document, 'readyState', {
			configurable: true,
			get: () => readyState,
		} );

	beforeEach( () => {
		setReadyState( 'complete' );
		reload = jest.fn();
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { ...originalLocation, reload },
		} );
		window.sessionStorage.clear();
		delete window.ppcpSettings;
	} );

	afterEach( () => {
		// Removes the instance override, which puts the real readyState back.
		delete ( document as { readyState?: unknown } ).readyState;
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
		delete window.ppcpSettings;
	} );

	it( 'renders the container the wallet app mounts into', () => {
		window.ppcpSettings = {};

		const { container } = render( <PayPalWalletSettingsRoute /> );

		expect(
			container.querySelector(
				'.paypal-wallet-settings > #ppcp-settings-container'
			)
		).toBeInTheDocument();
		expect( reload ).not.toHaveBeenCalled();
	} );

	it( 'leaves the page header to the wallet app', () => {
		window.ppcpSettings = {};

		render( <PayPalWalletSettingsRoute /> );

		expect( screen.queryByRole( 'link' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'button' ) ).not.toBeInTheDocument();
		expect( screen.queryByRole( 'heading' ) ).not.toBeInTheDocument();
		expect( screen.queryByText( 'PayPal Wallet' ) ).not.toBeInTheDocument();
		expect( screen.getByTestId( 'header' ) ).toHaveTextContent(
			'Settings'
		);
	} );

	it( 'reloads the page once when the settings app is not on the page', () => {
		render( <PayPalWalletSettingsRoute /> );

		expect( reload ).toHaveBeenCalledTimes( 1 );
		expect(
			window.sessionStorage.getItem( RELOAD_MARKER_KEY )
		).not.toBeNull();
	} );

	it( 'does not reload again right after a reload that did not bring the settings app', () => {
		window.sessionStorage.setItem(
			RELOAD_MARKER_KEY,
			String( Date.now() )
		);

		render( <PayPalWalletSettingsRoute /> );

		expect( reload ).not.toHaveBeenCalled();
	} );

	it( 'does not reload when the settings arrive between the first render and the load event', () => {
		setReadyState( 'interactive' );

		render( <PayPalWalletSettingsRoute /> );
		expect( reload ).not.toHaveBeenCalled();

		window.ppcpSettings = {};
		window.dispatchEvent( new Event( 'load' ) );

		expect( reload ).not.toHaveBeenCalled();
	} );

	it( 'reloads once when the settings are still missing at the load event', () => {
		setReadyState( 'interactive' );

		render( <PayPalWalletSettingsRoute /> );
		expect( reload ).not.toHaveBeenCalled();

		window.dispatchEvent( new Event( 'load' ) );
		window.dispatchEvent( new Event( 'load' ) );

		expect( reload ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'leaves no load listener behind when it unmounts before the page has loaded', () => {
		setReadyState( 'interactive' );

		const { unmount } = render( <PayPalWalletSettingsRoute /> );
		unmount();
		window.dispatchEvent( new Event( 'load' ) );

		expect( reload ).not.toHaveBeenCalled();
	} );
} );
