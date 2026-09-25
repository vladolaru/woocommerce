import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { runBlocksDeclineRecoverySmoke } from '../../../utils/woopayments-native/drivers/card-recovery';

/**
 * This family's other two rows moved out in T.1 batch 2: the Blocks
 * processing-error decline (no-retry) and Classic generic-decline retry
 * assertions are now proven at the PHPUnit and Jest layers cited in
 * `DISPOSITION.tsv` rows 18 and 19. This test is the family's one retained
 * provider-backed smoke, trimmed to the audit §3 minimal oracle via
 * `runBlocksDeclineRecoverySmoke()`/`validateBlocksDeclineRecoverySmoke()`
 * and asserting exactly that: the first attempt (card `4000000000000002`)
 * answers Store API HTTP 400 with `Error: Your card was declined.`, shown
 * and announced in `#a11y-speak-assertive`; one provider read of the failed
 * intent (`requires_payment_method`, `card_declined` + `generic_decline`,
 * 1001 usd); the retry (card `4242424242424242`) pays the same draft order;
 * and exactly one captured charge and one paid order across both attempts.
 * Timeline ordering, cart-line identity, control focus and frame markers are
 * not asserted here — `card-recovery.test.ts` still covers those on the
 * fuller `validateBlocksDeclineRecovery()` graph.
 */
const CONTRACT_BLOCKS_DECLINE_RECOVERY =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:123::WooCommerce Blocks › Checkout failures › should successfully complete order after retrying with a valid card without refreshing the page';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:card-decline-recovery',
];

const BLOCKS_DECLINE_RECOVERY_CAPABILITIES = [
	'product/payment',
	'card-decline-checkout',
	'basic-card',
	'basic-card-entry',
];

function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
}

test.describe( 'WooPayments native card decline recovery', () => {
	test.describe.configure( { timeout: 420_000 } );

	test(
		'A Blocks generic decline followed by one valid-card retry in the same checkout document pays the same draft order exactly once',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_DECLINE_RECOVERY,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities(
				pilotRuntime,
				BLOCKS_DECLINE_RECOVERY_CAPABILITIES
			);
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'card-recovery-blocks-decline' },
				async () => {
					const product =
						await pilotRuntime.createOwnedProduct( '10.01' );
					const observation = await runBlocksDeclineRecoverySmoke(
						pilotRuntime,
						page,
						product
					);

					expect( observation.firstAttemptStatus ).toBe( 400 );
					expect( observation.successfulRetry.orderId ).toBe(
						observation.draftOrderId
					);
				}
			);
		}
	);
} );
