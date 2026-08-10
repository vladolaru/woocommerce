import type { APIRequestContext, Locator, Page } from '@playwright/test';

import {
	expect,
	submitBlocksCheckout,
	tags,
	test,
} from '../../../fixtures/woopayments-native';
import { customer } from '../../../test-data/data';

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-cart-coupon.spec.ts:53::Checkout with free coupon & after modifying cart on Checkout page › Checkout with a free coupon';

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const COUPONS_ENABLED_API =
	'/wp-json/wc/v3/settings/general/woocommerce_enable_coupons';
// The suite's run-ownership stamp, the same key the pilot runtime writes on
// products it creates and orders it claims.
const RUN_META_KEY = '_e2e_woopayments_run_id';
const PRODUCT_PRICE = '13.00';

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

async function createRunOwnedProduct(
	adminApi: APIRequestContext,
	runId: string
): Promise< { id: number; name: string } > {
	const name = `WooPayments zero-total E2E ${ runId }`;
	const product = await readJson(
		await adminApi.post( '/wp-json/wc/v3/products', {
			data: {
				name,
				type: 'simple',
				virtual: true,
				regular_price: PRODUCT_PRICE,
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			},
		} ),
		'Run-owned product creation'
	);
	if ( typeof product.id !== 'number' ) {
		throw new Error(
			'Run-owned product response did not contain a numeric ID.'
		);
	}
	return { id: product.id, name };
}

async function createRunOwnedCoupon(
	adminApi: APIRequestContext,
	runId: string,
	productId: number
): Promise< { id: number; code: string } > {
	// Restricted to the run-owned product, so a concurrent run or leftover
	// cart content on the shared store can never be discounted by it.
	const coupon = await readJson(
		await adminApi.post( '/wp-json/wc/v3/coupons', {
			data: {
				code: runId,
				discount_type: 'percent',
				amount: '100',
				product_ids: [ productId ],
				meta_data: [ { key: RUN_META_KEY, value: runId } ],
			},
		} ),
		'Run-owned coupon creation'
	);
	if ( typeof coupon.id !== 'number' || typeof coupon.code !== 'string' ) {
		throw new Error(
			'Run-owned coupon response did not contain a numeric ID and normalized code.'
		);
	}
	return { id: coupon.id, code: coupon.code };
}

async function deleteRunOwnedResource(
	adminApi: APIRequestContext,
	resourcePath: string,
	resourceId: number,
	description: string
): Promise< void > {
	const response = await adminApi.delete(
		`/wp-json/wc/v3/${ resourcePath }/${ resourceId }`,
		{ data: { force: true }, failOnStatusCode: false }
	);
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } cleanup failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
}

// The same login the harness fixtures use, including the identity proof that
// the session belongs to the seeded customer.
async function logInAsCustomer( page: Page ): Promise< void > {
	await page.context().clearCookies();
	await page.goto( 'wp-login.php' );
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
	await expect(
		firstName.or( savedAddressEdit ).first()
	).toBeVisible();
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
 * Record browser-side requests to the provider's transacting hosts. This is
 * the client half of the no-provider proof — the payment element never
 * mounted and the page opened no payment exchange — while the server half is
 * the order's missing provider-identity metadata.
 *
 * The provider's script host is deliberately excluded. Native enqueues the
 * provider SDK on every Blocks checkout before the cart total is known, so it
 * loads even here; fetching a script creates no PaymentIntent and is not what
 * this contract forbids. Only hosts that carry payment exchanges count.
 */
const PROVIDER_TRANSACTING_HOSTS = [ 'api.stripe.com', 'm.stripe.com' ];

function trackProviderClientRequests( page: Page ): () => string[] {
	const providerRequests: string[] = [];
	page.on( 'request', ( request ) => {
		try {
			const { hostname } = new URL( request.url() );
			if ( PROVIDER_TRANSACTING_HOSTS.includes( hostname ) ) {
				providerRequests.push(
					`${ request.method() } ${ hostname }`
				);
			}
		} catch {
			// Unparsable URLs cannot be provider requests.
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
	async ( { adminApi, page, runId } ) => {
		// Precondition guards, before anything run-owned exists. The
		// no-provider claim is only meaningful on a store whose provider
		// gateway is actually enabled and would otherwise be in the path;
		// and the coupon journey needs coupons on.
		const paymentsSettings = await readJson(
			await adminApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings read'
		);
		expect( paymentsSettings.is_wcpay_enabled ).toBe( true );
		const couponsEnabled = await readJson(
			await adminApi.get( COUPONS_ENABLED_API ),
			'Coupons setting read'
		);
		expect( couponsEnabled.value ).toBe( 'yes' );

		const product = await createRunOwnedProduct( adminApi, runId );
		let couponId: number | undefined;
		let primaryError: unknown;
		try {
			const coupon = await createRunOwnedCoupon(
				adminApi,
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

			// Place the order through the suite's dispatch-proving submit;
			// no provider journal wraps it, because nothing here may reach
			// the provider — that is the contract.
			await submitBlocksCheckout( page, async ( button ) => {
				await button.click();
				return 'dispatched';
			} );
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
			const stampResponse = await adminApi.put(
				`/wp-json/wc/v3/orders/${ orderId }`,
				{
					data: {
						meta_data: [
							{ key: RUN_META_KEY, value: runId },
						],
					},
				}
			);
			if ( ! stampResponse.ok() ) {
				throw new Error(
					`Unable to attach the run ID to order ${ orderId }: HTTP ${ stampResponse.status() }.`
				);
			}

			const order = await readJson(
				await adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
				'Zero-total order read'
			);
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
			throw error;
		} finally {
			// Run-owned fixtures are deleted even when the journey failed;
			// the order itself is deliberately left behind, stamped with the
			// run ID, matching how provider specs leave their claimed orders.
			const cleanupErrors: Error[] = [];
			if ( couponId !== undefined ) {
				try {
					await deleteRunOwnedResource(
						adminApi,
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
					adminApi,
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
					throw cleanupErrors[ 0 ];
				} else {
					throw new AggregateError(
						cleanupErrors,
						'Run-owned fixture cleanup failed.'
					);
				}
			}
		}
	}
);
