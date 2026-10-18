/**
 * Platform events: wcpay.notification, a promotion note.
 *
 * `Promotion_Service::send_promotion_note()` sends `{type: "wcpay.notification", data: <note>, livemode: false}` with
 * no `id`, asynchronously. The note is the one the platform builds for the US action incentive's start, for a fee
 * structure held in memory only: no enrolment and no fee change. The store keeps it as one admin note with the
 * settings action, as client 11.1.0 does. Where the local platform has no such fee structure the case skips as a
 * known limit.
 */

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import {
	cleanUp,
	connectedAccountId,
	deleteAdminNotes,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	pollFor,
	readAdminNote,
	runOnPlatform,
	type Json,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const NOTE_NAME_PREFIX = 'wc-payments-remote-notes-';
const DELIVERY_BUDGET_MS = 120_000;
const FEE_NAME_PHP = `\\WCPay\\Service\\Incentive_Service::WCPAY_ACTION_INCENTIVE_DISCOUNTS[ \\WCPay\\Utils\\Country::US ]['discount_fee']`;

test.describe( 'Platform events: promotion note', () => {
	test.describe.configure( { timeout: 600_000 } );

	let storeNoteName: string | undefined;

	test.beforeAll( async ( { restApi } ) => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
		// Read only: whether the local platform holds the fee structure the note is built for.
		const fee = await runOnPlatform< { name: string; available: boolean } >(
			await connectedAccountId( restApi ),
			`
			$fee_name = ${ FEE_NAME_PHP };
			return array( 'name' => $fee_name, 'available' => null !== $e2e_service( \\WCPay\\Service\\Fee_Service::class )->get_fee_structure( $fee_name, $e2e_account ) );
			`
		);
		test.skip(
			! fee.available,
			`Known limit: the local platform has no available fee structure ${ fee.name }, so it builds no promotion note to send.`
		);
		storeNoteName = `${ NOTE_NAME_PREFIX }${ fee.name }-welcome`;
	} );

	test.afterEach( async () => {
		const name = storeNoteName;
		await cleanUp( [
			[
				'delete the admin note',
				async () => name && deleteAdminNotes( name ),
			],
		] );
	} );

	test(
		"the platform's promotion start note becomes one store admin note with its title and the settings action",
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			const name = storeNoteName as string;
			// A copy an earlier run left would make the store skip this one as a duplicate.
			await deleteAdminNotes( name );

			const sent = await runOnPlatform< { title: string } >(
				accountId,
				`
				$fee_name = ${ FEE_NAME_PHP };
				$fee_structure = $e2e_service( \\WCPay\\Service\\Fee_Service::class )->get_fee_structure( $fee_name, $e2e_account );
				if ( ! $fee_structure ) {
					throw new RuntimeException( 'The local platform has no available fee structure ' . $fee_name . '.' );
				}
				$account_fee = new \\WCPay\\Cache\\Account_Fee_Structure(
					array(
						'stripe_account_id' => (string) $e2e_account->stripe_account_id,
						'fee_structure_id' => $fee_structure->get_id(),
						'start_time' => gmdate( 'Y-m-d H:i:s' ),
						'end_time' => gmdate( 'Y-m-d H:i:s', time() + 90 * DAY_IN_SECONDS ),
					)
				);
				$account_fee->fee_structure = $fee_structure;
				$note = $e2e_service( \\WCPay\\Service\\Incentive_Service::class )->process_note( null, \\WCPay\\Service\\Incentive_Service::EVENT_START, $account_fee );
				if ( ! is_array( $note ) || empty( $note['name'] ) ) {
					throw new RuntimeException( 'The platform built no start note for ' . $fee_name . '.' );
				}
				$e2e_service( \\WCPay\\Service\\Promotion_Service::class )->send_promotion_note( $e2e_account, $account_fee, $note, true );
				return array( 'title' => (string) $note['title'] );
				`
			);

			const note = await pollFor< Json >(
				() => readAdminNote( name ),
				DELIVERY_BUDGET_MS,
				`No admin note ${ name } appeared within ${ DELIVERY_BUDGET_MS } ms: the platform's wcpay.notification did not arrive (the async jobs must be running) or the store refused it (read the store's WooPayments log).`
			);

			expect( note.title ).toBe( sent.title );
			expect( note.source ).toBe( 'woocommerce-payments' );
			expect( note.actions ).toEqual( [
				expect.objectContaining( {
					label: 'View Settings',
					status: 'unactioned',
					primary: true,
					url: expect.stringContaining(
						'page=wc-settings&tab=checkout&section=woocommerce_payments'
					),
				} ),
			] );
		}
	);
} );
