import type { APIRequestContext, APIResponse } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/klarna-checkout-purchase.spec.ts:42::Klarna Checkout › shows the message in the product page';

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const PRODUCTS_API = '/wp-json/wc/v3/products';
const STORE_CURRENCY_API =
	'/wp-json/wc/v3/settings/general/woocommerce_currency';
const STORE_COUNTRY_API =
	'/wp-json/wc/v3/settings/general/woocommerce_default_country';

const KLARNA = 'klarna';
const KLARNA_CAPABILITY = 'klarna_payments';

const PRODUCT_PRICE = '100.00';
const PRODUCT_PRICE_TEXT = '$100.00';
const PRODUCT_AMOUNT_MINOR = 10000;

const MESSAGING_FRAME_SELECTOR = '#payment-method-message iframe';

async function readJson< Result >(
	response: APIResponse,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

async function readSetting(
	adminApi: APIRequestContext,
	url: string,
	description: string
): Promise< string > {
	const setting = await readJson< { value?: unknown } >(
		await adminApi.get( url ),
		description
	);
	if ( typeof setting.value !== 'string' || setting.value === '' ) {
		throw new Error( `${ description } exposed no string value.` );
	}
	return setting.value;
}

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this spec.' );
	}
	return baseURL;
}

function readEnabledPaymentMethodIds(
	settings: Record< string, unknown >
): string[] {
	const enabled = settings.enabled_payment_method_ids;
	if (
		! Array.isArray( enabled ) ||
		enabled.some( ( id ) => typeof id !== 'string' )
	) {
		throw new Error(
			'WooPayments settings exposed no enabled payment-method list.'
		);
	}
	return [ ...( enabled as string[] ) ];
}

async function createRunProduct(
	adminApi: APIRequestContext,
	runId: string
): Promise< { id: number; name: string; permalink: string } > {
	const name = `WooPayments Klarna messaging ${ runId }`;
	const product = await readJson< {
		id?: unknown;
		name?: unknown;
		permalink?: unknown;
	} >(
		await adminApi.post( PRODUCTS_API, {
			data: {
				name,
				type: 'simple',
				status: 'publish',
				virtual: true,
				tax_status: 'none',
				regular_price: PRODUCT_PRICE,
				meta_data: [
					{
						key: '_e2e_woopayments_run_id',
						value: runId,
					},
				],
			},
		} ),
		'Klarna messaging product creation'
	);

	if (
		typeof product.id !== 'number' ||
		product.name !== name ||
		typeof product.permalink !== 'string' ||
		product.permalink === ''
	) {
		throw new Error(
			'Klarna messaging product response lacked its exact run-owned identity.'
		);
	}

	return { id: product.id, name, permalink: product.permalink };
}

async function deleteRunProduct(
	adminApi: APIRequestContext,
	productId: number
): Promise< void > {
	await readJson(
		await adminApi.delete( `${ PRODUCTS_API }/${ productId }`, {
			data: { force: true },
		} ),
		'Klarna messaging product cleanup'
	);
}

test(
	'Klarna Checkout › shows the message in the product page',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, runId, baseURL } ) => {
		const storeBaseUrl = requireBaseUrl( baseURL );
		const settings = await readJson< Record< string, unknown > >(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'WooPayments settings read'
		);
		expect( settings.is_wcpay_enabled ).toBe( true );
		expect( settings.account_country ).toBe( 'US' );
		expect( settings.available_payment_method_ids ).toContain( KLARNA );
		const enabledPaymentMethodIds = readEnabledPaymentMethodIds( settings );
		expect( enabledPaymentMethodIds ).toContain( KLARNA );

		const statuses = settings.payment_method_statuses as
			| Record< string, { status?: unknown } >
			| undefined;
		expect( statuses?.[ KLARNA_CAPABILITY ]?.status ).toBe( 'active' );
		expect(
			await readSetting(
				adminApi,
				STORE_CURRENCY_API,
				'Store currency read'
			)
		).toBe( 'USD' );
		expect(
			await readSetting(
				adminApi,
				STORE_COUNTRY_API,
				'Store country read'
			)
		).toMatch( /^US(?::|$)/ );

		const product = await createRunProduct( adminApi, runId );

		try {
			await page.context().clearCookies();
			const productUrl = new URL( product.permalink );
			const storeOrigin = new URL( storeBaseUrl ).origin;
			await page.goto(
				`${ storeOrigin }${ productUrl.pathname }${ productUrl.search }`
			);

			const main = page.getByRole( 'main' );
			await expect(
				main.getByRole( 'heading', {
					name: product.name,
					exact: true,
				} )
			).toBeVisible();
			await expect(
				main.getByText( PRODUCT_PRICE_TEXT, { exact: true } ).first()
			).toBeVisible();
			const messagingConfig = await page.evaluate( () =>
				Reflect.get( window, 'wcpayStripeSiteMessaging' )
			);
			expect( messagingConfig ).toMatchObject( {
				productId: 'base_product',
				productVariations: {
					base_product: {
						amount: PRODUCT_AMOUNT_MINOR,
						currency: 'USD',
					},
				},
				currencyCode: 'USD',
				paymentMethods: expect.arrayContaining( [ KLARNA ] ),
				// wp_localize_script serializes a PHP true as the string "1" in
				// both the WooPayments client and the native Core runtime.
				shouldShowPMME: '1',
			} );

			const hostedFrame = page.locator( MESSAGING_FRAME_SELECTOR );
			await expect( hostedFrame ).toHaveCount( 1 );
			await expect( hostedFrame ).toBeVisible();
			await expect( hostedFrame ).toHaveAttribute( 'title', /\S/ );

			const messageBody = page
				.frameLocator( MESSAGING_FRAME_SELECTOR )
				.locator( 'body' );
			await expect
				.poll( () => messageBody.ariaSnapshot(), {
					timeout: 30_000,
				} )
				.toMatch( /klarna/i );
			const accessibleMessage = await messageBody.ariaSnapshot();
			expect( accessibleMessage ).toMatch(
				/pay|payment|later|installment|instalment|financ/i
			);

			await expect(
				main.getByRole( 'button', {
					name: 'Add to cart',
					exact: true,
				} )
			).toBeEnabled();
		} finally {
			await deleteRunProduct( adminApi, product.id );
		}
	}
);
