import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	expectExactMerchantTransaction,
	openExactMerchantTransaction,
} from '../../../utils/woopayments-native/drivers/merchant-transactions';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

// This pilot no longer claims the transactions page-load contract
// (merchant-admin-transactions.spec.ts:14). That row's ledger target is
// merchant/overview-transactions.spec.ts, and its target_contract is the
// page-load title, which this provider journey does not carry: it proves
// navigation to one exact provider-created transaction, not the independent
// list load. A second annotation for the same case_id is rejected outright by
// the annotation↔ledger binding check, so the claim lives only in the ledger's
// designated target now.
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
				const product =
					await pilotRuntime.createOwnedProduct( '15.50' );
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
