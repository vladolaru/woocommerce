/**
 * Platform events: the fee breakdown envelope on payment_intent.succeeded.
 *
 * The platform attaches `fee_breakdown_v1` to the payment_intent.succeeded it forwards. The case waits until the store
 * finished handling that real delivery, then checks the order carries the platform's envelope net, read from the charge
 * the platform serves. Which path wrote the net, and when, is the PHPUnit rows' to prove with the recorded body: the
 * delivery can land before the browser reads the order, and on a same-currency card payment the envelope and the
 * application-fee fallback give the same net.
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
	waitForHandledDelivery,
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
		"the forwarded payment_intent.succeeded is processed and the order carries the platform envelope's net",
		{ tag: FAMILY_TAGS },
		async ( { page, restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			const since = Math.floor( Date.now() / 1000 ) - 5;
			const paid = await payWithBlocksCheckout(
				page,
				restApi,
				TEST_CARDS.basic,
				PRICE,
				( id ) => ( productId = id ),
				async ( id ) => {
					orderId = id;
				}
			);

			const event = await waitForStripeEvent(
				accountId,
				'payment_intent.succeeded',
				since,
				( object ) => object.id === paid.intentId,
				DELIVERY_BUDGET_MS
			);
			await waitForHandledDelivery( event.id, DELIVERY_BUDGET_MS );

			const envelope = ( await getCharge( restApi, paid.chargeId ) )
				.fee_breakdown_v1 as Json | undefined;
			const net = ( envelope?.totals as Json | undefined )?.net as
				{ amount?: unknown; currency?: unknown } | undefined;
			expect(
				net?.amount,
				"the platform's charge carries its fee breakdown envelope (WCPAY_FEE_BREAKDOWN_ENVELOPE_ENABLED on the local WPCOM)"
			).toBeDefined();
			expect( String( net?.currency ).toUpperCase() ).toBe( 'USD' );

			const order = (
				await restApi.get( `wc/v3/orders/${ paid.orderId }` )
			).data as Json;
			expect(
				Number( metaValue( order, '_wcpay_net' ) ),
				"the order's net is the envelope's net total, in dollars"
			).toBe( Number( net?.amount ) / 100 );
		}
	);
} );
