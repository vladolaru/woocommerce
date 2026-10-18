/**
 * Platform events: account.updated forwarded from Stripe.
 *
 * The platform forwards Stripe's account.updated as `{id, type, livemode, account}` with no `data`. The case changes
 * one metadata key on the connected account through the Stripe CLI, which makes Stripe send the event, and removes the
 * key afterwards. The store marks the delivery processed, refreshes the account and clears its cached saved methods,
 * as client 11.1.0 does.
 */

/**
 * Internal dependencies
 */
import { tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import {
	type AccountRefreshProbe,
	cleanUp,
	connectedAccountId,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	removeAccountRefreshProbe,
	seedAccountRefreshProbe,
	stripeTest,
	waitForAccountRefresh,
	waitForProcessedDelivery,
	waitForStripeEvent,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];
const METADATA_KEY = 'e2e_platform_events';
const DELIVERY_BUDGET_MS = 120_000;

test.describe( 'Platform events: Stripe account change', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
	} );

	let probe: AccountRefreshProbe | undefined;
	let accountId: string | undefined;

	test.afterEach( async () => {
		const account = accountId;
		await cleanUp( [
			[
				'remove the Stripe metadata key',
				async () =>
					account &&
					stripeTest(
						account,
						[
							'accounts',
							'update',
							account,
							'-d',
							`metadata[${ METADATA_KEY }]=`,
						],
						{ onPlatform: true }
					),
			],
			[ 'remove the probe', () => removeAccountRefreshProbe( probe ) ],
		] );
		probe = undefined;
		accountId = undefined;
	} );

	test(
		"Stripe's account.updated, forwarded without data, refreshes the store account and clears its cached saved methods",
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			accountId = await connectedAccountId( restApi );
			probe = await seedAccountRefreshProbe();
			const since = Math.floor( Date.now() / 1000 ) - 5;
			const value = random();

			await stripeTest(
				accountId,
				[
					'accounts',
					'update',
					accountId,
					'-d',
					`metadata[${ METADATA_KEY }]=${ value }`,
				],
				{ onPlatform: true }
			);
			const event = await waitForStripeEvent(
				accountId,
				'account.updated',
				since,
				( object ) =>
					( object.metadata as Record< string, unknown > )?.[
						METADATA_KEY
					] === value,
				DELIVERY_BUDGET_MS
			);

			await waitForProcessedDelivery( event.id, DELIVERY_BUDGET_MS );
			await waitForAccountRefresh(
				probe,
				DELIVERY_BUDGET_MS,
				`Stripe's account.updated ${ event.id }`
			);
		}
	);
} );
