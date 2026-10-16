/**
 * External dependencies
 */
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';

/**
 * Internal dependencies
 */
import { useOnboardingContext } from '../../../data/onboarding-context';
import JetpackStep from '../index';

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve() ) );

jest.mock( '../../../data/onboarding-context', () => ( {
	useOnboardingContext: jest.fn(),
} ) );

jest.mock( '../../../components/header', () => ( {
	__esModule: true,
	default: () => <div data-testid="step-header">Header</div>,
} ) );

jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsOnboardingEvent: jest.fn(),
} ) );

const mockUseOnboardingContext = useOnboardingContext as jest.Mock;

const AUTH_URL = 'https://wordpress.com/jetpack.authorize/1/';

const renderStep = ( connectionState?: Record< string, boolean > ) => {
	mockUseOnboardingContext.mockReturnValue( {
		currentStep: {
			id: 'wpcom_connection',
			status: 'not_started',
			actions: {
				start: { href: 'https://example.com/start' },
				auth: { href: AUTH_URL },
			},
			context:
				connectionState === undefined
					? {}
					: { connection_state: connectionState },
		},
		closeModal: jest.fn(),
		sessionEntryPoint: 'settings_payments',
	} );

	return render( <JetpackStep /> );
};

describe( 'JetpackStep', () => {
	const originalLocation = window.location;

	beforeEach( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: { href: '' },
		} );
	} );

	afterEach( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			value: originalLocation,
		} );
		jest.clearAllMocks();
	} );

	it.each( [
		[
			'a store not connected to WordPress.com',
			{
				wpcom_is_store_connected: false,
				wpcom_has_connected_owner: false,
			},
		],
		[
			'a connected store with its connection owner',
			{
				wpcom_is_store_connected: true,
				wpcom_has_connected_owner: true,
			},
		],
		[ 'a step sent without a connection state', undefined ],
	] )( 'asks to connect for %s', ( _label, connectionState ) => {
		renderStep( connectionState );

		expect(
			screen.getByRole( 'heading', { name: 'Connect to WordPress.com' } )
		).toBeInTheDocument();
		expect(
			screen.getByText( /briefly redirected to connect your store/ )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'button', { name: 'Connect' } )
		).toBeInTheDocument();
	} );

	it( 'asks to reconnect a connected store whose authorizing account is no longer linked', async () => {
		renderStep( {
			wpcom_is_store_connected: true,
			wpcom_has_connected_owner: false,
		} );

		expect(
			screen.getByRole( 'heading', {
				name: 'Reconnect to WordPress.com',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Your store is still connected to WordPress.com, but the account that authorized the connection is no longer linked. Reconnect to restore WooPayments.'
			)
		).toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Reconnect' } )
		);

		expect( window.location.href ).toBe( AUTH_URL );
	} );
} );
