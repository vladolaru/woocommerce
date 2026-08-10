import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';
import { withWidenedCurrencyCatalog } from '../../../utils/woopayments-native/multi-currency-catalog';

/**
 * Native multi-currency pricing settings (mc-pricing-configuration-smoke).
 *
 * Five provider-free merchant contracts: a manual exchange rate, charm
 * pricing, rounding precision, and the two-decimal / zero-decimal rendering
 * pair — each driven through the real currency-settings modal, joined to the
 * authoritative REST echo, and read back on the storefront as fully formatted
 * text.
 *
 * FORCED PREMISE — read before trusting any of these five results. The
 * standing store's provider rate cache is empty, so its available-currency
 * catalog is one code wide: USD plus EUR, the latter only because it is
 * enabled with a manual rate. Every route that could widen it validates
 * against that catalog first, so none of these rows is reachable as the store
 * stands. Each test therefore runs inside
 * utils/woopayments-native/multi-currency-catalog.ts, which snapshots the raw
 * rate-cache option, forces its run currency into it, and byte-restores it
 * with a verified read afterwards. What each test proves is native's pricing
 * behavior GIVEN a catalog that offers that currency; none of them proves the
 * provider offers it, because the run supplies that premise itself. The
 * premise is narrow rather than invented: the connected account already
 * reports CHF, GBP, and JPY among its supported customer currencies, and only
 * the FX rate payload is substituted.
 *
 * The run currencies (CHF, GBP, JPY) are deliberately not EUR: EUR carries the
 * documented 0.80 manual rate that the frozen shopper/multi-currency.spec.ts
 * depends on, and no test here reads, writes, or removes it. Every rate below
 * is a run-set manual rate on a run-added currency, so no frozen assertion is
 * duplicated or disturbed.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/merchant/multi-currency-setup.spec.ts:';
const CONTRACT_MANUAL_RATE = `${ CONTRACT_PREFIX }90::Multi-currency setup › Currency settings › can change the currency rate manually`;
const CONTRACT_CHARM_PRICE = `${ CONTRACT_PREFIX }122::Multi-currency setup › Currency settings › can change the charm price manually`;
const CONTRACT_ROUNDING = `${ CONTRACT_PREFIX }159::Multi-currency setup › Currency settings › can change the rounding precision manually`;
const CONTRACT_GBP_DECIMALS = `${ CONTRACT_PREFIX }207::Multi-currency setup › Currency decimal points › the decimal points for GBP are displayed correctly`;
const CONTRACT_JPY_DECIMALS = `${ CONTRACT_PREFIX }207::Multi-currency setup › Currency decimal points › the decimal points for JPY are displayed correctly`;

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const SETTINGS_PAGE_PATH =
	'/wp-admin/admin.php?page=wc-settings&tab=wcpay_multi_currency';

// One deterministic base price shared by every row. 1234.56 exercises the
// thousands separator, both fractional digits, and — through the run-set
// manual rates below — lands every converted amount on an exact cent, so
// each storefront assertion is a full formatted-text match instead of the
// original suite's locale-blind parseFloat.
//
// The frozen shopper family smoke (shopper/multi-currency.spec.ts) already
// proves the documented-rate EUR 0.80 conversion; every rate set here is a
// different, run-set manual rate on a run-added currency, so no frozen
// assertion is duplicated.
const PRODUCT_PRICE = '1234.56';
const USD_PRICE_TEXT = /\$1,234\.56(?!\d)/;

// CHF's bundled locale data carries an ASCII apostrophe as its thousands
// separator, but WordPress texturizes rendered content, so the storefront
// shows the typographic U+2019 instead. The CHF expectations below assert the
// character the shopper actually sees rather than the one the locale table
// stores — verified against this store's rendered product markup
// (CHF&nbsp;1&#8217;543.20).
const CHF_THOUSANDS_SEPARATOR = '’';

// Automatic catalog rates the run forces into the provider rate cache so its
// run currency exists at all. No assertion depends on these values: every test
// immediately switches its currency to a manual rate through the merchant
// modal, and the shopper amounts below are derived from those manual rates.
const CATALOG_RATES = {
	CHF: 0.9,
	GBP: 0.79,
	JPY: 151,
} as const;

interface SingleCurrencySettings {
	exchange_rate_type: string;
	manual_rate: unknown;
	price_rounding: unknown;
	price_charm: unknown;
}

async function readJson(
	response: Awaited< ReturnType< APIRequestContext[ 'get' ] > >,
	description: string
): Promise< Record< string, unknown > > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Record< string, unknown >;
}

async function logInAsAdmin( page: Page ): Promise< void > {
	await page.goto( 'wp-login.php' );
	await page.getByLabel( 'Username or Email Address' ).fill( ADMIN_USERNAME );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( ADMIN_PASSWORD );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.waitForURL( '**/wp-admin/**' );
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
	adminApi: APIRequestContext,
	currencyCode: string
): Promise< MultiCurrencySnapshot > {
	const state = await readJson(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state read'
	);
	const defaultCurrency = state.default as { code?: string };
	expect(
		defaultCurrency.code,
		'The pricing rows assume a USD store default; every expected amount is derived from it.'
	).toBe( 'USD' );

	const enabledCodes = Object.keys(
		( state.enabled ?? {} ) as Record< string, unknown >
	);
	expect(
		enabledCodes,
		`${ currencyCode } must start disabled: this suite owns it as a run-added currency and removes it at the end. An already-enabled ${ currencyCode } means prior-run leakage that needs manual attention, not silent normalization.`
	).not.toContain( currencyCode );

	const available = ( state.available ?? {} ) as Record<
		string,
		{ name?: string }
	>;
	expect( Object.keys( available ) ).toContain( currencyCode );
	const currencyName = available[ currencyCode ].name ?? '';
	expect( currencyName ).not.toBe( '' );

	const virginSettings = ( await readJson(
		await adminApi.get(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		),
		`${ currencyCode } settings read`
	) ) as unknown as SingleCurrencySettings;
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
	adminApi: APIRequestContext,
	runId: string
): Promise< { id: number; slug: string } > {
	const slug = `woopayments-mc-pricing-${ runId }`;
	const created = await readJson(
		await adminApi.post( '/wp-json/wc/v3/products', {
			data: {
				name: `WooPayments MC pricing ${ runId }`,
				slug,
				type: 'simple',
				virtual: true,
				regular_price: PRODUCT_PRICE,
				status: 'publish',
			},
		} ),
		'Run product creation'
	);
	return { id: created.id as number, slug };
}

async function enableRunCurrency(
	adminApi: APIRequestContext,
	snapshot: MultiCurrencySnapshot,
	currencyCode: string
): Promise< void > {
	// The route answers HTTP 200 with the unchanged list when the payload is
	// not a non-empty array, so assert the returned state instead of trusting
	// a green response.
	const updated = await readJson(
		await adminApi.post(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ data: { enabled: [ ...snapshot.enabledCodes, currencyCode ] } }
		),
		`${ currencyCode } enable`
	);
	expect(
		Object.keys( ( updated.enabled ?? {} ) as Record< string, unknown > )
	).toContain( currencyCode );
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
	adminApi: APIRequestContext,
	currencyCode: string,
	expected: { manualRate: number; priceRounding: number; priceCharm: number }
): Promise< void > {
	const echo = ( await readJson(
		await adminApi.get(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		),
		`${ currencyCode } settings echo`
	) ) as unknown as SingleCurrencySettings;
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
	adminApi: APIRequestContext,
	snapshot: MultiCurrencySnapshot,
	currencyCode: string,
	productId: number
): Promise< void > {
	const restored = await readJson(
		await adminApi.post(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ data: { enabled: snapshot.enabledCodes } }
		),
		'Enabled-currencies restore'
	);
	expect(
		Object.keys( ( restored.enabled ?? {} ) as Record< string, unknown > )
	).toEqual( snapshot.enabledCodes );

	// Cold re-read: the restore must hold on a fresh request, not only in the
	// mutating call's own response.
	const reread = await readJson(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state re-read'
	);
	expect(
		Object.keys( ( reread.enabled ?? {} ) as Record< string, unknown > )
	).toEqual( snapshot.enabledCodes );

	const settingsAfter = ( await readJson(
		await adminApi.get(
			`${ MULTI_CURRENCY_API }/currencies/${ currencyCode }`
		),
		`${ currencyCode } settings after restore`
	) ) as unknown as SingleCurrencySettings;
	expect( settingsAfter ).toEqual( snapshot.virginSettings );

	const deletion = await adminApi.delete(
		`/wp-json/wc/v3/products/${ productId }`,
		{ data: { force: true } }
	);
	if ( ! deletion.ok() ) {
		throw new Error(
			`Run product cleanup failed: HTTP ${ deletion.status() }.`
		);
	}
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
	async ( { adminApi, baseURL, page, runId } ) => {
		const currencyCode = 'CHF';
		// FORCED PREMISE (see the file header): CHF only exists in this
		// store's catalog because the run injects its automatic rate into the
		// provider rate cache and byte-restores it afterwards.
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { CHF: CATALOG_RATES.CHF } },
			async () => {
				const snapshot = await snapshotAndGuard(
					adminApi,
					currencyCode
				);
				const product = await createRunProduct( adminApi, runId );
				await enableRunCurrency( adminApi, snapshot, currencyCode );

				await logInAsAdmin( page );
				const { dialog, row } = await openCurrencySettingsModal(
					page,
					snapshot.currencyName
				);
				// Rate 1.25 with rounding and charm pinned off: the
				// calculator's zero-rounding path rounds the raw conversion to
				// the currency's two decimals, so 1234.56 × 1.25 = 1543.20
				// exactly.
				await saveModalConfiguration( dialog, {
					manualRate: '1.25',
					priceRoundingValue: '0',
					priceCharmValue: '0.00',
				} );
				// The enabled-currencies table reflects the saved manual rate.
				await expect( row ).toContainText( '1.25' );

				await expectPersistedSettings( adminApi, currencyCode, {
					manualRate: 1.25,
					priceRounding: 0,
					priceCharm: 0,
				} );

				// CHF pins its own formatting half: 'CHF' code as the symbol,
				// left_space position, apostrophe thousands separator, two
				// decimals.
				await expectShopperPrice(
					page,
					product.slug,
					currencyCode,
					new RegExp(
						`CHF\\s1${ CHF_THOUSANDS_SEPARATOR }543\\.20(?!\\d)`
					)
				);

				await restoreAndVerify(
					adminApi,
					snapshot,
					currencyCode,
					product.id
				);
			}
		);
	}
);

test(
	'Configured charm pricing is applied after conversion to the shopper-visible amount',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_CHARM_PRICE,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const currencyCode = 'CHF';
		// FORCED PREMISE (see the file header).
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { CHF: CATALOG_RATES.CHF } },
			async () => {
				const snapshot = await snapshotAndGuard(
					adminApi,
					currencyCode
				);
				const product = await createRunProduct( adminApi, runId );
				await enableRunCurrency( adminApi, snapshot, currencyCode );

				await logInAsAdmin( page );
				const { dialog, row } = await openCurrencySettingsModal(
					page,
					snapshot.currencyName
				);
				// Manual rate 1.00 isolates the charm: the converted amount
				// equals the base price, so the only difference the shopper
				// can see is the charm applied after conversion:
				// 1234.56 − 0.01 = 1234.55.
				await saveModalConfiguration( dialog, {
					manualRate: '1.00',
					priceRoundingValue: '0',
					priceCharmValue: '-0.01',
				} );
				// The enabled-currencies table's rate cell reflects the saved
				// rate.
				await expect( row.getByRole( 'cell' ).nth( 1 ) ).toHaveText(
					'1'
				);

				await expectPersistedSettings( adminApi, currencyCode, {
					manualRate: 1,
					priceRounding: 0,
					priceCharm: -0.01,
				} );

				await expectShopperPrice(
					page,
					product.slug,
					currencyCode,
					new RegExp(
						`CHF\\s1${ CHF_THOUSANDS_SEPARATOR }234\\.55(?!\\d)`
					)
				);

				await restoreAndVerify(
					adminApi,
					snapshot,
					currencyCode,
					product.id
				);
			}
		);
	}
);

test(
	'Configured currency rounding produces the documented shopper price in the selected currency',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_ROUNDING,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const currencyCode = 'CHF';
		// FORCED PREMISE (see the file header).
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { CHF: CATALOG_RATES.CHF } },
			async () => {
				const snapshot = await snapshotAndGuard(
					adminApi,
					currencyCode
				);
				const product = await createRunProduct( adminApi, runId );
				await enableRunCurrency( adminApi, snapshot, currencyCode );

				await logInAsAdmin( page );
				const { dialog, row } = await openCurrencySettingsModal(
					page,
					snapshot.currencyName
				);
				// 1234.56 × 1.20 = 1481.472; the 0.50 increment ceils it
				// upward to 1481.50 — an amount only the ceiling rule produces
				// (plain rounding would land on 1481.47).
				//
				// Deliberately no modal-reopen readback here: the rounding
				// deferral packet records a predicted 0.5-versus-0.50 semantic
				// reload mismatch as a future bounded RED candidate, and this
				// smoke must neither trigger nor paper over it. Persistence is
				// proven by the REST echo.
				await saveModalConfiguration( dialog, {
					manualRate: '1.20',
					priceRoundingValue: '0.50',
					priceCharmValue: '0.00',
				} );
				// The enabled-currencies table's rate cell reflects the saved
				// rate.
				await expect( row.getByRole( 'cell' ).nth( 1 ) ).toHaveText(
					'1.2'
				);

				await expectPersistedSettings( adminApi, currencyCode, {
					manualRate: 1.2,
					priceRounding: 0.5,
					priceCharm: 0,
				} );

				await expectShopperPrice(
					page,
					product.slug,
					currencyCode,
					new RegExp(
						`CHF\\s1${ CHF_THOUSANDS_SEPARATOR }481\\.50(?!\\d)`
					)
				);

				await restoreAndVerify(
					adminApi,
					snapshot,
					currencyCode,
					product.id
				);
			}
		);
	}
);

test(
	'Two-decimal currencies render shopper prices with exactly two fractional digits',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_GBP_DECIMALS,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const currencyCode = 'GBP';
		// FORCED PREMISE (see the file header).
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { GBP: CATALOG_RATES.GBP } },
			async () => {
				const snapshot = await snapshotAndGuard(
					adminApi,
					currencyCode
				);
				const product = await createRunProduct( adminApi, runId );
				await enableRunCurrency( adminApi, snapshot, currencyCode );

				await logInAsAdmin( page );
				const { dialog } = await openCurrencySettingsModal(
					page,
					snapshot.currencyName
				);

				// Modal half: a two-decimal currency renders the decimal-class
				// controls — the fractional rounding and charm option sets,
				// and the decimal default rounding — not the zero-decimal
				// ones.
				const roundingSelect = dialog.getByRole( 'combobox', {
					name: 'Price rounding',
				} );
				const charmSelect = dialog.getByRole( 'combobox', {
					name: 'Charm pricing',
				} );
				await expect( roundingSelect ).toHaveValue( '1.00' );
				await expect(
					roundingSelect.locator( 'option[value="0.50"]' )
				).toHaveCount( 1 );
				await expect(
					roundingSelect.locator( 'option[value="500"]' )
				).toHaveCount( 0 );
				await expect(
					charmSelect.locator( 'option[value="-0.01"]' )
				).toHaveCount( 1 );
				await expect(
					charmSelect.locator( 'option[value="-1"]' )
				).toHaveCount( 0 );

				// Manual rate 0.80 on GBP: 1234.56 × 0.80 = 987.648, rounded
				// to the currency's two decimals → 987.65. The frozen family
				// smoke's EUR 0.80 documented-rate conversion is untouched:
				// this is a run-set manual rate on a different, run-added
				// currency, asserted for its decimal rendering rather than for
				// any documented-rate claim.
				await saveModalConfiguration( dialog, {
					manualRate: '0.80',
					priceRoundingValue: '0',
					priceCharmValue: '0.00',
				} );

				await expectPersistedSettings( adminApi, currencyCode, {
					manualRate: 0.8,
					priceRounding: 0,
					priceCharm: 0,
				} );

				// Exactly two fractional digits: the trailing guard rejects a
				// third digit, so a 987.648 rendering cannot match.
				await expectShopperPrice(
					page,
					product.slug,
					currencyCode,
					/£987\.65(?!\d)/
				);

				await restoreAndVerify(
					adminApi,
					snapshot,
					currencyCode,
					product.id
				);
			}
		);
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
	async ( { adminApi, baseURL, page, runId } ) => {
		const currencyCode = 'JPY';
		// FORCED PREMISE (see the file header). Without it this row is not
		// merely weak but unreachable: a zero-decimal currency cannot be
		// enabled on this store at all, so the zero-decimal rendering
		// contract would have no settings- or storefront-layer expression.
		await withWidenedCurrencyCatalog(
			{ baseURL, rates: { JPY: CATALOG_RATES.JPY } },
			async () => {
				const snapshot = await snapshotAndGuard(
					adminApi,
					currencyCode
				);
				const product = await createRunProduct( adminApi, runId );
				await enableRunCurrency( adminApi, snapshot, currencyCode );

				await logInAsAdmin( page );
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

				// The zero-decimal modal offers no zero-rounding option — '1'
				// is its smallest increment — so the modal-driven amount is
				// ceil(1234.56 × 150.1) = ceil(185307.456) = 185308. The
				// round-to-zero-decimals calculation leaf (185307) is pinned
				// at the lower layer instead (MultiCurrencyPriceCalculator
				// staged test).
				await saveModalConfiguration( dialog, {
					manualRate: '150.1',
					priceRoundingValue: '1',
					priceCharmValue: '0.00',
				} );

				await expectPersistedSettings( adminApi, currencyCode, {
					manualRate: 150.1,
					priceRounding: 1,
					priceCharm: 0,
				} );

				// No fractional digits: the trailing guard rejects both
				// another digit and a decimal fraction after the yen amount.
				await expectShopperPrice(
					page,
					product.slug,
					currencyCode,
					/¥185,308(?!\.?\d)/
				);

				await restoreAndVerify(
					adminApi,
					snapshot,
					currencyCode,
					product.id
				);
			}
		);
	}
);
