import { expect, test } from '../../../fixtures/woopayments-native';
import {
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';
import { waitForPaymentState } from '../../../utils/woopayments-native/provider-evidence';

test(
	'merchant manually captures one exact authorization and restores capture mode',
	{
		tag: [
			'@woopayments-native',
			'@woopayments-provider',
			'@woopayments-pr',
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		pilotRuntime.requireApprovedProviderFixture( 'manual-capture' );

		await pilotRuntime.withCapturedManualCaptureSetting( async () => {
			const product = await pilotRuntime.createOwnedProduct( '12.00' );
			const orderId = await pilotRuntime.completeCardCheckout(
				page,
				product,
				runId
			);
			const authorized = await getPaymentEvidence( adminApi, orderId );

			expect( authorized.providerStatus ).toBe( 'requires_capture' );
			await pilotRuntime.captureExactOrder( page, authorized );
			const captured: PaymentEvidence = await waitForPaymentState(
				adminApi,
				authorized,
				'succeeded',
				Date.now() + 30_000
			);

			expect( captured.orderId ).toBe( authorized.orderId );
			expect( captured.intentId ).toBe( authorized.intentId );
			expect( captured.chargeId ).toBe( authorized.chargeId );
			expect( captured.amountMinor ).toBe( 1200 );
			expect( captured.occurrenceCount ).toBe( 1 );
			await pilotRuntime.expectCapturedOrderState( page, captured );
		} );
	}
);
