import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';
import { openFixtureAdminSession } from '../../../utils/woopayments-native/fixture-settings';
import {
	withGuaranteedRestoration,
	withWidenedCurrencyCatalog,
} from '../../../utils/woopayments-native/multi-currency-catalog';

/**
 * Native multi-currency settings management (mc-settings-management-spec).
 *
 * Five provider-free merchant contracts against the Core-owned multi-currency
 * settings surface (wc-settings → Multi-currency, MultiCurrencySettingsPage +
 * multi-currency-settings React app) and the Core Features screen.
 *
 * Per DECISIONS.md 2026-08-08 ("The multi-currency settings screen is the
 * native equivalent of the client's onboarding wizard"), the two retained
 * onboarding rows prove selection persistence into enabled currencies and the
 * automatic geolocation switch opt-in against the settings modal. No wizard
 * affordance is asserted, and the geolocation row's oracle is the opt-in setting round-trip
 * (wcpay_multi_currency_enable_auto_currency), never a live geolocation
 * simulation.
 *
 * Restoration discipline: every test snapshots the state it will touch via
 * the authoritative REST echoes before mutating, restores it through the same
 * routes afterwards, and verifies the restored echo equals the snapshot. The
 * snapshot surface is the REST projection, not raw option bytes — the
 * raw-byte journaling machinery lives in a closed frozen bundle
 * (drivers/card-testing-protection.ts) that this spec must not import; the
 * remaining absent-option-vs-default-value ambiguity is recorded in the
 * package NOTES as a run-phase residue.
 *
 * Forced-premise bound (selection-persistence row only): the standing store's
 * provider rate cache is empty, so its available-currency catalog offers
 * USD plus EUR, the latter only because it is enabled with a manual
 * rate. The selection-persistence row needs additional currencies and runs inside
 * utils/woopayments-native/multi-currency-catalog.ts, which snapshots the raw
 * rate-cache option, forces the codes it needs into it, and byte-restores it
 * with a verified read. That row therefore proves what native's
 * currency-management surface does GIVEN a catalog that offers those
 * currencies; it proves nothing about the provider offering them, because the
 * run supplies that premise itself. The other four rows need no such premise
 * and run against the store exactly as it stands.
 *
 * Feature-toggle bound: Core owns the native runtime feature through its
 * standard Features setting. The disable and enable rows use the Core REST
 * settings resource only for reversible setup and restoration, then exercise
 * the merchant transition through the user-visible Features form.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const SETUP_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:';
const ONBOARDING_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-on-boarding.spec.ts:';

const CONTRACT_IDS = {
	pageLoad:
		'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency.spec.ts:43::Multi-currency › page load without any errors',
	disableFeature: `${ SETUP_CONTRACT_PREFIX }42::Multi-currency setup › can disable the multi-currency feature`,
	enableFeature: `${ SETUP_CONTRACT_PREFIX }46::Multi-currency setup › can enable the multi-currency feature`,
	selectionPersists: `${ ONBOARDING_CONTRACT_PREFIX }139::Multi-currency on-boarding › Currency selection and management › selected currencies are enabled after onboarding`,
	geolocationOptIn: `${ ONBOARDING_CONTRACT_PREFIX }165::Multi-currency on-boarding › Geolocation features › should offer currency switch by geolocation`,
} as const;

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const CORE_MULTI_CURRENCY_FEATURE_API =
	'/wp-json/wc/v3/settings/advanced/woocommerce_feature_multi_currency_enabled';
const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const MC_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';
const FEATURES_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=advanced&section=features';

// Copy authored by core (app.tsx / store-settings.tsx / settings-page.tsx).
const ADD_REMOVE_BUTTON = 'Add/remove currencies';
const ADD_MODAL_TITLE = 'Add enabled currencies';
const UPDATE_SELECTED_BUTTON = 'Update selected';
const CURRENCIES_UPDATED_NOTICE = 'Enabled currencies updated.';
const STORE_SETTINGS_SAVED_NOTICE = 'Store settings saved.';
const AUTO_CURRENCY_LABEL =
	'Automatically switch customers to their local currency if it has been enabled';
const SAVE_CHANGES_BUTTON = 'Save changes';
const FEATURE_DISABLE_EXPLANATION =
	'Disabling Multi-Currency stops currency switching but keeps your currency settings and order data.';

// The failure shapes the page-load smoke exists to catch, matching the
// payouts-disputes release smoke.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

// The deterministic currencies the persistence row commits to, from the
// source contract's own observable outcome (GBP, EUR, CAD, AUD).
const PERSISTENCE_CODES = [ 'AUD', 'CAD', 'EUR', 'GBP' ] as const;
// Automatic catalog rates the selection-persistence row forces into the provider rate cache. Only their presence and positivity matter here —
// no row asserts a converted shopper amount — but they are held at plausible
// USD-base values so the merchant surface renders realistic data.
const CATALOG_RATES = {
	AUD: 1.52,
	CAD: 1.37,
	GBP: 0.79,
} as const;

interface CurrencyRecord {
	code: string;
	name: string;
	rate: number;
	is_default: boolean;
}

interface StoreCurrencies {
	available: Record< string, CurrencyRecord >;
	enabled: Record< string, CurrencyRecord >;
	default: CurrencyRecord;
}

interface StoreSettingsEcho {
	autoCurrency: boolean;
	storefrontSwitcher: boolean;
	renderingMode: string;
}

interface PaymentsSettingsCompanions {
	enabled_payment_method_ids: unknown;
	is_manual_capture_enabled: unknown;
	is_debug_log_enabled: unknown;
	is_payment_request_enabled: unknown;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this smoke.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

async function readJson< Result = Record< string, unknown > >(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

async function readPaymentsSettingsCompanions(
	adminApi: APIRequestContext,
	description: string
): Promise< PaymentsSettingsCompanions > {
	const settings = await readJson(
		await adminApi.get( PAYMENTS_SETTINGS_API ),
		description
	);
	expect( settings.is_wcpay_enabled ).toBe( true );
	return {
		enabled_payment_method_ids: settings.enabled_payment_method_ids,
		is_manual_capture_enabled: settings.is_manual_capture_enabled,
		is_debug_log_enabled: settings.is_debug_log_enabled,
		is_payment_request_enabled: settings.is_payment_request_enabled,
	};
}

async function logInAsAdmin(
	page: Page,
	baseURL: string | undefined
): Promise< void > {
	await openFixtureAdminSession( {
		baseURL: requireBaseUrl( baseURL ),
		fixtureEnabled: process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE === 'true',
		page,
		login: async () => {
			// Connected profiles retain the harness's explicit fresh-login path.
			await page.context().clearCookies();
			await page.goto( 'wp-login.php' );
			await waitForWordPressLoginReady( page );
			await page
				.getByLabel( 'Username or Email Address' )
				.fill( ADMIN_USERNAME );
			await page
				.getByRole( 'textbox', { name: 'Password' } )
				.fill( ADMIN_PASSWORD );
			await page.getByRole( 'button', { name: 'Log In' } ).click();
			await page.waitForURL( '**/wp-admin/**' );
		},
	} );
}

type CoreMultiCurrencyFeatureValue = 'yes' | 'no';

async function readCoreMultiCurrencyFeature(
	adminApi: APIRequestContext
): Promise< CoreMultiCurrencyFeatureValue > {
	const { value } = await readJson< { value: unknown } >(
		await adminApi.get( CORE_MULTI_CURRENCY_FEATURE_API ),
		'Core Multi-Currency feature read'
	);
	if ( value !== 'yes' && value !== 'no' ) {
		throw new Error(
			`Core Multi-Currency feature read returned an invalid value: ${ String(
				value
			) }`
		);
	}
	return value;
}

async function setCoreMultiCurrencyFeature(
	adminApi: APIRequestContext,
	value: CoreMultiCurrencyFeatureValue
): Promise< void > {
	await readJson(
		await adminApi.post( CORE_MULTI_CURRENCY_FEATURE_API, {
			data: { value },
		} ),
		'Core Multi-Currency feature write'
	);
	expect(
		await readCoreMultiCurrencyFeature( adminApi ),
		'Core Multi-Currency feature write must be visible through a fresh read'
	).toBe( value );
}

function multiCurrencyFeatureRow( page: Page ): Locator {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'rowheader', {
			name: 'Multi-currency',
			exact: true,
		} ),
	} );
}

async function getStoreCurrencies(
	adminApi: APIRequestContext
): Promise< StoreCurrencies > {
	return readJson< StoreCurrencies >(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state read'
	);
}

function sortedCodes( record: Record< string, unknown > ): string[] {
	return Object.keys( record ).toSorted();
}

/**
 * The enabled codes in the order the store itself reports them (default first,
 * then the catalog's own ordering). Writes restore this order rather than the
 * sorted one so the stored option comes back in its original sequence; sorted
 * codes stay the comparison surface, because set membership — not order — is
 * what the contracts are about.
 */
function storeOrderCodes( record: Record< string, unknown > ): string[] {
	return Object.keys( record );
}

function normalizeSettingsFlag( value: unknown ): boolean {
	return value === true || value === 'yes';
}

async function getStoreSettingsEcho(
	adminApi: APIRequestContext
): Promise< StoreSettingsEcho > {
	const settings = await readJson(
		await adminApi.get( `${ MULTI_CURRENCY_API }/get-settings` ),
		'Multi-currency store settings read'
	);
	return {
		autoCurrency: normalizeSettingsFlag(
			settings.wcpay_multi_currency_enable_auto_currency
		),
		storefrontSwitcher: normalizeSettingsFlag(
			settings.wcpay_multi_currency_enable_storefront_switcher
		),
		renderingMode: String(
			settings.wcpay_multi_currency_rendering_mode ?? 'speed'
		),
	};
}

/**
 * Canonical single-currency settings echo, used to prove per-currency
 * configuration survives an enabled-set mutation and its restoration —
 * update-enabled-currencies deletes per-currency options for removed codes.
 */
async function getCurrencySettingsEcho(
	adminApi: APIRequestContext,
	code: string
): Promise< string > {
	const settings = await readJson(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies/${ code }` ),
		`Single-currency settings read for ${ code }`
	);
	return JSON.stringify( settings, Object.keys( settings ).toSorted() );
}

/**
 * Write the enabled-currency set through the authoritative route and prove
 * the echoed state matches: the route answers HTTP 200 with the unchanged
 * list when the payload is not a non-empty array, so a green response alone
 * does not prove the request took effect.
 */
async function setEnabledCurrencies(
	adminApi: APIRequestContext,
	codes: string[]
): Promise< void > {
	const updated = await readJson< StoreCurrencies >(
		await adminApi.post(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ data: { enabled: codes } }
		),
		'Enabled-currencies update'
	);
	const expected = [ ...new Set( codes ) ].toSorted();
	const echoed = sortedCodes( updated.enabled );
	if ( echoed.join( ',' ) !== expected.join( ',' ) ) {
		throw new Error(
			`Enabled-currencies update did not take effect; requested ${ expected.join(
				', '
			) } but the store reports ${ echoed.join( ', ' ) }`
		);
	}
}

/**
 * Restore the multi-currency store settings from their snapshot through the
 * authoritative route and prove the echo matches the snapshot.
 */
async function restoreStoreSettings(
	adminApi: APIRequestContext,
	snapshot: StoreSettingsEcho
): Promise< void > {
	await readJson(
		await adminApi.post( `${ MULTI_CURRENCY_API }/update-settings`, {
			data: {
				wcpay_multi_currency_enable_auto_currency: snapshot.autoCurrency
					? 'yes'
					: 'no',
				wcpay_multi_currency_enable_storefront_switcher:
					snapshot.storefrontSwitcher ? 'yes' : 'no',
				wcpay_multi_currency_rendering_mode: snapshot.renderingMode,
			},
		} ),
		'Store settings restoration'
	);
	expect(
		await getStoreSettingsEcho( adminApi ),
		'restored store settings must echo the pre-test snapshot'
	).toEqual( snapshot );
}

/**
 * Count non-GET requests the page dispatches to one store REST route, so a
 * test can prove exactly how many writes an interaction performed — zero for
 * draft-only interactions, exactly one for a single save.
 */
function trackRouteWrites( page: Page, routeSuffix: string ): () => number {
	let writeCount = 0;
	page.on( 'request', ( request ) => {
		if ( request.method() === 'GET' ) {
			return;
		}
		try {
			const url = new URL( request.url() );
			const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
			if (
				url.pathname.replace( /\/+$/, '' ).endsWith( routeSuffix ) ||
				restRoute.replace( /\/+$/, '' ) === routeSuffix
			) {
				writeCount++;
			}
		} catch {
			// Unparsable URLs cannot be a store REST route.
		}
	} );
	return () => writeCount;
}

/**
 * Collect failed same-origin REST responses, so a page that renders its
 * chrome while its data fetches quietly error is not mistaken for a healthy
 * load. Mirrors the payouts-disputes release smoke's oracle.
 */
function trackFailedRestResponses(
	page: Page,
	baseUrl: string
): { failures: () => string[]; observed: () => number } {
	const failures: string[] = [];
	const restPrefix = `${ new URL( baseUrl ).pathname.replace(
		/\/+$/,
		''
	) }/wp-json/`;
	let observedRestResponses = 0;
	const storeRestPath = ( url: string ): string | null => {
		if ( ! url.startsWith( baseUrl ) ) {
			return null;
		}
		const { pathname, searchParams } = new URL( url );
		if (
			! pathname.startsWith( restPrefix ) &&
			! searchParams.has( 'rest_route' )
		) {
			return null;
		}
		return pathname;
	};

	page.on( 'response', ( response ) => {
		const path = storeRestPath( response.url() );
		if ( ! path ) {
			return;
		}
		observedRestResponses++;
		if ( response.status() >= 400 ) {
			failures.push( `${ response.status() } ${ path }` );
		}
	} );
	page.on( 'requestfailed', ( request ) => {
		const errorText = request.failure()?.errorText ?? 'unknown';
		if ( errorText === 'net::ERR_ABORTED' ) {
			return;
		}
		const path = storeRestPath( request.url() );
		if ( path ) {
			failures.push( `failed ${ path } (${ errorText })` );
		}
	} );

	return {
		failures: () => [ ...failures ],
		observed: () => observedRestResponses,
	};
}

// Assets belonging to plugins other than the one under test. The standing
// store carries unrelated third-party plugins — an installed
// woocommerce-subscriptions build 404s on its admin stylesheet on every admin
// screen — and Chromium echoes each such network failure as a console error.
// Those say nothing about the multi-currency surface. The exclusion is scoped
// by owner and named here rather than being a blanket console filter: every
// same-origin failure that is not another plugin's asset still counts, and
// uncaught exceptions always count regardless of source.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;

function isAmbientForeignResource( url: string, baseUrl: string ): boolean {
	if ( ! url.startsWith( baseUrl ) ) {
		// Cross-origin resources (gravatars, w.org emoji) are never this
		// surface's responsibility.
		return true;
	}
	try {
		return THIRD_PARTY_PLUGIN_ASSET.test( new URL( url ).pathname );
	} catch {
		return false;
	}
}

/**
 * Collect uncaught page exceptions and attributable console errors. The
 * page-load contract's title is literally "without any errors", so headings
 * alone are not the oracle.
 */
function trackPageErrors( page: Page, baseUrl: string ): () => string[] {
	const errors: string[] = [];
	page.on( 'pageerror', ( error ) => {
		errors.push( `pageerror: ${ error.message }` );
	} );
	page.on( 'console', ( message ) => {
		if ( message.type() !== 'error' ) {
			return;
		}
		const source = message.location().url;
		if ( source && isAmbientForeignResource( source, baseUrl ) ) {
			return;
		}
		errors.push(
			`console: ${ message.text() } (${ source || 'no source' })`
		);
	} );
	return () => [ ...errors ];
}

/**
 * The enabled-currencies table row for one currency code, keyed on the
 * code cell rather than row text so "Swiss franc" prose elsewhere in a row
 * cannot alias it.
 */
function enabledCurrencyRow( page: Page, code: string ): Locator {
	return page.getByRole( 'row' ).filter( {
		has: page.getByRole( 'cell', { name: code, exact: true } ),
	} );
}

/**
 * The add-currencies modal checkbox for one currency code. Labels are
 * "<name> <code>" (app.tsx), so anchoring on the trailing code keeps the
 * locator independent of display-name copy.
 */
function modalCurrencyCheckbox( dialog: Locator, code: string ): Locator {
	return dialog.getByRole( 'checkbox', {
		name: new RegExp( ` ${ code }$` ),
	} );
}

async function openAddCurrenciesModal( page: Page ): Promise< Locator > {
	await page
		.getByRole( 'button', { name: ADD_REMOVE_BUTTON, exact: true } )
		.click();
	const dialog = page.getByRole( 'dialog', { name: ADD_MODAL_TITLE } );
	await expect( dialog ).toBeVisible();
	return dialog;
}

/**
 * Assert the multi-currency settings surface reached its loaded terminal
 * state: both data reads settled, no loading placeholder remains, and none
 * of the surface's own error states rendered.
 */
async function expectMultiCurrencySurfaceLoaded( page: Page ): Promise< void > {
	await expect( page.getByText( DENIAL_TEXT ) ).toHaveCount( 0 );
	await expect( page.getByText( FATAL_TEXT ) ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'heading', { name: 'Enabled currencies' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: 'Store settings' } )
	).toBeVisible();
	// Loading states must settle; both the currencies list and the store
	// settings section render explicit pending and failure copy.
	await expect( page.getByText( 'Loading currencies…' ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Loading store settings…' ) ).toHaveCount(
		0
	);
	await expect(
		page.getByText( 'Unable to load multi-currency settings.' )
	).toHaveCount( 0 );
	await expect(
		page.getByText( 'Unable to load store settings.' )
	).toHaveCount( 0 );
	await expect( page.getByText( 'Error loading currencies.' ) ).toHaveCount(
		0
	);
	await expect(
		page.getByText( 'Error loading store settings.' )
	).toHaveCount( 0 );
}

test(
	'An authorized merchant can discover and load the native multi-currency management surface with default and enabled-currency information and no errors',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.pageLoad,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Precondition guard: the connected settings variant only renders for
		// a connected account. A degraded store must fail here, not pass by
		// rendering the onboarding CTA around missing data.
		const runtimeStatus = await readJson(
			await adminApi.get( RUNTIME_STATUS_API ),
			'Runtime status read'
		);
		expect(
			{
				account_connected: runtimeStatus.account_connected,
				gateway_enabled: runtimeStatus.gateway_enabled,
			},
			'the store must report a connected account and an enabled gateway'
		).toEqual( { account_connected: true, gateway_enabled: true } );

		// The authoritative truth this load must join to.
		const currencies = await getStoreCurrencies( adminApi );
		const enabledCodes = sortedCodes( currencies.enabled );
		expect(
			enabledCodes.length,
			'the enabled set always contains at least the default currency'
		).toBeGreaterThan( 0 );

		const restTracker = trackFailedRestResponses( page, storeBase );
		const pageErrors = trackPageErrors( page, storeBase );
		const currencyWrites = trackRouteWrites(
			page,
			'/wc/v3/payments/multi-currency/update-enabled-currencies'
		);
		const settingsWrites = trackRouteWrites(
			page,
			'/wc/v3/payments/multi-currency/update-settings'
		);
		const paymentsSettingsWrites = trackRouteWrites(
			page,
			'/wc/v3/payments/settings'
		);

		await logInAsAdmin( page, baseURL );
		await page.goto( MC_SETTINGS_PATH );

		// The surface is discoverable: its own tab is the active one.
		await expect(
			page
				.locator( '.nav-tab-wrapper' )
				.getByRole( 'link', { name: 'Multi-currency', exact: true } )
		).toHaveClass( /nav-tab-active/ );

		await expect( page.getByText( MIGRATION_TEXT ) ).toHaveCount( 0 );
		await expectMultiCurrencySurfaceLoaded( page );

		// Joined content: every REST-enabled currency renders exactly one
		// row, keyed on its code cell.
		for ( const columnHeader of [
			'Name',
			'Code',
			'Exchange rate',
			'Actions',
		] ) {
			await expect(
				page.getByRole( 'columnheader', { name: columnHeader } )
			).toBeVisible();
		}
		for ( const code of enabledCodes ) {
			await expect( enabledCurrencyRow( page, code ) ).toHaveCount( 1 );
		}
		// The default row carries its exact meaning and offers no removal.
		const defaultRow = enabledCurrencyRow( page, currencies.default.code );
		await expect(
			defaultRow.getByText( 'Default currency' ).first()
		).toBeVisible();
		await expect( defaultRow.getByRole( 'button' ) ).toHaveCount( 0 );

		// The management and store-settings controls are present, and the
		// pristine store-settings form is not actionable.
		await expect(
			page.getByRole( 'button', { name: ADD_REMOVE_BUTTON, exact: true } )
		).toBeEnabled();
		await expect(
			page.getByRole( 'checkbox', { name: AUTO_CURRENCY_LABEL } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: SAVE_CHANGES_BUTTON } )
		).toBeDisabled();

		// No store REST request behind the surface failed, the collector
		// really observed traffic, nothing threw in the page, and the load
		// dispatched zero settings writes.
		expect( restTracker.observed() ).toBeGreaterThan( 0 );
		expect( restTracker.failures() ).toEqual( [] );
		expect( pageErrors() ).toEqual( [] );
		expect( currencyWrites() ).toBe( 0 );
		expect( settingsWrites() ).toBe( 0 );
		expect( paymentsSettingsWrites() ).toBe( 0 );
	}
);

test(
	'A merchant can disable multi-currency and the disabled setting persists',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.disableFeature,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const originalFeatureValue =
			await readCoreMultiCurrencyFeature( adminApi );
		const paymentsSettingsBefore = await readPaymentsSettingsCompanions(
			adminApi,
			'Payments settings read before Core feature disable'
		);

		await withGuaranteedRestoration(
			async () => {
				await setCoreMultiCurrencyFeature( adminApi, 'yes' );
				await logInAsAdmin( page, baseURL );
				await page.goto( FEATURES_SETTINGS_PATH );
				const featureRow = multiCurrencyFeatureRow( page );
				const disableRadio = featureRow.getByRole( 'radio', {
					name: 'Disable',
					exact: true,
				} );
				await expect( disableRadio ).toBeDisabled();
				await expect(
					featureRow.getByText( FEATURE_DISABLE_EXPLANATION )
				).toBeVisible();
				await featureRow
					.getByRole( 'link', {
						name: 'Disable Multi-Currency',
						exact: true,
					} )
					.click();
				expect( await readCoreMultiCurrencyFeature( adminApi ) ).toBe(
					'no'
				);
				expect(
					await readPaymentsSettingsCompanions(
						adminApi,
						'Payments settings read after Core feature disable'
					)
				).toEqual( paymentsSettingsBefore );
			},
			async () => {
				await setCoreMultiCurrencyFeature(
					adminApi,
					originalFeatureValue
				);
			}
		);
	}
);

test(
	'A merchant can enable multi-currency and the enabled setting persists',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.enableFeature,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		const originalFeatureValue =
			await readCoreMultiCurrencyFeature( adminApi );

		await withGuaranteedRestoration(
			async () => {
				await setCoreMultiCurrencyFeature( adminApi, 'no' );
				await logInAsAdmin( page, baseURL );
				await page.goto( FEATURES_SETTINGS_PATH );
				const featureRow = multiCurrencyFeatureRow( page );
				const enableRadio = featureRow.getByRole( 'radio', {
					name: 'Enable',
					exact: true,
				} );
				await expect( enableRadio ).not.toBeChecked();
				await enableRadio.check();
				await page
					.getByRole( 'button', { name: SAVE_CHANGES_BUTTON } )
					.click();
				expect( await readCoreMultiCurrencyFeature( adminApi ) ).toBe(
					'yes'
				);
				await page.goto( MC_SETTINGS_PATH );
				await expectMultiCurrencySurfaceLoaded( page );
			},
			async () => {
				await setCoreMultiCurrencyFeature(
					adminApi,
					originalFeatureValue
				);
			}
		);
	}
);

test(
	'Submitting a multi-currency selection persists every selected currency as enabled store configuration',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.selectionPersists,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page } ) => {
		// FORCED PREMISE: the contract's own catalog is GBP/EUR/CAD/AUD, and
		// the standing store offers only EUR. The run injects the other three
		// into the provider rate cache and byte-restores it afterwards, so
		// this proves that a submitted selection persists GIVEN a catalog that
		// offers those currencies, not that the provider offers them.
		await withWidenedCurrencyCatalog(
			{
				baseURL,
				rates: {
					AUD: CATALOG_RATES.AUD,
					CAD: CATALOG_RATES.CAD,
					GBP: CATALOG_RATES.GBP,
				},
			},
			async () => {
				// Per DECISIONS.md 2026-08-08, selection persistence is proven
				// against the settings modal: submit a known selection, then
				// read the authoritative enabled-currency source as well as
				// the UI.
				const before = await getStoreCurrencies( adminApi );
				const baselineCodes = sortedCodes( before.enabled );
				const currencySettingsBefore: Record< string, string > = {};
				for ( const code of PERSISTENCE_CODES ) {
					expect(
						before.available[ code ],
						`${ code } must be an available currency on this store`
					).toBeTruthy();
					currencySettingsBefore[ code ] =
						await getCurrencySettingsEcho( adminApi, code );
				}
				const expectedCodes = [
					...new Set( [ ...baselineCodes, ...PERSISTENCE_CODES ] ),
				].toSorted();

				const currencyWrites = trackRouteWrites(
					page,
					'/wc/v3/payments/multi-currency/update-enabled-currencies'
				);

				await logInAsAdmin( page, baseURL );
				await page.goto( MC_SETTINGS_PATH );
				const dialog = await openAddCurrenciesModal( page );

				// Select the four deterministic currencies. check() is
				// idempotent, so a code already enabled on the baseline store
				// stays selected.
				for ( const code of PERSISTENCE_CODES ) {
					await modalCurrencyCheckbox( dialog, code ).check();
				}
				for ( const code of PERSISTENCE_CODES ) {
					await expect(
						modalCurrencyCheckbox( dialog, code )
					).toBeChecked();
				}

				await dialog
					.getByRole( 'button', { name: UPDATE_SELECTED_BUTTON } )
					.click();
				await expect( dialog ).toHaveCount( 0 );
				await expect(
					page.getByText( CURRENCIES_UPDATED_NOTICE ).first()
				).toBeVisible();
				expect( currencyWrites() ).toBe( 1 );

				// Authoritative echo: the enabled set is exactly the baseline
				// union the selection, compared as sets — every selected code
				// once, every baseline code preserved.
				const after = await getStoreCurrencies( adminApi );
				expect( sortedCodes( after.enabled ) ).toEqual( expectedCodes );

				// The merchant-visible configuration agrees after a full
				// reload: exactly one enabled row per selected code.
				await page.reload();
				for ( const code of PERSISTENCE_CODES ) {
					await expect(
						enabledCurrencyRow( page, code )
					).toHaveCount( 1 );
				}

				// Restore the snapshot and verify, including the per-currency
				// settings of every code this test enabled — restoration
				// removes the non-baseline codes, which deletes their
				// per-currency options.
				await setEnabledCurrencies(
					adminApi,
					storeOrderCodes( before.enabled )
				);
				for ( const code of PERSISTENCE_CODES ) {
					expect(
						await getCurrencySettingsEcho( adminApi, code ),
						`${ code } per-currency settings must survive the enable/restore cycle`
					).toBe( currencySettingsBefore[ code ] );
				}
			}
		);
	}
);

test(
	'The multi-currency settings screen offers a comprehensible automatic geolocation currency-switch opt-in that persists when saved',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.geolocationOptIn,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, baseURL } ) => {
		// Per DECISIONS.md 2026-08-08, the geolocation switch capability is
		// proven against the settings screen. The oracle is the opt-in
		// setting round-trip — never a live geolocation simulation, and the
		// smoke must not leave automatic switching enabled.
		const before = await getStoreCurrencies( adminApi );
		const settingsBefore = await getStoreSettingsEcho( adminApi );

		// Meaningful fixture: the opt-in only means something with at least
		// one non-default enabled currency to switch to.
		expect(
			sortedCodes( before.enabled ).filter(
				( code ) => code !== before.default.code
			).length,
			'at least one non-default currency must be enabled'
		).toBeGreaterThan( 0 );
		// Authoritatively disabled baseline. Automatic switching is the one
		// setting this suite refuses to repair silently: an already-enabled
		// flag on the standing store is prior-run leakage that must surface,
		// not be papered over.
		expect(
			settingsBefore.autoCurrency,
			'automatic currency switching must start disabled; a prior run leaked state'
		).toBe( false );

		const settingsWrites = trackRouteWrites(
			page,
			'/wc/v3/payments/multi-currency/update-settings'
		);

		await logInAsAdmin( page, baseURL );
		await page.goto( MC_SETTINGS_PATH );

		// The opt-in is a comprehensible, labelled control that starts
		// unchecked, next to a save action that is not yet actionable.
		const optIn = page.getByRole( 'checkbox', {
			name: AUTO_CURRENCY_LABEL,
		} );
		await expect( optIn ).toBeVisible();
		await expect( optIn ).not.toBeChecked();
		const saveButton = page.getByRole( 'button', {
			name: SAVE_CHANGES_BUTTON,
		} );
		await expect( saveButton ).toBeDisabled();

		// Draft boundary: activating the opt-in makes the form dirty and the
		// save actionable, and dispatches nothing by itself.
		await optIn.check();
		await expect( optIn ).toBeChecked();
		await expect( saveButton ).toBeEnabled();
		expect( settingsWrites() ).toBe( 0 );

		// Exactly one acknowledged save.
		await saveButton.click();
		await expect(
			page.getByText( STORE_SETTINGS_SAVED_NOTICE ).first()
		).toBeVisible();
		expect( settingsWrites() ).toBe( 1 );

		// Authoritative round-trip: the raw opt-in reports enabled through a
		// fresh read, companion settings are preserved, and the checked
		// state survives a full reload.
		const settingsAfterSave = await getStoreSettingsEcho( adminApi );
		expect( settingsAfterSave.autoCurrency ).toBe( true );
		expect( settingsAfterSave.storefrontSwitcher ).toBe(
			settingsBefore.storefrontSwitcher
		);
		expect( settingsAfterSave.renderingMode ).toBe(
			settingsBefore.renderingMode
		);
		await page.reload();
		await expect( optIn ).toBeChecked();

		// Restore the snapshot and verify: automatic switching returns to
		// its disabled baseline, so the smoke cannot leave the storefront
		// auto-switching currencies behind it.
		await restoreStoreSettings( adminApi, settingsBefore );
		expect( ( await getStoreSettingsEcho( adminApi ) ).autoCurrency ).toBe(
			false
		);
	}
);
