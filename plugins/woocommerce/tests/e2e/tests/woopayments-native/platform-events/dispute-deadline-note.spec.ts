/**
 * Platform events: wcpay.notification, the dispute deadline note.
 *
 * The case pays with Stripe's disputed-card fixture, waits for the platform to record the dispute, then runs the
 * platform's evidence-due reminder job for it instead of waiting for its schedule. The job mails the merchant and sends
 * the note asynchronously, with a relative admin path and `url_is_admin`. The store keeps one admin note whose link
 * reaches that payment's dispute, as client 11.1.0 does.
 */

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import { getCharge, TEST_CARDS } from '../../../utils/woopayments';
import {
	cleanUp,
	connectedAccountId,
	countAdminNotes,
	deleteAdminNotes,
	deleteOrder,
	deleteProduct,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	payWithBlocksCheckout,
	pollFor,
	readAdminNote,
	runOnPlatform,
	stripeTest,
	type Json,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const PRICE = '10.99';
const NOTE_NAME_PREFIX = 'wc-payments-remote-notes-';
const DISPUTE_BUDGET_MS = 180_000;
const DELIVERY_BUDGET_MS = 120_000;

function idOf( value: unknown ): string {
	if ( typeof value === 'string' ) {
		return value;
	}
	return typeof value === 'object' && value !== null && 'id' in value
		? String( ( value as { id: unknown } ).id )
		: '';
}

test.describe( 'Platform events: dispute deadline note', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
	} );

	let orderId: number | undefined;
	let productId: number | undefined;
	let storeNoteName: string | undefined;

	test.afterEach( async ( { restApi } ) => {
		const [ order, product, note ] = [ orderId, productId, storeNoteName ];
		await cleanUp( [
			[
				'delete the admin note',
				async () => note && deleteAdminNotes( note ),
			],
			[ 'delete the order', () => deleteOrder( restApi, order ) ],
			[ 'delete the product', () => deleteProduct( restApi, product ) ],
		] );
		orderId = undefined;
		productId = undefined;
		storeNoteName = undefined;
	} );

	test(
		"the platform's dispute deadline reminder becomes one store admin note linking to that dispute",
		{ tag: FAMILY_TAGS },
		async ( { page, restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			const paid = await payWithBlocksCheckout(
				page,
				restApi,
				TEST_CARDS.dispute,
				PRICE,
				( id ) => ( productId = id ),
				async ( id ) => {
					orderId = id;
				}
			);

			const disputeId = await pollFor< string >(
				async () =>
					idOf(
						( await getCharge( restApi, paid.chargeId ) ).dispute
					) || null,
				DISPUTE_BUDGET_MS,
				`Charge ${ paid.chargeId } carries no dispute within ${ DISPUTE_BUDGET_MS } ms.`
			);

			// The job the platform schedules carries the dispute's balance transaction, and its note links to the payment
			// details only when it has one.
			const stripeDispute = await stripeTest( accountId, [
				'disputes',
				'retrieve',
				disputeId,
			] );
			const transactionId = String(
				stripeDispute.balance_transaction ??
					(
						stripeDispute.balance_transactions as
							| Array< { id?: unknown } >
							| undefined
					 )?.[ 0 ]?.id ??
					''
			);
			expect( transactionId ).toMatch( /^txn_/ );

			// The reminder reads the dispute the platform recorded from charge.dispute.created; wait for that record.
			const reminded = await pollFor< { note_name: string } >(
				async () =>
					runOnPlatform< { note_name: string } | null >(
						accountId,
						`
						$dispute = $e2e_service( \\WCPay\\Repository\\Dispute_Repository::class )->get_by_dispute_id( (string) $e2e_args['dispute'], true );
						if ( empty( $dispute ) ) {
							return null;
						}
						$e2e_service( \\WCPay\\Event_Handler\\Charge_Event_Handler::class )->send_dispute_evidence_due_reminder(
							array(
								'account_id' => (string) $e2e_account->stripe_account_id,
								'dispute_id' => (string) $e2e_args['dispute'],
								'payment_intent_id' => (string) $e2e_args['intent'],
								'transaction_id' => (string) $e2e_args['transaction'],
								'test_mode' => true,
							)
						);
						return array( 'note_name' => 'approaching-dispute-deadline-' . $dispute->dispute_id );
						`,
						{
							dispute: disputeId,
							intent: paid.intentId,
							transaction: transactionId,
						}
					),
				DISPUTE_BUDGET_MS,
				`The local platform never recorded dispute ${ disputeId } (charge.dispute.created must reach it through transact listen).`
			);
			storeNoteName = `${ NOTE_NAME_PREFIX }${ reminded.note_name }`;
			const name = storeNoteName;

			const note = await pollFor< Json >(
				() => readAdminNote( name ),
				DELIVERY_BUDGET_MS,
				`No admin note ${ name } appeared within ${ DELIVERY_BUDGET_MS } ms: the platform's wcpay.notification did not arrive (the async jobs must be running) or the store refused it (read the store's WooPayments log).`
			);

			expect(
				await countAdminNotes( name ),
				'the store keeps exactly one note for the delivery'
			).toBe( 1 );
			expect( note.source ).toBe( 'woocommerce-payments' );
			const actions = note.actions as Array< Record< string, unknown > >;
			expect( actions ).toHaveLength( 1 );
			expect( actions[ 0 ] ).toEqual(
				expect.objectContaining( {
					label: 'View dispute',
					status: 'actioned',
				} )
			);
			const link = new URL( String( actions[ 0 ].url ) );
			expect(
				link.pathname,
				'the platform sends a relative admin path, which the store resolves under wp-admin'
			).toMatch( /\/wp-admin\/admin\.php$/ );
			expect(
				link.searchParams.get( 'id' ),
				'the note links to this payment'
			).toBe( paid.intentId );
			expect( link.searchParams.get( 'type' ) ).toBe( 'dispute' );
		}
	);
} );
