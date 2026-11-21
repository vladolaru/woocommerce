/**
 * Platform events: `{"type":"account.updated"}` from an account change made on the platform.
 *
 * `Admin_Service::set_account_meta()` (the Mission Control account meta tool) ends with a synchronous type-only
 * account.updated, so the store has handled it when the platform call returns. The case writes the card-testing
 * bypass meta back with the value it already has. The store answers as client 11.1.0 does: it refreshes the account
 * and clears every cached saved-method list.
 */

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import {
	type AccountRefreshProbe,
	cleanUp,
	connectedAccountId,
	hasLivePlatformTools,
	isRefreshed,
	LIVE_PLATFORM_SKIP_REASON,
	readAccountRefreshState,
	removeAccountRefreshProbe,
	runOnPlatform,
	seedAccountRefreshProbe,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];

test.describe( 'Platform events: account meta change', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
	} );

	let probe: AccountRefreshProbe | undefined;

	test.afterEach( async () => {
		await cleanUp( [
			[ 'remove the probe', () => removeAccountRefreshProbe( probe ) ],
		] );
		probe = undefined;
	} );

	test(
		'an account meta change on the platform refreshes the store account and clears its cached saved methods',
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			probe = await seedAccountRefreshProbe();

			await runOnPlatform< unknown >(
				accountId,
				`
				$key = \\WCPay\\Models\\Account_Meta::SKIP_CARD_TESTING_PREVENTION_CHECKS;
				$current = $e2e_service( \\WCPay\\Service\\Account_Service::class )->get_account_meta( (int) $e2e_account->wcpay_account_id, $key );
				if ( null === $current || '' === (string) $current ) {
					throw new RuntimeException( 'The account has no card-testing bypass meta to write back unchanged.' );
				}
				$e2e_service( \\WCPay\\Service\\Admin_Service::class )->set_account_meta( $e2e_account, $key, (string) $current );
				return true;
				`
			);

			// The sender is synchronous: one read, right after it returns.
			const state = await readAccountRefreshState( probe );
			expect(
				isRefreshed( probe, state ),
				`the platform's type-only account.updated refreshes the account (fetched ${ state.fetched } after ${ probe.fetchedBefore }) and clears the cached saved methods (user meta left: ${ state.meta }, option left: ${ state.option })`
			).toBe( true );
		}
	);
} );
