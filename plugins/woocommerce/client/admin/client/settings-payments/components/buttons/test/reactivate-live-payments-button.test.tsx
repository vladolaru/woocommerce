/**
 * External dependencies
 */
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, useDispatch } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { ReactivateLivePaymentsButton } from '../reactivate-live-payments-button';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@woocommerce/data', () => ( { paymentSettingsStore: {} } ) );
jest.mock( '@wordpress/data', () => {
	const notices = {
		createSuccessNotice: jest.fn(),
		createErrorNotice: jest.fn(),
	};
	const store = { invalidateResolutionForStoreSelector: jest.fn() };
	return {
		...jest.requireActual( '@wordpress/data' ),
		dispatch: jest.fn( () => notices ),
		useDispatch: jest.fn( () => store ),
	};
} );
jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsEvent: jest.fn(),
} ) );

describe( 'ReactivateLivePaymentsButton', () => {
	afterEach( () => jest.clearAllMocks() );

	it( 'switches to live mode, refreshes providers, and reports success', async () => {
		let resolveRequest: () => void = () => {};
		( apiFetch as jest.Mock ).mockImplementation(
			() =>
				new Promise< void >( ( resolve ) => {
					resolveRequest = resolve;
				} )
		);
		const onSuccess = jest.fn();
		const onUpdatingChange = jest.fn();
		render(
			<ReactivateLivePaymentsButton
				buttonText="Turn on live payments"
				settingsHref="/payments/settings"
				onSuccess={ onSuccess }
				onUpdatingChange={ onUpdatingChange }
			/>
		);

		const button = screen.getByRole( 'link', {
			name: 'Turn on live payments',
		} );
		expect( button ).toHaveAttribute( 'href', '/payments/settings' );
		await userEvent.click( button );
		expect( onUpdatingChange ).toHaveBeenCalledWith( true );
		await waitFor( () =>
			expect(
				screen.getByRole( 'button', { name: 'Turn on live payments' } )
			).toHaveAttribute( 'aria-disabled', 'true' )
		);

		expect( apiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/settings',
			method: 'POST',
			data: { is_test_mode_enabled: false },
		} );
		await act( async () => resolveRequest() );
		await waitFor( () => expect( onSuccess ).toHaveBeenCalledTimes( 1 ) );
		expect( onUpdatingChange ).toHaveBeenLastCalledWith( false );
		expect(
			jest.mocked( useDispatch ).mock.results[ 0 ].value
				.invalidateResolutionForStoreSelector
		).toHaveBeenCalledWith( 'getPaymentProviders' );
		expect(
			dispatch( 'core/notices' ).createSuccessNotice
		).toHaveBeenCalled();
	} );
} );
