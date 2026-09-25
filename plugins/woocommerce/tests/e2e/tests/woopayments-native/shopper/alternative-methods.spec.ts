import type { APIRequestContext, APIResponse } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/klarna-checkout-purchase.spec.ts:42::Klarna Checkout › shows the message in the product page';

const PRODUCTS_API = '/wp-json/wc/v3/products';

const PRODUCT_PRICE = '100.00';

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

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this spec.' );
	}
	return baseURL;
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
	'Klarna Checkout › shows provider-hosted messaging in the product page @woopayments-provider',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ],
	},
	async ( { adminApi, page, runId, baseURL } ) => {
		const product = await createRunProduct( adminApi, runId );
		try {
			const productUrl = new URL( product.permalink );
			const storeOrigin = new URL( requireBaseUrl( baseURL ) ).origin;
			await page.goto(
				`${ storeOrigin }${ productUrl.pathname }${ productUrl.search }`
			);
			const hostedFrame = page.locator( MESSAGING_FRAME_SELECTOR );
			await expect( hostedFrame ).toHaveCount( 1 );
			await expect( hostedFrame ).toBeVisible();

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
		} finally {
			await deleteRunProduct( adminApi, product.id );
		}
	}
);
