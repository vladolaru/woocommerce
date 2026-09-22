import type { APIRequestContext, Page } from '@playwright/test';

import {
	expect,
	tags,
	test,
	waitForWordPressLoginReady,
} from '../../../fixtures/woopayments-native';
import { customer } from '../../../test-data/data';

const CONTRACT_PREFIX =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:';
const CONTRACT_IDS = [
	`${ CONTRACT_PREFIX }40::Shopper Multi-Currency widget › should display currency switcher widget if multi-currency is enabled`,
	`${ CONTRACT_PREFIX }59::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the product page`,
	`${ CONTRACT_PREFIX }63::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the cart page`,
	`${ CONTRACT_PREFIX }67::Shopper Multi-Currency widget › Should allow shopper to switch currency › at the checkout page`,
	// The client suite reached the same two properties from the merchant
	// widget-setup spec: that the published switcher offers the default plus
	// every enabled currency, and that switching it on the frontend converts
	// prices. This test already proves both — the enabled set is pinned and
	// read back, the switcher is asserted to expose USD and EUR, and each
	// surface asserts the exact converted amount — so they bind here rather
	// than to a separate merchant-side test that would assert the same thing
	// through a widget editor native does not have.
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-multi-currency-widget.spec.ts:172::Multi-currency widget setup › displays enabled currencies correctly in the frontend',
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-multi-currency-widget.spec.ts:248::Multi-currency widget setup › currency switching works on the frontend',
];

const HISTORICAL_ORDER_CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:84::Multi-currency checkout › My account › should display the correct currency in the my account order history table',
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:96::Shopper Multi-Currency widget › Should not affect prices › at My account › Orders',
];

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const TEMPLATE_PARTS_API = '/wp-json/wp/v2/template-parts';
const SWITCHER_BLOCK =
	'<!-- wp:woocommerce-payments/multi-currency-switcher /-->';
const SWITCHER_BLOCK_NAME = 'woocommerce-payments/multi-currency-switcher';
const SWITCHER_ACCESSIBLE_NAME = 'Select your currency';

// One run-stable virtual product priced so the manual EUR rate below converts
// it to a whole amount: USD 10.00 × 0.80 = EUR 8.00, immune to charm pricing
// and price rounding, both pinned to zero in the setup.
const PRODUCT_SLUG = 'woopayments-mc-family-smoke';
const PRODUCT_NAME = 'WooPayments MC family smoke';
const PRODUCT_PRICE = '10.00';
const EUR_MANUAL_RATE = 0.8;
const USD_PRICE_TEXT = /\$10\.00/;
const EUR_PRICE_TEXT = /8,00\s*€/;

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

interface HistoricalOrder {
	id: number;
	customer_id: number;
	currency: string;
	total: string;
	status: string;
	payment_method: string;
	payment_method_title: string;
	transaction_id: string;
	meta_data: Array< { key: string; value: unknown } >;
}

interface HistoricalOrderExpectation {
	currency: 'USD' | 'EUR';
	total: string;
	amount: RegExp;
	meta: Record< string, string >;
}

const HISTORICAL_ORDER_EXPECTATIONS: HistoricalOrderExpectation[] = [
	{
		currency: 'USD',
		total: '10.00',
		amount: /\$10[.,]00/,
		meta: {},
	},
	{
		currency: 'EUR',
		total: '12.34',
		amount: /12[.,]34\s*€|€\s*12[.,]34/,
		// Oracle: WooPayments 11.1.0 FrontendPrices::add_order_meta writes the
		// order-time rate and USD default only for a converted order. The
		// settlement rate is the exact value recorded on :8082 order 2210.
		meta: {
			_wcpay_multi_currency_order_exchange_rate: '0.88',
			_wcpay_multi_currency_order_default_currency: 'USD',
			_wcpay_multi_currency_stripe_exchange_rate: '1.14382',
		},
	},
];

function historicalOrderSnapshot( order: HistoricalOrder ) {
	const multiCurrencyMeta = Object.fromEntries(
		order.meta_data
			.filter( ( entry ) =>
				entry.key.startsWith( '_wcpay_multi_currency_' )
			)
			.map( ( entry ) => [ entry.key, String( entry.value ) ] )
	);

	return {
		customer_id: order.customer_id,
		currency: order.currency,
		total: order.total,
		status: order.status,
		payment_method: order.payment_method,
		payment_method_title: order.payment_method_title,
		transaction_id: order.transaction_id,
		multi_currency_meta: multiCurrencyMeta,
	};
}

async function standingCustomerId(
	adminApi: APIRequestContext
): Promise< number > {
	const customers = await readJson<
		Array< { id: number; email: string; username: string } >
	>(
		await adminApi.get(
			`/wp-json/wc/v3/customers?role=all&search=${ encodeURIComponent(
				customer.email
			) }`
		),
		'Standing customer lookup'
	);
	const match = customers.find(
		( candidate ) =>
			candidate.email === customer.email ||
			candidate.username === customer.username
	);
	expect(
		match,
		'the shared store must contain the standing customer'
	).toBeDefined();
	return match!.id;
}

async function createHistoricalOrder(
	adminApi: APIRequestContext,
	productId: number,
	customerId: number,
	runId: string,
	expectation: HistoricalOrderExpectation
): Promise< HistoricalOrder > {
	return readJson< HistoricalOrder >(
		await adminApi.post( '/wp-json/wc/v3/orders', {
			data: {
				customer_id: customerId,
				currency: expectation.currency,
				status: 'completed',
				payment_method: 'woocommerce_payments',
				payment_method_title: 'Visa credit card',
				transaction_id: `pi_e2e_historical_${ runId }_${ expectation.currency.toLowerCase() }`,
				line_items: [
					{
						product_id: productId,
						quantity: 1,
						subtotal: expectation.total,
						total: expectation.total,
					},
				],
				meta_data: [ { key: '_e2e_woopayments_run_id', value: runId } ],
			},
		} ),
		`${ expectation.currency } historical order creation`
	);
}

async function seedHistoricalOrderMetadata(
	adminApi: APIRequestContext,
	order: HistoricalOrder,
	expectation: HistoricalOrderExpectation
): Promise< HistoricalOrder > {
	if ( Object.keys( expectation.meta ).length === 0 ) {
		return order;
	}

	// Administrative order creation fires the current runtime's
	// `woocommerce_new_order` projection, which derives an order-time rate
	// from today's request context. A second REST write replaces that
	// scaffolding with the captured plugin-era values this historical fixture
	// is specifically meant to preserve.
	return readJson< HistoricalOrder >(
		await adminApi.put( `/wp-json/wc/v3/orders/${ order.id }`, {
			data: {
				meta_data: Object.entries( expectation.meta ).map(
					( [ key, value ] ) => ( { key, value } )
				),
			},
		} ),
		`${ expectation.currency } historical order metadata seed`
	);
}

async function deleteHistoricalOrder(
	adminApi: APIRequestContext,
	orderId: number
): Promise< void > {
	const deletion = await adminApi.delete(
		`/wp-json/wc/v3/orders/${ orderId }`,
		{ data: { force: true }, failOnStatusCode: false }
	);
	if ( ! deletion.ok() ) {
		throw new Error(
			`Historical order ${ orderId } cleanup failed: HTTP ${ deletion.status() } ${ await deletion.text() }`
		);
	}
}

async function logInAsStandingCustomer( page: Page ): Promise< void > {
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	await waitForWordPressLoginReady( page );
	await page
		.getByLabel( 'Username or Email Address' )
		.fill( customer.username );
	await page
		.getByRole( 'textbox', { name: 'Password' } )
		.fill( customer.password );
	await page.getByRole( 'button', { name: 'Log In' } ).click();
	await page.goto( 'my-account/edit-account/' );
	await expect(
		page.getByRole( 'textbox', { name: /Email address/i } )
	).toHaveValue( customer.email );
}

function currencySwitcher( page: Page ) {
	return page.getByRole( 'combobox', {
		name: SWITCHER_ACCESSIBLE_NAME,
		exact: true,
	} );
}

async function expectHistoricalOrdersRendered(
	page: Page,
	orders: HistoricalOrder[],
	shopperCurrency?: string
): Promise< void > {
	await page.goto( 'my-account/orders/' );
	if ( shopperCurrency ) {
		await expect( currencySwitcher( page ) ).toHaveValue( shopperCurrency );
	}
	for ( const [ index, order ] of orders.entries() ) {
		const expectation = HISTORICAL_ORDER_EXPECTATIONS[ index ];
		const row = page.locator( 'tr' ).filter( {
			has: page.getByText( `#${ order.id }`, { exact: true } ),
		} );
		await expect( row ).toHaveCount( 1 );
		await expect( row ).toContainText( expectation.currency );
		await expect( row ).toContainText( expectation.amount );
		await expect( row ).toContainText( 'Completed' );

		await row.getByText( `#${ order.id }`, { exact: true } ).click();
		const details = page.locator( '.woocommerce-order-details' );
		await expect( details ).toBeVisible();
		await expect( details ).toContainText( expectation.currency );
		await expect( details ).toContainText( expectation.amount );
		await page.goto( 'my-account/orders/' );
		if ( shopperCurrency ) {
			await expect( currencySwitcher( page ) ).toHaveValue(
				shopperCurrency
			);
		}
	}
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

test(
	'historical orders keep their stored money and WooPayments metadata when shopper currency changes',
	{
		annotation: HISTORICAL_ORDER_CONTRACT_IDS.map( ( contractId ) => ( {
			type: 'woopayments-contract',
			description: contractId,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, runId } ) => {
		await ensureEnabledCurrencies( adminApi );
		await ensureSwitcherPlacement( adminApi );
		const productId = await ensureSmokeProduct( adminApi );
		const customerId = await standingCustomerId( adminApi );
		const orders: HistoricalOrder[] = [];

		try {
			for ( const expectation of HISTORICAL_ORDER_EXPECTATIONS ) {
				let order = await createHistoricalOrder(
					adminApi,
					productId,
					customerId,
					runId,
					expectation
				);
				orders.push( order );
				order = await seedHistoricalOrderMetadata(
					adminApi,
					order,
					expectation
				);
				orders[ orders.length - 1 ] = order;
				expect( historicalOrderSnapshot( order ) ).toEqual( {
					customer_id: customerId,
					currency: expectation.currency,
					total: expectation.total,
					status: 'completed',
					payment_method: 'woocommerce_payments',
					payment_method_title: 'Visa credit card',
					transaction_id: `pi_e2e_historical_${ runId }_${ expectation.currency.toLowerCase() }`,
					multi_currency_meta: expectation.meta,
				} );
			}

			await logInAsStandingCustomer( page );
			await expectHistoricalOrdersRendered( page, orders );

			await page.goto( 'my-account/orders/' );
			await currencySwitcher( page ).selectOption( 'EUR' );
			await page.waitForURL( /[?&]currency=EUR/ );
			await page.reload();
			await expect( currencySwitcher( page ) ).toHaveValue( 'EUR' );
			await expectHistoricalOrdersRendered( page, orders, 'EUR' );

			for ( const order of orders ) {
				const stored = await readJson< HistoricalOrder >(
					await adminApi.get( `/wp-json/wc/v3/orders/${ order.id }` ),
					`Historical order ${ order.id } re-read`
				);
				expect( historicalOrderSnapshot( stored ) ).toEqual(
					historicalOrderSnapshot( order )
				);
			}
		} finally {
			await Promise.all(
				orders.map( ( order ) =>
					deleteHistoricalOrder( adminApi, order.id )
				)
			);
		}
	}
);
