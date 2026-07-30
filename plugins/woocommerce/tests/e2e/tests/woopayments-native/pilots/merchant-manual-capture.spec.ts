import { expect, tags, test } from '../../../fixtures/woopayments-native';
import {
	getCaptureOrderNoteEvidence,
	getPaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

test(
	'merchant manually captures one exact authorization and restores capture mode',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-manual-capture.spec.ts:39::Order › Manual Capture › should create an "On hold" order then capture the charge',
			},
		],
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_PR,
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		await pilotRuntime.withCapturedManualCaptureSetting( async () => {
			pilotRuntime.requireApprovedProviderFixture( 'manual-capture' );
			const product = await pilotRuntime.createOwnedProduct( '12.00' );
			const orderId = await pilotRuntime.completeCardCheckout(
				page,
				product,
				runId
			);
			const authorized = await getPaymentEvidence( adminApi, orderId );

			expect( authorized.providerStatus ).toBe( 'requires_capture' );
			expect( authorized.chargeCaptured ).toBe( false );
			expect( authorized.orderStatus ).toBe( 'on-hold' );
			const captured = await pilotRuntime.captureExactOrder(
				page,
				authorized
			);

			expect( captured.orderId ).toBe( authorized.orderId );
			expect( captured.intentId ).toBe( authorized.intentId );
			expect( captured.chargeId ).toBe( authorized.chargeId );
			expect( captured.amountMinor ).toBe( 1200 );
			expect( captured.providerStatus ).toBe( 'succeeded' );
			expect( captured.chargeStatus ).toBe( 'succeeded' );
			expect( captured.chargeCaptured ).toBe( true );
			expect( captured.occurrenceCount ).toBe( 1 );
			expect( captured.captureOccurrenceCount ).toBe( 1 );
			expect( captured.orderStatus ).toBe( 'processing' );
			const captureNote = await getCaptureOrderNoteEvidence(
				adminApi,
				captured
			);
			expect( captureNote.captureNoteCount ).toBe( 1 );
			expect( captureNote.captureNote ).toContain(
				'was <strong>successfully captured</strong> using WooPayments'
			);
			await pilotRuntime.expectCapturedOrderState( page, captured );
		} );
	}
);
