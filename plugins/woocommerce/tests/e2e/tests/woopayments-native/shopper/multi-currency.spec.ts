import type { APIRequestContext, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:';
const CONTRACT_IDS = [
	`${ CONTRACT_PREFIX }40::Shopper Multi-Currency widget › should display currency switcher widget if multi-currency is enabled`,
	`${ CONTRACT_PREFIX }59::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the product page`,
	`${ CONTRACT_PREFIX }63::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the cart page`,
	`${ CONTRACT_PREFIX }67::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the checkout page`,
];

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const TEMPLATE_PARTS_API = '/wp-json/wp/v2/template-parts';
const SWITCHER_BLOCK =
	'<!-- wp:woocommerce-payments/multi-currency-switcher /-->';
const SWITCHER_BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';

// One run-stable virtual product priced so the manual EUR rate below converts
// it to a whole amount: USD 10.00 × 0.80 = EUR 8.00, immune to charm pricing
// and price rounding, both pinned to zero in the setup.
const PRODUCT_SLUG = 'woopayments-mc-family-smoke';
const PRODUCT_NAME = 'WooPayments MC family smoke';
const PRODUCT_PRICE = '10.00';
const EUR_MANUAL_RATE = 0.8;
const USD_PRICE_TEXT = /\$10\.00/;
const EUR_PRICE_TEXT = /8,00\s*€/;

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

/**
 * Make EUR deterministically available and enabled next to the USD store
 * currency. The manual rate keeps the smoke independent of provider-fetched
 * exchange rates, which this standing store does not cache.
 */
async function ensureEnabledCurrencies(
	adminApi: APIRequestContext
): Promise< void > {
	await readJson(
		await adminApi.post( `${ MULTI_CURRENCY_API }/currencies/EUR`, {
			data: {
				exchange_rate_type: 'manual',
				manual_rate: EUR_MANUAL_RATE,
				price_rounding: 0,
				price_charm: 0,
			},
		} ),
		'EUR manual-rate update'
	);
	// The route answers HTTP 200 with the unchanged list when the payload is
	// not a non-empty array, so a green response alone does not prove the
	// request took effect; assert the returned state at the call site.
	const updated = await readJson(
		await adminApi.post(
			`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
			{ data: { enabled: [ 'USD', 'EUR' ] } }
		),
		'Enabled-currencies update'
	);
	const updatedCodes = Object.keys(
		( updated.enabled ?? {} ) as Record< string, unknown >
	).toSorted();
	if ( updatedCodes.join( ',' ) !== 'EUR,USD' ) {
		throw new Error(
			`Enabled-currencies update did not take effect; store reports: ${ updatedCodes.join(
				', '
			) }`
		);
	}
}

/**
 * Place the native switcher block in the header template parts so every
 * storefront context renders it: the theme header covers shop, product and
 * cart, while the WooCommerce blocks checkout swaps in its own minimal
 * checkout-header part. Skips parts that already carry the block.
 */
async function ensureSwitcherPlacement(
	adminApi: APIRequestContext
): Promise< void > {
	const response = await adminApi.get(
		`${ TEMPLATE_PARTS_API }?context=edit`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`Header template-part lookup failed: HTTP ${ response.status() }`
		);
	}
	const headerParts = (
		( await response.json() ) as Array< {
			id: string;
			slug: string;
			content: { raw?: string };
		} >
	 ).filter( ( part ) =>
		[ 'header', 'checkout-header' ].includes( part.slug )
	);

	if ( headerParts.length < 2 ) {
		throw new Error(
			'Expected both the theme header and the checkout-header template parts.'
		);
	}

	for ( const header of headerParts ) {
		const rawContent = header.content.raw ?? '';
		if ( rawContent.includes( SWITCHER_BLOCK_NAME ) ) {
			continue;
		}

		await readJson(
			await adminApi.post( `${ TEMPLATE_PARTS_API }/${ header.id }`, {
				data: { content: `${ SWITCHER_BLOCK }\n${ rawContent }` },
			} ),
			`Template-part update for ${ header.slug }`
		);
	}
}

async function ensureSmokeProduct(
	adminApi: APIRequestContext
): Promise< number > {
	const lookup = await adminApi.get(
		`/wp-json/wc/v3/products?slug=${ PRODUCT_SLUG }&status=publish`
	);
	if ( ! lookup.ok() ) {
		throw new Error(
			`Smoke product lookup failed: HTTP ${ lookup.status() }`
		);
	}
	const existing = ( await lookup.json() ) as Array< {
		id: number;
		regular_price: string;
	} >;
	if ( existing.length > 0 ) {
		// A price drift on the standing store would otherwise surface as a
		// misleading conversion-assertion failure far from its cause.
		if ( existing[ 0 ].regular_price !== PRODUCT_PRICE ) {
			throw new Error(
				`Smoke product price drifted: expected ${ PRODUCT_PRICE }, found ${ existing[ 0 ].regular_price }`
			);
		}
		return existing[ 0 ].id;
	}

	const created = await readJson(
		await adminApi.post( '/wp-json/wc/v3/products', {
			data: {
				name: PRODUCT_NAME,
				slug: PRODUCT_SLUG,
				type: 'simple',
				virtual: true,
				regular_price: PRODUCT_PRICE,
				status: 'publish',
			},
		} ),
		'Smoke product creation'
	);
	return created.id as number;
}

function currencySwitcher( page: Page ) {
	return page.getByRole( 'combobox', { name: 'Currency', exact: true } );
}

/**
 * First visible occurrence of a price text. Blocks surfaces render hidden
 * duplicates (for example the collapsed mobile order-summary header on
 * checkout), so a bare first() can land on a hidden node.
 */
function visibleText( page: Page, pattern: RegExp ) {
	return page.getByText( pattern ).filter( { visible: true } ).first();
}

/**
 * Assert a converted surface completely: the new-currency price is visible
 * and no old-currency price remains, so a partial re-render across duplicate
 * price nodes cannot pass.
 */
async function expectConvertedPrices(
	page: Page,
	shownPrice: RegExp,
	gonePrice: RegExp
): Promise< void > {
	await expect( visibleText( page, shownPrice ) ).toBeVisible();
	await expect(
		page.getByText( gonePrice ).filter( { visible: true } )
	).toHaveCount( 0 );
}

/**
 * Select a currency through the switcher and wait for the resulting
 * form-driven navigation to land on the switched URL.
 */
async function switchCurrency( page: Page, currency: string ): Promise< void > {
	await currencySwitcher( page ).selectOption( currency );
	await page.waitForURL( new RegExp( `[?&]currency=${ currency }` ) );
	await expect( currencySwitcher( page ) ).toHaveValue( currency );
}

test(
	'shopper currency switcher renders and switches across storefront contexts',
	{
		annotation: CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page } ) => {
		await ensureEnabledCurrencies( adminApi );
		await ensureSwitcherPlacement( adminApi );
		const productId = await ensureSmokeProduct( adminApi );

		// Precondition guard: the smoke is vacuous unless the native runtime
		// actually reports multi-currency enabled with an additional currency.
		// Without this, a storefront that silently dropped the switcher would
		// fail visibly below, but a run against a store where multi-currency
		// never activated must fail here, not pass by accident.
		const currencies = await readJson(
			await adminApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
			'Multi-currency state read'
		);
		const enabledCodes = Object.keys(
			( currencies.enabled ?? {} ) as Record< string, unknown >
		);
		expect( enabledCodes ).toContain( 'USD' );
		expect( enabledCodes ).toContain( 'EUR' );

		// Contract: the currency switcher is visible on the storefront when
		// multi-currency is enabled.
		await page.goto( '?post_type=product&currency=USD' );
		const shopSwitcher = currencySwitcher( page );
		await expect( shopSwitcher ).toBeVisible();
		await expect( shopSwitcher ).toHaveValue( 'USD' );
		await expect(
			shopSwitcher.getByRole( 'option', { name: /EUR/ } )
		).toHaveCount( 1 );
		// The switcher must be a genuinely focusable control, not just
		// rendered markup.
		await shopSwitcher.focus();
		await expect( shopSwitcher ).toBeFocused();

		// Contract: switching currency at the product page converts the
		// product price and the selection survives a query-free navigation.
		await page.goto( `product/${ PRODUCT_SLUG }/?currency=USD` );
		await expect( visibleText( page, USD_PRICE_TEXT ) ).toBeVisible();
		await switchCurrency( page, 'EUR' );
		await expectConvertedPrices( page, EUR_PRICE_TEXT, USD_PRICE_TEXT );
		await page.goto( `product/${ PRODUCT_SLUG }/` );
		await expect( currencySwitcher( page ) ).toHaveValue( 'EUR' );
		await expectConvertedPrices( page, EUR_PRICE_TEXT, USD_PRICE_TEXT );

		// Contract: switching currency at the cart page converts the totals
		// for the same cart contents.
		await page.goto( `?add-to-cart=${ productId }` );
		await page.goto( 'cart/?currency=USD' );
		await expect( page.getByText( PRODUCT_NAME ).first() ).toBeVisible();
		await expect( visibleText( page, USD_PRICE_TEXT ) ).toBeVisible();
		await switchCurrency( page, 'EUR' );
		await expectConvertedPrices( page, EUR_PRICE_TEXT, USD_PRICE_TEXT );

		// Contract: switching currency at the checkout page converts the
		// order total without submitting payment.
		await page.goto( 'checkout/?currency=USD' );
		await expect( visibleText( page, USD_PRICE_TEXT ) ).toBeVisible();
		await switchCurrency( page, 'EUR' );
		await expectConvertedPrices( page, EUR_PRICE_TEXT, USD_PRICE_TEXT );
	}
);
