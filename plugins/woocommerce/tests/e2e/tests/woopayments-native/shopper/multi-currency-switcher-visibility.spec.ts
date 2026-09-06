import type { APIRequestContext, Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { admin } from '../../../test-data/data';

/**
 * Native multi-currency switcher visibility boundaries
 * (mc-visibility-boundaries-spec).
 *
 * Two provider-free shopper contracts about where the native currency
 * switcher must NOT offer a choice. An absence assertion is worthless on its
 * own — a store where the switcher never rendered at all would satisfy it —
 * so each test first proves the switcher IS offered in the comparable allowed
 * context, in the same session, and only then proves the boundary.
 *
 * NO FORCED PREMISE. Neither test widens the available-currency catalog, and
 * neither writes the enabled-currency set: both read the authoritative set
 * from `/wc/v3/payments/multi-currency/currencies` and assert against exactly
 * what the merchant has enabled. That restraint is deliberate rather than
 * incidental — see the parity note below.
 *
 * HISTORY — the shopper-side disabled-feature boundary was unassertable when
 * this spec was written. `MultiCurrencyRuntimeArbiter::get_runtime_owner()`
 * returned `OWNER_CORE` for every native-payments store and consulted
 * `_wcpay_feature_customer_multi_currency` only on the plugin branch, so every
 * native multi-currency controller registered regardless of the merchant's
 * setting and turning multi-currency off did not remove the storefront
 * switcher — a merchant who had switched the feature off got it switched back
 * on by moving to native. Rather than encode that as intended behaviour or
 * write a knowingly red test, the arbiter was fixed to read the same option on
 * both branches, and the disabled-state test below now asserts the whole
 * contract: the setting round-trips, the merchant-facing status report
 * reflects it, and the storefront switcher is gone while the flag is off.
 *
 * The reason the enabled set is never written is the same standing-store
 * hazard the sibling specs record: the store's rate cache is empty, so EUR is
 * available only because it is enabled with a manual rate, and
 * `update-enabled-currencies` deletes the per-currency options of any code it
 * removes (MultiCurrencyRestController.php:196-198). Removing EUR would take
 * it out of the available catalog irrecoverably through the REST surface and
 * break the frozen `shopper/multi-currency.spec.ts`. Reaching the
 * "no additional enabled currency" state is therefore out of bounds here.
 */

// The same environment-first resolution the harness fixtures use.
const ADMIN_USERNAME =
	process.env.E2E_WOOPAYMENTS_ADMIN_USERNAME ?? admin.username;
const ADMIN_PASSWORD =
	process.env.E2E_WOOPAYMENTS_ADMIN_PASSWORD ?? admin.password;

const SHOPPER_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:';

const CONTRACT_IDS = {
	payForOrder: `${ SHOPPER_CONTRACT_PREFIX }106::Shopper Multi-Currency widget › should not display currency switcher on pay for order page`,
	featureDisabled: `${ SHOPPER_CONTRACT_PREFIX }122::Shopper Multi-Currency widget › should not display currency switcher widget if multi-currency is disabled`,
} as const;

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const ORDERS_API = '/wp-json/wc/v3/orders';
const PRODUCTS_API = '/wp-json/wc/v3/products';
const PAGES_API = '/wp-json/wp/v2/pages';
const POSTS_API = '/wp-json/wp/v2/posts';
const CHECKOUT_PAGE_SETTING_API =
	'/wp-json/wc/v3/settings/advanced/woocommerce_checkout_page_id';
const STATUS_PATH = '/wp-admin/admin.php?page=wc-status';

const BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';
const SWITCHER_BLOCK = `<!-- wp:${ BLOCK_NAME } /-->`;

const PRODUCT_PRICE = '10.00';

// Any euro-formatted amount. The pay-for-order page must keep showing the
// order's own currency even after the shopper's session currency moves, so
// the presence of a euro amount there is the failure this looks for.
const EURO_AMOUNT = /\d[\d.,]*\s*€|€\s*\d/;

// Deterministic preference order for a currency the merchant has not
// enabled. The first candidate outside the enabled set is used.
const UNENABLED_CANDIDATES = [ 'CHF', 'JPY', 'NZD', 'SEK', 'DKK' ] as const;

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

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this spec.' );
	}
	return baseURL.replace( /\/+$/, '' );
}

function toMessage( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
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

// Assets belonging to plugins other than the one under test. The standing
// store carries unrelated third-party plugins — an installed
// woocommerce-subscriptions build 404s on its admin stylesheet on every admin
// screen — and Chromium echoes each such network failure as a console error.
// Those say nothing about the switcher boundaries. The exclusion is scoped by
// owner rather than being a blanket console filter: every same-origin failure
// that is not another plugin's asset still counts, and uncaught exceptions
// always count regardless of source.
const THIRD_PARTY_PLUGIN_ASSET = /\/wp-content\/plugins\/(?!woocommerce\/)/;
const MEDIA_UPLOAD_ASSET = /\/wp-content\/uploads\//;

function isAmbientForeignResource( url: string, baseUrl: string ): boolean {
	if ( ! url.startsWith( baseUrl ) ) {
		// Cross-origin resources (gravatars, w.org emoji) are never this
		// surface's responsibility.
		return true;
	}
	try {
		const { pathname } = new URL( url );
		// Uploaded media is store fixture data, not code this surface owns.
		// The standing store is missing WooCommerce's product placeholder
		// image, so every product without its own image logs a 404 that says
		// nothing about currency switching. Scripts and styles under uploads
		// are still attributed, since those would be code.
		if (
			MEDIA_UPLOAD_ASSET.test( pathname ) &&
			! /\.(?:js|mjs|css)$/i.test( pathname )
		) {
			return true;
		}
		return THIRD_PARTY_PLUGIN_ASSET.test( pathname );
	} catch {
		return false;
	}
}

/**
 * Collect uncaught page exceptions and attributable console errors, so a
 * surface that renders while its scripts throw is not mistaken for a healthy
 * one — which matters most for an absence assertion, where a page that died
 * early would otherwise look like a clean boundary.
 *
 * @param page    Page to observe.
 * @param baseUrl Store base URL used to attribute resources.
 * @return Accessor returning the errors collected so far.
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
 * Read the enabled-currency set and refuse to run against a store that cannot
 * express these contracts.
 *
 * The native switcher renders nothing unless the store has an additional
 * enabled currency, so on a single-currency store the positive control that
 * makes every absence assertion below meaningful would itself be absent.
 *
 * @param adminApi Authenticated admin REST context.
 * @return Sorted enabled codes, the store default, and the first candidate
 *         currency the merchant has not enabled.
 */
async function readCurrencyBaseline( adminApi: APIRequestContext ): Promise< {
	enabledCodes: string[];
	defaultCode: string;
	additionalCode: string;
	unenabledCode: string;
} > {
	const currencies = await readJson< StoreCurrencies >(
		await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state read'
	);
	const defaultCode = currencies.default.code;
	expect(
		defaultCode,
		'these boundary contracts assume a USD store default; the order and price expectations are derived from it'
	).toBe( 'USD' );

	const enabledCodes = Object.keys( currencies.enabled ).toSorted();
	expect( enabledCodes ).toContain( defaultCode );
	expect(
		enabledCodes.length,
		'the switcher only renders when the store has an additional enabled currency; without one the positive control cannot exist and every absence assertion below would be vacuous'
	).toBeGreaterThan( 1 );

	const additionalCode = enabledCodes.filter(
		( code ) => code !== defaultCode
	)[ 0 ];
	const unenabledCode = UNENABLED_CANDIDATES.filter(
		( code ) => ! enabledCodes.includes( code )
	)[ 0 ];
	expect(
		unenabledCode,
		'the boundary needs a currency the merchant has not enabled'
	).toBeTruthy();

	return { enabledCodes, defaultCode, additionalCode, unenabledCode };
}

/**
 * Currency switchers rendered inside the page or post body.
 *
 * Scoped to core's own post-content wrapper (`core/post-content` always emits
 * `entry-content`) rather than the whole document, because the standing
 * store's theme header may carry its own switcher placement and a page-wide
 * locator would alias it with the one under test.
 *
 * @param page Page rendering the content.
 * @return Locator matching every in-content switcher.
 */
function contentSwitchers( page: Page ): Locator {
	return page
		.locator( '.entry-content' )
		.getByRole( 'combobox', { name: 'Currency', exact: true } );
}

/**
 * Every currency switcher on the page, wherever it is placed.
 *
 * The pay-for-order boundary is global — `should_disable_currency_switching()`
 * is evaluated per request, not per placement — so its absence assertion is
 * page-wide rather than content-scoped, and a theme-header placement left by
 * another spec must be suppressed too.
 *
 * @param page Page rendering the content.
 * @return Locator matching every switcher on the page.
 */
function anySwitcher( page: Page ): Locator {
	return page.getByRole( 'combobox', { name: 'Currency', exact: true } );
}

async function createRunProduct(
	adminApi: APIRequestContext,
	runId: string,
	label: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments MC ${ label } ${ runId }`;
	const created = await readJson< { id: number } >(
		await adminApi.post( PRODUCTS_API, {
			data: {
				name,
				slug: `woopayments-mc-${ label }-${ runId }`,
				type: 'simple',
				virtual: true,
				regular_price: PRODUCT_PRICE,
				status: 'publish',
			},
		} ),
		'Run product creation'
	);
	return { id: created.id, name };
}

async function deleteRunResource(
	adminApi: APIRequestContext,
	url: string,
	description: string
): Promise< void > {
	const deletion = await adminApi.delete( url, { data: { force: true } } );
	if ( ! deletion.ok() ) {
		throw new Error(
			`${ description } cleanup failed: HTTP ${ deletion.status() }.`
		);
	}
}

/**
 * Idempotent feature-flag seed: repair a drifted baseline through the
 * documented settings route rather than failing the row for a prior run's
 * leftovers, mirroring the frozen settings spec's baseline discipline.
 *
 * @param adminApi     Authenticated admin REST context.
 * @param enabled      Desired flag state.
 * @param currentValue Flag state already read from the settings echo.
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
 * The value cell of one row in the native WooPayments system status section.
 *
 * @param page  Admin page showing WooCommerce → Status.
 * @param label Exact label cell text, including its trailing colon.
 * @return Locator for the row's value cell.
 */
function statusValue( page: Page, label: string ): Locator {
	return page
		.getByRole( 'table' )
		.filter( {
			has: page.getByRole( 'heading', {
				name: 'WooPayments native payments',
			} ),
		} )
		.getByRole( 'row' )
		.filter( {
			has: page.getByRole( 'cell', { name: label, exact: true } ),
		} )
		.getByRole( 'cell' )
		.last();
}

/**
 * Publish the switcher block into the store's checkout page for the duration
 * of `callback`, then restore the page's stored content and prove the restore.
 *
 * The positive control for the pay-for-order boundary has to be the same
 * block instance on the same page, differing only by the endpoint: that is
 * the only comparison that isolates `pay_for_order` as the cause. This is
 * out-of-band run scaffolding rather than product state under test, so it is
 * restored unconditionally — including after a failed scenario — and the
 * restoration never masks the scenario's own error.
 *
 * @param adminApi Authenticated admin REST context.
 * @param callback Scenario body, run with the switcher published on checkout.
 * @return The callback's result.
 */
async function withCheckoutPageSwitcher< Result >(
	adminApi: APIRequestContext,
	callback: () => Promise< Result >
): Promise< Result > {
	const setting = await readJson< { value: unknown } >(
		await adminApi.get( CHECKOUT_PAGE_SETTING_API ),
		'Checkout page setting read'
	);
	const checkoutPageId = Number( setting.value );
	expect(
		Number.isSafeInteger( checkoutPageId ) && checkoutPageId > 0,
		'the store must have a configured checkout page for this boundary'
	).toBe( true );

	const before = await readJson< { content: { raw?: string } } >(
		await adminApi.get( `${ PAGES_API }/${ checkoutPageId }?context=edit` ),
		'Checkout page read'
	);
	const originalContent = before.content.raw ?? '';
	expect(
		originalContent.includes( BLOCK_NAME ),
		'the checkout page must not already carry a switcher block; a previous run leaked scaffolding that needs manual attention rather than silent reuse'
	).toBe( false );

	const seeded = await readJson< { content: { raw?: string } } >(
		await adminApi.post( `${ PAGES_API }/${ checkoutPageId }`, {
			data: { content: `${ originalContent }\n\n${ SWITCHER_BLOCK }` },
		} ),
		'Checkout page switcher seed'
	);
	expect(
		( seeded.content.raw ?? '' ).includes( BLOCK_NAME ),
		'the seeded checkout page must carry the switcher block'
	).toBe( true );

	const restore = async (): Promise< void > => {
		const restored = await readJson< { content: { raw?: string } } >(
			await adminApi.post( `${ PAGES_API }/${ checkoutPageId }`, {
				data: { content: originalContent },
			} ),
			'Checkout page restoration'
		);
		expect(
			restored.content.raw ?? '',
			'the checkout page content must come back exactly as it was'
		).toBe( originalContent );

		// Cold re-read: the restore must hold on a fresh request, not only in
		// the mutating call's own response.
		const reread = await readJson< { content: { raw?: string } } >(
			await adminApi.get(
				`${ PAGES_API }/${ checkoutPageId }?context=edit`
			),
			'Checkout page restoration re-read'
		);
		expect( reread.content.raw ?? '' ).toBe( originalContent );
	};

	let result: Result | undefined;
	let scenarioError: unknown;
	try {
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}

	// Restoration must never mask the scenario's own failure: a `finally` that
	// throws replaces the original error, which would hide exactly the finding
	// the run exists to produce.
	let restorationError: unknown;
	try {
		await restore();
	} catch ( error ) {
		restorationError = error;
	}

	if ( scenarioError !== undefined ) {
		if ( restorationError !== undefined ) {
			throw new Error(
				`${ toMessage(
					scenarioError
				) }\n\nThe checkout page then failed to restore: ${ toMessage(
					restorationError
				) }`,
				{ cause: scenarioError }
			);
		}
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}

test(
	'An existing pay-for-order obligation offers no currency switcher and stays fixed to its order currency under a shopper currency override',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.payForOrder,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const { defaultCode, additionalCode } =
			await readCurrencyBaseline( adminApi );
		// The euro-absence assertions below are derived from the additional
		// enabled currency being EUR. If the store's enabled set ever changes,
		// that expectation must be re-derived rather than silently weakened,
		// so it fails here instead.
		expect(
			additionalCode,
			'this row asserts the absence of a euro-formatted amount, which assumes the additional enabled currency is EUR'
		).toBe( 'EUR' );

		const product = await createRunProduct( adminApi, runId, 'payorder' );
		const order = await readJson< {
			id: number;
			currency: string;
			total: string;
			status: string;
			payment_url: string;
		} >(
			await adminApi.post( ORDERS_API, {
				data: {
					// A non-provider method: this obligation is never paid, and
					// nothing here touches the payment provider.
					payment_method: 'cod',
					payment_method_title: 'Cash on delivery',
					status: 'pending',
					billing: {
						first_name: 'WooPayments',
						last_name: 'E2E',
						email: `woopayments-mc-${ runId }@example.com`,
						address_1: '60 29th Street #343',
						city: 'San Francisco',
						state: 'CA',
						postcode: '94110',
						country: 'US',
					},
					line_items: [ { product_id: product.id, quantity: 1 } ],
					meta_data: [
						{ key: '_e2e_woopayments_run_id', value: runId },
					],
				},
			} ),
			'Run order creation'
		);

		// The obligation this contract is about: an unpaid order fixed to the
		// store currency, with a total the page can be asserted against
		// literally.
		expect( order.currency ).toBe( defaultCode );
		expect( order.status ).toBe( 'pending' );
		expect(
			order.total,
			'the run order total must be a plain sub-thousand amount so the rendered price can be matched exactly'
		).toMatch( /^\d{1,3}\.\d{2}$/ );
		expect( order.payment_url ).toContain( 'pay_for_order' );
		const orderTotalText = new RegExp(
			`\\$${ order.total.replace( '.', '\\.' ) }(?!\\d)`
		);

		const pageErrors = trackPageErrors( page, storeBase );

		await withCheckoutPageSwitcher( adminApi, async () => {
			// A fresh anonymous shopper for the whole comparison.
			await page.context().clearCookies();

			// POSITIVE CONTROL — the same block instance, on the same
			// checkout page, outside the pay-for-order context. Without this
			// the absence assertions below would pass on a store where the
			// switcher never rendered at all.
			await page.goto( `?add-to-cart=${ product.id }` );
			await page.goto( 'checkout/' );
			const checkoutSwitcher = contentSwitchers( page );
			await expect( checkoutSwitcher ).toHaveCount( 1 );
			await expect( checkoutSwitcher ).toBeVisible();
			await expect( checkoutSwitcher ).toHaveValue( defaultCode );
			await expect(
				checkoutSwitcher.locator(
					`option[value="${ additionalCode }"]`
				)
			).toHaveCount( 1 );

			// BOUNDARY — the customer payment page for the existing order.
			// Absence is page-wide because the guard is evaluated per
			// request, so a theme-header placement must be suppressed too.
			await page.goto( order.payment_url );
			await expect(
				page
					.locator( '.entry-content' )
					.getByText( product.name )
					.first()
			).toBeVisible();
			await expect( anySwitcher( page ) ).toHaveCount( 0 );
			// The order table renders the amount once per line, once as the
			// subtotal and once as the total, so this asserts the currency
			// the obligation reads in rather than a single occurrence of it.
			// The euro assertion below stays an exact zero-count.
			await expect(
				page
					.locator( '.entry-content' )
					.getByText( orderTotalText )
					.first()
			).toBeVisible();

			// The obligation resists an explicit reprice attempt through the
			// documented currency entry point: no switcher appears, and the
			// page keeps the order's own currency.
			// MultiCurrencySelectedCurrencyController::handle_init() honours
			// `?currency=` on every frontend request, so this is a real
			// override rather than a rejected one — the order simply is not
			// repriced by it.
			await page.goto(
				`${ order.payment_url }&currency=${ additionalCode }`
			);
			await expect( anySwitcher( page ) ).toHaveCount( 0 );
			// The order table renders the amount once per line, once as the
			// subtotal and once as the total, so this asserts the currency
			// the obligation reads in rather than a single occurrence of it.
			// The euro assertion below stays an exact zero-count.
			await expect(
				page
					.locator( '.entry-content' )
					.getByText( orderTotalText )
					.first()
			).toBeVisible();
			await expect(
				page.locator( '.entry-content' ).getByText( EURO_AMOUNT )
			).toHaveCount( 0 );

			// The override pressure was real, not silently discarded: the
			// shopper's session currency did move, which is what makes the
			// assertion above a genuine invariant rather than a no-op.
			await page.goto( 'checkout/' );
			await expect( contentSwitchers( page ) ).toHaveValue(
				additionalCode
			);

			// And with that session currency in force, the obligation still
			// reads in the order's currency on a plain visit with no query
			// override at all.
			await page.goto( order.payment_url );
			await expect( anySwitcher( page ) ).toHaveCount( 0 );
			// The order table renders the amount once per line, once as the
			// subtotal and once as the total, so this asserts the currency
			// the obligation reads in rather than a single occurrence of it.
			// The euro assertion below stays an exact zero-count.
			await expect(
				page
					.locator( '.entry-content' )
					.getByText( orderTotalText )
					.first()
			).toBeVisible();
			await expect(
				page.locator( '.entry-content' ).getByText( EURO_AMOUNT )
			).toHaveCount( 0 );

			// Authoritative record: the stored obligation is untouched. This
			// is the half that selector absence alone can never prove.
			const orderAfter = await readJson< {
				currency: string;
				total: string;
				status: string;
			} >(
				await adminApi.get( `${ ORDERS_API }/${ order.id }` ),
				'Run order re-read'
			);
			expect( {
				currency: orderAfter.currency,
				total: orderAfter.total,
				status: orderAfter.status,
			} ).toEqual( {
				currency: order.currency,
				total: order.total,
				status: order.status,
			} );

			expect( pageErrors() ).toEqual( [] );
		} );

		await deleteRunResource(
			adminApi,
			`${ ORDERS_API }/${ order.id }`,
			'Run order'
		);
		await deleteRunResource(
			adminApi,
			`${ PRODUCTS_API }/${ product.id }`,
			'Run product'
		);
	}
);

test(
	'The storefront offers and honours only the merchant-enabled currencies, and disabling multi-currency removes the shopper switcher and is recorded in the store status',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.featureDisabled,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, baseURL, page, runId } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const { enabledCodes, defaultCode, additionalCode, unenabledCode } =
			await readCurrencyBaseline( adminApi );

		const post = await readJson< { id: number; link: string } >(
			await adminApi.post( POSTS_API, {
				data: {
					title: `WooPayments MC switcher boundary ${ runId }`,
					slug: `woopayments-mc-boundary-${ runId }`,
					status: 'publish',
					content: SWITCHER_BLOCK,
				},
			} ),
			'Run post creation'
		);

		const pageErrors = trackPageErrors( page, storeBase );

		// POSITIVE CONTROL — a fresh anonymous shopper is offered a working
		// switcher listing exactly the enabled currencies.
		await page.context().clearCookies();
		await page.goto( post.link );
		const switcher = contentSwitchers( page );
		await expect( switcher ).toHaveCount( 1 );
		await expect( switcher ).toBeVisible();
		await expect( switcher ).toHaveValue( defaultCode );
		await expect( switcher.locator( 'option' ) ).toHaveCount(
			enabledCodes.length
		);

		// BOUNDARY (offered) — a currency the merchant has not enabled is not
		// among the shopper's choices.
		await expect(
			switcher.locator( `option[value="${ unenabledCode }"]` ),
			`${ unenabledCode } is not enabled, so it must not be offered as a choice`
		).toHaveCount( 0 );

		// BOUNDARY (honoured) — and it is not reachable through the URL entry
		// point either. MultiCurrencySelectedCurrencyPersistenceService
		// ::update_selected_currency() refuses codes outside the enabled set,
		// so no alternate switching path remains exposed.
		await page.goto( `${ post.link }?currency=${ unenabledCode }` );
		await expect( contentSwitchers( page ) ).toHaveValue( defaultCode );

		// The entry point itself is live, so the rejection above is a real
		// boundary and not a dead parameter.
		await page.goto( `${ post.link }?currency=${ additionalCode }` );
		await expect( contentSwitchers( page ) ).toHaveValue( additionalCode );

		// Rejecting an unenabled code leaves the shopper's existing choice
		// intact rather than resetting or corrupting it.
		await page.goto( `${ post.link }?currency=${ unenabledCode }` );
		await expect( contentSwitchers( page ) ).toHaveValue( additionalCode );

		// The run post stays published through the flag phase below, so the
		// disabled-state check runs against the very block this positive
		// control just proved working.

		// The merchant's disabled intent is recorded and merchant-visible.
		const settingsBefore = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( settingsBefore.is_wcpay_enabled ).toBe( true );
		const originalFlag = settingsBefore.is_multi_currency_enabled === true;

		// Isolated initial-on fixture: the disable transition needs a
		// genuinely enabled starting point regardless of prior runs.
		await ensureMultiCurrencyFeatureFlag( adminApi, true, originalFlag );

		await logInAsAdmin( page );
		await page.goto( STATUS_PATH );
		await expect( statusValue( page, 'Multi-currency:' ) ).toHaveText(
			'Enabled'
		);

		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_multi_currency_enabled: false },
			} ),
			'Multi-currency feature disable'
		);
		const settingsAfter = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings re-read'
		);
		expect( settingsAfter.is_multi_currency_enabled ).toBe( false );

		await page.goto( STATUS_PATH );
		await expect( statusValue( page, 'Multi-currency:' ) ).toHaveText(
			'Disabled'
		);

		// And the merchant's intent reaches the shopper: with the feature
		// off, the storefront offers no switcher at all. The positive control
		// above ran against the same published block on the same page with
		// the feature on, so this is the flag's effect and not a page that
		// never had a switcher.
		const disabledVisitor = await page.context().browser()?.newContext( {
			baseURL: storeBase,
		} );
		if ( ! disabledVisitor ) {
			throw new Error(
				'Could not open an anonymous context for the disabled-feature check.'
			);
		}
		try {
			const disabledPage = await disabledVisitor.newPage();
			const disabledResponse = await disabledPage.goto( post.link );
			// The page itself still renders — this is the switcher going
			// away, not the post disappearing.
			expect( disabledResponse?.status() ).toBe( 200 );
			await expect( anySwitcher( disabledPage ) ).toHaveCount( 0 );
		} finally {
			await disabledVisitor.close();
		}

		// Restore the snapshot and verify through a fresh read.
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_multi_currency_enabled: originalFlag },
			} ),
			'Multi-currency feature restoration'
		);
		const settingsRestored = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings restoration read'
		);
		expect( settingsRestored.is_multi_currency_enabled ).toBe(
			originalFlag
		);

		await deleteRunResource(
			adminApi,
			`${ POSTS_API }/${ post.id }`,
			'Run post'
		);

		expect( pageErrors() ).toEqual( [] );
	}
);
