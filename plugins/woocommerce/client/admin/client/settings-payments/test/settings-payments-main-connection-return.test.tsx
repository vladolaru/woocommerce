/**
 * External dependencies
 */
import type { ReactNode } from 'react';
import { recordEvent } from '@woocommerce/tracks';
import { act, render, screen, waitFor } from '@testing-library/react';
import { MemoryRouter as Router } from 'react-router-dom';
import { dispatch } from '@wordpress/data';
import { paymentSettingsStore } from '@woocommerce/data';
import { getHistory } from '@woocommerce/navigation';
import type { SuggestedPaymentsExtension } from '@woocommerce/data';
import apiFetch from '@wordpress/api-fetch';

/**
 * Internal dependencies
 */
import { SettingsPaymentsMain } from '../settings-payments-main';

const mockCreateErrorNotice = jest.fn();

jest.mock( '@wordpress/data', () => {
	const actual = jest.requireActual( '@wordpress/data' );
	return {
		...actual,
		dispatch: ( store: unknown ) =>
			store === 'core/notices'
				? { createErrorNotice: mockCreateErrorNotice }
				: actual.dispatch( store ),
	};
} );

jest.mock( '@woocommerce/tracks', () => ( {
	recordEvent: jest.fn(),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn().mockResolvedValue( {} ) );

jest.mock( '~/utils/features', () => ( {
	isFeatureEnabled: jest.fn(),
} ) );

jest.mock( '~/settings-payments/components/payment-gateways', () => ( {
	PaymentGateways: () => <div>Payment gateways list</div>,
} ) );

// The WooPayments modal itself is real; only its frame and the onboarding steps are stand-ins.
jest.mock( '~/settings-payments/onboarding/components/modal', () => ( {
	__esModule: true,
	default: ( { children }: { children: ReactNode } ) => (
		<div role="dialog">{ children }</div>
	),
} ) );
jest.mock(
	'~/settings-payments/onboarding/providers/woopayments/components/onboarding',
	() => ( {
		__esModule: true,
		default: () => <div>WooPayments onboarding steps</div>,
	} )
);

// The built-in WooPayments suggestion as "More payment options" lists it once hidden, with its onboarding state.
const hiddenBuiltinSuggestion = ( wpcomConnected: boolean ) =>
	( {
		id: 'woopayments',
		_priority: 1,
		_type: 'psp',
		title: 'Accept payments with Woo',
		description: 'Payments',
		short_description: '',
		plugin: { status: 'not_installed' },
		image: '',
		icon: '',
		links: [],
		tags: [],
		category: '',
		onboarding: {
			type: 'native_in_context',
			state: {
				wpcom_has_working_connection: wpcomConnected,
				wpcom_is_store_connected: wpcomConnected,
				wpcom_has_connected_owner: wpcomConnected,
				wpcom_is_connection_owner: wpcomConnected,
			},
		},
		_links: {},
	} ) as unknown as SuggestedPaymentsExtension;

const opened = 'settings_payments_woopayments_onboarding_modal_opened';
const cancelled =
	'settings_payments_woopayments_onboarding_wpcom_connection_cancelled';

describe( 'SettingsPaymentsMain, back from the WordPress.com connection for the hidden built-in suggestion', () => {
	beforeEach( () => {
		( recordEvent as jest.Mock ).mockClear();
		mockCreateErrorNotice.mockClear();
		// The return from WordPress.com lands on the onboarding route with the return flag.
		getHistory().push(
			'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fwoopayments%2Fonboarding&wpcom_connection_return=1'
		);
	} );

	afterEach( () => {
		( apiFetch as jest.Mock ).mockReset().mockResolvedValue( {} );
		getHistory().push( '/' );
		act( () => {
			dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
				[],
				[],
				[],
				[]
			);
		} );
	} );

	it.each( [
		{
			name: 'continues the onboarding when the store is connected',
			connected: true,
			event: opened,
			absentEvent: cancelled,
			onboardingShown: true,
			notices: [],
		},
		{
			name: 'shows the cancel notice when the store is not connected',
			connected: false,
			event: cancelled,
			absentEvent: opened,
			onboardingShown: false,
			notices: [ 'Setup was cancelled!' ],
		},
	] )(
		'$name',
		async ( {
			connected,
			event,
			absentEvent,
			onboardingShown,
			notices,
		} ) => {
			const suggestion = hiddenBuiltinSuggestion( connected );
			// The opened onboarding loads its steps and refreshes the providers; the list stays as it is.
			( apiFetch as jest.Mock ).mockImplementation( ( request ) => {
				if ( request.path?.includes( '/woopayments/onboarding' ) ) {
					return Promise.resolve( { steps: [], context: {} } );
				}
				return Promise.resolve(
					request.path?.includes( '/settings/payments/providers' )
						? {
								providers: [],
								offline_payment_methods: [],
								suggestions: [ suggestion ],
								suggestion_categories: [],
							}
						: {}
				);
			} );
			act( () => {
				dispatch( paymentSettingsStore ).getPaymentProvidersSuccess(
					[],
					[],
					[ suggestion ],
					[]
				);
			} );

			render(
				<Router>
					<SettingsPaymentsMain />
				</Router>
			);

			await waitFor( () =>
				expect( recordEvent ).toHaveBeenCalledWith(
					event,
					expect.anything()
				)
			);
			await waitFor( () =>
				expect(
					screen.queryByText( 'WooPayments onboarding steps' ) !==
						null
				).toBe( onboardingShown )
			);
			expect( recordEvent ).not.toHaveBeenCalledWith(
				absentEvent,
				expect.anything()
			);
			expect(
				mockCreateErrorNotice.mock.calls.map(
					( [ message ] ) => message
				)
			).toEqual( notices );
		}
	);
} );
