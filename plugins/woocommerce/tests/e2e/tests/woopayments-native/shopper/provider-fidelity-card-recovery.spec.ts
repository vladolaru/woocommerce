import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	runBlocksDeclineRecoverySmoke,
	runClassicDeclineRecovery,
} from '../../../utils/woopayments-native/drivers/card-recovery';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';

/**
 * The Blocks processing-error decline (no-retry) moved out in T.1 batch 2:
 * its assertions are proven at the PHPUnit and Jest layers cited in
 * `DISPOSITION.tsv` row 18. This file's Blocks case is the family's retained
 * provider-backed smoke for that layer split, trimmed to the audit §3
 * minimal oracle via
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
 *
 * The Classic generic-decline retry case was removed in the same batch onto
 * the same PHPUnit rows (`DISPOSITION.tsv` row 19), but N-122(3) treats it as
 * a rule-3 classic-surface gap: client contract row 113's Classic surface
 * ("Retry after failure without page refresh") had no native browser owner.
 * T.3 Task 1 restores it byte-identical from `ee9e87f401^` (D4) as this
 * file's second case, kept pending T.4's rewrite of the retained specs onto
 * shared e2e helpers.
 */
const CONTRACT_BLOCKS_DECLINE_RECOVERY =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:123::WooCommerce Blocks › Checkout failures › should successfully complete order after retrying with a valid card without refreshing the page';
const CONTRACT_CLASSIC_DECLINE_RECOVERY =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:205::Shopper › Checkout › Retry after failure without page refresh › should successfully complete order after retrying with a valid card without refreshing the page';
// The Blocks case's first attempt answers with `Error: Your card was
// declined.`, which is also the client's generic-decline message case.
const CONTRACT_BLOCKS_CARD_DECLINED_MESSAGE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – Your card was declined.';

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
const CLASSIC_DECLINE_RECOVERY_CAPABILITIES = [
	...BLOCKS_DECLINE_RECOVERY_CAPABILITIES,
	'classic-checkout-page',
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
	// Serial on purpose: the Classic case provisions the shared
	// `classic-checkout-page` resource, and both cases spend real provider
	// budget, so a failure must halt the case after it rather than run a
	// second real decline against a store whose state is no longer described.
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test(
		'A Classic generic decline followed by one valid-card retry in the same checkout document creates one successful payment effect and no duplicate paid order or charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_CLASSIC_DECLINE_RECOVERY,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities(
				pilotRuntime,
				CLASSIC_DECLINE_RECOVERY_CAPABILITIES
			);
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'card-recovery-classic-decline' },
				async () => {
					await withClassicCheckoutPage(
						pilotRuntime,
						pilotRuntime.runId,
						async ( scope ) => {
							const product =
								await pilotRuntime.createOwnedProduct(
									'10.01'
								);
							const observation = await runClassicDeclineRecovery(
								pilotRuntime,
								page,
								product,
								scope.classicCheckout
							);

							expect( observation.surface ).toBe( 'classic' );
						}
					);
				}
			);
		}
	);

	test(
		'A Blocks generic decline followed by one valid-card retry in the same checkout document pays the same draft order exactly once',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_DECLINE_RECOVERY,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_CARD_DECLINED_MESSAGE,
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
