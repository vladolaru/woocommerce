import { randomUUID } from 'node:crypto';

import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { ADMIN_STATE_PATH } from '../../../playwright.config';

test.use( { storageState: ADMIN_STATE_PATH } );

const CONTRACT_IDS = [
	'performance::chromium::tests/e2e/specs/performance/payment-methods.spec.ts:42::Checkout page performance › Stripe › measures averaged page load metrics',
	'performance::chromium::tests/e2e/specs/performance/payment-methods.spec.ts:71::Checkout page performance › WooPay › measures averaged page load metrics',
];

const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const PRODUCTS_API = 'wc/v3/products';
const CARD_GATEWAY_ID = 'woocommerce_payments';
// The native Blocks Card element's iframe. This readonly spec is never
// provider-involved, so it carries no import from
// utils/woopayments-native/drivers (the routing validator treats that
// directory as provider machinery); the selector is the same one
// drivers/checkout.ts's getBlocksCardFrameSelector returns for 'native'.
const CARD_FRAME_SELECTOR =
	'#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]';

// The provider's transaction-creating endpoints only. Mounting the Card
// element legitimately fetches the Elements session configuration from other
// api.stripe.com routes on a real store, and Stripe.js reaches its telemetry
// host (m.stripe.com) on every load regardless of intent, so the guard names
// only the calls that actually create a transaction object; toggling the
// express action never needs to reach any of them.
const STRIPE_TRANSACTION_ENDPOINT_PATTERN =
	/^https:\/\/api\.stripe\.com\/v1\/(?:payment_intents|setup_intents|payment_methods|confirmation_tokens)(?:[/?]|$)/;

function requireBoolean( value: unknown, description: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		throw new Error( `${ description } was not a boolean.` );
	}
	return value;
}

/**
 * Record requests to the provider's transaction-creating endpoints. A
 * synchronous `request` listener, not `page.route`: it needs no await,
 * intercepts nothing, and leaves the page's HTTP cache alone.
 */
function trackStripeTransactionRequests( page: Page ): () => string[] {
	const requests: string[] = [];
	page.on( 'request', ( request ) => {
		if ( STRIPE_TRANSACTION_ENDPOINT_PATTERN.test( request.url() ) ) {
			requests.push(
				`${ request.method() } ${ new URL( request.url() ).hostname }`
			);
		}
	} );
	return () => [ ...requests ];
}

/**
 * Fixture-mode-only proof that the local Stripe adapter — not a real
 * Stripe.js — is what mounted the Card element, and that no Stripe.js call
 * on the page was rejected and swallowed (the WooPay express-availability
 * probe is the one place that can happen). The adapter file itself is kept
 * per D7; this only reads the telemetry it already writes.
 */
async function assertStripeAdapterFixtureIntegrity(
	page: Page
): Promise< void > {
	if ( process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE !== 'true' ) {
		return;
	}
	const adapter = await page.evaluate( () => ( {
		errors: Reflect.get( window, '__wooPaymentsStripeAdapterErrors' ),
		calls: Reflect.get( window, '__wooPaymentsStripePaymentCalls' ),
	} ) );
	expect( adapter.errors ).toEqual( [] );
	expect( adapter.calls ).toContainEqual(
		expect.objectContaining( {
			type: 'payment',
			mount: '#wcpay-core-blocks-payment-element',
			lifecycle: expect.arrayContaining( [ 'mount' ] ),
		} )
	);
}

async function enableWooPayIfNeeded(
	restApi: ApiClient,
	alreadyEnabled: boolean
): Promise< void > {
	if ( alreadyEnabled ) {
		return;
	}
	await restApi.post( PAYMENTS_SETTINGS_API, { is_woopay_enabled: true } );
}

async function restoreWooPaySetting(
	restApi: ApiClient,
	originalValue: boolean
): Promise< void > {
	if ( ! originalValue ) {
		await restApi.post( PAYMENTS_SETTINGS_API, {
			is_woopay_enabled: false,
		} );
	}
	const restored = (
		await restApi.get< Record< string, unknown > >( PAYMENTS_SETTINGS_API )
	).data;
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
	restApi: ApiClient,
	runId: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments checkout readiness ${ runId }`;
	const product = (
		await restApi.post< { id?: unknown; name?: unknown } >( PRODUCTS_API, {
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
		} )
	).data;
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
	async ( { restApi, page } ) => {
		const runId = `woopayments-${ randomUUID() }`;
		const stripeTransactionRequests =
			trackStripeTransactionRequests( page );
		const settings = (
			await restApi.get< Record< string, unknown > >(
				PAYMENTS_SETTINGS_API
			)
		).data;
		expect( settings.is_wcpay_enabled ).toBe( true );
		const originalWooPayEnabled = requireBoolean(
			settings.is_woopay_enabled,
			'is_woopay_enabled'
		);
		const product = await createProduct( restApi, runId );
		const cleanupErrors: Error[] = [];

		try {
			await page.context().clearCookies();
			await page.goto( `?add-to-cart=${ product.id }` );
			await page.goto( 'checkout/' );
			await assertCardReady( page );
			await assertStripeAdapterFixtureIntegrity( page );

			await enableWooPayIfNeeded( restApi, originalWooPayEnabled );
			const enabledSettings = (
				await restApi.get< Record< string, unknown > >(
					PAYMENTS_SETTINGS_API
				)
			).data;
			expect( enabledSettings.is_woopay_enabled ).toBe( true );

			await page.reload();
			await assertCardReady( page );
			await assertStripeAdapterFixtureIntegrity( page );
			const wooPayAction = page.locator( '.woopay-express-button' );
			await expect( wooPayAction ).toHaveCount( 1 );
			await expect( wooPayAction ).toBeVisible();
			await expect( wooPayAction ).toBeEnabled();
			await expect( wooPayAction ).toHaveAttribute(
				'aria-label',
				/WooPay/i
			);

			// Card readiness and the WooPay express action never dispatch to
			// the provider's transaction API on their own.
			expect( stripeTransactionRequests() ).toEqual( [] );
		} finally {
			try {
				await emptyCart( page );
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			try {
				await restoreWooPaySetting( restApi, originalWooPayEnabled );
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			try {
				await restApi.delete( `${ PRODUCTS_API }/${ product.id }`, {
					force: true,
				} );
			} catch ( error ) {
				collectCleanupError( cleanupErrors, error );
			}
			assertCleanupSucceeded( cleanupErrors );
		}
	}
);
