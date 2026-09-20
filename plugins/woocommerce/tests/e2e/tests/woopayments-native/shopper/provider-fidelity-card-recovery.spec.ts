import {
	expect,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	runBlocksDeclineRecovery,
	runBlocksProcessingErrorRecovery,
	runClassicDeclineRecovery,
} from '../../../utils/woopayments-native/drivers/card-recovery';
import { withClassicCheckoutPage } from '../../../utils/woopayments-native/drivers/classic-checkout-page';

const CONTRACT_BLOCKS_PROCESSING_ERROR =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:90::WooCommerce Blocks › Checkout failures › Should show error – An error occurred while processing your card. Try again in a little bit.';
const CONTRACT_CLASSIC_DECLINE_RECOVERY =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-failures.spec.ts:205::Shopper › Checkout › Retry after failure without page refresh › should successfully complete order after retrying with a valid card without refreshing the page';
const CONTRACT_BLOCKS_DECLINE_RECOVERY =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-failures.spec.ts:123::WooCommerce Blocks › Checkout failures › should successfully complete order after retrying with a valid card without refreshing the page';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:card-decline-recovery',
];

const BLOCKS_PROCESSING_ERROR_CAPABILITIES = [
	'product/payment',
	'card-decline-checkout',
];
const CLASSIC_DECLINE_RECOVERY_CAPABILITIES = [
	...BLOCKS_PROCESSING_ERROR_CAPABILITIES,
	'classic-checkout-page',
	'basic-card',
	'basic-card-entry',
];
const BLOCKS_DECLINE_RECOVERY_CAPABILITIES = [
	...BLOCKS_PROCESSING_ERROR_CAPABILITIES,
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
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test(
		'A Blocks processing-error decline shows and announces exact guidance and leaves the same checkout document ready for one explicit correction without an automatic retry',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_BLOCKS_PROCESSING_ERROR,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime } ) => {
			requireCapabilities(
				pilotRuntime,
				BLOCKS_PROCESSING_ERROR_CAPABILITIES
			);
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'card-recovery-blocks-processing-error' },
				async () => {
					const product =
						await pilotRuntime.createOwnedProduct( '10.05' );
					const observation = await runBlocksProcessingErrorRecovery(
						pilotRuntime,
						page,
						product
					);

					expect( observation.surface ).toBe(
						'blocks-processing-error'
					);
				}
			);
		}
	);

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
					const observation = await runBlocksDeclineRecovery(
						pilotRuntime,
						page,
						product
					);

					expect( observation.surface ).toBe( 'blocks-decline' );
				}
			);
		}
	);
} );
