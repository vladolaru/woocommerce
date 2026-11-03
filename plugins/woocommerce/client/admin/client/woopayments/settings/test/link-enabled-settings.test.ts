/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { act, renderHook } from '@testing-library/react';
import { resolveSelect, select } from '@wordpress/data';

/**
 * Internal dependencies
 */
import { useLinkEnabledSettings } from '../data/hooks';
import { registerStoreUnlessTaken } from '../../data/register-store';
import { store as settingsStore, STORE_NAME } from '../data/store';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'useLinkEnabledSettings', () => {
	// Client 11.2.0 `settings/express-checkout/link-item.tsx:30-40`: the Link updater refuses nothing, so a store holding
	// Link and WooPay can turn Link off.
	it( 'turns Link off while WooPay is on', async () => {
		registerStoreUnlessTaken( settingsStore );
		( apiFetch as unknown as jest.Mock ).mockResolvedValueOnce( {
			is_woopay_enabled: true,
			enabled_payment_method_ids: [ 'card', 'link' ],
		} );
		await resolveSelect( STORE_NAME ).getSettings();
		const { result } = renderHook( () => useLinkEnabledSettings() );

		act( () => {
			( result.current[ 1 ] as ( isEnabled: boolean ) => void )( false );
		} );

		expect( select( STORE_NAME ).getEnabledPaymentMethodIds() ).toEqual( [
			'card',
		] );
	} );
} );
