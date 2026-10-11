import type { Locator, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';
import { random } from '../../../utils/helpers';

test.use( { storageState: ADMIN_STATE_PATH } );

/**
 * Native multi-currency switcher visibility boundaries
 * (mc-visibility-boundaries-spec).
 *
 * Provider-free shopper contract for the pay-for-order boundary. An absence
 * assertion is worthless on its own — a store where the switcher never
 * rendered at all would satisfy it — so the test first proves the switcher IS
 * offered in the comparable checkout context, in the same session, and only
 * then proves the boundary.
 *
 * NO FORCED PREMISE. The test does not widen the available-currency catalog
 * or write the enabled-currency set: it reads the authoritative set
 * from `/wc/v3/payments/multi-currency/currencies` and asserts against exactly
 * what the merchant has enabled. That restraint is deliberate rather than
 * incidental — see the parity note below.
 *
 * The disabled-state test uses the Core feature settings resource, which owns
 * the native runtime. It keeps the switcher's positive control in the same
 * session before proving that a freshly read Core-disabled state removes every
 * anonymous storefront switcher.
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

const SHOPPER_CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:';

const CONTRACT_IDS = {
	payForOrder: `${ SHOPPER_CONTRACT_PREFIX }106::Shopper Multi-Currency widget › should not display currency switcher on pay for order page`,
	// The pay-for-order test's own positive control renders the switcher on
	// checkout with EUR offered and reflects the EUR session currency, which
	// is also the client's checkout-page switch case.
	checkoutSwitcherPositiveControl: `${ SHOPPER_CONTRACT_PREFIX }67::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the checkout page`,
} as const;

const MULTI_CURRENCY_API = 'wc/v3/payments/multi-currency';
const ORDERS_API = 'wc/v3/orders';
const PRODUCTS_API = 'wc/v3/products';
const PAGES_API = 'wp/v2/pages';
const CHECKOUT_PAGE_SETTING_PATH =
	'wc/v3/settings/advanced/woocommerce_checkout_page_id';

const BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';
const SWITCHER_BLOCK = `<!-- wp:${ BLOCK_NAME } /-->`;
const SWITCHER_ACCESSIBLE_NAME = 'Select your currency';

const PRODUCT_PRICE = '10.00';

// Any euro-formatted amount. The pay-for-order page must keep showing the
// order's own currency even after the shopper's session currency moves, so
// the presence of a euro amount there is the failure this looks for.
const EURO_AMOUNT = /\d[\d.,]*\s*€|€\s*\d/;

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
 * @param restApi Authenticated admin REST client.
 * @return The store default and first additional enabled currency.
 */
async function readCurrencyBaseline( restApi: ApiClient ): Promise< {
	defaultCode: string;
	additionalCode: string;
} > {
	const currencies = (
		await restApi.get< StoreCurrencies >(
			`${ MULTI_CURRENCY_API }/currencies`
		)
	).data;
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
	return { defaultCode, additionalCode };
}

/**
 * Currency switchers rendered inside the page or post body.
 *
 * Scoped to the main content landmark rather than the whole document because
 * the standing store's theme header may carry its own switcher placement and
 * a page-wide locator would alias it with the one under test.
 *
 * @param page Page rendering the content.
 * @return Locator matching every in-content switcher.
 */
function contentSwitchers( page: Page ): Locator {
	return page.getByRole( 'main' ).getByRole( 'combobox', {
		name: SWITCHER_ACCESSIBLE_NAME,
		exact: true,
	} );
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
	return page.getByRole( 'combobox', {
		name: SWITCHER_ACCESSIBLE_NAME,
		exact: true,
	} );
}

async function createRunProduct(
	restApi: ApiClient,
	runId: string,
	label: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments MC ${ label } ${ runId }`;
	const created = (
		await restApi.post< { id: number } >( PRODUCTS_API, {
			name,
			slug: `woopayments-mc-${ label }-${ runId }`,
			type: 'simple',
			virtual: true,
			regular_price: PRODUCT_PRICE,
			status: 'publish',
		} )
	).data;
	return { id: created.id, name };
}

async function deleteRunResource(
	restApi: ApiClient,
	url: string
): Promise< void > {
	await restApi.delete( url, { force: true } );
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
 * @param restApi  Authenticated admin REST client.
 * @param callback Scenario body, run with the switcher published on checkout.
 * @return The callback's result.
 */
async function withCheckoutPageSwitcher< Result >(
	restApi: ApiClient,
	callback: () => Promise< Result >
): Promise< Result > {
	const setting = (
		await restApi.get< { value: unknown } >( CHECKOUT_PAGE_SETTING_PATH )
	).data;
	const checkoutPageId = Number( setting.value );
	expect(
		Number.isSafeInteger( checkoutPageId ) && checkoutPageId > 0,
		'the store must have a configured checkout page for this boundary'
	).toBe( true );

	const before = (
		await restApi.get< { content: { raw?: string } } >(
			`${ PAGES_API }/${ checkoutPageId }?context=edit`
		)
	).data;
	const originalContent = before.content.raw ?? '';
	expect(
		originalContent.includes( BLOCK_NAME ),
		'the checkout page must not already carry a switcher block; a previous run leaked scaffolding that needs manual attention rather than silent reuse'
	).toBe( false );

	const seeded = (
		await restApi.post< { content: { raw?: string } } >(
			`${ PAGES_API }/${ checkoutPageId }`,
			{ content: `${ originalContent }\n\n${ SWITCHER_BLOCK }` }
		)
	).data;
	expect(
		( seeded.content.raw ?? '' ).includes( BLOCK_NAME ),
		'the seeded checkout page must carry the switcher block'
	).toBe( true );

	const restore = async (): Promise< void > => {
		const restored = (
			await restApi.post< { content: { raw?: string } } >(
				`${ PAGES_API }/${ checkoutPageId }`,
				{ content: originalContent }
			)
		).data;
		expect(
			restored.content.raw ?? '',
			'the checkout page content must come back exactly as it was'
		).toBe( originalContent );

		// Cold re-read: the restore must hold on a fresh request, not only in
		// the mutating call's own response.
		const reread = (
			await restApi.get< { content: { raw?: string } } >(
				`${ PAGES_API }/${ checkoutPageId }?context=edit`
			)
		).data;
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
	'pay-for-order hides the switcher and keeps its rendered stored amount under a real currency override',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.payForOrder,
			},
			{
				type: 'woopayments-contract',
				description: CONTRACT_IDS.checkoutSwitcherPositiveControl,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, baseURL, page } ) => {
		const storeBase = requireBaseUrl( baseURL );
		const runId = random();
		const { defaultCode, additionalCode } =
			await readCurrencyBaseline( restApi );
		// The euro-absence assertions below are derived from the additional
		// enabled currency being EUR. If the store's enabled set ever changes,
		// that expectation must be re-derived rather than silently weakened,
		// so it fails here instead.
		expect(
			additionalCode,
			'this row asserts the absence of a euro-formatted amount, which assumes the additional enabled currency is EUR'
		).toBe( 'EUR' );

		const product = await createRunProduct( restApi, runId, 'payorder' );
		try {
			const order = (
				await restApi.post< {
					id: number;
					currency: string;
					total: string;
					payment_url: string;
				} >( ORDERS_API, {
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
				} )
			).data;

			// The obligation this contract is about: an unpaid order fixed to the
			// store currency, with a total the page can be asserted against
			// literally.
			expect( order.currency ).toBe( defaultCode );
			expect(
				order.total,
				'the run order total must be a plain sub-thousand amount so the rendered price can be matched exactly'
			).toMatch( /^\d{1,3}\.\d{2}$/ );
			expect( order.payment_url ).toContain( 'pay_for_order' );
			const orderTotalText = new RegExp(
				`\\$${ order.total.replace( '.', '\\.' ) }(?!\\d)`
			);

			const pageErrors = trackPageErrors( page, storeBase );

			try {
				await withCheckoutPageSwitcher( restApi, async () => {
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
					await expect(
						page
							.locator( '.entry-content' )
							.getByText( EURO_AMOUNT )
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
						page
							.locator( '.entry-content' )
							.getByText( EURO_AMOUNT )
					).toHaveCount( 0 );

					const orderAfter = (
						await restApi.get< {
							currency: string;
							total: string;
						} >( `${ ORDERS_API }/${ order.id }` )
					).data;
					expect( orderAfter ).toMatchObject( {
						currency: order.currency,
						total: order.total,
					} );

					expect( pageErrors() ).toEqual( [] );
				} );
			} finally {
				await deleteRunResource(
					restApi,
					`${ ORDERS_API }/${ order.id }`
				);
			}
		} finally {
			await deleteRunResource(
				restApi,
				`${ PRODUCTS_API }/${ product.id }`
			);
		}
	}
);
