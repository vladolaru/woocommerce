/**
 * In-person payments through a Stripe simulated reader, on the store's own terminal routes, as the Woo mobile apps
 * use them: location, reader, customer, card_present intent, the reader collecting a test card, prepare, the reader
 * confirming, capture. After the capture the case waits for the platform's real payment_intent.succeeded and proves
 * the end state client 11.1.0 leaves: the order keeps woocommerce_payments and its in-person title, and the order's
 * subscription keeps renewing automatically. Last, a second intent for the captured order is refused at capture.
 */

/**
 * External dependencies
 */
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpEvalJson } from '../../../utils/cli';
import { getFakeUser } from '../../../utils/data';
import { random } from '../../../utils/helpers';
import { getPaymentIntent, metaValue } from '../../../utils/woopayments';
import {
	cleanUp,
	connectedAccountId,
	deleteOrder,
	deleteProduct,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	pollFor,
	stripeTest,
	type Json,
	waitForProcessedDelivery,
	waitForStripeEvent,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:in-person',
];
const GATEWAY_ID = 'woocommerce_payments';
const IN_PERSON_TITLE = 'WooCommerce In-Person Payments';
const PRICE = '20.00';
const AMOUNT_MINOR = 2000;
const READER_BUDGET_MS = 60_000;
const DELIVERY_BUDGET_MS = 180_000;
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const PAYMENTS_ROUTE = 'wc/v3/payments';

interface SubscriptionState {
	id: number;
	payment_method: string;
	is_manual: boolean;
	requires_manual_renewal: boolean;
}

const SUBSCRIPTION_STATE_PHP = `array( 'id' => $subscription->get_id(), 'payment_method' => $subscription->get_payment_method(), 'is_manual' => $subscription->is_manual(), 'requires_manual_renewal' => (bool) $subscription->get_requires_manual_renewal() )`;

async function readSubscription(
	subscriptionId: number
): Promise< SubscriptionState > {
	return wpEvalJson< SubscriptionState >( `
		$subscription = wcs_get_subscription( ${ subscriptionId } );
		if ( ! $subscription ) {
			throw new RuntimeException( 'The run subscription is gone.' );
		}
		return ${ SUBSCRIPTION_STATE_PHP };
	` );
}

async function waitForIntentStatus(
	restApi: ApiClient,
	intentId: string,
	status: string,
	after: string
): Promise< void > {
	await pollFor(
		async () =>
			( await getPaymentIntent( restApi, intentId ) ).status === status
				? true
				: null,
		READER_BUDGET_MS,
		`Intent ${ intentId } never reached ${ status } after ${ after }.`
	);
}

/** The reader collects a test card for the intent, which then waits for confirmation. */
async function collectOnReader(
	restApi: ApiClient,
	accountId: string,
	readerId: string,
	intentId: string
): Promise< void > {
	await stripeTest( accountId, [
		'terminal',
		'readers',
		'collect_payment_method',
		readerId,
		'--payment-intent',
		intentId,
	] );
	await stripeTest( accountId, [
		'test_helpers',
		'terminal',
		'readers',
		'present_payment_method',
		readerId,
	] );
	await waitForIntentStatus(
		restApi,
		intentId,
		'requires_confirmation',
		'the reader collected a card'
	);
}

async function confirmOnReader(
	restApi: ApiClient,
	accountId: string,
	readerId: string,
	intentId: string
): Promise< void > {
	await stripeTest( accountId, [
		'terminal',
		'readers',
		'confirm_payment_intent',
		readerId,
		'--payment-intent',
		intentId,
	] );
	await waitForIntentStatus(
		restApi,
		intentId,
		'requires_capture',
		'the reader confirmed it'
	);
}

async function createTerminalIntent(
	restApi: ApiClient,
	orderId: number,
	customerId: string
): Promise< string > {
	const intent = (
		await restApi.post(
			`${ PAYMENTS_ROUTE }/orders/${ orderId }/create_terminal_intent`,
			{
				payment_methods: [ 'card_present' ],
				capture_method: 'manual',
				customer_id: customerId,
			}
		)
	).data as Json;
	expect( String( intent.id ) ).toMatch( /^pi_/ );
	return String( intent.id );
}

test.describe( 'In-person: simulated reader capture', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( async () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
		const eligible = await wpEvalJson< boolean >(
			`return wc_get_container()->get( \\Automattic\\WooCommerce\\Internal\\Payments\\Providers\\WooPayments\\WooPaymentsAccountService::class )->is_card_present_eligible();`
		);
		test.skip(
			! eligible,
			'The store account is not in an in-person country (the platform enables in-person payments by account country only).'
		);
	} );

	let accountId: string | undefined;
	let readerId: string | undefined;
	let secondIntentId: string | undefined;
	let subscriptionId: number | undefined;
	let orderId: number | undefined;
	let productId: number | undefined;
	let customerId: number | undefined;

	test.afterEach( async ( { restApi } ) => {
		const account = accountId;
		const [ reader, second, subscription, order, product, customer ] = [
			readerId,
			secondIntentId,
			subscriptionId,
			orderId,
			productId,
			customerId,
		];
		await cleanUp( [
			[
				'cancel the second intent',
				async () =>
					account &&
					second &&
					stripeTest( account, [
						'payment_intents',
						'cancel',
						second,
					] ),
			],
			[
				'delete the reader',
				async () =>
					account &&
					reader &&
					stripeTest( account, [
						'terminal',
						'readers',
						'delete',
						reader,
						'--confirm',
					] ),
			],
			[
				'delete the subscription',
				async () =>
					subscription &&
					wpEvalJson( `
						$subscription = wcs_get_subscription( ${ subscription } );
						if ( $subscription ) {
							$subscription->update_status( 'cancelled' );
							$subscription->delete( true );
						}
						return true;
					` ),
			],
			[ 'delete the order', () => deleteOrder( restApi, order ) ],
			[ 'delete the product', () => deleteProduct( restApi, product ) ],
			[
				'delete the customer',
				async () =>
					customer &&
					restApi.delete( `wc/v3/customers/${ customer }`, {
						force: true,
					} ),
			],
		] );
		accountId = undefined;
		readerId = undefined;
		secondIntentId = undefined;
		subscriptionId = undefined;
		orderId = undefined;
		productId = undefined;
		customerId = undefined;
	} );

	test(
		'a simulated-reader payment for a subscription order captures, keeps woocommerce_payments and automatic renewal after the success webhook, and a second intent for the order is refused',
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			accountId = await connectedAccountId( restApi );

			const customer = getFakeUser( 'customer' );
			customerId = (
				( await restApi.post( 'wc/v3/customers', customer ) ).data as {
					id: number;
				}
			 ).id;
			productId = (
				(
					await restApi.post( PRODUCTS_ROUTE, {
						name: `WooPayments in-person subscription ${ random() }`,
						type: 'subscription',
						virtual: true,
						regular_price: PRICE,
						status: 'publish',
						meta_data: [
							{ key: '_subscription_price', value: PRICE },
							{ key: '_subscription_period', value: 'month' },
							{
								key: '_subscription_period_interval',
								value: '1',
							},
							{ key: '_subscription_length', value: '0' },
							{ key: '_subscription_sign_up_fee', value: '0' },
							{ key: '_subscription_trial_length', value: '0' },
						],
					} )
				).data as { id: number }
			 ).id;
			const created = (
				await restApi.post( ORDERS_ROUTE, {
					status: 'pending',
					customer_id: customerId,
					currency: 'USD',
					billing: { email: customer.email },
					line_items: [ { product_id: productId, quantity: 1 } ],
				} )
			).data as Json;
			orderId = Number( created.id );
			expect( created.total ).toBe( PRICE );

			// Set by hand, not by the capture: neither the client's nor the store's terminal intent route sends
			// setup_future_usage, so the reader's charge carries no generated_card and the capture leaves the
			// subscription alone (asserted below). The state is the one a capture with a generated card writes; the
			// case proves that the success webhook keeps it.
			const subscription = await wpEvalJson< SubscriptionState >( `
				$subscription = wcs_create_subscription( array( 'order_id' => ${ orderId }, 'customer_id' => ${ customerId }, 'billing_period' => 'month', 'billing_interval' => 1 ) );
				if ( is_wp_error( $subscription ) ) {
					throw new RuntimeException( $subscription->get_error_message() );
				}
				$subscription->add_product( wc_get_product( ${ productId } ), 1 );
				$subscription->set_payment_method( '${ GATEWAY_ID }' );
				$subscription->set_requires_manual_renewal( false );
				$subscription->calculate_totals();
				$subscription->save();
				return ${ SUBSCRIPTION_STATE_PHP };
			` );
			subscriptionId = subscription.id;
			expect( subscription ).toEqual(
				expect.objectContaining( {
					payment_method: GATEWAY_ID,
					is_manual: false,
				} )
			);

			const location = (
				await restApi.get(
					`${ PAYMENTS_ROUTE }/terminal/locations/store`
				)
			).data as Json;
			expect( String( location.id ) ).toMatch( /^tml_/ );
			const reader = (
				await restApi.post( `${ PAYMENTS_ROUTE }/readers`, {
					location: location.id,
					registration_code: 'simulated-wpe',
					label: `WooPayments e2e ${ random() }`,
				} )
			).data as Json;
			readerId = String( reader.id );
			expect( readerId ).toMatch( /^tmr_/ );

			const providerCustomer = (
				await restApi.post(
					`${ PAYMENTS_ROUTE }/orders/${ orderId }/create_customer`
				)
			).data as Json;
			const providerCustomerId = String( providerCustomer.id );
			expect( providerCustomerId ).toMatch( /^cus_/ );
			const since = Math.floor( Date.now() / 1000 ) - 5;
			const intentId = await createTerminalIntent(
				restApi,
				orderId,
				providerCustomerId
			);

			await collectOnReader( restApi, accountId, readerId, intentId );
			const prepared = await restApi.post(
				`${ PAYMENTS_ROUTE }/orders/${ orderId }/prepare_terminal_payment`,
				{ payment_intent_id: intentId }
			);
			expect( prepared.status ).toBe( 200 );
			await confirmOnReader( restApi, accountId, readerId, intentId );

			const captured = await restApi.post(
				`${ PAYMENTS_ROUTE }/orders/${ orderId }/capture_terminal_payment`,
				{ payment_intent_id: intentId }
			);
			expect( captured.status ).toBe( 200 );
			expect( ( captured.data as Json ).status ).toBe( 'succeeded' );

			const afterCapture = (
				await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` )
			).data as Json;
			expect( afterCapture.status ).toBe( 'completed' );
			expect( afterCapture.payment_method ).toBe( GATEWAY_ID );
			expect( afterCapture.payment_method_title ).toBe( IN_PERSON_TITLE );
			expect( metaValue( afterCapture, '_intent_id' ) ).toBe( intentId );
			expect( metaValue( afterCapture, 'receipt_url' ) ).toContain(
				`/payments/readers/receipts/${ intentId }`
			);
			const capturedIntent = await stripeTest( accountId, [
				'payment_intents',
				'retrieve',
				intentId,
				'-d',
				'expand[]=latest_charge',
			] );
			expect( capturedIntent.status ).toBe( 'succeeded' );
			expect( capturedIntent.amount_received ).toBe( AMOUNT_MINOR );
			expect(
				(
					( capturedIntent.latest_charge as Json )
						?.payment_method_details as Json
				 )?.card_present as Json,
				'the premise of the hand-set subscription: no generated card, so the capture does not touch the subscription'
			).not.toHaveProperty( 'generated_card', expect.any( String ) );

			const succeeded = await waitForStripeEvent(
				accountId,
				'payment_intent.succeeded',
				since,
				( object ) => object.id === intentId,
				DELIVERY_BUDGET_MS
			);
			await waitForProcessedDelivery( succeeded.id, DELIVERY_BUDGET_MS );
			const afterWebhook = (
				await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` )
			).data as Json;
			expect(
				{
					payment_method: afterWebhook.payment_method,
					payment_method_title: afterWebhook.payment_method_title,
				},
				`after payment_intent.succeeded ${ succeeded.id } the order keeps the capture's method and title`
			).toEqual( {
				payment_method: GATEWAY_ID,
				payment_method_title: IN_PERSON_TITLE,
			} );
			expect(
				await readSubscription( subscriptionId ),
				`after payment_intent.succeeded ${ succeeded.id } the subscription still renews automatically on ${ GATEWAY_ID }`
			).toEqual(
				expect.objectContaining( {
					payment_method: GATEWAY_ID,
					is_manual: false,
					requires_manual_renewal: false,
				} )
			);

			// A second intent for the captured order is refused, and nothing is captured at the provider.
			secondIntentId = await createTerminalIntent(
				restApi,
				orderId,
				metaValue( afterWebhook, '_stripe_customer_id' )
			);
			await collectOnReader(
				restApi,
				accountId,
				readerId,
				secondIntentId
			);
			await confirmOnReader(
				restApi,
				accountId,
				readerId,
				secondIntentId
			);
			const refused = await restApi
				.post(
					`${ PAYMENTS_ROUTE }/orders/${ orderId }/capture_terminal_payment`,
					{ payment_intent_id: secondIntentId }
				)
				.catch(
					( error: {
						response?: { status?: number; data?: Json };
					} ) => ( {
						status: error.response?.status,
						data: error.response?.data ?? {},
					} )
				);
			expect( refused.status ).toBe( 409 );
			expect( ( refused.data as Json ).code ).toBe(
				'wcpay_payment_uncapturable'
			);
			const untouched = await stripeTest( accountId, [
				'payment_intents',
				'retrieve',
				secondIntentId,
			] );
			expect(
				{
					status: untouched.status,
					amount_received: untouched.amount_received,
				},
				'the refused capture never reached the provider'
			).toEqual( { status: 'requires_capture', amount_received: 0 } );
		}
	);
} );
