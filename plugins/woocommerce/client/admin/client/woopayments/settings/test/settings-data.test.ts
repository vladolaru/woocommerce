/**
 * External dependencies
 */
import directApiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

const mockCreateSuccessNotice = jest.fn();
const mockCreateErrorNotice = jest.fn();
const mockGetSettings = jest.fn();
const mockRegister = jest.fn();

jest.mock( '@wordpress/data', () => ( {
	combineReducers: jest.fn( ( reducers ) => reducers ),
	createReduxStore: jest.fn( ( name, config ) => ( {
		name,
		...config,
	} ) ),
	dispatch: jest.fn( ( storeName: string ) => {
		if ( storeName === 'core/notices' ) {
			return {
				createSuccessNotice: mockCreateSuccessNotice,
				createErrorNotice: mockCreateErrorNotice,
			};
		}

		return {
			startResolution: jest.fn(),
			finishResolution: jest.fn(),
		};
	} ),
	register: mockRegister,
	select: jest.fn( () => ( {
		getSettings: mockGetSettings,
	} ) ),
	useDispatch: jest.fn(),
	useSelect: jest.fn(),
} ) );

const mockDirectApiFetch = directApiFetch as jest.MockedFunction<
	typeof directApiFetch
>;

describe( 'WooPayments settings data store', () => {
	beforeEach( () => {
		mockCreateSuccessNotice.mockReset();
		mockCreateErrorNotice.mockReset();
		mockGetSettings.mockReset();
		mockRegister.mockReset();
		mockDirectApiFetch.mockReset();
		delete (
			window as typeof window & {
				wcSettings?: unknown;
				wcpaySettings?: unknown;
			}
		 ).wcSettings;
		delete (
			window as typeof window & {
				wcSettings?: unknown;
				wcpaySettings?: unknown;
			}
		 ).wcpaySettings;
	} );

	it( 'exports the public settings store name used by WooPayments settings components', async () => {
		const { STORE_NAME, store } = await import( '../data/store' );

		expect( STORE_NAME ).toBe( 'wc/payments/settings' );
		expect( store.name ).toBe( 'wc/payments/settings' );
		expect( mockRegister ).not.toHaveBeenCalled();
	} );

	it( 'resolves settings from the preserved WooPayments settings endpoint', async () => {
		const { getSettings } = await import( '../data/resolvers' );
		const resolver = getSettings();

		expect( resolver.next().value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/wc/v3/payments/settings',
			},
		} );
	} );

	it( 'saves settings to the preserved WooPayments settings endpoint', async () => {
		const settings = {
			is_wcpay_enabled: true,
			enabled_payment_method_ids: [ 'card' ],
		};
		const response = {
			data: {
				woopay_last_disable_date: '2026-06-20',
				payment_method_statuses: {
					card_payments: {
						status: 'active',
						requirements: [],
					},
				},
			},
		};
		mockGetSettings.mockReturnValue( settings );

		const { saveSettings } = await import( '../data/actions' );
		const action = saveSettings();

		action.next();

		expect( action.next().value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/wc/v3/payments/settings',
				method: 'post',
				data: settings,
			},
		} );

		expect( action.next( response ).value ).toEqual( {
			type: 'SET_SETTINGS',
			data: {
				...settings,
				woopay_last_disable_date: '2026-06-20',
				payment_method_statuses: {
					card_payments: {
						status: 'active',
						requirements: [],
					},
				},
			},
		} );
		action.next();
		action.next();
		const result = action.next();

		expect( result.value ).toBe( true );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Settings saved.'
		);
	} );

	it( 'accepts unwrapped REST settings responses when saving settings', async () => {
		const settings = {
			is_wcpay_enabled: true,
			enabled_payment_method_ids: [ 'card' ],
		};
		mockGetSettings.mockReturnValue( settings );

		const { saveSettings } = await import( '../data/actions' );
		const action = saveSettings();

		action.next();
		action.next();
		action.next( {
			payment_method_statuses: {
				card_payments: {
					status: 'active',
					requirements: [],
				},
			},
		} );
		action.next();
		action.next();
		const result = action.next();

		expect( result.value ).toBe( true );
		expect( mockCreateSuccessNotice ).toHaveBeenCalledWith(
			'Settings saved.'
		);
	} );

	it( 'suppresses the raw server error notice when saving settings fails with field-level details', async () => {
		const settings = {
			is_wcpay_enabled: true,
		};
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
		mockGetSettings.mockReturnValue( settings );

		const { saveSettings } = await import( '../data/actions' );
		const action = saveSettings();

		action.next();
		action.next();
		let result = action.throw( error );
		while ( ! result.done ) {
			result = action.next();
		}

		expect( result.value ).toBe( false );
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 1 );
		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Error saving settings.'
		);
	} );

	it( 'shows the raw server error notice when saving settings fails without field-level details', async () => {
		const settings = {
			is_wcpay_enabled: true,
		};
		const error = {
			server_error: 'The request could not be completed.',
			data: {
				details: {},
			},
		};
		mockGetSettings.mockReturnValue( settings );

		const { saveSettings } = await import( '../data/actions' );
		const action = saveSettings();

		action.next();
		action.next();
		let result = action.throw( error );
		while ( ! result.done ) {
			result = action.next();
		}

		expect( result.value ).toBe( false );
		expect( mockCreateErrorNotice ).toHaveBeenCalledTimes( 2 );
		expect( mockCreateErrorNotice ).toHaveBeenNthCalledWith(
			1,
			'Error saving settings.'
		);
		expect( mockCreateErrorNotice ).toHaveBeenNthCalledWith(
			2,
			'The request could not be completed.'
		);
	} );

	it( 'saves allowlisted options through the preserved option endpoint', async () => {
		mockDirectApiFetch.mockResolvedValue( {} );

		const { saveOption } = await import( '../data/actions' );

		await saveOption(
			'wcpay_fraud_protection_welcome_tour_dismissed',
			true
		);

		expect( mockDirectApiFetch ).toHaveBeenCalledWith( {
			path: '/wc/v3/payments/settings/wcpay_fraud_protection_welcome_tour_dismissed',
			method: 'post',
			data: { value: true },
		} );
	} );

	it( 'reads account fees and dismissed duplicate notices from settings state', async () => {
		const selectors = await import( '../data/selectors' );
		const state = {
			settings: {
				data: {
					account_fees: {
						card: {
							base: {
								percentage_rate: 0.029,
								fixed_rate: 30,
								currency: 'USD',
							},
						},
					},
					dismissed_duplicate_payment_method_notices: {
						card: [ 'legacy_card_gateway' ],
					},
				},
			},
		};

		expect( selectors.getAccountFees( state ) ).toEqual( {
			card: {
				base: {
					percentage_rate: 0.029,
					fixed_rate: 30,
					currency: 'USD',
				},
			},
		} );
		expect(
			selectors.getDismissedDuplicatePaymentMethodNotices( state )
		).toEqual( {
			card: [ 'legacy_card_gateway' ],
		} );
	} );

	it( 'is no longer dirty once edited settings are changed back to their saved values', async () => {
		const { default: reducer } = await import( '../data/reducer' );
		const loaded = reducer( undefined, {
			type: 'SET_SETTINGS',
			data: {
				is_debug_log_enabled: false,
				enabled_payment_method_ids: [ 'card', 'link' ],
			},
		} );

		const toggled = reducer( loaded, {
			type: 'SET_SETTINGS_VALUES',
			payload: { is_debug_log_enabled: true },
		} );
		expect( toggled.isDirty ).toBe( true );

		const toggledBack = reducer( toggled, {
			type: 'SET_SETTINGS_VALUES',
			payload: { is_debug_log_enabled: false },
		} );
		expect( toggledBack.isDirty ).toBe( false );

		const unselected = reducer( toggledBack, {
			type: 'SET_UNSELECTED_PAYMENT_METHOD',
			id: 'card',
		} );
		expect( unselected.isDirty ).toBe( true );
		expect(
			reducer( unselected, {
				type: 'SET_SELECTED_PAYMENT_METHOD',
				id: 'card',
			} ).isDirty
		).toBe( false );
	} );

	it( 'treats reordered order-free lists as unchanged but keeps fraud rule order significant', async () => {
		const { default: reducer } = await import( '../data/reducer' );
		const rules = [ { key: 'avs_verification' }, { key: 'ip_address' } ];
		const loaded = reducer( undefined, {
			type: 'SET_SETTINGS',
			data: {
				enabled_payment_method_ids: [ 'card', 'link' ],
				express_checkout_product_methods: [
					'payment_request',
					'woopay',
				],
				advanced_fraud_protection_settings: rules,
			},
		} );

		expect(
			reducer( loaded, {
				type: 'SET_SETTINGS_VALUES',
				payload: { enabled_payment_method_ids: [ 'link', 'card' ] },
			} ).isDirty
		).toBe( false );
		expect(
			reducer( loaded, {
				type: 'SET_SETTINGS_VALUES',
				payload: {
					express_checkout_product_methods: [
						'woopay',
						'payment_request',
					],
				},
			} ).isDirty
		).toBe( false );
		expect(
			reducer( loaded, {
				type: 'SET_SETTINGS_VALUES',
				payload: {
					advanced_fraud_protection_settings: [ ...rules ].reverse(),
				},
			} ).isDirty
		).toBe( true );
	} );

	it( 'updates dismissed duplicate notices in the settings store', async () => {
		const { updateDismissedDuplicatePaymentMethodNotices } = await import(
			'../data/actions'
		);

		expect(
			updateDismissedDuplicatePaymentMethodNotices( {
				card: [ 'woocommerce_payments', 'legacy_card_gateway' ],
			} )
		).toEqual( {
			type: 'SET_SETTINGS_VALUES',
			payload: {
				dismissed_duplicate_payment_method_notices: {
					card: [ 'woocommerce_payments', 'legacy_card_gateway' ],
				},
			},
		} );
	} );

	it( 'starts the Stripe Billing migration through the plugin route and tracks the request', async () => {
		const { dispatch } = jest.requireMock( '@wordpress/data' );
		const { submitStripeBillingSubscriptionMigration } = await import(
			'../data/actions'
		);
		const action = submitStripeBillingSubscriptionMigration();

		action.next();
		const storeDispatch = dispatch.mock.results.at( -1 ).value;
		expect( storeDispatch.startResolution ).toHaveBeenCalledWith(
			'scheduleStripeBillingMigration',
			[]
		);
		expect( action.next().value ).toEqual( {
			type: 'API_FETCH',
			request: {
				path: '/wc/v3/payments/settings/schedule-stripe-billing-migration',
				method: 'post',
			},
		} );

		action.next();
		expect(
			dispatch.mock.results.at( -1 ).value.finishResolution
		).toHaveBeenCalledWith( 'scheduleStripeBillingMigration', [] );
		expect( action.next().done ).toBe( true );
		expect( mockCreateErrorNotice ).not.toHaveBeenCalled();
	} );

	it( 'reports a failed Stripe Billing migration request and still finishes it', async () => {
		const { dispatch } = jest.requireMock( '@wordpress/data' );
		const { submitStripeBillingSubscriptionMigration } = await import(
			'../data/actions'
		);
		const action = submitStripeBillingSubscriptionMigration();

		action.next();
		action.next();
		action.throw( new Error( 'Forbidden' ) );

		expect( mockCreateErrorNotice ).toHaveBeenCalledWith(
			'Error starting the Stripe Billing migration.'
		);
		action.next();
		expect(
			dispatch.mock.results.at( -1 ).value.finishResolution
		).toHaveBeenCalledWith( 'scheduleStripeBillingMigration', [] );
	} );

	it( 'reads the Stripe Billing settings, with the plugin defaults when they are missing', async () => {
		const selectors = await import( '../data/selectors' );
		const withSettings = ( data: Record< string, unknown > ) => ( {
			settings: { data },
		} );
		const reported = withSettings( {
			is_stripe_billing_enabled: true,
			is_migrating_stripe_billing: true,
			stripe_billing_subscription_count: 3,
			stripe_billing_migrated_count: 2,
		} );
		const missing = withSettings( {} );

		expect( selectors.getIsStripeBillingEnabled( reported ) ).toBe( true );
		expect(
			selectors.getIsStripeBillingMigrationInProgress( reported )
		).toBe( true );
		expect( selectors.getStripeBillingSubscriptionCount( reported ) ).toBe(
			3
		);
		expect( selectors.getStripeBillingMigratedCount( reported ) ).toBe( 2 );
		expect( selectors.getIsStripeBillingEnabled( missing ) ).toBe( false );
		expect(
			selectors.getIsStripeBillingMigrationInProgress( missing )
		).toBe( false );
		expect( selectors.getStripeBillingSubscriptionCount( missing ) ).toBe(
			0
		);
		expect( selectors.getStripeBillingMigratedCount( missing ) ).toBe( 0 );
	} );

	it( 'saves the Stripe Billing toggle under the plugin field name', async () => {
		const { updateIsStripeBillingEnabled } = await import(
			'../data/actions'
		);

		expect( updateIsStripeBillingEnabled( false ) ).toEqual( {
			type: 'SET_SETTINGS_VALUES',
			payload: { is_stripe_billing_enabled: false },
		} );
	} );

	it( 'reads settings bootstrap data from the Core-owned wcSettings admin payload', async () => {
		(
			window as typeof window & {
				wcSettings: {
					admin: {
						woopaymentsSettings: {
							accountStatus: string;
						};
					};
				};
				wcpaySettings: {
					accountStatus: string;
				};
			}
		 ).wcSettings = {
			admin: {
				woopaymentsSettings: {
					accountStatus: 'connected',
				},
			},
		};
		(
			window as typeof window & {
				wcpaySettings: {
					accountStatus: string;
				};
			}
		 ).wcpaySettings = {
			accountStatus: 'legacy-plugin-global',
		};

		const { getWooPaymentsSettingsBootstrap } = await import(
			'../bootstrap'
		);

		expect( getWooPaymentsSettingsBootstrap() ).toEqual( {
			accountStatus: 'connected',
		} );
	} );
} );
