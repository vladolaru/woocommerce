import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpEvalJson } from '../../../utils/cli';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	hasStripeTestKey,
	StripeTestClock,
} from '../../../utils/stripe-test-clock';
import {
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `stripe-billing-subscriptions` provider-fidelity family (Task 6.3): a
 * subscription bought while Stripe Billing is on is billed by Stripe, and its
 * renewal reaches the store as an `invoice.paid` webhook.
 *
 * Client 11.1.0 has no browser test of Stripe Billing, so the oracle is its
 * mechanics: the purchase creates a Stripe subscription tagged
 * `subscription_source = woo_subscriptions` and pays the first invoice out of
 * band (`includes/subscriptions/class-wc-payments-subscription-service.php`
 * create_subscription, `class-wc-payments-invoice-service.php` mark paid out of
 * band), and a renewal order is created from `invoice.paid`, linked by
 * `_wcpay_billing_invoice_id` (`class-wc-payments-subscriptions-event-handler.php:141-156`).
 *
 * Local-only, like the other provider cases: Stripe only bills a renewal when
 * its clock passes the period end, so the renewal case binds the shopper to a
 * Stripe test clock through `stripe-test-clock.ts`, which needs the local WPCOM
 * env's Stripe test key. Webhooks reach the store through
 * `wpcom-local transact listen`. Cancellation, payment-method change,
 * migration and cutover are local runs recorded in the ledger, not cases here.
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:stripe-billing-subscriptions',
];

const GATEWAY = 'woocommerce_payments';
const SETTINGS_ROUTE = 'wc/v3/payments/settings';
const PRODUCTS_ROUTE = 'wc/v3/products';
const SUBSCRIPTIONS_ROUTE = 'wc/v3/subscriptions';
const CUSTOMER_ID_META = '_wcpay_customer_id_test';
const SUBSCRIPTION_ID_META = '_wcpay_subscription_id';
const INVOICE_ID_META = '_wcpay_billing_invoice_id';
const RECURRING_PRICE = '19.47';
const RECURRING_MINOR = 1947;
const CARD = { brand: 'visa', last4: '4242' };
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
/** The clock moves this far past the period end, so Stripe has created, finalized and charged the renewal invoice. */
const ADVANCE_PAST_PERIOD_END_SECONDS = 3 * 60 * 60;
const RECEIPT_TIMEOUT_MS = 90_000;
const WEBHOOK_TIMEOUT_MS = 180_000;
const POLL_INTERVAL_MS = 5_000;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

type MetaEntry = { key: string; value: unknown };

function metaValue(
	record: { meta_data?: MetaEntry[] },
	key: string
): string | undefined {
	const entry = ( record.meta_data ?? [] ).find(
		( meta ) => meta.key === key
	);
	return typeof entry?.value === 'string' ? entry.value : undefined;
}

interface StoreSubscription {
	id: number;
	status: string;
	parent_id: number;
	payment_method: string;
	next_payment_date_gmt: string;
	meta_data: MetaEntry[];
}

interface StoreOrder {
	id: number;
	status: string;
	total: string;
	meta_data: MetaEntry[];
}

async function readSubscription(
	restApi: ApiClient,
	subscriptionId: number
): Promise< StoreSubscription > {
	return (
		await restApi.get( `${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }` )
	).data as StoreSubscription;
}

async function readRenewalOrders(
	restApi: ApiClient,
	subscriptionId: number,
	parentOrderId: number
): Promise< StoreOrder[] > {
	const orders = (
		await restApi.get(
			`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }/orders`
		)
	).data as StoreOrder[];
	return orders.filter( ( order ) => order.id !== parentOrderId );
}

/** Polls until a paid renewal order reaches the store, the webhook's only visible effect. */
async function waitForPaidRenewalOrders(
	restApi: ApiClient,
	subscriptionId: number,
	parentOrderId: number
): Promise< StoreOrder[] > {
	const deadline = Date.now() + WEBHOOK_TIMEOUT_MS;
	for (;;) {
		const renewals = await readRenewalOrders(
			restApi,
			subscriptionId,
			parentOrderId
		);
		if (
			renewals.length > 0 &&
			PAID_ORDER_STATUSES.includes( renewals[ 0 ].status )
		) {
			return renewals;
		}
		if ( Date.now() >= deadline ) {
			throw new Error(
				`No paid renewal order reached the store within ${ WEBHOOK_TIMEOUT_MS } ms; is wpcom-local transact listen running?`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/** Stripe's period end moved onto the subscription item in newer API versions; read whichever is present. */
function periodEnd( stripeSubscription: Record< string, unknown > ): number {
	const items = stripeSubscription.items as {
		data?: Array< { current_period_end?: number } >;
	};
	const value =
		( stripeSubscription.current_period_end as number | undefined ) ??
		items?.data?.[ 0 ]?.current_period_end;
	if ( typeof value !== 'number' ) {
		throw new Error( 'The Stripe subscription carries no period end.' );
	}
	return value;
}

let customer: ReturnType< typeof getFakeUser >;
let customerId: number;
let productId: number;
let clock: StripeTestClock;
let clockId: string;
let clockCustomerId: string;
let wasStripeBillingEnabled: boolean;
let subscriptionId: number | undefined;
let parentOrderId: number | undefined;
let stripeSubscriptionId: string | undefined;

test.describe( 'WooPayments native Stripe Billing subscription fidelity', () => {
	test.describe.configure( { mode: 'serial', timeout: 600_000 } );

	test.beforeAll( async ( { restApi } ) => {
		test.skip(
			! hasStripeTestKey(),
			'Needs the local WPCOM env Stripe test key (stripe-test-clock.ts).'
		);
		await requireTestModeAccount( restApi );
		await createClassicCheckoutPage();
		clock = await StripeTestClock.forStore( restApi );

		const settings = ( await restApi.get( SETTINGS_ROUTE ) ).data as {
			is_stripe_billing_enabled?: boolean;
		};
		wasStripeBillingEnabled = settings.is_stripe_billing_enabled === true;
		await restApi.post( SETTINGS_ROUTE, {
			is_stripe_billing_enabled: true,
		} );

		const created = await clock.createClock(
			Math.floor( Date.now() / 1000 ),
			'WooPayments native Stripe Billing e2e'
		);
		clockId = String( created.id );

		customer = getFakeUser( 'customer' );
		const stripeCustomer = await clock.createClockCustomer(
			clockId,
			'pm_card_visa'
		);
		clockCustomerId = String( stripeCustomer.id );
		expect( clockCustomerId ).toMatch( /^cus_[A-Za-z0-9]+$/ );
		const createdCustomer = (
			await restApi.post( 'wc/v3/customers', customer )
		).data as { id: number };
		customerId = createdCustomer.id;
		// The store reuses a shopper's saved WooPayments customer, so binding
		// this one to the clock puts the Stripe subscription on the clock too.
		// The customers REST route drops protected meta, so this goes through
		// the same user option the store reads.
		const storedCustomerId = await wpEvalJson< string | false >( `
			update_user_option( ${ customerId }, '${ CUSTOMER_ID_META }', '${ clockCustomerId }' );
			return get_user_option( '${ CUSTOMER_ID_META }', ${ customerId } );
		` );
		expect(
			storedCustomerId,
			'the run customer must carry the clock-bound WooPayments customer'
		).toBe( clockCustomerId );

		const product = (
			await restApi.post( PRODUCTS_ROUTE, {
				name: `WooPayments native Stripe Billing E2E ${ customer.username }`,
				type: 'subscription',
				virtual: true,
				regular_price: RECURRING_PRICE,
				status: 'publish',
				meta_data: [
					{ key: '_subscription_price', value: RECURRING_PRICE },
					{ key: '_subscription_period', value: 'month' },
					{ key: '_subscription_period_interval', value: '1' },
					{ key: '_subscription_length', value: '0' },
					{ key: '_subscription_sign_up_fee', value: '0' },
					{ key: '_subscription_trial_length', value: '0' },
					{ key: '_subscription_trial_period', value: 'day' },
				],
			} )
		).data as { id: number };
		productId = product.id;
	} );

	test.afterAll( async ( { restApi } ) => {
		if ( subscriptionId ) {
			await restApi.put( `${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`, {
				status: 'cancelled',
			} );
		}
		// Deleting the clock deletes its customer and any subscription still on it.
		if ( clockId ) {
			await clock.deleteClock( clockId );
		}
		if ( wasStripeBillingEnabled === false ) {
			await restApi.post( SETTINGS_ROUTE, {
				is_stripe_billing_enabled: false,
			} );
		}
		if ( productId ) {
			await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
				force: true,
			} );
		}
		if ( customerId ) {
			await restApi.delete( `wc/v3/customers/${ customerId }`, {
				force: true,
			} );
		}
	} );

	test(
		'SB1 a subscription bought with Stripe Billing on is billed by a Stripe subscription tagged woo_subscriptions whose first invoice is paid out of band, and the parent order settles one 1947 usd charge',
		{ tag: FAMILY_TAGS },
		async ( { page, restApi } ) => {
			await page.goto( 'wp-login.php' );
			await logIn( page, customer.username, customer.password, false );

			await page.goto( `?post_type=product&p=${ productId }` );
			await page
				.getByRole( 'button', { name: /^(Sign up now|Add to cart)$/i } )
				.click();
			await page.goto( 'classic-checkout/' );

			const billing = page.locator(
				'.woocommerce-billing-fields:has(#billing_first_name)'
			);
			await expect( billing ).toBeVisible();
			await billing.getByLabel( /^First name/i ).fill( 'E2E' );
			await billing.getByLabel( /^Last name/i ).fill( 'StripeBilling' );
			await billing.locator( '#billing_country' ).selectOption( 'US' );
			await billing
				.getByLabel( /^Street address/i )
				.first()
				.fill( '123 Test Street' );
			await billing
				.getByLabel( /^(?:Town \/ City|City)/i )
				.fill( 'San Francisco' );
			await billing.locator( '#billing_state' ).selectOption( 'CA' );
			await billing
				.getByLabel( /^(?:ZIP Code|Postcode)/i )
				.fill( '94107' );
			await billing.getByLabel( /^Phone/i ).fill( '5555550100' );
			await page.locator( '#billing_email' ).fill( customer.email );

			await page
				.locator( `input[name="payment_method"][value="${ GATEWAY }"]` )
				.check();
			await fillCardDetails( page, TEST_CARDS.basic, 'classic' );
			await page.getByRole( 'button', { name: /place order/i } ).click();
			await page.waitForURL( /\/order-received\/[1-9]\d*/, {
				timeout: RECEIPT_TIMEOUT_MS,
			} );
			parentOrderId = Number(
				/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
			);

			await expectSettledCardPayment( restApi, parentOrderId, {
				amountMinor: RECURRING_MINOR,
				currency: 'USD',
				card: CARD,
			} );

			const subscriptions = (
				await restApi.get( SUBSCRIPTIONS_ROUTE, {
					customer: String( customerId ),
				} )
			).data as StoreSubscription[];
			expect(
				subscriptions,
				'one purchase must create exactly one subscription'
			).toHaveLength( 1 );
			subscriptionId = subscriptions[ 0 ].id;
			const subscription = await readSubscription(
				restApi,
				subscriptionId
			);
			expect( subscription.status ).toBe( 'active' );
			expect( subscription.parent_id ).toBe( parentOrderId );
			expect( subscription.payment_method ).toBe( GATEWAY );

			stripeSubscriptionId = metaValue(
				subscription,
				SUBSCRIPTION_ID_META
			);
			expect(
				stripeSubscriptionId,
				'the subscription must name the Stripe subscription that bills it'
			).toMatch( /^sub_/ );

			const stripeSubscription = await clock.get(
				`subscriptions/${ stripeSubscriptionId }`
			);
			expect( stripeSubscription.status ).toBe( 'active' );
			expect(
				stripeSubscription.customer,
				'the Stripe subscription must bill the clock-bound customer the shopper was given'
			).toBe( clockCustomerId );
			expect( stripeSubscription.metadata ).toMatchObject( {
				subscription_source: 'woo_subscriptions',
			} );

			const firstInvoice = await clock.get(
				`invoices/${ String( stripeSubscription.latest_invoice ) }`
			);
			expect( firstInvoice.status ).toBe( 'paid' );
			expect(
				firstInvoice.paid_out_of_band,
				'the store charges the parent order itself and marks the first invoice paid out of band'
			).toBe( true );
			expect( firstInvoice.amount_due ).toBe( RECURRING_MINOR );
			expect(
				metaValue( subscription, INVOICE_ID_META ),
				'the subscription keeps the parent invoice it was created with'
			).toBe( String( firstInvoice.id ) );
		}
	);

	test(
		'SB2 advancing the Stripe test clock past the period end bills one 1947 usd renewal that reaches the store as invoice.paid and becomes one paid renewal order linked by the invoice id, with the next payment date at the new period end',
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			test.skip(
				! subscriptionId || ! stripeSubscriptionId || ! parentOrderId,
				'The purchase case did not produce a Stripe-billed subscription.'
			);

			const before = await clock.get(
				`subscriptions/${ stripeSubscriptionId }`
			);
			await clock.advanceClock(
				clockId,
				periodEnd( before ) + ADVANCE_PAST_PERIOD_END_SECONDS
			);

			const after = await clock.get(
				`subscriptions/${ stripeSubscriptionId }`
			);
			const renewalInvoice = await clock.get(
				`invoices/${ String( after.latest_invoice ) }`
			);
			expect( renewalInvoice.billing_reason ).toBe(
				'subscription_cycle'
			);
			expect( renewalInvoice.status ).toBe( 'paid' );
			expect( renewalInvoice.amount_paid ).toBe( RECURRING_MINOR );

			const renewals = await waitForPaidRenewalOrders(
				restApi,
				subscriptionId!,
				parentOrderId!
			);

			expect(
				renewals,
				'one invoice.paid must create exactly one renewal order'
			).toHaveLength( 1 );
			const renewal = renewals[ 0 ];
			expect(
				metaValue( renewal, INVOICE_ID_META ),
				'the renewal order must be linked to the invoice Stripe paid'
			).toBe( String( renewalInvoice.id ) );
			await expectSettledCardPayment( restApi, renewal.id, {
				amountMinor: RECURRING_MINOR,
				currency: 'USD',
				card: CARD,
			} );

			const subscription = await readSubscription(
				restApi,
				subscriptionId!
			);
			expect( subscription.status ).toBe( 'active' );
			expect(
				Date.parse( `${ subscription.next_payment_date_gmt }Z` ) / 1000,
				'the next payment date must be the Stripe subscription period end'
			).toBe( periodEnd( after ) );
		}
	);
} );
