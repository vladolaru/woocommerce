import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import { requireTestModeAccount } from '../../../utils/woopayments';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/klarna-checkout-purchase.spec.ts:42::Klarna Checkout › shows the message in the product page';

const PRODUCTS_API = 'wc/v3/products';

const PRODUCT_PRICE = '100.00';

const MESSAGING_FRAME_SELECTOR = '#payment-method-message iframe';

function requireBaseUrl( baseURL: string | undefined ): string {
	if ( ! baseURL ) {
		throw new Error( 'BASE_URL is required for this spec.' );
	}
	return baseURL;
}

async function createRunProduct(
	restApi: ApiClient,
	runId: string
): Promise< { id: number; name: string; permalink: string } > {
	const name = `WooPayments Klarna messaging ${ runId }`;
	const product = (
		await restApi.post( PRODUCTS_API, {
			name,
			type: 'simple',
			status: 'publish',
			virtual: true,
			tax_status: 'none',
			regular_price: PRODUCT_PRICE,
			meta_data: [ { key: '_e2e_woopayments_run_id', value: runId } ],
		} )
	).data as { id?: unknown; name?: unknown; permalink?: unknown };

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
	restApi: ApiClient,
	productId: number
): Promise< void > {
	await restApi.delete( `${ PRODUCTS_API }/${ productId }`, {
		force: true,
	} );
}

test.beforeAll( async ( { restApi } ) => {
	await requireTestModeAccount( restApi );
} );

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
	async ( { restApi, page, baseURL } ) => {
		const runId = random();
		const product = await createRunProduct( restApi, runId );
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
				/pay|payment|later|installment|instalment|financ|interest.free/i
			);
		} finally {
			await deleteRunProduct( restApi, product.id );
		}
	}
);
