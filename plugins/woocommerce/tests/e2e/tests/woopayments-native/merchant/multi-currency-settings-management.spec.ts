import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';
import { withWidenedCurrencyCatalog } from '../../../utils/woopayments-native/multi-currency-catalog';

/**
 * Native multi-currency settings management (mc-settings-management-spec).
 *
 * Eight provider-free merchant contracts against the Core-owned multi-currency
 * settings surface (wc-settings → Multi-currency, MultiCurrencySettingsPage +
 * multi-currency-settings React app) and the native WooPayments settings
 * feature toggle.
 *
 * Per DECISIONS.md 2026-08-08 ("The multi-currency settings screen is the
 * native equivalent of the client's onboarding wizard"), the three onboarding
 * rows here prove their underlying capabilities against the settings modal:
 * multi-select, selection persistence into enabled currencies, and the
 * automatic geolocation switch opt-in. No wizard affordance is asserted, and
 * the geolocation row's oracle is the opt-in setting round-trip
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
 * Forced-premise bound (currency-management rows only): the standing store's
 * provider rate cache is empty, so its available-currency catalog is one code
 * wide — USD plus EUR, the latter only because it is enabled with a manual
 * rate. The four rows that need a third currency (add, remove, multi-select,
 * selection persistence) run inside
 * utils/woopayments-native/multi-currency-catalog.ts, which snapshots the raw
 * rate-cache option, forces the codes it needs into it, and byte-restores it
 * with a verified read. Those rows therefore prove what native's
 * currency-management surface does GIVEN a catalog that offers those
 * currencies; they prove nothing about the provider offering them, because the
 * run supplies that premise itself. The other four rows need no such premise
 * and run against the store exactly as it stands.
 *
 * Feature-toggle bound: MultiCurrencyRuntimeArbiter currently returns Core
 * ownership for the native runtime without consulting
 * _wcpay_feature_customer_multi_currency (the source-anchored defect
 * candidate named by the disable/enable deferral packets). The disable test
 * therefore proves the authoritative REST flag echo and reload persistence —
 * the arbiter-effect proxy that is truthful today — and deliberately does not
 * assert tab or switcher absence, which would convert this smoke into the
 * packets' RED-fingerprint defect run.
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
	addCurrency: `${ SETUP_CONTRACT_PREFIX }53::Multi-currency setup › Currency management › can add a new currency`,
	removeCurrency: `${ SETUP_CONTRACT_PREFIX }57::Multi-currency setup › Currency management › can remove a currency`,
	multiSelect: `${ ONBOARDING_CONTRACT_PREFIX }86::Multi-currency on-boarding › Currency selection and management › should allow multiple currencies to be selected`,
	selectionPersists: `${ ONBOARDING_CONTRACT_PREFIX }139::Multi-currency on-boarding › Currency selection and management › selected currencies are enabled after onboarding`,
	geolocationOptIn: `${ ONBOARDING_CONTRACT_PREFIX }165::Multi-currency on-boarding › Geolocation features › should offer currency switch by geolocation`,
} as const;

const RUNTIME_STATUS_API = '/wp-json/wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const MC_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';
const PAYMENTS_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=checkout&section=woocommerce_payments';

// Copy authored by core (app.tsx / store-settings.tsx / settings-page.tsx).
const ADD_REMOVE_BUTTON = 'Add/remove currencies';
const ADD_MODAL_TITLE = 'Add enabled currencies';
const UPDATE_SELECTED_BUTTON = 'Update selected';
const CURRENCIES_UPDATED_NOTICE = 'Enabled currencies updated.';
const STORE_SETTINGS_SAVED_NOTICE = 'Store settings saved.';
const PAYMENTS_SETTINGS_SAVED_NOTICE = 'Settings saved.';
const AUTO_CURRENCY_LABEL =
	'Automatically switch customers to their local currency if it has been enabled';
const FEATURE_TOGGLE_LABEL = 'Enable multi-currency';
const SAVE_CHANGES_BUTTON = 'Save changes';

// The failure shapes the page-load smoke exists to catch, matching the
// payouts-disputes release smoke.
const DENIAL_TEXT = /not allowed|do not have permission/i;
const FATAL_TEXT = /fatal error|there has been a critical error/i;
const MIGRATION_TEXT = /database update|update required|migration/i;

// The deterministic currencies the persistence row commits to, from the
// source contract's own observable outcome (GBP, EUR, CAD, AUD).
const PERSISTENCE_CODES = [ 'AUD', 'CAD', 'EUR', 'GBP' ] as const;
// The deterministic currency the add/remove rows commit to.
const MANAGED_CODE = 'CHF';
// Deterministic preference order for the transient multi-select row: the
// first two available, not-yet-enabled, non-default codes from this list.
const MULTI_SELECT_CANDIDATES = [
	'CHF',
	'CAD',
	'AUD',
	'GBP',
	'JPY',
	'NZD',
	'SEK',
] as const;

// Automatic catalog rates the run forces into the provider rate cache for the
// currency-management rows. Only their presence and positivity matter here —
// no row asserts a converted shopper amount — but they are held at plausible
// USD-base values so the merchant surface renders realistic data.
const CATALOG_RATES = {
	AUD: 1.52,
	CAD: 1.37,
	CHF: 0.9,
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

async function logInAsAdmin( page: Page ): Promise< void > {
	// Clear first, matching the harness's own admin login: a stale session
	// cookie would redirect wp-login.php to wp-admin and leave the form fill
	// hunting a field that is not there.
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USERNAME );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( ADMIN_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( '**/wp-admin/**' );
}

/**
 * Idempotent feature-flag seed: repair a drifted baseline through the
 * documented settings route rather than failing the row for a prior run's
 * leftovers, mirroring the frozen settings spec's baseline discipline.
 */
async function ensureMultiCurrencyFeatureFlag(
	adminApi: APIRequestContext,
	enabled: boolean,
	currentValue: boolean
): Promise< void > {
	if ( currentValue === enabled ) {
		return;
	}
	await readJson(
		await adminApi.post( PAYMENTS_SETTINGS_API, {
			data: { is_multi_currency_enabled: enabled },
		} ),
		'Multi-currency feature baseline seed'
	);
}

/**
 * Read the first-run fraud-protection tour's dismissal state out of the
 * payments settings echo.
 */
function readFraudTourDismissed(
	settings: Record< string, unknown >
): boolean {
	const fraudProtection = settings.fraud_protection;
	return (
		typeof fraudProtection === 'object' &&
		fraudProtection !== null &&
		( fraudProtection as Record< string, unknown > )
			.is_welcome_tour_dismissed === true
	);
}

/**
 * Seed or restore the first-run fraud-protection tour's dismissal state
 * through the same documented route the settings app writes it with
 * (client/admin/client/woopayments/settings/fraud-protection/tour.tsx →
 * saveOption).
 *
 * The two feature-toggle rows need this because that tour mounts as soon as
 * the fraud-protection card scrolls into view on the payments settings screen,
 * and its spotlight overlay intercepts pointer events across the whole form —
 * ambient first-run UI that has nothing to do with the multi-currency contract
 * under test. Seeding it dismissed is a fixture, not a workaround for a
 * product defect: a merchant who has seen the tour once never meets it again.
 *
 * Residue: the route restores the value, not the option's prior absence — the
 * same absent-versus-default boundary the package NOTES record for every
 * REST-echo restoration in this spec.
 */
async function setFraudTourDismissed(
	adminApi: APIRequestContext,
	dismissed: boolean
): Promise< void > {
	const response = await adminApi.post(
		`${ PAYMENTS_SETTINGS_API }/wcpay_fraud_protection_welcome_tour_dismissed`,
		{ data: { value: dismissed } }
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Fraud-protection tour dismissal write failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
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

		await logInAsAdmin( page );
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
	async ( { adminApi, page } ) => {
		// Snapshot the state this test touches, plus the companion settings
		// the full-form save must not disturb.
		const settingsBefore = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( settingsBefore.is_wcpay_enabled ).toBe( true );
		const originalFlag = settingsBefore.is_multi_currency_enabled === true;
		const companionsBefore = {
			enabled_payment_method_ids:
				settingsBefore.enabled_payment_method_ids,
			is_manual_capture_enabled: settingsBefore.is_manual_capture_enabled,
			is_debug_log_enabled: settingsBefore.is_debug_log_enabled,
			is_payment_request_enabled:
				settingsBefore.is_payment_request_enabled,
		};

		// Isolated initial-on fixture: the disable transition needs a
		// genuinely enabled starting point regardless of prior runs.
		await ensureMultiCurrencyFeatureFlag( adminApi, true, originalFlag );
		// Ambient first-run overlay, seeded away and restored below.
		const tourDismissedBefore = readFraudTourDismissed( settingsBefore );
		await setFraudTourDismissed( adminApi, true );

		await logInAsAdmin( page );
		await page.goto( PAYMENTS_SETTINGS_PATH );

		const featureToggle = page.getByRole( 'checkbox', {
			name: FEATURE_TOGGLE_LABEL,
			exact: true,
		} );
		await expect( featureToggle ).toBeChecked();
		await featureToggle.uncheck();
		await page.getByRole( 'button', { name: SAVE_CHANGES_BUTTON } ).click();
		await expect(
			page.getByText( PAYMENTS_SETTINGS_SAVED_NOTICE ).first()
		).toBeVisible();

		// Authoritative state, not the save notice: the stored flag reports
		// disabled through a fresh authenticated read. This is the runtime
		// arbiter's input and the REST echo the ownership decision consults;
		// tab/switcher absence is deliberately not asserted while the pinned
		// native arbiter ignores the flag (see the file header).
		const settingsAfterDisable = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings re-read'
		);
		expect( settingsAfterDisable.is_multi_currency_enabled ).toBe( false );

		// Persistence across reload: the control still reports disabled when
		// the form is rebuilt from stored state, so this cannot pass on a
		// transient client-side value behind a success notice.
		await page.reload();
		await expect( featureToggle ).not.toBeChecked();

		// Companion settings survived the full-form save untouched.
		expect( {
			enabled_payment_method_ids:
				settingsAfterDisable.enabled_payment_method_ids,
			is_manual_capture_enabled:
				settingsAfterDisable.is_manual_capture_enabled,
			is_debug_log_enabled: settingsAfterDisable.is_debug_log_enabled,
			is_payment_request_enabled:
				settingsAfterDisable.is_payment_request_enabled,
		} ).toEqual( companionsBefore );

		// Restore the snapshot and verify: the suite must not leave the
		// feature off on the standing store, nor the first-run tour dismissed
		// when it was not.
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_multi_currency_enabled: originalFlag },
			} ),
			'Multi-currency feature restoration'
		);
		await setFraudTourDismissed( adminApi, tourDismissedBefore );
		const settingsRestored = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings restoration read'
		);
		expect( settingsRestored.is_multi_currency_enabled ).toBe(
			originalFlag
		);
		expect( readFraudTourDismissed( settingsRestored ) ).toBe(
			tourDismissedBefore
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
	async ( { adminApi, page } ) => {
		const settingsBefore = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( settingsBefore.is_wcpay_enabled ).toBe( true );
		const originalFlag = settingsBefore.is_multi_currency_enabled === true;

		// Isolated initial-off fixture, seeded per test rather than
		// inherited from the disable case: focused execution must exercise a
		// real off-to-on transition, not a no-op on an already-enabled store.
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_multi_currency_enabled: false },
			} ),
			'Multi-currency feature disabled seed'
		);
		// Ambient first-run overlay, seeded away and restored below.
		const tourDismissedBefore = readFraudTourDismissed( settingsBefore );
		await setFraudTourDismissed( adminApi, true );
		const settingsSeeded = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings seeded read'
		);
		expect( settingsSeeded.is_multi_currency_enabled ).toBe( false );

		await logInAsAdmin( page );
		await page.goto( PAYMENTS_SETTINGS_PATH );

		// The disabled baseline reached the merchant surface: a no-op helper
		// cannot report success from here, because the control observably
		// starts unchecked.
		const featureToggle = page.getByRole( 'checkbox', {
			name: FEATURE_TOGGLE_LABEL,
			exact: true,
		} );
		await expect( featureToggle ).not.toBeChecked();

		await featureToggle.check();
		await page.getByRole( 'button', { name: SAVE_CHANGES_BUTTON } ).click();
		await expect(
			page.getByText( PAYMENTS_SETTINGS_SAVED_NOTICE ).first()
		).toBeVisible();

		// Authoritative state: the stored flag reports enabled through a
		// fresh authenticated read, and the control persists across reload.
		const settingsAfterEnable = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings re-read'
		);
		expect( settingsAfterEnable.is_multi_currency_enabled ).toBe( true );
		await page.reload();
		await expect( featureToggle ).toBeChecked();

		// With the feature enabled, the merchant-facing multi-currency
		// runtime surface is reachable and loads its authoritative content.
		await page.goto( MC_SETTINGS_PATH );
		await expectMultiCurrencySurfaceLoaded( page );

		// Restore the snapshot and verify.
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_multi_currency_enabled: originalFlag },
			} ),
			'Multi-currency feature restoration'
		);
		await setFraudTourDismissed( adminApi, tourDismissedBefore );
		const settingsRestored = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings restoration read'
		);
		expect( settingsRestored.is_multi_currency_enabled ).toBe(
			originalFlag
		);
		expect( readFraudTourDismissed( settingsRestored ) ).toBe(
			tourDismissedBefore
		);
	}
);

test(
	'A merchant can add a supported currency and it becomes enabled for the store',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.addCurrency,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page } ) => {
		// FORCED PREMISE: the standing store's rate cache is empty, so its
		// catalog cannot offer a third currency at all. The run injects CHF's
		// automatic rate into that cache and byte-restores it afterwards.
		// What follows proves native's merchant add path GIVEN a catalog that
		// offers CHF; it does not prove the provider offers CHF.
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { CHF: CATALOG_RATES.CHF } },
			async () => {
				const before = await getStoreCurrencies( adminApi );
				const baselineCodes = sortedCodes( before.enabled );
				const chfSettingsBefore = await getCurrencySettingsEcho(
					adminApi,
					MANAGED_CODE
				);

				// Deterministic fixture: CHF must be available with a positive
				// rate and must not already be enabled. A drifted baseline
				// fails loudly here instead of surfacing as a misleading modal
				// assertion.
				expect(
					before.available[ MANAGED_CODE ],
					`${ MANAGED_CODE } must be an available currency on this store`
				).toBeTruthy();
				expect( before.available[ MANAGED_CODE ].rate ).toBeGreaterThan(
					0
				);
				expect(
					baselineCodes,
					`${ MANAGED_CODE } must start disabled; a prior run leaked state`
				).not.toContain( MANAGED_CODE );

				const currencyWrites = trackRouteWrites(
					page,
					'/wc/v3/payments/multi-currency/update-enabled-currencies'
				);

				await logInAsAdmin( page );
				await page.goto( MC_SETTINGS_PATH );
				await expect(
					enabledCurrencyRow( page, MANAGED_CODE )
				).toHaveCount( 0 );

				// Add through the modal's real controls, exercising the search
				// filter the merchant would use.
				const dialog = await openAddCurrenciesModal( page );
				await dialog
					.getByRole( 'searchbox', { name: 'Search currencies' } )
					.fill( MANAGED_CODE );
				const chfCheckbox = modalCurrencyCheckbox(
					dialog,
					MANAGED_CODE
				);
				await expect( chfCheckbox ).not.toBeChecked();
				await chfCheckbox.check();
				await dialog
					.getByRole( 'button', { name: UPDATE_SELECTED_BUTTON } )
					.click();
				await expect( dialog ).toHaveCount( 0 );
				await expect(
					page.getByText( CURRENCIES_UPDATED_NOTICE ).first()
				).toBeVisible();

				// Exactly one update request carried the whole transition.
				expect( currencyWrites() ).toBe( 1 );

				// Authoritative echo: the enabled set is the untouched
				// baseline plus CHF, and the enabled entry carries a usable
				// positive rate.
				const after = await getStoreCurrencies( adminApi );
				expect( sortedCodes( after.enabled ) ).toEqual(
					[ ...baselineCodes, MANAGED_CODE ].toSorted()
				);
				expect( after.enabled[ MANAGED_CODE ].rate ).toBeGreaterThan(
					0
				);

				// The merchant-visible state agrees after a full reload.
				await page.reload();
				await expect(
					enabledCurrencyRow( page, MANAGED_CODE )
				).toHaveCount( 1 );

				// Restore the snapshot and verify: the baseline enabled set
				// returns in its original order, and CHF's per-currency
				// settings are exactly what they were before the add/restore
				// cycle.
				await setEnabledCurrencies(
					adminApi,
					storeOrderCodes( before.enabled )
				);
				expect(
					await getCurrencySettingsEcho( adminApi, MANAGED_CODE ),
					'CHF per-currency settings must survive the add/restore cycle'
				).toBe( chfSettingsBefore );
			}
		);
	}
);

test(
	'A merchant can remove an enabled non-default currency and it ceases to be enabled',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.removeCurrency,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page } ) => {
		// FORCED PREMISE: as in the add row — the run injects CHF's automatic
		// rate into the provider rate cache and byte-restores it afterwards,
		// so this proves native's merchant removal path GIVEN a catalog that
		// offers CHF, not that the provider offers CHF.
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { CHF: CATALOG_RATES.CHF } },
			async () => {
				const before = await getStoreCurrencies( adminApi );
				const baselineCodes = sortedCodes( before.enabled );
				const chfSettingsBefore = await getCurrencySettingsEcho(
					adminApi,
					MANAGED_CODE
				);

				expect(
					before.available[ MANAGED_CODE ],
					`${ MANAGED_CODE } must be an available currency on this store`
				).toBeTruthy();
				expect(
					MANAGED_CODE,
					'the removal target must not be the store default'
				).not.toBe( before.default.code );
				expect(
					baselineCodes,
					`${ MANAGED_CODE } must start disabled; a prior run leaked state`
				).not.toContain( MANAGED_CODE );

				// Independent seed: this test creates the CHF it removes, so
				// focused execution cannot fail for another case's leftovers.
				await setEnabledCurrencies( adminApi, [
					...storeOrderCodes( before.enabled ),
					MANAGED_CODE,
				] );

				const currencyWrites = trackRouteWrites(
					page,
					'/wc/v3/payments/multi-currency/update-enabled-currencies'
				);

				await logInAsAdmin( page );
				await page.goto( MC_SETTINGS_PATH );

				const chfRow = enabledCurrencyRow( page, MANAGED_CODE );
				await expect( chfRow ).toHaveCount( 1 );
				// Default protection: the default currency's row offers no
				// removal action, so the merchant cannot be led into an
				// invalid state.
				await expect(
					enabledCurrencyRow( page, before.default.code ).getByRole(
						'button',
						{ name: /^Remove / }
					)
				).toHaveCount( 0 );

				await chfRow
					.getByRole( 'button', {
						name: /^Remove .* as an enabled currency$/,
					} )
					.click();
				await expect(
					page.getByText( CURRENCIES_UPDATED_NOTICE ).first()
				).toBeVisible();
				await expect( chfRow ).toHaveCount( 0 );
				expect( currencyWrites() ).toBe( 1 );

				// Authoritative echo: CHF left the enabled set and every
				// companion survived exactly once.
				const after = await getStoreCurrencies( adminApi );
				expect( sortedCodes( after.enabled ) ).toEqual( baselineCodes );

				// The removal persists across a full reload.
				await page.reload();
				await expect(
					enabledCurrencyRow( page, MANAGED_CODE )
				).toHaveCount( 0 );
				await expect(
					enabledCurrencyRow( page, before.default.code )
				).toHaveCount( 1 );

				// Verify restored state: the seed/remove cycle returned the
				// store to its snapshot, including CHF's per-currency settings
				// — the removal path deletes per-currency options, so echo
				// equality here proves the cleanup stayed bounded to what this
				// test created.
				expect(
					await getCurrencySettingsEcho( adminApi, MANAGED_CODE ),
					'CHF per-currency settings must survive the seed/remove cycle'
				).toBe( chfSettingsBefore );
			}
		);
	}
);

test(
	'A merchant can select multiple eligible currencies together in the enabled-currencies modal before submitting',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.multiSelect,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page } ) => {
		// FORCED PREMISE: a multi-select contract needs at least two
		// selectable currencies, and the standing store's catalog offers
		// none. The run injects CHF and CAD into the provider rate cache and
		// byte-restores it afterwards, so this proves the modal's multi-select
		// behavior GIVEN a catalog that offers them, not that the provider
		// offers them.
		await withWidenedCurrencyCatalog(
			{
				baseURL,
				rates: { CAD: CATALOG_RATES.CAD, CHF: CATALOG_RATES.CHF },
			},
			async () => {
				// Per DECISIONS.md 2026-08-08, the settings modal is the
				// native surface for the onboarding wizard's multi-select
				// capability. The contract is transient: selection only, no
				// submission, no writes.
				const before = await getStoreCurrencies( adminApi );
				const baselineCodes = sortedCodes( before.enabled );
				const settingsBefore = await getStoreSettingsEcho( adminApi );

				// Two deterministic, initially unselected, non-default
				// currencies.
				const selectionCodes = MULTI_SELECT_CANDIDATES.filter(
					( code ) =>
						code !== before.default.code &&
						Boolean( before.available[ code ] ) &&
						! baselineCodes.includes( code )
				).slice( 0, 2 );
				expect(
					selectionCodes,
					'the store must offer at least two selectable non-enabled currencies'
				).toHaveLength( 2 );

				const currencyWrites = trackRouteWrites(
					page,
					'/wc/v3/payments/multi-currency/update-enabled-currencies'
				);
				const settingsWrites = trackRouteWrites(
					page,
					'/wc/v3/payments/multi-currency/update-settings'
				);

				await logInAsAdmin( page );
				await page.goto( MC_SETTINGS_PATH );
				const dialog = await openAddCurrenciesModal( page );

				const firstCheckbox = modalCurrencyCheckbox(
					dialog,
					selectionCodes[ 0 ]
				);
				const secondCheckbox = modalCurrencyCheckbox(
					dialog,
					selectionCodes[ 1 ]
				);
				await expect( firstCheckbox ).not.toBeChecked();
				await expect( secondCheckbox ).not.toBeChecked();

				// Both selections hold simultaneously: checking the second
				// must not clear the first, which is the exact single-select
				// failure shape this contract exists to rule out.
				await firstCheckbox.check();
				await secondCheckbox.check();
				await expect( firstCheckbox ).toBeChecked();
				await expect( secondCheckbox ).toBeChecked();
				// The selection is actionable — the primary action accepts it
				// — but this contract stops before submission.
				await expect(
					dialog.getByRole( 'button', {
						name: UPDATE_SELECTED_BUTTON,
					} )
				).toBeEnabled();

				await dialog
					.getByRole( 'button', { name: 'Cancel', exact: true } )
					.click();
				await expect( dialog ).toHaveCount( 0 );

				// Zero-write proof: the whole interaction dispatched no
				// settings request, and the authoritative state is unchanged.
				expect( currencyWrites() ).toBe( 0 );
				expect( settingsWrites() ).toBe( 0 );
				const after = await getStoreCurrencies( adminApi );
				expect( sortedCodes( after.enabled ) ).toEqual( baselineCodes );
				expect( await getStoreSettingsEcho( adminApi ) ).toEqual(
					settingsBefore
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

				await logInAsAdmin( page );
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
	async ( { adminApi, page } ) => {
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

		await logInAsAdmin( page );
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
