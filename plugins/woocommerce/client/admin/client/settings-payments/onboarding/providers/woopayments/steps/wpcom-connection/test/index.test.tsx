/**
 * External dependencies
 */
import {
	act,
	fireEvent,
	render,
	screen,
	waitFor,
} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import apiFetch from '@wordpress/api-fetch';
import { speak } from '@wordpress/a11y';

/**
 * Internal dependencies
 */
import { useOnboardingContext } from '../../../data/onboarding-context';
import JetpackStep from '../index';
import { recordPaymentsOnboardingEvent } from '~/settings-payments/utils';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/a11y', () => ( { speak: jest.fn() } ) );
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
jest.mock( '~/utils/admin-settings', () => ( {
	WC_ASSET_URL: '',
} ) );

const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
const mockUseOnboardingContext = useOnboardingContext as jest.Mock;

const AUTH_URL = 'https://wordpress.com/jetpack.authorize/1/';

// The actions of a store already registered with WordPress.com: the step sends the auth URL, not authorize.
const REGISTERED_STORE_ACTIONS = {
	start: { href: 'https://example.com/start' },
	auth: { href: AUTH_URL },
};

// The step's connection_state as WooPaymentsService::get_wpcom_connection_state() sends it: the owner-less store's
// answer recorded on a program store (has_working_connection false, is_store_connected true, has_connected_owner false).
const OWNERLESS_CONNECTION_STATE = {
	has_working_connection: false,
	is_store_connected: true,
	has_connected_owner: false,
	is_connection_owner: false,
};

const renderStep = (
	actions: Record< string, { href: string } > = {
		authorize: { href: 'https://example.com/authorize' },
	},
	connectionState?: Record< string, boolean >
) => {
	mockUseOnboardingContext.mockReturnValue( {
		currentStep: {
			id: 'wpcom_connection',
			status: 'not_started',
			actions,
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
		jest.clearAllMocks();
		mockApiFetch.mockReset();
		Object.defineProperty( window, 'location', {
			configurable: true,
			writable: true,
			value: { ...originalLocation, href: '' },
		} );
	} );

	afterEach( () => {
		Object.defineProperty( window, 'location', {
			configurable: true,
			writable: true,
			value: originalLocation,
		} );
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
		renderStep( REGISTERED_STORE_ACTIONS, connectionState );

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
		renderStep( REGISTERED_STORE_ACTIONS, OWNERLESS_CONNECTION_STATE );

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

	it( 'redirects to the authorization URL returned after registering the store', async () => {
		mockApiFetch.mockResolvedValue( {
			success: true,
			url: 'https://wordpress.com/auth',
		} );
		renderStep();

		fireEvent.click( screen.getByRole( 'button', { name: 'Connect' } ) );

		await waitFor( () =>
			expect( window.location.href ).toBe( 'https://wordpress.com/auth' )
		);
	} );

	it( 'redirects to the auth URL without a request when the store is already registered', () => {
		renderStep( { auth: { href: 'https://wordpress.com/auth' } } );

		fireEvent.click( screen.getByRole( 'button', { name: 'Connect' } ) );

		expect( mockApiFetch ).not.toHaveBeenCalled();
		expect( window.location.href ).toBe( 'https://wordpress.com/auth' );
	} );

	it( 'decodes registration errors and lets the merchant retry', async () => {
		mockApiFetch.mockRejectedValue( {
			message: 'The site&#039;s URL can&#039;t be reached.',
		} );
		renderStep();
		const connect = screen.getByRole( 'button', { name: 'Connect' } );

		fireEvent.click( connect );

		expect(
			await screen.findByText( "The site's URL can't be reached.", {
				selector: 'p',
			} )
		).toBeInTheDocument();
		expect( connect ).not.toBeDisabled();

		fireEvent.click( connect );

		await waitFor( () =>
			expect( mockApiFetch ).toHaveBeenCalledTimes( 2 )
		);
		expect( mockApiFetch ).toHaveBeenLastCalledWith( {
			url: 'https://example.com/authorize',
			method: 'POST',
			data: { source: 'settings_payments' },
		} );
		await waitFor( () => expect( connect ).not.toBeDisabled() );
	} );

	it( 'keeps Connect focused and announces progress while blocking repeated clicks', async () => {
		let rejectRegistration: ( error: Error ) => void;
		mockApiFetch.mockImplementation(
			() =>
				new Promise( ( resolve, reject ) => {
					rejectRegistration = reject;
				} )
		);
		renderStep();
		const connect = screen.getByRole( 'button', { name: 'Connect' } );
		connect.focus();

		fireEvent.click( connect );

		expect( connect ).toHaveFocus();
		expect( connect ).toHaveAttribute( 'aria-disabled', 'true' );
		expect( connect ).not.toBeDisabled();
		expect( speak ).toHaveBeenCalledWith(
			'Connecting to WordPress.com. Please wait.',
			'polite'
		);

		fireEvent.click( connect );
		expect( mockApiFetch ).toHaveBeenCalledTimes( 1 );

		await act( async () => {
			rejectRegistration( new Error( 'Registration failed.' ) );
		} );

		expect( connect ).toHaveFocus();
		expect( connect ).not.toHaveAttribute( 'aria-disabled', 'true' );
		expect(
			screen.getByText( 'Registration failed.', { selector: 'p' } )
		).toBeInTheDocument();
	} );

	it( 'shows the fallback message when authorization fails without a message', async () => {
		mockApiFetch.mockRejectedValue( {} );
		renderStep();

		fireEvent.click( screen.getByRole( 'button', { name: 'Connect' } ) );

		expect(
			await screen.findByText(
				'Something went wrong. Please try again.',
				{
					selector: 'p',
				}
			)
		).toBeInTheDocument();
	} );
} );
