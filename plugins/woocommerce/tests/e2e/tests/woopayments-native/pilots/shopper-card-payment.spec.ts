import { expect, test } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'shopper card payment retains exact order and provider evidence',
	{
		tag: [
			'@woopayments-native',
			'@woopayments-provider',
			'@woopayments-pr',
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-payment' },
			async () => {
				const product = await pilotRuntime.createOwnedProduct(
					'10.99'
				);
				const orderId = await pilotRuntime.completeCardCheckout(
					page,
					product,
					runId
				);
				const evidence = await getPaymentEvidence( adminApi, orderId );

				expect( evidence.runId ).toBe( runId );
				expect( evidence.orderId ).toBe( orderId );
				expect( evidence.intentId ).not.toBe( '' );
				expect( evidence.chargeId ).not.toBe( '' );
				expect( evidence.paymentMethodId ).not.toBe( '' );
				expect( evidence.amountMinor ).toBe( 1099 );
				expect( evidence.currency ).toBe( 'USD' );
				expect( [ 'processing', 'completed' ] ).toContain(
					evidence.orderStatus
				);
				expect( evidence.providerStatus ).toBe( 'succeeded' );
				expect( evidence.chargeStatus ).toBe( 'succeeded' );
				expect( evidence.chargeCaptured ).toBe( true );
				expect( evidence.occurrenceCount ).toBe( 1 );
				expect( evidence.captureOccurrenceCount ).toBe( 1 );
				await expect(
					page.getByText( 'Your order has been received' )
				).toBeVisible();
			}
		);
	}
);
