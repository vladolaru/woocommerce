import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'merchant reaches the exact transaction created by this atomic journey',
	{
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_PR,
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'merchant-transaction-navigation' },
			async () => {
				pilotRuntime.requireApprovedProviderFixture(
					'transaction-navigation'
				);
				const product = await pilotRuntime.createOwnedProduct(
					'15.50'
				);
				const orderId = await pilotRuntime.completeCardCheckout(
					page,
					product,
					runId
				);
				const evidence = await getPaymentEvidence( adminApi, orderId );

				await pilotRuntime.openExactMerchantTransaction(
					page,
					evidence
				);
				await pilotRuntime.expectExactMerchantTransaction(
					page,
					evidence
				);
				await expect(
					page.getByRole( 'heading', {
						name: /^(Payment details|Transaction details)$/,
					} )
				).toBeVisible();
			}
		);
	}
);
