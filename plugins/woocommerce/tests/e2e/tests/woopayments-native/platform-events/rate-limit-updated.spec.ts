/**
 * Platform events: `{"type":"account.updated"}` from the rate limiter.
 *
 * The platform's card-testing-prevention off job sends the type-only body through its async jobs, then writes the
 * account's card checks back to Stripe. Only the off half runs, on an account whose prevention is already off, with
 * the account's own current checks. Stripe can answer that write with its own account.updated, which the platform
 * forwards; the case then cannot tell which body refreshed the store, so it fails as inconclusive instead of passing.
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
	listStripeEvents,
	LIVE_PLATFORM_SKIP_REASON,
	removeAccountRefreshProbe,
	runOnPlatform,
	seedAccountRefreshProbe,
	waitForAccountRefresh,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const DELIVERY_BUDGET_MS = 120_000;

test.describe( 'Platform events: rate limiter', () => {
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
		"the rate limiter's card-testing-prevention off job refreshes the store account and clears its cached saved methods",
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			const accountId = await connectedAccountId( restApi );
			probe = await seedAccountRefreshProbe();
			const since = Math.floor( Date.now() / 1000 ) - 5;

			await runOnPlatform< unknown >(
				accountId,
				`
				$meta_service = $e2e_service( \\WCPay\\Service\\Account_Meta_Service::class );
				if ( $meta_service->is_card_testing_prevention_enabled( (int) $e2e_account->wcpay_account_id ) ) {
					throw new RuntimeException( 'Card-testing prevention is on for this account; the off job would change it. Refusing.' );
				}
				$checks = $e2e_service( \\WCPay\\Service\\Account_Service::class )->get_stripe_account( (string) $e2e_account->stripe_account_id, true, true )->get_decline_on_checks();
				if ( array() === $checks ) {
					throw new RuntimeException( 'The Stripe account reports no decline_on checks to write back.' );
				}
				$e2e_service( \\WCPay\\Service\\Payment_Rate_Limit_Service::class )->handle_toggle_card_testing_prevention_off(
					array(
						'wpcom_blog_id' => (int) $e2e_account->wpcom_blog_id,
						'wcpay_account_id' => (int) $e2e_account->wcpay_account_id,
						'stripe_account_id' => (string) $e2e_account->stripe_account_id,
						'decline_on_checks' => $checks,
					)
				);
				if ( $meta_service->is_card_testing_prevention_enabled( (int) $e2e_account->wcpay_account_id ) ) {
					throw new RuntimeException( 'Card-testing prevention is on after the off job.' );
				}
				return true;
				`
			);

			await waitForAccountRefresh(
				probe,
				DELIVERY_BUDGET_MS,
				"The rate limiter's account.updated"
			);

			const forwarded = await listStripeEvents(
				accountId,
				'account.updated',
				since
			);
			expect(
				forwarded.map( ( event ) => event.id ),
				"Inconclusive if Stripe also sent account.updated for the checks write: its forward refreshes the store the same way, so the refresh could not be attributed to the rate limiter's type-only body"
			).toEqual( [] );
		}
	);
} );
