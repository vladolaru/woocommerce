import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { logIn } from '../../../utils/login';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:117::Saved cards › When using a basic card added on checkout › should not allow guest user to save the card';

const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const GUEST_CHECKOUT_SETTING_API =
	'wc/v3/settings/account/woocommerce_enable_guest_checkout';
const SAVED_CARD_EVIDENCE_API = 'wc-native-payments-e2e/v1/saved-card-evidence';

// The provider's transaction-creating endpoints only. Mounting the Card
// element legitimately fetches the Elements session configuration from other
// api.stripe.com routes on a real store, and Stripe.js reaches its telemetry
// host (m.stripe.com) on every load regardless of intent, so the guard names
// only the calls that actually create a transaction object; neither the
// positive control nor the guest journey below ever submits the checkout, so
// nothing here may reach any of them.
const STRIPE_TRANSACTION_ENDPOINT_PATTERN =
	/^https:\/\/api\.stripe\.com\/v1\/(?:payment_intents|setup_intents|payment_methods|confirmation_tokens)(?:[/?]|$)/;

// Core blocks renders this exact control when a payment method offers saving
// and the shopper has (or will have) an account; its absence for a plain guest
// is the contract under test.
const SAVE_CONTROL_LABEL =
	'Save payment information to my account for future purchases.';
// The wrapper class core blocks puts on the save control. Backstop only: the
// role-based zero-count above it excludes accessibility-hidden nodes, so this
// class count catches an aria-hidden-yet-focusable ghost the role query
// cannot see.
const SAVE_CONTROL_CLASS =
	'.wc-block-components-payment-methods__save-card-info';
// Rendered by the contact-information block only for an anonymous session on
// a store that permits guest checkout, so it discriminates a real guest from
// a logged-in shopper, unlike the unconditionally-rendered email field.
const GUEST_NOTICE = 'You are currently checking out as a guest.';

// One run-stable virtual product and one run-stable customer owned by this
// smoke. Both are established idempotently on every run; nothing is deleted.
const PRODUCT_SLUG = 'woopayments-guest-save-smoke';
const PRODUCT_NAME = 'WooPayments guest-save smoke';
const PRODUCT_PRICE = '10.00';
const CUSTOMER_USERNAME = 'woopayments-guest-save-smoke-customer';
const CUSTOMER_EMAIL = `${ CUSTOMER_USERNAME }@example.com`;
const CUSTOMER_PASSWORD = 'woopayments-guest-save-smoke-password';

function isolatedBrowserContextOptions( baseURL: string ) {
	return {
		baseURL,
		storageState: { cookies: [], origins: [] },
	};
}

function cardFrameRuntime(): 'client' | 'native' {
	const runtime = process.env.WCPAY_RUNTIME;
	if ( runtime !== 'client' && runtime !== 'native' ) {
		throw new Error(
			'WCPAY_RUNTIME must be client or native for this smoke.'
		);
	}
	return runtime;
}

// The Blocks Card element's iframe for the given runtime. This readonly spec
// is never provider-involved, so it carries no import from
// utils/woopayments-native/drivers (the routing validator treats that
// directory as provider machinery); these are the same two selectors
// drivers/checkout.ts's getBlocksCardFrameSelector returns.
function blocksCardFrameSelector( runtime: 'client' | 'native' ): string {
	return runtime === 'client'
		? '#payment-method .wcpay-payment-element iframe[name^="__privateStripeFrame"]'
		: '#wcpay-core-blocks-payment-element iframe[name^="__privateStripeFrame"]';
}

async function ensureSmokeProduct( restApi: ApiClient ): Promise< number > {
	const existing = (
		await restApi.get< Array< { id: number; status: string } > >(
			'wc/v3/products',
			{ slug: PRODUCT_SLUG, status: 'any' }
		)
	).data;
	if ( existing.length > 0 ) {
		// A same-slug product in any other status would make a fresh create
		// silently take a suffixed slug and orphan a product per run; fail at
		// the drift instead.
		if ( existing[ 0 ].status !== 'publish' ) {
			throw new Error(
				`Smoke product slug is occupied by a ${ existing[ 0 ].status } product; expected publish.`
			);
		}
		return existing[ 0 ].id;
	}

	const created = (
		await restApi.post< { id: number } >( 'wc/v3/products', {
			name: PRODUCT_NAME,
			slug: PRODUCT_SLUG,
			type: 'simple',
			virtual: true,
			regular_price: PRODUCT_PRICE,
			status: 'publish',
		} )
	).data;
	return created.id;
}

/**
 * Establish the run-stable smoke customer with a known password. The password
 * is re-asserted on every run because a pre-existing customer's password is
 * not readable, and the positive-control login below depends on it. The reset
 * only ever touches the smoke's own fixture: a username match with a foreign
 * email means this store is not the dedicated one this spec assumes, and the
 * run must stop rather than reassign a real account's credentials.
 */
async function ensureSmokeCustomer( restApi: ApiClient ): Promise< void > {
	const existing = (
		await restApi.get<
			Array< { id: number; username: string; email: string } >
		>( 'wc/v3/customers', { role: 'all', search: CUSTOMER_USERNAME } )
	).data;
	const match = existing.find(
		( customer ) => customer.username === CUSTOMER_USERNAME
	);

	if ( match ) {
		if ( match.email !== CUSTOMER_EMAIL ) {
			throw new Error(
				'Smoke customer username is taken by an account with a different email; refusing to reset its password.'
			);
		}
		await restApi.put( `wc/v3/customers/${ match.id }`, {
			password: CUSTOMER_PASSWORD,
		} );
		return;
	}

	await restApi.post( 'wc/v3/customers', {
		email: CUSTOMER_EMAIL,
		username: CUSTOMER_USERNAME,
		password: CUSTOMER_PASSWORD,
		first_name: 'Guest-save',
		last_name: 'Smoke',
	} );
}

/**
 * Count orders containing the smoke product. Scoping to the run-owned product
 * keeps the counter exact for this smoke even if unrelated activity creates
 * orders on the store concurrently.
 */
async function countSmokeProductOrders(
	restApi: ApiClient,
	productId: number
): Promise< number > {
	const response = await restApi.get( 'wc/v3/orders', {
		product: productId,
		per_page: 1,
	} );
	const total = Number( response.headers[ 'x-wp-total' ] );
	if ( ! Number.isSafeInteger( total ) || total < 0 ) {
		throw new Error( 'Order count did not return a usable X-WP-Total.' );
	}
	return total;
}

async function countCustomerTokens( restApi: ApiClient ): Promise< number > {
	const evidence = (
		await restApi.get< { tokens?: unknown } >( SAVED_CARD_EVIDENCE_API, {
			customer_username: CUSTOMER_USERNAME,
		} )
	).data;
	if ( ! Array.isArray( evidence.tokens ) ) {
		throw new Error(
			'Saved-card evidence did not return a token list for the smoke customer.'
		);
	}
	return evidence.tokens.length;
}

function saveControl( page: Page ) {
	return page.getByRole( 'checkbox', {
		name: SAVE_CONTROL_LABEL,
		exact: true,
	} );
}

/**
 * Count POSTs to the Store API checkout route so the test can prove it never
 * dispatched a checkout submission from either browsing context. Matches the
 * pretty-permalink path and the rest_route query form.
 */
function trackCheckoutSubmissions( page: Page ): () => number {
	let submissionCount = 0;
	page.on( 'request', ( request ) => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		try {
			const url = new URL( request.url() );
			const restRoute = url.searchParams.get( 'rest_route' ) ?? '';
			if (
				url.pathname.replace( /\/+$/, '' ) ===
					'/wp-json/wc/store/v1/checkout' ||
				restRoute.replace( /\/+$/, '' ) === '/wc/store/v1/checkout'
			) {
				submissionCount++;
			}
		} catch {
			// Unparsable URLs cannot be the Store API checkout endpoint.
		}
	} );
	return () => submissionCount;
}

/**
 * Record browser-side requests to the provider's transaction-creating
 * endpoints. Neither journey below submits the checkout, so nothing on
 * either page may reach them. A synchronous `request` listener, not
 * `page.route`: it needs no await, intercepts nothing, and leaves the page's
 * HTTP cache alone.
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
 * on the page was rejected and swallowed. The adapter file itself is kept
 * per D7; this only reads the telemetry it already writes.
 */
async function assertStripeAdapterFixtureIntegrity(
	page: Page,
	runtime: 'client' | 'native'
): Promise< void > {
	if ( process.env.E2E_WOOPAYMENTS_NATIVE_FIXTURE !== 'true' ) {
		return;
	}
	const mount =
		runtime === 'client'
			? '#payment-method .wcpay-payment-element'
			: '#wcpay-core-blocks-payment-element';
	const adapter = await page.evaluate( () => ( {
		errors: Reflect.get( window, '__wooPaymentsStripeAdapterErrors' ),
		calls: Reflect.get( window, '__wooPaymentsStripePaymentCalls' ),
	} ) );
	expect( adapter.errors ).toEqual( [] );
	expect( adapter.calls ).toContainEqual(
		expect.objectContaining( {
			type: 'payment',
			mount,
			lifecycle: expect.arrayContaining( [ 'mount' ] ),
		} )
	);
}

/**
 * Drive a context to the blocks checkout with the smoke product in the cart
 * and wait until the card payment surface is interactive: the provider frame
 * is visible, exposed to assistive technology, and accessibly named.
 */
async function openCheckoutWithProduct(
	page: Page,
	productId: number
): Promise< void > {
	await page.goto( `?add-to-cart=${ productId }` );
	await page.goto( 'checkout/' );
	const runtime = cardFrameRuntime();
	const cardFrame = page
		.locator( blocksCardFrameSelector( runtime ) )
		.first();
	await expect( cardFrame ).toBeVisible();
	await expect( cardFrame ).not.toHaveAttribute( 'aria-hidden', 'true' );
	await expect( cardFrame ).toHaveAttribute( 'title', /\S/ );
	await assertStripeAdapterFixtureIntegrity( page, runtime );
}

test(
	'guest checkout offers no credential persistence while card payment stays operable',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, browser, baseURL } ) => {
		await ensureSmokeCustomer( restApi );
		const productId = await ensureSmokeProduct( restApi );

		// Precondition guard: the absence assertion below is vacuous unless
		// this store actually offers credential persistence to eligible
		// shoppers and permits guest checkout at all. A store where saved
		// cards or the gateway silently deactivated must fail here, loudly,
		// not pass by asserting an absence that means nothing. WooPay must be
		// off because it suppresses the save control even for logged-in
		// shoppers, which would kill the positive control opaquely.
		const paymentsSettings = (
			await restApi.get< Record< string, unknown > >(
				PAYMENTS_SETTINGS_API
			)
		).data;
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
		expect( paymentsSettings.is_saved_cards_enabled ).toBe( true );
		expect( paymentsSettings.is_woopay_enabled ).toBe( false );
		const guestCheckoutSetting = (
			await restApi.get< { value?: unknown } >(
				GUEST_CHECKOUT_SETTING_API
			)
		).data;
		expect( guestCheckoutSetting.value ).toBe( 'yes' );

		const ordersBefore = await countSmokeProductOrders(
			restApi,
			productId
		);
		const tokensBefore = await countCustomerTokens( restApi );

		// Positive control: a logged-in customer on the same checkout sees
		// and can operate the save control, proving the oracle can detect
		// the control and that the store state genuinely offers persistence
		// to account holders. Without this, the guest-side zero-count could
		// pass on a surface that never renders the control for anyone.
		const customerContext = await browser.newContext(
			isolatedBrowserContextOptions( baseURL )
		);
		try {
			const customerPage = await customerContext.newPage();
			const customerStripeRequests =
				trackStripeTransactionRequests( customerPage );
			const customerSubmissions =
				trackCheckoutSubmissions( customerPage );
			await customerPage.goto( 'wp-login.php' );
			// Not an admin: no Dashboard to land on, so the standard success
			// assertion is skipped in favor of the checkout journey below.
			await logIn(
				customerPage,
				CUSTOMER_USERNAME,
				CUSTOMER_PASSWORD,
				false
			);
			await customerPage.waitForURL( /my-account|wp-admin/ );
			await openCheckoutWithProduct( customerPage, productId );
			await expect( saveControl( customerPage ) ).toBeVisible();
			// The control is genuinely operable, not just rendered: it
			// starts opted-out, accepts a check, and accepts the uncheck
			// that restores the safe default.
			await expect( saveControl( customerPage ) ).not.toBeChecked();
			await saveControl( customerPage ).check();
			await expect( saveControl( customerPage ) ).toBeChecked();
			await saveControl( customerPage ).uncheck();
			await expect( saveControl( customerPage ) ).not.toBeChecked();
			expect( customerSubmissions() ).toBe( 0 );
			expect( customerStripeRequests() ).toEqual( [] );
		} finally {
			await customerContext.close();
		}

		// Contract: a fresh anonymous guest gets an operable card payment
		// surface with no way to request credential persistence and no copy
		// implying one exists.
		const guestContext = await browser.newContext(
			isolatedBrowserContextOptions( baseURL )
		);
		try {
			const guestPage = await guestContext.newPage();
			const guestStripeRequests =
				trackStripeTransactionRequests( guestPage );
			const guestSubmissions = trackCheckoutSubmissions( guestPage );
			await openCheckoutWithProduct( guestPage, productId );
			// The session truly is a guest one: this notice renders only for an
			// anonymous shopper on a store that permits guest checkout.
			await expect(
				guestPage.getByText( GUEST_NOTICE ).first()
			).toBeVisible();
			// The full checkout form has rendered before any absence is read:
			// the submit control is the last piece of the checkout block tree.
			await expect(
				guestPage.getByRole( 'button', { name: /place order/i } )
			).toBeVisible();
			await expect( saveControl( guestPage ) ).toHaveCount( 0 );
			await expect( guestPage.getByRole( 'switch' ) ).toHaveCount( 0 );
			await expect( guestPage.locator( SAVE_CONTROL_CLASS ) ).toHaveCount(
				0
			);
			await expect(
				guestPage
					.getByText( /future purchases/i )
					.filter( { visible: true } )
			).toHaveCount( 0 );
			expect( guestSubmissions() ).toBe( 0 );
			expect( guestStripeRequests() ).toEqual( [] );
		} finally {
			await guestContext.close();
		}

		// Zero-dispatch proof: no checkout was submitted, no order exists
		// for the run-owned product beyond what preceded the run, and the
		// smoke customer's saved tokens are untouched. A guest has no user
		// record to hang a token on, so the customer-scoped token counter is
		// the closest local-record proxy this surface offers.
		expect( await countSmokeProductOrders( restApi, productId ) ).toBe(
			ordersBefore
		);
		expect( await countCustomerTokens( restApi ) ).toBe( tokensBefore );
	}
);
