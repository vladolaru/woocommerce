/**
 * External dependencies
 */
import type apiFetchType from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const SETTINGS_PATH = '/wc/v3/payments/settings';

/**
 * Register the settings store on a fresh default registry, with only the REST client mocked.
 */
const setupStore = async () => {
	jest.resetModules();
	const data = await import( '@wordpress/data' );
	await import( '@wordpress/notices' );
	const { store, STORE_NAME } = await import( '../data/store' );
	data.register( store );
	const apiFetch = ( await import( '@wordpress/api-fetch' ) )
		.default as jest.MockedFunction< typeof apiFetchType >;
	const getNoticeTexts = () =>
		(
			data.select( 'core/notices' ) as unknown as {
				getNotices: () => Array< { content: string; status: string } >;
			}
		 )
			.getNotices()
			.map( ( { content, status } ) => [ status, content ] );

	return {
		apiFetch,
		getNoticeTexts,
		select: () => data.select( STORE_NAME ),
		dispatch: () => data.dispatch( STORE_NAME ),
		resolveSelect: () => data.resolveSelect( STORE_NAME ),
	};
};

// Load the store's settings through its resolver, as the settings page does on mount.
const loadSettings = async (
	store: Awaited< ReturnType< typeof setupStore > >,
	settings: Record< string, unknown >
) => {
	store.apiFetch.mockResolvedValueOnce( settings );
	await store.resolveSelect().getSettings();
	store.apiFetch.mockClear();
};

describe( 'WooPayments settings store', () => {
	it( 'resolves settings from the preserved WooPayments settings endpoint', async () => {
		const store = await setupStore();
		const settings = {
			is_wcpay_enabled: true,
			enabled_payment_method_ids: [ 'card' ],
		};
		store.apiFetch.mockResolvedValueOnce( settings );

		await expect( store.resolveSelect().getSettings() ).resolves.toEqual(
			settings
		);

		expect( store.apiFetch ).toHaveBeenCalledWith( {
			path: SETTINGS_PATH,
		} );
		expect( store.select().isDirty() ).toBe( false );
	} );

	it( 'reports a failed settings read', async () => {
		const store = await setupStore();
		store.apiFetch.mockRejectedValueOnce( new Error( 'Forbidden' ) );

		await store.resolveSelect().getSettings();

		expect( store.select().getSettings() ).toEqual( {} );
		expect( store.getNoticeTexts() ).toEqual( [
			[ 'error', 'Error retrieving settings.' ],
		] );
	} );

	it( 'saves the edited settings to the preserved endpoint and keeps the response values', async () => {
		const store = await setupStore();
		await loadSettings( store, {
			is_wcpay_enabled: true,
			is_debug_log_enabled: false,
			enabled_payment_method_ids: [ 'card' ],
		} );
		store.dispatch().updateIsDebugLogEnabled( true );
		expect( store.select().isDirty() ).toBe( true );
		const statuses = {
			card_payments: { status: 'active', requirements: [] },
		};
		// The client's wrapped save response (client 11.1.0 `client/data/settings/actions.js:190-200`).
		store.apiFetch.mockResolvedValueOnce( {
			data: {
				woopay_last_disable_date: '2026-06-20',
				payment_method_statuses: statuses,
			},
		} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( true );

		expect( store.apiFetch ).toHaveBeenCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: {
				is_wcpay_enabled: true,
				is_debug_log_enabled: true,
				enabled_payment_method_ids: [ 'card' ],
			},
		} );
		expect( store.select().getSettings() ).toEqual( {
			is_wcpay_enabled: true,
			is_debug_log_enabled: true,
			enabled_payment_method_ids: [ 'card' ],
			woopay_last_disable_date: '2026-06-20',
			payment_method_statuses: statuses,
		} );
		expect( store.select().isDirty() ).toBe( false );
		expect( store.select().isSavingSettings() ).toBe( false );
		expect( store.getNoticeTexts() ).toEqual( [
			[ 'success', 'Settings saved.' ],
		] );
	} );

	it( 'reports the save as pending until the REST request settles', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_wcpay_enabled: true } );
		let settleSave: ( response: unknown ) => void = () => undefined;
		store.apiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				settleSave = resolve;
			} )
		);

		const save = store.dispatch().saveSettings();
		await Promise.resolve();

		expect( store.select().isSavingSettings() ).toBe( true );

		settleSave( { is_wcpay_enabled: true } );
		await expect( save ).resolves.toBe( true );

		expect( store.select().isSavingSettings() ).toBe( false );
	} );

	it( 'accepts an unwrapped REST settings response when saving', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_wcpay_enabled: true } );
		const statuses = {
			card_payments: { status: 'active', requirements: [] },
		};
		// Core answers the save with the settings themselves (`WooPaymentsMerchantRestController::update_native_settings()`).
		store.apiFetch.mockResolvedValueOnce( {
			payment_method_statuses: statuses,
		} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( true );

		expect( store.select().getSettings() ).toEqual( {
			is_wcpay_enabled: true,
			payment_method_statuses: statuses,
		} );
	} );

	it( 'keeps the submitted WooPay choice when the save response reports WooPay off for an ineligible account', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_woopay_enabled: true } );
		// Core reports WooPay as on only while the account is eligible (`WooPaymentsSettingsService::get_settings()`).
		store.apiFetch.mockResolvedValueOnce( {
			data: { is_woopay_enabled: false },
		} );

		await store.dispatch().saveSettings();

		expect( store.select().getSettings() ).toEqual(
			expect.objectContaining( { is_woopay_enabled: true } )
		);
	} );

	it( 'suppresses the raw server error notice when saving fails with field-level details', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_wcpay_enabled: true } );
		store.dispatch().updateIsDebugLogEnabled( true );
		// Client 11.1.0 `client/data/settings/__tests__/actions.test.js:220-236`; core's rejected-field errors carry the
		// same `data.details` map (`WooPaymentsSettingsService`, `wcpay_server_error`).
		const error = {
			server_error:
				'The statement descriptor contains invalid characters.',
			data: {
				details: {
					account_statement_descriptor: {
						message:
							'The statement descriptor contains invalid characters.',
					},
				},
			},
		};
		store.apiFetch.mockRejectedValueOnce( error );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( false );

		expect( store.getNoticeTexts() ).toEqual( [
			[ 'error', 'Error saving settings.' ],
		] );
		expect( store.select().getSavingError() ).toEqual( error );
		expect( store.select().isSavingSettings() ).toBe( false );
		// A failed save leaves the edit unsaved.
		expect( store.select().isDirty() ).toBe( true );
	} );

	it( 'shows the raw server error notice when saving fails without field-level details', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_wcpay_enabled: true } );
		// Core's account-update rejection body is `{ server_error }` (`update_native_settings()`); an empty details map
		// counts as no field details.
		store.apiFetch.mockRejectedValueOnce( {
			server_error: 'The request could not be completed.',
			data: { details: {} },
		} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( false );

		expect( store.getNoticeTexts() ).toEqual( [
			[ 'error', 'Error saving settings.' ],
			[ 'error', 'The request could not be completed.' ],
		] );
	} );
} );
