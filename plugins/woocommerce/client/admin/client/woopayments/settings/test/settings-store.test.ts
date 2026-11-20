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
			data: { is_debug_log_enabled: true },
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

	// Client 11.2.0 `components/duplicate-notice/index.tsx:45-50`: a dismissed notice is saved as its own option and
	// never touches the settings form, so it leaves nothing to save and keeps the merchant's other edits unsaved.
	it( 'keeps a dismissed duplicate payment method notice out of the unsaved changes', async () => {
		const store = await setupStore();
		await loadSettings( store, {
			is_debug_log_enabled: false,
			dismissed_duplicate_payment_method_notices: {},
		} );
		const klarna = { klarna: [ 'woocommerce_payments_klarna', 'other' ] };

		store.dispatch().updateDismissedDuplicatePaymentMethodNotices( klarna );

		expect(
			store.select().getDismissedDuplicatePaymentMethodNotices()
		).toEqual( klarna );
		expect( store.select().isDirty() ).toBe( false );

		store.dispatch().updateIsDebugLogEnabled( true );
		store.dispatch().updateDismissedDuplicatePaymentMethodNotices( {
			...klarna,
			affirm: [ 'woocommerce_payments_affirm', 'other' ],
		} );

		expect( store.select().isDirty() ).toBe( true );
		store.dispatch().updateIsDebugLogEnabled( false );
		expect( store.select().isDirty() ).toBe( false );
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
		// Core answers the save with the settings themselves (`MerchantRestController::update_settings()`).
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
		// Core's account-update rejection body is `{ server_error }` (`update_settings()`); an empty details map
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

	it( 'sends the Multi-Currency checkbox only when it was changed on this page, as every other setting', async () => {
		const store = await setupStore();
		// Loaded with Multi-Currency on; the merchant then turned it off on the Features page in another tab.
		await loadSettings( store, {
			is_multi_currency_enabled: true,
			is_debug_log_enabled: false,
		} );
		store.dispatch().updateIsDebugLogEnabled( true );
		store.apiFetch.mockResolvedValueOnce( {
			is_multi_currency_enabled: false,
			is_debug_log_enabled: true,
		} );

		await store.dispatch().saveSettings();

		expect( store.apiFetch ).toHaveBeenCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: { is_debug_log_enabled: true },
		} );
		// The page then shows the stored state, off.
		expect( store.select().getSettings() ).toEqual(
			expect.objectContaining( { is_multi_currency_enabled: false } )
		);

		store.dispatch().updateIsMultiCurrencyEnabled( true );
		store.apiFetch.mockResolvedValueOnce( {
			is_multi_currency_enabled: true,
			is_debug_log_enabled: true,
		} );

		await store.dispatch().saveSettings();

		expect( store.apiFetch ).toHaveBeenLastCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: { is_multi_currency_enabled: true },
		} );
	} );

	it( 'offers the Features page disable link when a Multi-Currency untick is refused', async () => {
		const store = await setupStore();
		await loadSettings( store, { is_multi_currency_enabled: true } );
		store.dispatch().updateIsMultiCurrencyEnabled( false );
		// The settings route's refused-untick body (`MerchantRestController::update_settings()`).
		const disableUrl =
			'http://example.org/wp-admin/admin.php?page=wc-settings&tab=advanced&section=features&multi_currency=0&_feature_nonce=abc';
		store.apiFetch.mockRejectedValueOnce( {
			server_error:
				'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.',
			disable_url: disableUrl,
		} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( false );
		// The untick itself is sent; the route refused it.
		expect( store.apiFetch ).toHaveBeenCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: { is_multi_currency_enabled: false },
		} );

		const data = await import( '@wordpress/data' );
		const notices = (
			data.select( 'core/notices' ) as unknown as {
				getNotices: () => Array< {
					content: string;
					actions: Array< { label: string; url: string } >;
				} >;
			}
		 ).getNotices();
		expect( notices[ 1 ].content ).toBe(
			'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.'
		);
		expect( notices[ 1 ].actions ).toEqual( [
			{ label: 'Disable Multi-Currency', url: disableUrl },
		] );
	} );
} );

describe( 'WooPayments settings save body', () => {
	const loaded = {
		is_wcpay_enabled: true,
		is_debug_log_enabled: false,
		is_manual_capture_enabled: false,
		enabled_payment_method_ids: [ 'card', 'ideal' ],
		account_business_support_email: 'help@example.com',
		account_statement_descriptor: 'EXAMPLE STORE',
		current_protection_level: 'basic',
		advanced_fraud_protection_settings: [],
		deposit_schedule_interval: 'weekly',
		deposit_schedule_weekly_anchor: 'monday',
		deposit_schedule_monthly_anchor: null,
		is_woopay_enabled: true,
	};

	// Load the settings, make the edit, save, and answer the body the save posted.
	const saveAfter = async (
		edit: ( store: Awaited< ReturnType< typeof setupStore > > ) => void,
		response: Record< string, unknown > = {}
	) => {
		const store = await setupStore();
		await loadSettings( store, loaded );
		edit( store );
		store.apiFetch.mockResolvedValueOnce( response );
		await store.dispatch().saveSettings();
		const [ request ] = store.apiFetch.mock.calls[ 0 ] as [
			{ data: Record< string, unknown > },
		];

		return { store, body: request.data };
	};

	it( 'sends only a changed setting that belongs to no group', async () => {
		const { body } = await saveAfter( ( store ) =>
			store
				.dispatch()
				.updateAccountBusinessSupportEmail( 'support@example.com' )
		);

		expect( body ).toEqual( {
			account_business_support_email: 'support@example.com',
		} );
	} );

	it( 'sends the fraud ruleset with a changed protection level', async () => {
		const { body } = await saveAfter( ( store ) =>
			store.dispatch().updateProtectionLevel( 'standard' )
		);

		expect( body ).toEqual( {
			current_protection_level: 'standard',
			advanced_fraud_protection_settings: [],
		} );
	} );

	it( 'sends the payout interval and both anchors with a changed anchor', async () => {
		const { body } = await saveAfter( ( store ) =>
			store.dispatch().updateDepositScheduleWeeklyAnchor( 'friday' )
		);

		expect( body ).toEqual( {
			deposit_schedule_interval: 'weekly',
			deposit_schedule_weekly_anchor: 'friday',
			deposit_schedule_monthly_anchor: null,
		} );
	} );

	it( 'sends the enabled payment methods with a changed manual capture', async () => {
		const { body } = await saveAfter( ( store ) =>
			store.dispatch().updateIsManualCaptureEnabled( true )
		);

		expect( body ).toEqual( {
			is_manual_capture_enabled: true,
			enabled_payment_method_ids: [ 'card', 'ideal' ],
		} );
	} );

	it( 'leaves reordered payment methods out of the save', async () => {
		const { body } = await saveAfter( ( store ) => {
			store
				.dispatch()
				.updateEnabledPaymentMethodIds( [ 'ideal', 'card' ] );
			store.dispatch().updateIsDebugLogEnabled( true );
		} );

		expect( body ).toEqual( { is_debug_log_enabled: true } );
	} );

	it( 'sends nothing when nothing changed, and still takes the saved settings from the response', async () => {
		const response = {
			...loaded,
			account_statement_descriptor: 'SAVED STORE',
		};
		const { store, body } = await saveAfter( () => undefined, response );

		expect( body ).toEqual( {} );
		expect( store.select().getSavedSettings() ).toEqual( response );
		expect( store.select().getSettings() ).toEqual( response );
		expect( store.select().isDirty() ).toBe( false );
	} );

	it( 'keeps a value the server changed as saved, and the WooPay choice as submitted', async () => {
		const { store } = await saveAfter(
			( current ) => current.dispatch().updateIsDebugLogEnabled( true ),
			{
				is_debug_log_enabled: true,
				account_statement_descriptor: 'SAVED STORE',
				is_woopay_enabled: false,
			}
		);

		for ( const values of [
			store.select().getSettings(),
			store.select().getSavedSettings(),
		] ) {
			expect( values ).toEqual(
				expect.objectContaining( {
					is_debug_log_enabled: true,
					account_statement_descriptor: 'SAVED STORE',
					is_woopay_enabled: true,
				} )
			);
		}
		expect( store.select().isDirty() ).toBe( false );
	} );

	// A refused account update answers after the server already stored the fraud settings.
	it( 'reads the saved settings again after a failed save, so a retry sends the fraud settings the server holds differently', async () => {
		const ruleset = [ { key: 'avs_verification', outcome: 'block' } ];
		const stored = {
			...loaded,
			current_protection_level: 'basic',
			advanced_fraud_protection_settings: [],
			account_statement_descriptor: 'EXAMPLE STORE',
		};
		const store = await setupStore();
		await loadSettings( store, {
			...loaded,
			current_protection_level: 'advanced',
			advanced_fraud_protection_settings: ruleset,
		} );
		store.dispatch().updateProtectionLevel( 'basic' );
		store.dispatch().updateAccountStatementDescriptor( 'Example' );
		store.apiFetch.mockRejectedValueOnce( {
			server_error:
				"The descriptor you entered isn't allowed. Please choose a different one.",
		} );
		store.apiFetch.mockResolvedValueOnce( stored );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( false );

		expect( store.apiFetch ).toHaveBeenCalledTimes( 2 );
		expect( store.apiFetch ).toHaveBeenLastCalledWith( {
			path: SETTINGS_PATH,
		} );
		expect( store.select().getSettings() ).toEqual(
			expect.objectContaining( {
				current_protection_level: 'basic',
				account_statement_descriptor: 'Example',
			} )
		);
		expect( store.select().getSavedSettings() ).toEqual( stored );
		expect( store.select().isDirty() ).toBe( true );

		store.dispatch().updateProtectionLevel( 'advanced' );
		store.dispatch().updateAccountStatementDescriptor( 'RETRY STORE' );
		store.apiFetch.mockResolvedValueOnce( {} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( true );

		expect( store.apiFetch ).toHaveBeenLastCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: {
				current_protection_level: 'advanced',
				advanced_fraud_protection_settings: ruleset,
				account_statement_descriptor: 'RETRY STORE',
			},
		} );
	} );

	it( 'sends every setting after a failed save whose fresh read fails too', async () => {
		const store = await setupStore();
		await loadSettings( store, loaded );
		store.dispatch().updateIsDebugLogEnabled( true );
		// The save and the read after it both fail.
		store.apiFetch.mockRejectedValue( new Error( 'Network error' ) );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( false );

		expect( store.select().getSettings() ).toEqual( {
			...loaded,
			is_debug_log_enabled: true,
		} );
		expect( store.select().isDirty() ).toBe( true );

		store
			.dispatch()
			.updateAccountBusinessSupportEmail( 'support@example.com' );
		store.apiFetch.mockReset();
		store.apiFetch.mockResolvedValueOnce( {} );

		await expect( store.dispatch().saveSettings() ).resolves.toBe( true );

		expect( store.apiFetch ).toHaveBeenLastCalledWith( {
			path: SETTINGS_PATH,
			method: 'post',
			data: {
				...loaded,
				is_debug_log_enabled: true,
				account_business_support_email: 'support@example.com',
			},
		} );
	} );

	// Fails the next save and holds the fresh read that follows it until `answerRead()`.
	const failSaveAndHoldRead = async (
		store: Awaited< ReturnType< typeof setupStore > >
	) => {
		let answerRead: ( settings: unknown ) => void = () => undefined;
		store.apiFetch.mockRejectedValueOnce( {
			server_error: 'The request could not be completed.',
		} );
		store.apiFetch.mockReturnValueOnce(
			new Promise( ( resolve ) => {
				answerRead = resolve;
			} )
		);
		const save = store.dispatch().saveSettings();
		await new Promise( ( resolve ) => setTimeout( resolve ) );
		expect( store.apiFetch ).toHaveBeenLastCalledWith( {
			path: SETTINGS_PATH,
		} );

		return {
			save,
			answerRead: ( settings: unknown ) => answerRead( settings ),
		};
	};

	it( 'keeps a failed save pending until its fresh read answers', async () => {
		const store = await setupStore();
		await loadSettings( store, loaded );
		store.dispatch().updateIsDebugLogEnabled( true );

		const { save, answerRead } = await failSaveAndHoldRead( store );

		expect( store.select().isSavingSettings() ).toBe( true );

		answerRead( loaded );
		await expect( save ).resolves.toBe( false );

		expect( store.select().isSavingSettings() ).toBe( false );
		expect( store.select().isDirty() ).toBe( true );
	} );

	it( "leaves the next visit's saved settings alone when a failed save's fresh read answers after its visit closed", async () => {
		const next = {
			...loaded,
			is_debug_log_enabled: true,
			account_statement_descriptor: 'NEXT STORE',
		};
		const store = await setupStore();
		await loadSettings( store, loaded );
		store.dispatch().updateIsDebugLogEnabled( true );
		const { save, answerRead } = await failSaveAndHoldRead( store );

		await store.dispatch().resetSettings();
		store.apiFetch.mockResolvedValueOnce( next );
		await store.resolveSelect().getSettings();
		answerRead( loaded );
		await expect( save ).resolves.toBe( false );

		expect( store.select().getSavedSettings() ).toEqual( next );
		expect( store.select().getSettings() ).toEqual( next );
		expect( store.select().isDirty() ).toBe( false );
	} );
} );
