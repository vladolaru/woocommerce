import { randomUUID } from 'node:crypto';

import type { Locator, Page, Request } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { logIn } from '../../../utils/login';
import { customer } from '../../../test-data/data';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-cart-coupon.spec.ts:53::Checkout with free coupon & after modifying cart on Checkout page › Checkout with a free coupon';

const PAYMENTS_SETTINGS_API = 'wc/v3/payments/settings';
const COUPONS_ENABLED_API = 'wc/v3/settings/general/woocommerce_enable_coupons';
// The suite's run-ownership stamp, the same key the pilot runtime writes on
// products it creates and orders it claims.
const RUN_META_KEY = '_e2e_woopayments_run_id';
const PRODUCT_PRICE = '13.00';

// The provider's transaction-dispatch host. A zero-total cart never mounts a
// payment element at all, so unlike the readiness and saved-methods guards
// this one keeps the whole host rather than narrowing to specific endpoints:
// no legitimate Elements-session traffic exists to allow for. Stripe.js's
// telemetry host (m.stripe.com) is excluded on purpose — it is reached from a
// loaded-but-unused SDK, not from a transaction, matching the deleted
// isStripeTransactionHost unit test's contract.
const STRIPE_TRANSACTION_HOST_PATTERN = /^https:\/\/api\.stripe\.com\//;

// The order metadata a provider-touched order carries in the native runtime.
// Every charge path writes `_intent_id` from the outcome's provider payment
// id (WooPaymentsOutcomeMetadataMapper / WooPaymentsOrderEffectApplier), and
// the sibling keys ride along whenever a provider object existed. The
// zero-total path (PaymentProcessingService STATUS_NO_EXTERNAL_PAYMENT)
// constructs its outcome with no provider identity at all, so a zero-total
// order that dispatched no intent carries none of these. `_intention_status`
// is deliberately absent from this list: the mapper writes it even for the
// no-external-payment outcome, so it is not provider-identity evidence.
const PROVIDER_IDENTITY_META_KEYS = [
	'_intent_id',
	'_charge_id',
	'_payment_method_id',
	'_stripe_customer_id',
	'_wcpay_payment_transaction_id',
	'_wcpay_intent_currency',
];

async function createRunOwnedProduct(
	restApi: ApiClient,
	runId: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments zero-total E2E ${ runId }`;
	const product = (
		await restApi.post< { id?: unknown } >( 'wc/v3/products', {
			name,
			type: 'simple',
			virtual: true,
			regular_price: PRODUCT_PRICE,
			meta_data: [ { key: RUN_META_KEY, value: runId } ],
		} )
	).data;
	if ( typeof product.id !== 'number' ) {
		throw new Error(
			'Run-owned product response did not contain a numeric ID.'
		);
	}
	return { id: product.id, name };
}

async function createRunOwnedCoupon(
	restApi: ApiClient,
	runId: string,
	productId: number
): Promise< { id: number; code: string } > {
	// Restricted to the run-owned product, so a concurrent run or leftover
	// cart content on the shared store can never be discounted by it.
	const coupon = (
		await restApi.post< { id?: unknown; code?: unknown } >(
			'wc/v3/coupons',
			{
				code: runId,
				discount_type: 'percent',
				amount: '100',
				product_ids: [ productId ],
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			}
		)
	).data;
	if ( typeof coupon.id !== 'number' || typeof coupon.code !== 'string' ) {
		throw new Error(
			'Run-owned coupon response did not contain a numeric ID and normalized code.'
		);
	}
	return { id: coupon.id, code: coupon.code };
}

async function deleteRunOwnedResource(
	restApi: ApiClient,
	resourcePath: string,
	resourceId: number,
	description: string
): Promise< void > {
	try {
		await restApi.delete( `wc/v3/${ resourcePath }/${ resourceId }`, {
			force: true,
		} );
	} catch ( error ) {
		throw new Error( `${ description } cleanup failed.`, { cause: error } );
	}
}

// The same login the shared customer journeys use, including the identity
// proof that the session belongs to the seeded customer.
async function logInAsCustomer( page: Page ): Promise< void > {
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
	// Not an admin: no Dashboard to land on, so the standard success
	// assertion is skipped in favor of the identity read below.
	await logIn( page, customer.username, customer.password, false );
	await page.goto( 'my-account/edit-account/' );
	await expect(
		page.getByRole( 'textbox', { name: /Email address/i } )
	).toHaveValue( customer.email );
}

/**
 * Empty the shared customer's persistent cart. The zero-total property below
 * is about this run's own cart, and the run-restricted coupon deliberately
 * cannot discount anything else — so an item left behind by an interrupted
 * earlier run would surface as a nonzero total. Clearing first makes each run
 * self-healing instead of poisoned by its predecessor, which is the session
 * residue the original client spec's teardown could miss.
 */
async function resetCustomerCart( page: Page ): Promise< void > {
	await page.goto( 'cart/' );
	const emptyNotice = page.getByText( 'Your cart is currently empty!' );
	const removeButtons = page.getByRole( 'button', {
		name: /^Remove .+ from cart$/,
	} );
	// The cart block hydrates client-side; wait for one of its two terminal
	// states before deciding whether anything needs removing.
	await expect(
		emptyNotice.or( removeButtons.first() ).first()
	).toBeVisible();
	for (
		let guard = 0;
		guard < 20 && ! ( await emptyNotice.isVisible() );
		guard++
	) {
		const countBefore = await removeButtons.count();
		await removeButtons.first().click();
		await expect
			.poll( () => removeButtons.count(), {
				message:
					'Removing a cart line item must reduce the removable line items.',
			} )
			.toBeLessThan( countBefore );
	}
	await expect( emptyNotice ).toBeVisible();
}

/**
 * Satisfy the checkout's contact and billing requirements for the logged-in
 * customer. A customer with a complete saved address gets an address card
 * instead of the form, and that card already satisfies checkout — only a
 * visible form is filled.
 */
async function ensureContactAndBillingDetails( page: Page ): Promise< void > {
	const email = page.getByRole( 'textbox', { name: 'Email address' } );
	await expect( email ).toBeVisible();
	if ( ( await email.inputValue() ) === '' ) {
		await email.fill( customer.email );
	}

	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	await expect( billing ).toBeVisible();
	const firstName = billing.getByRole( 'textbox', { name: 'First name' } );
	const savedAddressEdit = billing.getByRole( 'button', { name: /edit/i } );
	await expect( firstName.or( savedAddressEdit ).first() ).toBeVisible();
	if ( ! ( await firstName.isVisible() ) ) {
		return;
	}
	await billing
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( customer.billing.us.country );
	await firstName.fill( customer.billing.us.first_name );
	await billing
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( customer.billing.us.last_name );
	await billing
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( customer.billing.us.address );
	await billing
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( customer.billing.us.city );
	await billing
		.getByRole( 'combobox', { name: 'State', exact: true } )
		.selectOption( customer.billing.us.state );
	await billing
		.getByRole( 'textbox', { name: 'ZIP Code' } )
		.fill( customer.billing.us.zip );
	// Present whether the store renders it optional or required; filled
	// whenever the form offers it so a phone-requiring configuration cannot
	// stall the submit.
	const phone = billing.getByRole( 'textbox', { name: /^Phone/ } );
	if ( await phone.isVisible() ) {
		await phone.fill( customer.billing.us.phone );
	}
}

/**
 * Assert the totals footer inside the given cart or checkout block reports a
 * zero total. This is the half the original client spec never asserted: it
 * placed the order without ever proving the discount had actually zeroed the
 * amount.
 */
async function expectZeroTotal( blockScope: Locator ): Promise< void > {
	const totalRow = blockScope.locator(
		'.wc-block-components-totals-footer-item'
	);
	// Cart and checkout label this row differently ("Estimated total" before
	// an address is known, "Total" after), so match the shared word rather
	// than either surface's exact phrasing.
	await expect( totalRow ).toContainText( /total/i );
	await expect(
		totalRow.locator( '.wc-block-components-totals-item__value' )
	).toHaveText( /(^|\D)0(?:[.,]00)?(\D|$)/ );
}

/**
 * Record browser-side requests to the provider's transaction API. This is
 * the client half of the no-provider proof — the payment element never
 * mounted and the page opened no payment exchange — while the server half is
 * the order's missing provider-identity metadata.
 *
 * The provider's script host is deliberately excluded. Native enqueues the
 * provider SDK on every Blocks checkout before the cart total is known, so it
 * loads even here; fetching a script or emitting SDK telemetry creates no
 * PaymentIntent and is not what this contract forbids.
 *
 * A synchronous `request` listener, not `page.route`: it needs no await,
 * intercepts nothing, and leaves the page's HTTP cache alone.
 */
function trackProviderClientRequests( page: Page ): () => string[] {
	const providerRequests: string[] = [];
	page.on( 'request', ( request ) => {
		try {
			const { hostname } = new URL( request.url() );
			if ( STRIPE_TRANSACTION_HOST_PATTERN.test( request.url() ) ) {
				providerRequests.push( `${ request.method() } ${ hostname }` );
			}
		} catch {
			// Unparsable URLs cannot be a provider transaction request.
		}
	} );
	return () => [ ...providerRequests ];
}

function getOrderIdFromUrl( url: string ): number {
	const match = url.match( /order-received\/(\d+)/ );
	if ( ! match ) {
		throw new Error(
			`Checkout confirmation did not expose a durable order ID: ${ url }`
		);
	}
	return Number( match[ 1 ] );
}

/**
 * Click "Place order" and wait for a real dispatch signal — the Store API
 * checkout request, a Core checkout/payment state change, or navigation away
 * from checkout — before treating the click as sent. This is a local,
 * call-site-scoped copy of the dispatch-wait race in
 * drivers/checkout.ts's submitBlocksCheckout: this readonly spec is never
 * provider-involved, so it carries no import from
 * utils/woopayments-native/drivers (the routing validator treats that
 * directory as provider machinery). The retry-on-not-dispatched loop the
 * shared driver offers other callers is dropped: this journey's click always
 * dispatches, so a single attempt is the whole contract.
 */
async function submitZeroTotalCheckout( page: Page ): Promise< void > {
	const checkoutUrl = page.url();
	const button = page.getByRole( 'button', { name: /place order/i } );
	let resolveCheckoutRequest = () => {};
	const checkoutRequestStarted = new Promise< void >( ( resolve ) => {
		resolveCheckoutRequest = resolve;
	} );
	const countCheckoutRequest = ( request: Request ): void => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		try {
			if (
				new URL( request.url() ).pathname.replace( /\/+$/, '' ) ===
				'/wp-json/wc/store/v1/checkout'
			) {
				resolveCheckoutRequest();
			}
		} catch {
			// Unparsable URLs cannot be the Store API checkout endpoint.
		}
	};
	page.on( 'request', countCheckoutRequest );

	try {
		await button.click();
		const checkoutStateOrNavigationStarted = page.waitForFunction(
			( initialCheckoutUrl ) => {
				if ( window.location.href !== initialCheckoutUrl ) {
					return true;
				}
				type Selector = ( ...args: unknown[] ) => unknown;
				type Store = Record< string, Selector >;
				const wpData = (
					window as Window & {
						wp?: {
							data?: {
								select?: ( key: string ) => Store;
							};
						};
					}
				 ).wp?.data;
				const checkout = wpData?.select?.( 'wc/store/checkout' );
				const payment = wpData?.select?.( 'wc/store/payment' );
				return (
					checkout?.getCheckoutStatus?.() !== 'idle' ||
					checkout?.hasError?.() === true ||
					payment?.isPaymentIdle?.() === false ||
					payment?.hasPaymentError?.() === true
				);
			},
			checkoutUrl,
			{ timeout: 10_000 }
		);
		try {
			await Promise.race( [
				checkoutRequestStarted,
				checkoutStateOrNavigationStarted,
			] );
		} catch ( error ) {
			if ( page.url() !== checkoutUrl ) {
				return;
			}
			throw new Error(
				`WooPayments Blocks checkout was clicked, but no checkout request, Core state, or navigation signal was observed: ${
					error instanceof Error ? error.message : String( error )
				}`
			);
		}
	} finally {
		page.off( 'request', countCheckoutRequest );
	}
}

test(
	'a 100% coupon checkout completes a zero-total order without dispatching any provider payment intent',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: CONTRACT_ID,
			},
		],
		tag: [ tags.WOOPAYMENTS_NATIVE ],
	},
	async ( { restApi, page } ) => {
		const runId = `woopayments-${ randomUUID() }`;

		// Precondition guards, before anything run-owned exists. The
		// no-provider claim is only meaningful on a store whose provider
		// gateway is actually enabled and would otherwise be in the path;
		// and the coupon journey needs coupons on.
		const paymentsSettings = (
			await restApi.get< Record< string, unknown > >(
				PAYMENTS_SETTINGS_API
			)
		).data;
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
		const couponsEnabled = (
			await restApi.get< { value?: unknown } >( COUPONS_ENABLED_API )
		).data;
		expect( couponsEnabled.value ).toBe( 'yes' );

		const product = await createRunOwnedProduct( restApi, runId );
		let couponId: number | undefined;
		let primaryError: unknown;
		let cleanupFailure: Error | AggregateError | undefined;
		try {
			const coupon = await createRunOwnedCoupon(
				restApi,
				runId,
				product.id
			);
			couponId = coupon.id;

			await logInAsCustomer( page );
			await resetCustomerCart( page );

			// The shopper's cart: the run-owned product, fully discounted by
			// the run-owned coupon, applied in the cart — so by the time the
			// checkout page loads, the cart already needs no payment and the
			// payment step must never mount at all.
			await page.goto( `?add-to-cart=${ product.id }` );
			await page.goto( 'cart/' );
			const cartBlock = page.locator( '.wp-block-woocommerce-cart' );
			await expect(
				cartBlock.getByText( product.name ).first()
			).toBeVisible();
			await cartBlock
				.getByRole( 'button', { name: 'Add coupons' } )
				.click();
			await cartBlock
				.getByRole( 'textbox', { name: 'Enter code' } )
				.fill( coupon.code );
			await cartBlock
				.getByRole( 'button', { name: 'Apply', exact: true } )
				.click();
			// The applied-coupon chip carries the code; its appearance is the
			// application's terminal state.
			await expect(
				cartBlock.getByText( coupon.code ).first()
			).toBeVisible();
			await expectZeroTotal( cartBlock );

			const providerRequests = trackProviderClientRequests( page );
			await page.goto( 'checkout/' );

			// The checkout genuinely rendered this cart — order summary line,
			// zero total, submit control — before anything is asserted
			// absent. Without this, the no-payment-step assertions below
			// would pass on a checkout that failed to render at all.
			const checkoutBlock = page.locator(
				'.wp-block-woocommerce-checkout'
			);
			await expect(
				checkoutBlock.getByText( product.name ).first()
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: /place order/i } )
			).toBeVisible();
			await ensureContactAndBillingDetails( page );
			await expectZeroTotal( checkoutBlock );

			// The no-provider property on the surface: a cart that needs no
			// payment renders no payment step (the payment block returns
			// nothing when cartNeedsPayment is false), so no payment options
			// fieldset and no provider card element exist to collect
			// anything.
			await expect(
				page.getByRole( 'group', { name: 'Payment options' } )
			).toHaveCount( 0 );
			await expect(
				page.locator( 'iframe[name^="__privateStripeFrame"]' )
			).toHaveCount( 0 );

			// Place the order through the dispatch-proving submit; no
			// provider journal wraps it, because nothing here may reach the
			// provider — that is the contract.
			await submitZeroTotalCheckout( page );
			await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
				timeout: 60_000,
			} );
			await expect(
				page.getByText(
					/^(Your order has been received|Order received)$/i
				)
			).toBeVisible();
			expect( providerRequests() ).toEqual( [] );

			// Claim the order for this run first, so even a failure below
			// leaves it attributable, then read it back through the store's
			// own API for the durable half of the contract.
			const orderId = getOrderIdFromUrl( page.url() );
			await restApi.put( `wc/v3/orders/${ orderId }`, {
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			} );

			const order = (
				await restApi.get< Record< string, unknown > >(
					`wc/v3/orders/${ orderId }`
				)
			).data;
			// Completed, as an order state and not just a thank-you page: a
			// zero-total order needs no payment and lands in a paid status.
			expect( [ 'processing', 'completed' ] ).toContain( order.status );
			// The total is actually zero and actually the coupon's doing.
			expect( order.total ).toBe( '0.00' );
			expect( Number( order.discount_total ) ).toBeGreaterThan( 0 );
			expect(
				( order.coupon_lines as Array< { code?: unknown } > ).map(
					( line ) => line.code
				)
			).toEqual( [ coupon.code ] );
			// The no-provider property in the order record: no provider
			// transaction reference and none of the metadata every
			// provider-touched order carries.
			expect( order.transaction_id ).toBe( '' );
			const metaKeys = (
				order.meta_data as Array< { key?: unknown } >
			 ).map( ( meta ) => meta.key );
			expect(
				metaKeys.filter( ( key ) =>
					PROVIDER_IDENTITY_META_KEYS.includes( key as string )
				)
			).toEqual( [] );
			expect( metaKeys ).toContain( RUN_META_KEY );
		} catch ( error ) {
			primaryError = error;
		} finally {
			// Run-owned fixtures are deleted even when the journey failed;
			// the order itself is deliberately left behind, stamped with the
			// run ID, matching how provider specs leave their claimed orders.
			const cleanupErrors: Error[] = [];
			if ( couponId !== undefined ) {
				try {
					await deleteRunOwnedResource(
						restApi,
						'coupons',
						couponId,
						'Run-owned coupon'
					);
				} catch ( cleanupError ) {
					cleanupErrors.push(
						cleanupError instanceof Error
							? cleanupError
							: new Error( String( cleanupError ) )
					);
				}
			}
			try {
				await deleteRunOwnedResource(
					restApi,
					'products',
					product.id,
					'Run-owned product'
				);
			} catch ( cleanupError ) {
				cleanupErrors.push(
					cleanupError instanceof Error
						? cleanupError
						: new Error( String( cleanupError ) )
				);
			}
			if ( cleanupErrors.length > 0 ) {
				if ( primaryError !== undefined ) {
					for ( const cleanupError of cleanupErrors ) {
						console.error(
							'Run-owned fixture cleanup failed after the primary test failure:',
							cleanupError
						);
					}
				} else if ( cleanupErrors.length === 1 ) {
					cleanupFailure = cleanupErrors[ 0 ];
				} else {
					cleanupFailure = new AggregateError(
						cleanupErrors,
						'Run-owned fixture cleanup failed.'
					);
				}
			}
		}
		if ( primaryError !== undefined ) {
			throw primaryError;
		}
		if ( cleanupFailure ) {
			throw cleanupFailure;
		}
	}
);
