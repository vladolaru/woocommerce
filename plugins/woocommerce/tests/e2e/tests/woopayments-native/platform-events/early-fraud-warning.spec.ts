/**
 * Platform events: radar.early_fraud_warning.created and .updated.
 *
 * The platform forwards both with `data`. The case pays with Stripe's early-fraud-warning test card, waits for the
 * warning, then refunds the order in full, which makes the warning not actionable and sends the update. The store
 * records the warning on the order and then resolves it, as client 11.1.0 does.
 */

/**
 * External dependencies
 */
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import type { ProviderTestCard } from '../../../utils/woopayments';
import {
	cleanUp,
	connectedAccountId,
	deleteOrder,
	deleteProduct,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	payWithBlocksCheckout,
	pollFor,
	type Json,
	waitForStripeEvent,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const PRICE = '10.99';
const WARNING_META_KEY = '_wcpay_early_fraud_warning';
const WARNING_BUDGET_MS = 180_000;
const DELIVERY_BUDGET_MS = 120_000;

/** Stripe's test card whose charge succeeds and then receives an early fraud warning (Stripe testing docs, fraud). */
const EARLY_FRAUD_WARNING_CARD: ProviderTestCard = {
	number: '4000000000005423',
	expiry: '0245',
	cvc: '424',
};

/** The early fraud warning the store recorded on the order, or null. */
async function readWarning(
	restApi: ApiClient,
	orderId: number
): Promise< Json | null > {
	const order = ( await restApi.get( `wc/v3/orders/${ orderId }` ) )
		.data as Json;
	const raw = ( order.meta_data as Json[] | undefined )?.find(
		( entry ) => entry.key === WARNING_META_KEY
	)?.value;
	return typeof raw === 'object' && raw !== null ? ( raw as Json ) : null;
}

test.describe( 'Platform events: early fraud warning', () => {
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
		'a forwarded early fraud warning is recorded on its order and resolved when the order is refunded',
		{ tag: FAMILY_TAGS },
		async ( { page, restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			const since = Math.floor( Date.now() / 1000 ) - 5;
			const paid = await payWithBlocksCheckout(
				page,
				restApi,
				EARLY_FRAUD_WARNING_CARD,
				PRICE,
				( id ) => ( productId = id ),
				async ( id ) => {
					orderId = id;
				}
			);
			const paidOrderId = paid.orderId;

			const created = await waitForStripeEvent(
				accountId,
				'radar.early_fraud_warning.created',
				since,
				( object ) => object.charge === paid.chargeId,
				WARNING_BUDGET_MS
			);
			const recorded = await pollFor(
				() => readWarning( restApi, paidOrderId ),
				DELIVERY_BUDGET_MS,
				`Order ${ paidOrderId } has no early fraud warning after ${ created.id }: no real delivery of it arrived (is wpcom-local transact listen running with --ensure-jobs?), or the store refused it (read the store's WooPayments log for "Failed processing event ${ created.id }").`
			);
			expect( recorded ).toEqual(
				expect.objectContaining( {
					efw_id: created.object.id,
					efw_actionable: true,
					efw_type: created.object.fraud_type,
				} )
			);

			const refundedAt = Math.floor( Date.now() / 1000 ) - 5;
			await restApi.post( `wc/v3/orders/${ paidOrderId }/refunds`, {
				amount: PRICE,
				api_refund: true,
			} );
			const updated = await waitForStripeEvent(
				accountId,
				'radar.early_fraud_warning.updated',
				refundedAt,
				( object ) =>
					object.id === created.object.id &&
					object.actionable === false,
				WARNING_BUDGET_MS
			);
			await pollFor(
				async () => {
					const warning = await readWarning( restApi, paidOrderId );
					return warning?.efw_actionable === false ? warning : null;
				},
				DELIVERY_BUDGET_MS,
				`Order ${ paidOrderId } still records warning ${ created.object.id } as actionable after its update ${ updated.id } was sent.`
			);
		}
	);
} );
