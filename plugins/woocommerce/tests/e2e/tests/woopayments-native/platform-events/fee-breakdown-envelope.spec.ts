/**
 * Platform events: the fee breakdown envelope on payment_intent.succeeded.
 *
 * The platform attaches `fee_breakdown_v1` to the payment_intent.succeeded it forwards. The checkout's own response
 * carries no envelope, so the order has no `_wcpay_net` until the delivery writes it. The case reads the net as soon
 * as the order exists, then after the real delivery is processed, and expects the platform's envelope net (read from
 * the charge the platform serves) only after it. Which branch wrote it (envelope or application fee, equal on a
 * same-currency card payment) is the PHPUnit rows' to prove, with the recorded body.
 */

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import { getCharge, metaValue, TEST_CARDS } from '../../../utils/woopayments';
import {
	cleanUp,
	connectedAccountId,
	deleteOrder,
	deleteProduct,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	payWithBlocksCheckout,
	type Json,
	waitForProcessedDelivery,
	waitForStripeEvent,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const PRICE = '10.99';
const DELIVERY_BUDGET_MS = 120_000;

test.describe( 'Platform events: fee breakdown envelope', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
	} );

	let orderId: number | undefined;
	let productId: number | undefined;

	test.afterEach( async ( { restApi } ) => {
		const [ order, product ] = [ orderId, productId ];
		await cleanUp( [
			[ 'delete the order', () => deleteOrder( restApi, order ) ],
			[ 'delete the product', () => deleteProduct( restApi, product ) ],
		] );
		orderId = undefined;
		productId = undefined;
	} );

	test(
		"the forwarded payment_intent.succeeded writes the platform envelope's net on an order that had none",
		{ tag: FAMILY_TAGS },
		async ( { page, restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			const since = Math.floor( Date.now() / 1000 ) - 5;
			let netBeforeDelivery = '';
			const paid = await payWithBlocksCheckout(
				page,
				restApi,
				TEST_CARDS.basic,
				PRICE,
				( id ) => ( productId = id ),
				async ( id ) => {
					netBeforeDelivery = metaValue(
						( await restApi.get( `wc/v3/orders/${ id }` ) )
							.data as Json,
						'_wcpay_net'
					);
				}
			);
			orderId = paid.orderId;

			const event = await waitForStripeEvent(
				accountId,
				'payment_intent.succeeded',
				since,
				( object ) => object.id === paid.intentId,
				DELIVERY_BUDGET_MS
			);
			await waitForProcessedDelivery( event.id, DELIVERY_BUDGET_MS );

			const envelope = ( await getCharge( restApi, paid.chargeId ) )
				.fee_breakdown_v1 as Json | undefined;
			const net = ( envelope?.totals as Json | undefined )?.net as
				| { amount?: unknown; currency?: unknown }
				| undefined;
			expect(
				net?.amount,
				"the platform's charge carries its fee breakdown envelope (WCPAY_FEE_BREAKDOWN_ENVELOPE_ENABLED on the local WPCOM)"
			).toBeDefined();
			expect( String( net?.currency ).toUpperCase() ).toBe( 'USD' );

			const envelopeNet = Number( net?.amount ) / 100;
			expect(
				netBeforeDelivery,
				`before the delivery the order must carry no net yet (it read ${ netBeforeDelivery }): if the checkout or an early delivery already wrote ${ envelopeNet }, this case cannot show the delivery's write and must be retitled to "processed" only`
			).not.toBe( String( envelopeNet ) );
			const order = ( await restApi.get( `wc/v3/orders/${ orderId }` ) )
				.data as Json;
			expect(
				Number( metaValue( order, '_wcpay_net' ) ),
				"after the delivery the order's net is the envelope's net total, in dollars"
			).toBe( envelopeNet );
		}
	);
} );
