/**
 * Platform events: the delivery check body, `{"type":"test"}`.
 *
 * The platform's `Admin_Service::send_webhook()` sends it with no event id (the Mission Control "send webhook" tool and
 * the linked-blog switch check) and returns the store's reply. Client 11.1.0 has no case for the type and answers the
 * success envelope; the store must too.
 */

/**
 * Internal dependencies
 */
import { expect, tags, test } from '../../../fixtures/fixtures';
import {
	connectedAccountId,
	hasLivePlatformTools,
	LIVE_PLATFORM_SKIP_REASON,
	runOnPlatform,
} from '../live-platform';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:platform-events',
];

test.describe( 'Platform events: delivery check', () => {
	test.describe.configure( { timeout: 600_000 } );

	test.beforeAll( () => {
		test.skip( ! hasLivePlatformTools(), LIVE_PLATFORM_SKIP_REASON );
	} );

	test(
		"the platform's test delivery is answered with the success envelope",
		{ tag: FAMILY_TAGS },
		async ( { restApi } ) => {
			const accountId = await connectedAccountId( restApi );

			const sent = await runOnPlatform< { reply: unknown } >(
				accountId,
				`return array( 'reply' => $e2e_service( \\WCPay\\Service\\Admin_Service::class )->send_webhook( $e2e_account ) );`
			);

			expect(
				sent.reply,
				"the platform returns the store's own reply to its test delivery"
			).toEqual( { result: 'success' } );
		}
	);
} );
