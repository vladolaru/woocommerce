/**
 * External dependencies
 */
import apiFetch from '@wordpress/api-fetch';
import { dispatch } from '@wordpress/data';
import { createRef } from '@wordpress/element';
import { paymentSettingsStore } from '@woocommerce/data';
import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { WooPaymentsAdminNotice } from '@woocommerce/data';

/**
 * Internal dependencies
 */
import { registerStoreUnlessTaken } from '../../data/register-store';
import { useSettings } from '../data/hooks';
import { store as settingsStore } from '../data/store';
import { WooPaymentsSettingsPage } from '../settings-page';
import { SettingsSubpage } from '../settings-subpage';
import { FraudProtectionAdvancedSettingsPage } from '../fraud-protection/advanced';
import { ReactivateLivePaymentsButton } from '~/settings-payments/components/buttons/reactivate-live-payments-button';
import { WooPaymentsAdminNotices } from '~/settings-payments/components/woopayments-admin-notices';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// The test setup's `core/notices` store has no notice creators.
jest.mock( '@wordpress/data', () => {
	const data = jest.requireActual( '@wordpress/data' );
	const notices = {
		createErrorNotice: jest.fn(),
		createInfoNotice: jest.fn(),
		createSuccessNotice: jest.fn(),
	};

	return {
		...data,
		dispatch: ( storeName: string ) =>
			storeName === 'core/notices' ? notices : data.dispatch( storeName ),
	};
} );
jest.mock( '@woocommerce/data', () => {
	const data = jest.requireActual( '@wordpress/data' );

	return {
		paymentSettingsStore: data.createReduxStore( 'test/payment-settings', {
			reducer: ( state = {} ) => state,
			selectors: { getPaymentProviders: () => [] },
		} ),
	};
} );
jest.mock( '~/settings-payments/utils', () => ( {
	recordPaymentsEvent: jest.fn(),
} ) );
jest.mock( '../../promotions/spotlight', () => ( {
	SpotlightPromotion: () => null,
} ) );

const SETTINGS_PATH = '/wc/v3/payments/settings';
const mockApiFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;
// The settings the server holds: a live account still in test mode, Basic fraud protection, saved cards on.
let server: Record< string, unknown >;
const posts: Array< Record< string, unknown > > = [];

const setUpServer = () => {
	server = {
		is_wcpay_enabled: true,
		is_test_mode_enabled: true,
		is_saved_cards_enabled: true,
		is_debug_log_enabled: false,
		enabled_payment_method_ids: [ 'card' ],
		available_payment_method_ids: [ 'card' ],
		current_protection_level: 'basic',
		advanced_fraud_protection_settings: [],
		store_currency: 'USD',
		account_statement_descriptor: 'EXAMPLE STORE',
		account_communications_email: 'owner@example.com',
		account_business_support_email: 'help@example.com',
		account_business_support_phone: '+12025550123',
		fraud_protection: {
			decline_on_avs_failure: false,
			decline_on_cvc_failure: true,
			is_welcome_tour_dismissed: true,
		},
		fraud_protection_allowed_countries: { type: 'all', countries: [] },
	};
	posts.length = 0;
	mockApiFetch.mockImplementation( ( options ) => {
		const { path, method, data } = options as {
			path?: string;
			method?: string;
			data?: Record< string, unknown >;
		};

		if ( path === SETTINGS_PATH && method?.toLowerCase() === 'post' ) {
			posts.push( data ?? {} );
			server = { ...server, ...data };

			return Promise.resolve( {} );
		}

		return Promise.resolve( path === SETTINGS_PATH ? { ...server } : {} );
	} );
};

const testToLiveNotice: WooPaymentsAdminNotice = {
	id: 'test_to_live',
	message: "You're ready to take real payments.",
	primary: { kind: 'disable_test_mode', label: 'Turn on live payments' },
	_links: {
		shown: { href: '/admin-notices/test_to_live/shown' },
		dismiss: { href: '/admin-notices/test_to_live/dismiss' },
		cta: { href: '/admin-notices/test_to_live/cta' },
	},
} as WooPaymentsAdminNotice;

// Stands in for an express checkout Customize page, which reads the settings store as the settings page does.
const CustomizeSettings = () =>
	useSettings().isLoading ? null : <p>Customize settings loaded</p>;

const renderSettingsPage = async () => {
	const page = render( <WooPaymentsSettingsPage /> );
	await screen.findByRole( 'checkbox', { name: 'Enable test mode' } );

	return page;
};

const save = async () => {
	await userEvent.click(
		screen.getByRole( 'button', { name: 'Save changes' } )
	);
	await waitFor( () => expect( posts ).toHaveLength( 1 ) );
};

describe( 'WooPayments settings store across settings routes', () => {
	beforeAll( () => {
		registerStoreUnlessTaken( paymentSettingsStore );
		registerStoreUnlessTaken( settingsStore );
	} );

	beforeEach( () => {
		window.wcSettings = { adminUrl: 'http://example.com/wp-admin/' };
		jest.spyOn( window, 'confirm' ).mockReturnValue( true );
		setUpServer();
		// Each row starts from the server's settings, whatever the row before left in the store.
		dispatch( settingsStore ).updateSettings( { ...server } );
	} );

	afterEach( () => jest.restoreAllMocks() );

	it.each( [
		[
			'the "Turn on live payments" notice',
			'Turn on live payments',
			() => (
				<WooPaymentsAdminNotices
					notice={ testToLiveNotice }
					focusTargetRef={ createRef() }
					onDismiss={ () => undefined }
				/>
			),
		],
		[
			'the "Reactivate payments" row button',
			'Reactivate payments',
			() => <ReactivateLivePaymentsButton settingsHref="#" />,
		],
	] )(
		'saves live mode after going live through %s and coming back',
		async ( _path, label, GoLive ) => {
			const settingsPage = await renderSettingsPage();
			settingsPage.unmount();
			// The merchant reads the Payments list before going live, so any read made on the way out has landed.
			await act(
				() => new Promise( ( resolve ) => setTimeout( resolve ) )
			);

			const payments = render( <GoLive /> );
			await userEvent.click( screen.getByText( label ) );
			await waitFor( () =>
				expect( server.is_test_mode_enabled ).toBe( false )
			);
			payments.unmount();
			posts.length = 0;

			await renderSettingsPage();
			await waitFor( () =>
				expect(
					screen.getByRole( 'checkbox', { name: 'Enable test mode' } )
				).not.toBeChecked()
			);
			await userEvent.click(
				screen.getByRole( 'checkbox', { name: 'Log error messages' } )
			);
			await save();

			expect( posts[ 0 ] ).toEqual(
				expect.objectContaining( {
					is_test_mode_enabled: false,
					is_debug_log_enabled: true,
				} )
			);
		}
	);

	it.each( [
		[ 'only the level changed', false, false ],
		[ 'another setting changed too', true, true ],
	] )(
		'asks before opening the advanced fraud rules when %s',
		async ( _case, untickSavedCards, asks ) => {
			await renderSettingsPage();
			if ( untickSavedCards ) {
				await userEvent.click(
					screen.getByRole( 'checkbox', {
						name: 'Enable payments via saved cards',
					} )
				);
			}

			await userEvent.click(
				screen.getByRole( 'radio', { name: 'Advanced' } )
			);
			await userEvent.click(
				screen.getByRole( 'link', { name: 'Configure' } )
			);

			expect( window.confirm ).toHaveBeenCalledTimes( asks ? 1 : 0 );
			expect( window.location.search ).toContain(
				'path=%2Fwoopayments%2Fsettings%2Ffraud-protection'
			);
		}
	);

	it( 'leaves the edits the merchant was told may be lost out of the advanced fraud rules save', async () => {
		const settingsPage = await renderSettingsPage();
		await userEvent.click(
			screen.getByRole( 'checkbox', {
				name: 'Enable payments via saved cards',
			} )
		);
		await userEvent.click(
			screen.getByRole( 'radio', { name: 'Advanced' } )
		);
		await userEvent.click(
			screen.getByRole( 'link', { name: 'Configure' } )
		);
		expect( window.confirm ).toHaveBeenCalledTimes( 1 );
		settingsPage.unmount();

		render( <FraudProtectionAdvancedSettingsPage /> );
		// The route change settles before the merchant acts on the new page.
		await act( async () => undefined );
		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'Enable AVS Mismatch filter',
			} )
		);
		await save();

		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( {
				is_saved_cards_enabled: true,
				current_protection_level: 'advanced',
			} )
		);
	} );

	it( 'leaves an edit made on a subpage out of the next settings page save', async () => {
		( await renderSettingsPage() ).unmount();
		const subpage = render(
			<SettingsSubpage
				headingId="woopayments-express-checkout-settings-payment_request"
				title="Apple Pay / Google Pay"
				backPath="/woopayments/settings"
				from="woopayments_express_checkout_settings"
			>
				<CustomizeSettings />
			</SettingsSubpage>
		);
		await act( async () => undefined );
		await screen.findByText( 'Customize settings loaded' );
		// One of its controls, left unsaved after the leave prompt.
		act( () => {
			dispatch( settingsStore ).updateIsDebugLogEnabled( true );
		} );
		subpage.unmount();

		await renderSettingsPage();
		await act( async () => undefined );
		await userEvent.click(
			await screen.findByRole( 'checkbox', {
				name: 'Enable payments via saved cards',
			} )
		);
		await save();

		expect( posts[ 0 ] ).toEqual(
			expect.objectContaining( {
				is_debug_log_enabled: false,
				is_saved_cards_enabled: false,
			} )
		);
	} );
} );
