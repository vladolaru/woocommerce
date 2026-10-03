import type { Locator, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';
import { random } from '../../../utils/helpers';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * Native multi-currency pricing settings (mc-pricing-configuration-smoke).
 *
 * Two provider-free merchant contracts: a CHF manual exchange rate with
 * locale-specific storefront formatting and a JPY zero-decimal amount. Each
 * runs through the real currency-settings modal, authoritative REST echo, and
 * storefront text.
 *
 * CHF and JPY are enabled through the ordinary `update-enabled-currencies`
 * route: the connected account (the CI fixture and the standing test account)
 * lists both as supported customer currencies, which is what WooPayments
 * 11.1.0 also requires before it accepts them.
 *
 * The run currencies (CHF, JPY) are deliberately not EUR: EUR carries the
 * readonly fixture's seeded 0.8 manual rate (`envs/woopayments-native/seed-readonly.sh`),
 * which the retained `switcher:830` smoke reads directly from the live store.
 * The PHPUnit/Jest EUR-conversion owners (MultiCurrencyFrontendPricesControllerTest,
 * MultiCurrencyPriceCalculatorTest, MultiCurrencyLocalizationServiceTest) prove the
 * same conversion mechanism against their own isolated fixtures and never read this
 * option. No test here reads, writes, or removes it either. Every rate below is a
 * run-set manual rate on a run-added currency, so no fixture value is duplicated or
 * disturbed.
 */

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:';
const CONTRACT_MANUAL_RATE = `${ CONTRACT_PREFIX }90::Multi-currency setup › Currency settings › can change the currency rate manually`;
const CONTRACT_JPY_DECIMALS = `${ CONTRACT_PREFIX }207::Multi-currency setup › Currency decimal points › the decimal points for JPY are displayed correctly`;

const MULTI_CURRENCY_API = 'wc/v3/payments/multi-currency';
const SETTINGS_PAGE_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';

// One deterministic base price shared by every row. 1234.56 exercises the
// thousands separator, both fractional digits, and — through the run-set
// manual rates below — lands every converted amount on an exact cent, so
// each storefront assertion is a full formatted-text match instead of the
// original suite's locale-blind parseFloat.
//
// MultiCurrencyFrontendPricesControllerTest and MultiCurrencyPriceCalculatorTest
// already prove the same rate-and-charm conversion mechanism against their own
// isolated fixtures (neither reads the readonly fixture's seeded EUR rate; only
// the retained switcher:830 smoke does); every rate set here is a different,
// run-set manual rate on a run-added currency, so no fixture value is
// duplicated.
const PRODUCT_PRICE = '1234.56';
const USD_PRICE_TEXT = /\$1,234\.56(?!\d)/;

// CHF's bundled locale data carries an ASCII apostrophe as its thousands
// separator, but WordPress texturizes rendered content, so the storefront
// shows the typographic U+2019 instead. The CHF expectations below assert the
// character the shopper actually sees rather than the one the locale table
// stores — verified against this store's rendered product markup
// (CHF&nbsp;1&#8217;543.20).
const CHF_THOUSANDS_SEPARATOR = '’';

interface SingleCurrencySettings {
	exchange_rate_type: string;
	manual_rate: unknown;
	price_rounding: unknown;
	price_charm: unknown;
}

/**
 * First visible occurrence of a price text; storefront surfaces can render
 * hidden duplicate price nodes.
 */
function visibleText( page: Page, pattern: RegExp ): Locator {
	return page.getByText( pattern ).filter( { visible: true } ).first();
}

/**
 * Snapshot of the pre-test multi-currency state this suite must put back.
 */
interface MultiCurrencySnapshot {
	enabledCodes: string[];
	currencyName: string;
	virginSettings: SingleCurrencySettings;
}

/**
 * Read the pre-test state and enforce the run-added-currency preconditions.
 *
 * The package's restore contract is byte-for-byte: the run-added currency is
 * removed at the end, and core deletes that currency's four per-currency
 * options on removal (`remove_removed_currency_settings`). That restore is
 * only byte-exact when the currency starts both disabled and option-free, so
 * a drifted standing store must fail here — loudly, before any write — rather
 * than be silently normalized.
 */
async function snapshotAndGuard(
	restApi: ApiClient,
	currencyCode: string
): Promise< MultiCurrencySnapshot > {
	const state = (
		await restApi.get< {
			default: { code?: string };
			enabled: Record< string, unknown >;
			available: Record< string, { name?: string } >;
		} >( `${ MULTI_CURRENCY_API }/currencies` )
	).data;
	expect(
		state.default.code,
		'The pricing rows assume a USD store default; every expected amount is derived from it.'
	).toBe( 'USD' );

	const enabledCodes = Object.keys( state.enabled ?? {} );
	expect(
		enabledCodes,
		`${ currencyCode } must start disabled: this suite owns it as a run-added currency and removes it at the end. An already-enabled ${ currencyCode } means prior-run leakage that needs manual attention, not silent normalization.`
	).not.toContain( currencyCode );

	const available = state.available ?? {};
	expect( Object.keys( available ) ).toContain( currencyCode );
	const currencyName = available[ currencyCode ].name ?? '';
	expect( currencyName ).not.toBe( '' );

	const virginSettings = (
		await restApi.get< SingleCurrencySettings >(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		)
	).data;
	expect(
		virginSettings,
		`${ currencyCode } per-currency options must start absent so the end-of-test removal restores them byte-for-byte.`
	).toEqual( {
		exchange_rate_type: 'automatic',
		manual_rate: null,
		price_rounding: null,
		price_charm: null,
	} );

	return { enabledCodes, currencyName, virginSettings };
}

async function createRunProduct(
	restApi: ApiClient,
	runId: string
): Promise< { id: number; slug: string } > {
	const slug = `woopayments-mc-pricing-${ runId }`;
	const created = (
		await restApi.post< { id: number } >( 'wc/v3/products', {
			name: `WooPayments MC pricing ${ runId }`,
			slug,
			type: 'simple',
			virtual: true,
			regular_price: PRODUCT_PRICE,
			status: 'publish',
		} )
	).data;
	return { id: created.id, slug };
}

async function enableRunCurrency(
	restApi: ApiClient,
	snapshot: MultiCurrencySnapshot,
	currencyCode: string
): Promise< void > {
	// The route answers HTTP 200 with the unchanged list when the payload is
	// not a non-empty array, so assert the returned state instead of trusting
	// a green response.
	const updated = (
		await restApi.post< { enabled: Record< string, unknown > } >(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ enabled: [ ...snapshot.enabledCodes, currencyCode ] }
		)
	).data;
	expect( Object.keys( updated.enabled ?? {} ) ).toContain( currencyCode );
}

interface ModalConfiguration {
	manualRate: string;
	priceRoundingValue: string;
	priceCharmValue: string;
}

/**
 * Drive the currency-settings modal on the native multi-currency settings
 * screen: open the row's Manage dialog, switch to a manual rate, pick the
 * rounding and charm options, and save. Returns the dialog locator so rows
 * with modal-rendering assertions can interleave them before saving.
 */
async function openCurrencySettingsModal(
	page: Page,
	currencyName: string
): Promise< { dialog: Locator; row: Locator } > {
	await page.goto( SETTINGS_PAGE_PATH );
	const row = page
		.getByRole( 'row' )
		.filter( { hasText: currencyName } )
		.first();
	await row
		.getByRole( 'button', {
			name: `Manage ${ currencyName } settings`,
			exact: true,
		} )
		.click();
	const dialog = page.getByRole( 'dialog', {
		name: `Manage ${ currencyName } settings`,
		exact: true,
	} );
	await expect( dialog ).toBeVisible();
	return { dialog, row };
}

async function saveModalConfiguration(
	dialog: Locator,
	configuration: ModalConfiguration
): Promise< void > {
	await dialog.getByRole( 'radio', { name: 'Manual', exact: true } ).check();
	await dialog
		.getByRole( 'textbox', { name: 'Manual rate' } )
		.fill( configuration.manualRate );
	await dialog
		.getByRole( 'combobox', { name: 'Price rounding' } )
		.selectOption( { value: configuration.priceRoundingValue } );
	await dialog
		.getByRole( 'combobox', { name: 'Charm pricing' } )
		.selectOption( { value: configuration.priceCharmValue } );
	await dialog
		.getByRole( 'button', { name: 'Save changes', exact: true } )
		.click();
	// The modal closes only after the settings POST succeeds; on failure it
	// stays open behind an error notice. Closing is therefore the UI-side
	// persistence signal, ahead of the authoritative REST echo below.
	await expect( dialog ).toHaveCount( 0 );
}

/**
 * Authoritative persistence proof: the same REST route the modal writes
 * through echoes the stored option values. Values are compared numerically —
 * the route stores floats via `update_option`, which round-trips them as
 * their canonical string forms.
 */
async function expectPersistedSettings(
	restApi: ApiClient,
	currencyCode: string,
	expected: { manualRate: number; priceRounding: number; priceCharm: number }
): Promise< void > {
	const echo = (
		await restApi.get< SingleCurrencySettings >(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		)
	).data;
	expect( echo.exchange_rate_type ).toBe( 'manual' );
	expect( Number( echo.manual_rate ) ).toBe( expected.manualRate );
	expect( Number( echo.price_rounding ) ).toBe( expected.priceRounding );
	expect( Number( echo.price_charm ) ).toBe( expected.priceCharm );
}

/**
 * One shopper-visible read in a disposable session: prove the USD base price
 * first, then the explicitly selected run currency's fully formatted price,
 * with the USD text gone so a partially re-rendered page cannot pass.
 */
async function expectShopperPrice(
	page: Page,
	productSlug: string,
	currencyCode: string,
	expectedPrice: RegExp
): Promise< void > {
	// Clearing cookies drops the wp-admin login and the WooCommerce session,
	// so the storefront read happens in a fresh anonymous shopper session.
	await page.context().clearCookies();

	await page.goto( `product/${ productSlug }/?currency=USD` );
	await expect( visibleText( page, USD_PRICE_TEXT ) ).toBeVisible();

	await page.goto( `product/${ productSlug }/?currency=${ currencyCode }` );
	await expect( visibleText( page, expectedPrice ) ).toBeVisible();
	await expect(
		page.getByText( USD_PRICE_TEXT ).filter( { visible: true } )
	).toHaveCount( 0 );
}

/**
 * Restore the snapshot state and verify the restore through fresh reads:
 * removing the run-added currency also makes core delete its four
 * per-currency options, so the post-restore echo must equal the virgin
 * pre-test echo byte for byte.
 */
async function restoreAndVerify(
	restApi: ApiClient,
	snapshot: MultiCurrencySnapshot,
	currencyCode: string,
	productId: number
): Promise< void > {
	const restored = (
		await restApi.post< { enabled: Record< string, unknown > } >(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ enabled: snapshot.enabledCodes }
		)
	).data;
	expect( Object.keys( restored.enabled ?? {} ) ).toEqual(
		snapshot.enabledCodes
	);

	// Cold re-read: the restore must hold on a fresh request, not only in the
	// mutating call's own response.
	const reread = (
		await restApi.get< { enabled: Record< string, unknown > } >(
			`${ MULTI_CURRENCY_API }/currencies`
		)
	).data;
	expect( Object.keys( reread.enabled ?? {} ) ).toEqual(
		snapshot.enabledCodes
	);

	const settingsAfter = (
		await restApi.get< SingleCurrencySettings >(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		)
	).data;
	expect( settingsAfter ).toEqual( snapshot.virginSettings );

	await restApi.delete( `wc/v3/products/${ productId }`, { force: true } );
}

/**
 * Best-effort cleanup that always runs, so a case that fails before
 * `restoreAndVerify` cannot leave CHF or JPY enabled for the next spec in the
 * run. It asserts nothing and never masks the test's own error; after a
 * successful restore it rewrites the same enabled set.
 */
async function removeRunState(
	restApi: ApiClient,
	snapshot: MultiCurrencySnapshot,
	productId: number
): Promise< void > {
	await restApi
		.post( `${ MULTI_CURRENCY_API }/update-enabled-currencies`, {
			enabled: snapshot.enabledCodes,
		} )
		.catch( () => undefined );
	await restApi
		.delete( `wc/v3/products/${ productId }`, { force: true } )
		.catch( () => undefined );
}

test(
	'A merchant-supplied manual exchange rate deterministically controls shopper product pricing in that currency',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_MANUAL_RATE,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, page } ) => {
		const currencyCode = 'CHF';
		const runId = random();
		const snapshot = await snapshotAndGuard( restApi, currencyCode );
		const product = await createRunProduct( restApi, runId );
		await enableRunCurrency( restApi, snapshot, currencyCode );
		try {
			const { dialog, row } = await openCurrencySettingsModal(
				page,
				snapshot.currencyName
			);
			// Rate 1.25 with rounding and charm pinned off: the calculator's
			// zero-rounding path rounds the raw conversion to the currency's two
			// decimals, so 1234.56 × 1.25 = 1543.20 exactly.
			await saveModalConfiguration( dialog, {
				manualRate: '1.25',
				priceRoundingValue: '0',
				priceCharmValue: '0.00',
			} );
			// The enabled-currencies table reflects the saved manual rate.
			await expect( row ).toContainText( '1.25' );

			await expectPersistedSettings( restApi, currencyCode, {
				manualRate: 1.25,
				priceRounding: 0,
				priceCharm: 0,
			} );

			// CHF pins its own formatting half: 'CHF' code as the symbol,
			// left_space position, apostrophe thousands separator, two decimals.
			await expectShopperPrice(
				page,
				product.slug,
				currencyCode,
				new RegExp(
					`CHF\\s1${ CHF_THOUSANDS_SEPARATOR }543\\.20(?!\\d)`
				)
			);

			await restoreAndVerify(
				restApi,
				snapshot,
				currencyCode,
				product.id
			);
		} finally {
			await removeRunState( restApi, snapshot, product.id );
		}
	}
);

test(
	'Zero-decimal currencies render shopper prices without fractional digits',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_JPY_DECIMALS,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, page } ) => {
		const currencyCode = 'JPY';
		const runId = random();
		const snapshot = await snapshotAndGuard( restApi, currencyCode );
		const product = await createRunProduct( restApi, runId );
		await enableRunCurrency( restApi, snapshot, currencyCode );
		try {
			const { dialog } = await openCurrencySettingsModal(
				page,
				snapshot.currencyName
			);

			// Modal half: a zero-decimal currency renders the whole-unit
			// rounding and charm option sets and the zero-decimal default
			// rounding — no fractional options anywhere.
			const roundingSelect = dialog.getByRole( 'combobox', {
				name: 'Price rounding',
			} );
			const charmSelect = dialog.getByRole( 'combobox', {
				name: 'Charm pricing',
			} );
			await expect( roundingSelect ).toHaveValue( '100' );
			await expect(
				roundingSelect.locator( 'option[value="500"]' )
			).toHaveCount( 1 );
			await expect(
				roundingSelect.locator( 'option[value="0.50"]' )
			).toHaveCount( 0 );
			await expect(
				charmSelect.locator( 'option[value="-1"]' )
			).toHaveCount( 1 );
			await expect(
				charmSelect.locator( 'option[value="-0.01"]' )
			).toHaveCount( 0 );

			// The zero-decimal modal offers no zero-rounding option — '1' is its
			// smallest increment — so the modal-driven amount is
			// ceil(1234.56 × 150.1) = ceil(185307.456) = 185308. The
			// round-to-zero-decimals calculation leaf (185307) is pinned at the
			// lower layer instead (MultiCurrencyPriceCalculator staged test).
			await saveModalConfiguration( dialog, {
				manualRate: '150.1',
				priceRoundingValue: '1',
				priceCharmValue: '0.00',
			} );

			await expectPersistedSettings( restApi, currencyCode, {
				manualRate: 150.1,
				priceRounding: 1,
				priceCharm: 0,
			} );

			// No fractional digits: the trailing guard rejects both another
			// digit and a decimal fraction after the yen amount.
			await expectShopperPrice(
				page,
				product.slug,
				currencyCode,
				/¥185,308(?!\.?\d)/
			);

			await restoreAndVerify(
				restApi,
				snapshot,
				currencyCode,
				product.id
			);
		} finally {
			await removeRunState( restApi, snapshot, product.id );
		}
	}
);
