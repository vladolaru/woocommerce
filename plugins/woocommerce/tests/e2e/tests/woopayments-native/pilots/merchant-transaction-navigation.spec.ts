import { expect, test } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'merchant reaches the exact transaction created by this atomic journey',
	{
		tag: [
			'@woopayments-native',
			'@woopayments-provider',
			'@woopayments-pr',
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

				await expect(
					page.getByRole( 'heading', {
						name: new RegExp( evidence.orderId.toString() ),
					} )
				).toBeVisible();
				await expect(
					page.getByText( evidence.currency, { exact: false } )
				).toBeVisible();
				await expect(
					page.getByText( evidence.providerStatus, { exact: false } )
				).toBeVisible();
				await expect(
					page.getByRole( 'button', {
						name: /refund|capture|view order/i,
					} )
				).toBeVisible();
			}
		);
	}
);
