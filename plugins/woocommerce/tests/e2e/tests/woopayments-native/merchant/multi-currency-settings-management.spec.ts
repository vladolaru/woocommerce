import type { Locator, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';
import { updateIfNeeded, resetValue } from '../../../utils/settings';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * Native multi-currency settings management (mc-settings-management-spec).
 *
 * Three provider-free merchant contracts against the Core-owned multi-currency
 * settings surface (wc-settings → Multi-currency, MultiCurrencySettingsPage +
 * multi-currency-settings React app) and the Core Features screen.
 *
 * Restoration discipline: every test snapshots the state it will touch via
 * the authoritative REST echoes before mutating, restores it through the same
 * routes afterwards, and verifies the restored echo equals the snapshot.
 *
 * Feature-toggle bound: Core owns the native runtime feature through its
 * standard Features setting. The disable and enable rows use the Core REST
 * settings resource only for reversible setup and restoration, then exercise
 * the merchant transition through the user-visible Features form.
 */

const SETUP_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:';

const CONTRACT_IDS = {
	pageLoad:
		'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency.spec.ts:43::Multi-currency › page load without any errors',
	disableFeature: `${ SETUP_CONTRACT_PREFIX }42::Multi-currency setup › can disable the multi-currency feature`,
	enableFeature: `${ SETUP_CONTRACT_PREFIX }46::Multi-currency setup › can enable the multi-currency feature`,
} as const;

const RUNTIME_STATUS_API = 'wc-native-payments-e2e/v1/status';
const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const CORE_MULTI_CURRENCY_FEATURE_PATH =
	'advanced/woocommerce_feature_multi_currency_enabled';
const MULTI_CURRENCY_API = 'wc/v3/payments/multi-currency';
const MC_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';
const FEATURES_SETTINGS_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=advanced&section=features';

// Copy authored by core (app.tsx / store-settings.tsx / settings-page.tsx).
const ADD_REMOVE_BUTTON = 'Add/remove currencies';
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

async function readPaymentsSettingsCompanions(
	restApi: ApiClient,
	description: string
): Promise< PaymentsSettingsCompanions > {
	const settings = (
		await restApi.get< Record< string, unknown > >( PAYMENTS_SETTINGS_API )
	).data;
	expect( settings.is_wcpay_enabled, description ).toBe( true );
	return {
		enabled_payment_method_ids: settings.enabled_payment_method_ids,
		is_manual_capture_enabled: settings.is_manual_capture_enabled,
		is_debug_log_enabled: settings.is_debug_log_enabled,
		is_payment_request_enabled: settings.is_payment_request_enabled,
	};
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
	restApi: ApiClient
): Promise< StoreCurrencies > {
	return (
		await restApi.get< StoreCurrencies >(
			`${ MULTI_CURRENCY_API }/currencies`
		)
	).data;
}

function sortedCodes( record: Record< string, unknown > ): string[] {
	return Object.keys( record ).toSorted();
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
// by owner rather than being a blanket console filter: every same-origin failure
// that is not another plugin's asset still counts, and uncaught exceptions
// always count regardless of source.
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
	async ( { restApi, page, baseURL } ) => {
		const storeBase = requireBaseUrl( baseURL );

		// Precondition guard: the connected settings variant only renders for
		// a connected account. A degraded store must fail here, not pass by
		// rendering the onboarding CTA around missing data.
		const runtimeStatus = (
			await restApi.get< Record< string, unknown > >( RUNTIME_STATUS_API )
		).data;
		expect(
			{
				account_connected: runtimeStatus.account_connected,
				gateway_enabled: runtimeStatus.gateway_enabled,
			},
			'the store must report a connected account and an enabled gateway'
		).toEqual( { account_connected: true, gateway_enabled: true } );

		// The authoritative truth this load must join to.
		const currencies = await getStoreCurrencies( restApi );
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
	async ( { restApi, page } ) => {
		const paymentsSettingsBefore = await readPaymentsSettingsCompanions(
			restApi,
			'Payments settings read before Core feature disable'
		);

		const featureState = await updateIfNeeded(
			CORE_MULTI_CURRENCY_FEATURE_PATH,
			'yes'
		);
		try {
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

			const disabled = (
				await restApi.get< { value: unknown } >(
					`wc/v3/settings/${ CORE_MULTI_CURRENCY_FEATURE_PATH }`
				)
			).data;
			expect( disabled.value ).toBe( 'no' );
			expect(
				await readPaymentsSettingsCompanions(
					restApi,
					'Payments settings read after Core feature disable'
				)
			).toEqual( paymentsSettingsBefore );
		} finally {
			await resetValue( CORE_MULTI_CURRENCY_FEATURE_PATH, featureState );
		}
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
	async ( { restApi, page } ) => {
		const featureState = await updateIfNeeded(
			CORE_MULTI_CURRENCY_FEATURE_PATH,
			'no'
		);
		try {
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

			const enabled = (
				await restApi.get< { value: unknown } >(
					`wc/v3/settings/${ CORE_MULTI_CURRENCY_FEATURE_PATH }`
				)
			).data;
			expect( enabled.value ).toBe( 'yes' );

			await page.goto( MC_SETTINGS_PATH );
			await expectMultiCurrencySurfaceLoaded( page );
		} finally {
			await resetValue( CORE_MULTI_CURRENCY_FEATURE_PATH, featureState );
		}
	}
);
