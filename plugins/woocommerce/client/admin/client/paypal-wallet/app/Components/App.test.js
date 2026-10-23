import { render } from '@testing-library/react';

import SettingsApp from './App';

let mockOnboardingCompleted = false;
let mockIsSendOnlyCountry = false;

jest.mock( '../data', () => ( {
	OnboardingHooks: {
		useSteps: () => ( {
			isReady: true,
			completed: mockOnboardingCompleted,
		} ),
	},
	CommonHooks: {
		useStore: () => ( { isReady: true } ),
		useMerchantInfo: () => ( {
			merchant: { isSendOnlyCountry: mockIsSendOnlyCountry },
		} ),
	},
} ) );
jest.mock( '../services/tracking', () => ( {
	initializeTracking: jest.fn(),
} ) );
jest.mock( './ReusableComponents/Notifications', () => () => null );
jest.mock( './ReusableComponents/FlashNotices', () => () => null );
jest.mock( './Screens/SendOnlyMessage', () => () => null );
jest.mock( './Screens/Onboarding', () => () => null );
jest.mock( './Screens/Settings', () => () => null );

const ROUTE_URL =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout&path=%2Fpaypal-wallet';

describe( 'SettingsApp URL clean-up', () => {
	beforeEach( () => {
		mockOnboardingCompleted = false;
		mockIsSendOnlyCountry = false;
		window.history.replaceState( {}, '', ROUTE_URL + '&panel=styling' );
	} );

	it( 'keeps the wallet route when the onboarding screen drops other arguments', () => {
		render( <SettingsApp /> );

		const query = new URLSearchParams( window.location.search );
		expect( query.get( 'page' ) ).toBe( 'wc-settings' );
		expect( query.get( 'tab' ) ).toBe( 'checkout' );
		expect( query.get( 'path' ) ).toBe( '/paypal-wallet' );
		expect( query.has( 'panel' ) ).toBe( false );
	} );

	it( 'keeps the wallet route on the send-only country screen', () => {
		mockIsSendOnlyCountry = true;

		render( <SettingsApp /> );

		const query = new URLSearchParams( window.location.search );
		expect( query.get( 'path' ) ).toBe( '/paypal-wallet' );
		expect( query.has( 'panel' ) ).toBe( false );
	} );
} );
