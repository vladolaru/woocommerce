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
import { recordPaymentsOnboardingEvent } from '~/settings-payments/utils';

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

// The step's connection_state as WooPaymentsService::get_wpcom_connection_state() sends it: the owner-less store's
// answer recorded on a program store (has_working_connection false, is_store_connected true, has_connected_owner false).
const OWNERLESS_CONNECTION_STATE = {
	has_working_connection: false,
	is_store_connected: true,
	has_connected_owner: false,
	is_connection_owner: false,
};

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
				has_working_connection: false,
				is_store_connected: false,
				has_connected_owner: false,
				is_connection_owner: false,
			},
		],
		[
			'a connected store with its connection owner',
			{
				has_working_connection: true,
				is_store_connected: true,
				has_connected_owner: true,
				is_connection_owner: true,
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
		renderStep( OWNERLESS_CONNECTION_STATE );

		expect(
			screen.getByRole( 'heading', {
				name: 'Reconnect to WordPress.com',
			} )
		).toBeInTheDocument();
		expect(
			screen.getByText(
				'Your store is connected to WordPress.com, but the account that authorized it is no longer linked. Reconnect to restore WooPayments.'
			)
		).toBeInTheDocument();

		await userEvent.click(
			screen.getByRole( 'button', { name: 'Reconnect' } )
		);

		expect( window.location.href ).toBe( AUTH_URL );
		expect( recordPaymentsOnboardingEvent ).toHaveBeenCalledWith(
			'woopayments_onboarding_modal_click',
			{
				step: 'wpcom_connection',
				action: 'connect_to_wpcom',
				source: 'settings_payments',
			}
		);
	} );
} );
