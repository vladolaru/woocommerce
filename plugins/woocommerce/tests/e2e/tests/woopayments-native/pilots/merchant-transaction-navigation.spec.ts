import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	expectExactMerchantTransaction,
	openExactMerchantTransaction,
} from '../../../utils/woopayments-native/drivers/merchant-transactions';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'merchant reaches the exact transaction created by this atomic journey',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-admin-transactions.spec.ts:14::Admin transactions › page should load without errors',
			},
		],
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
				const orderId = await completeCardCheckout(
					pilotRuntime,
					page,
					product,
					runId
				);
				const evidence = await getPaymentEvidence( adminApi, orderId );

				await openExactMerchantTransaction(
					pilotRuntime,
					page,
					evidence
				);
				await expectExactMerchantTransaction(
					pilotRuntime,
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
