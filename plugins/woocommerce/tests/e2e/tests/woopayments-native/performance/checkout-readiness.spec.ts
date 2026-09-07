import type { APIRequestContext, APIResponse, Page } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	tags,
	test,
} from '../../../fixtures/woopayments-native';
import { startStrictStripeAdapterBrowserOracle } from '../../../utils/woopayments-native/stripe-adapter-browser';

const CONTRACT_IDS = [
	'performance::chromium::tests/e2e/specs/performance/payment-methods.spec.ts:42::Checkout page performance › Stripe › measures averaged page load metrics',
	'performance::chromium::tests/e2e/specs/performance/payment-methods.spec.ts:71::Checkout page performance › WooPay › measures averaged page load metrics',
];

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const PRODUCTS_API = '/wp-json/wc/v3/products';
const CARD_GATEWAY_ID = 'woocommerce_payments';
const CARD_FRAME_SELECTOR = getBlocksCardFrameSelector( 'native' );

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

function requireBoolean( value: unknown, description: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		throw new Error( `${ description } was not a boolean.` );
	}
	return value;
}

async function enableWooPayIfNeeded(
	adminApi: APIRequestContext,
	alreadyEnabled: boolean
): Promise< void > {
	if ( alreadyEnabled ) {
		return;
	}
	await readJson(
		await adminApi.post( PAYMENTS_SETTINGS_API, {
			data: { is_woopay_enabled: true },
		} ),
		'WooPay enablement'
	);
}

async function restoreWooPaySetting(
	adminApi: APIRequestContext,
	originalValue: boolean
): Promise< void > {
	if ( ! originalValue ) {
		await readJson(
			await adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { is_woopay_enabled: false },
			} ),
			'WooPay setting restoration'
		);
	}
	const restored = await readJson< Record< string, unknown > >(
		await adminApi.get( PAYMENTS_SETTINGS_API ),
		'WooPay restored-state readback'
	);
	if ( restored.is_woopay_enabled !== originalValue ) {
		throw new Error( 'WooPay setting was not restored.' );
	}
}

function collectCleanupError( errors: Error[], error: unknown ): void {
	errors.push(
		error instanceof Error ? error : new Error( String( error ) )
	);
}

function assertCleanupSucceeded( errors: Error[] ): void {
	if ( errors.length > 0 ) {
		throw new Error(
			`Checkout-readiness cleanup failed: ${ errors
				.map( ( error ) => error.message )
				.join( '; ' ) }`
		);
	}
}

async function createProduct(
	adminApi: APIRequestContext,
	runId: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments checkout readiness ${ runId }`;
	const product = await readJson< { id?: unknown; name?: unknown } >(
		await adminApi.post( PRODUCTS_API, {
			data: {
				name,
				type: 'simple',
				status: 'publish',
				virtual: true,
				tax_status: 'none',
				regular_price: '10.00',
				meta_data: [
					{
						key: '_e2e_woopayments_run_id',
						value: runId,
					},
				],
			},
		} ),
		'Checkout-readiness product creation'
	);
	if ( typeof product.id !== 'number' || product.name !== name ) {
		throw new Error(
			'Checkout-readiness product response lacked its run-owned identity.'
		);
	}
	return { id: product.id, name };
}

async function emptyCart( page: Page ): Promise< void > {
	await page.goto( 'cart/' );
	const emptyNotice = page.getByText( 'Your cart is currently empty!' );
	if ( await emptyNotice.isVisible() ) {
		return;
	}

	const removeButtons = page.getByLabel( /Remove .* from cart/i );
	while ( ( await removeButtons.count() ) > 0 ) {
		await removeButtons.first().click();
		await expect( removeButtons ).toHaveCount( 0, { timeout: 15_000 } );
	}
	await expect( emptyNotice ).toBeVisible();
}

async function assertCardReady( page: Page ): Promise< void > {
	const paymentOptions = page.getByRole( 'group', {
		name: 'Payment options',
	} );
	const card = paymentOptions.getByRole( 'radio', { name: /Card/i } ).first();
	await expect( card ).toBeVisible();
	await expect( card ).toBeEnabled();
	await expect( card ).toHaveValue( CARD_GATEWAY_ID );
	await card.check();
	await expect( card ).toBeChecked();

	const cardFrame = page.locator( CARD_FRAME_SELECTOR );
	await expect( cardFrame ).toHaveCount( 1 );
	await expect( cardFrame ).toBeVisible();
	await expect( cardFrame ).not.toHaveAttribute( 'aria-hidden', 'true' );
	await expect( cardFrame ).toHaveAttribute( 'title', /\S/ );
}

test(
	'a populated native Blocks checkout keeps Card ready and exposes an accessible WooPay express action when the persisted merchant setting is enabled',
	{
		annotation: CONTRACT_IDS.map( ( description ) => ( {
			type: 'woopayments-contract',
			description,
		} ) ),
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { adminApi, page, runId } ) => {
		const stripeOracle =
			process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE === 'true'
				? startStrictStripeAdapterBrowserOracle( page )
				: null;
		const settings = await readJson< Record< string, unknown > >(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( settings.is_wcpay_enabled ).toBe( true );
		const originalWooPayEnabled = requireBoolean(
			settings.is_woopay_enabled,
			'is_woopay_enabled'
		);
		const product = await createProduct( adminApi, runId );
		const cleanupErrors: Error[] = [];

		try {
			await page.context().clearCookies();
			await page.goto( `?add-to-cart=${ product.id }` );
			await page.goto( 'checkout/' );
			await assertCardReady( page );
			await stripeOracle?.assertPaymentMounted(
				'#wcpay-core-blocks-payment-element'
			);

			await enableWooPayIfNeeded( adminApi, originalWooPayEnabled );
			const enabledSettings = await readJson< Record< string, unknown > >(
				await adminApi.get( PAYMENTS_SETTINGS_API ),
				'WooPay enabled-state readback'
			);
			expect( enabledSettings.is_woopay_enabled ).toBe( true );

			await page.reload();
			await assertCardReady( page );
			await stripeOracle?.assertPaymentMounted(
				'#wcpay-core-blocks-payment-element'
			);
			const wooPayAction = page.locator( '.woopay-express-button' );
			await expect( wooPayAction ).toHaveCount( 1 );
			await expect( wooPayAction ).toBeVisible();
			await expect( wooPayAction ).toBeEnabled();
			await expect( wooPayAction ).toHaveAttribute(
				'aria-label',
				/WooPay/i
			);
		} finally {
			try {
				await emptyCart( page );
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			try {
				await restoreWooPaySetting( adminApi, originalWooPayEnabled );
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			try {
				await readJson(
					await adminApi.delete(
						`${ PRODUCTS_API }/${ product.id }`,
						{
							data: { force: true },
						}
					),
					'Checkout-readiness product deletion'
				);
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			assertCleanupSucceeded( cleanupErrors );
		}
	}
);
