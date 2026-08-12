import type { APIRequestContext } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	captureExactOrder,
	CAPTURE_SETTLE_BUDGET_MS,
	expectCapturedOrderState,
	withCapturedManualCaptureSetting,
} from '../../../utils/woopayments-native/drivers/manual-capture';
import {
	getAuthorizationOrderNoteEvidence,
	getCaptureOrderNoteEvidence,
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

/**
 * The `manual-authorization-capture` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`. It has exactly one case, `C1`,
 * and this spec is it.
 *
 * The claim: a USD 10.99 checkout under manual capture leaves exactly one
 * uncaptured authorization at the real provider, and one merchant capture
 * action captures that same intent and charge, for that amount and currency,
 * exactly once. What falsifies it is an authorization captured without the
 * merchant action, captured twice, or captured against a different intent,
 * charge, amount or currency — so the whole case turns on the graph being
 * exactly one authorization and exactly one capture, on the same identities.
 *
 * The case stays in this file rather than moving to a new fidelity suite. The
 * ledger row it discharges already names this path and this title as its
 * target, this test already carries its `woopayments-contract` annotation, and
 * the annotation↔ledger binding check rejects a second test claiming the same
 * row. What the case gained is the rest of `C1`'s fixed contract: the claim's
 * USD 10.99 fixture, the authorization-side cardinality and note count, the
 * two-identical-reads convergence at *both* terminal states inside the claim's
 * 60-second budget, and a final read proving no second capture.
 *
 * The manual-capture setting is snapshotted raw and restored byte-for-byte by
 * `withCapturedManualCaptureSetting`, which quarantines the account, store and
 * feature-setting locks if the restore cannot be proven.
 */

/** The claim's fixture. */
const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
/** The claim's Convergence row: every 2 seconds, at most 60. */
const CONVERGENCE_INTERVAL_MS = 2_000;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

/**
 * Everything the claim fixes about one terminal state, in one string. Two
 * identical reads of it are what the claim means by converged: a single read
 * can catch a value mid-propagation, and neither of these states regresses.
 */
function paymentSignature( evidence: PaymentEvidence ): string {
	return [
		evidence.orderId,
		evidence.orderKey,
		evidence.intentId,
		evidence.chargeId,
		evidence.paymentMethodId,
		evidence.amountMinor,
		evidence.currency,
		evidence.orderStatus,
		evidence.providerStatus,
		evidence.chargeStatus,
		evidence.chargeCaptured,
		evidence.occurrenceCount,
		evidence.captureOccurrenceCount,
	].join( '|' );
}

/**
 * Poll the exact order, intent and charge until the listed terminal state is
 * read twice identically, exactly as the claim's Convergence row fixes it.
 */
async function convergeOnPaymentState(
	restApi: APIRequestContext,
	orderId: number,
	expected: { providerStatus: string; orderStatus: string }
): Promise< PaymentEvidence > {
	const deadline = Date.now() + CAPTURE_SETTLE_BUDGET_MS;
	let previousSignature = '';
	let lastSeen = 'no read yet';

	for (;;) {
		const evidence = await getPaymentEvidence( restApi, orderId );
		lastSeen = `${ evidence.providerStatus } / ${ evidence.orderStatus }`;

		if (
			evidence.providerStatus === expected.providerStatus &&
			evidence.orderStatus === expected.orderStatus
		) {
			const signature = paymentSignature( evidence );
			if ( signature === previousSignature ) {
				return evidence;
			}
			previousSignature = signature;
		}

		const remaining = deadline - Date.now();
		if ( remaining <= 0 ) {
			throw new Error(
				`Order ${ orderId } did not reach two stable ${ expected.providerStatus } / ${ expected.orderStatus } reads within ${ CAPTURE_SETTLE_BUDGET_MS }ms (last seen: ${ lastSeen }).`
			);
		}
		await delay( Math.min( CONVERGENCE_INTERVAL_MS, remaining ) );
	}
}

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
			'@fidelity:manual-authorization-capture',
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		test.setTimeout( 300_000 );

		await withCapturedManualCaptureSetting( pilotRuntime, async () => {
			pilotRuntime.requireApprovedProviderFixture( 'manual-capture' );
			const product = await pilotRuntime.createOwnedProduct( PRICE );
			const orderId = await completeCardCheckout(
				pilotRuntime,
				page,
				product,
				runId
			);

			// Authorization: one uncaptured `1099 usd` charge on one
			// `requires_capture` intent, held on an on-hold order, read twice
			// identically before anything acts on it.
			const authorized = await convergeOnPaymentState(
				adminApi,
				orderId,
				{
					providerStatus: 'requires_capture',
					orderStatus: 'on-hold',
				}
			);

			expect( authorized.orderId ).toBe( orderId );
			expect( authorized.amountMinor ).toBe( AMOUNT_MINOR );
			expect( authorized.currency ).toBe( CURRENCY );
			expect( authorized.providerStatus ).toBe( 'requires_capture' );
			expect( authorized.chargeCaptured ).toBe( false );
			expect( authorized.orderStatus ).toBe( 'on-hold' );
			// One authorization, and no capture has happened yet: the claim's
			// falsifier is an authorization captured without the merchant
			// action, so this is where that would show.
			expect( authorized.occurrenceCount ).toBe( 1 );
			expect( authorized.captureOccurrenceCount ).toBe( 0 );

			const authorizationNote = await getAuthorizationOrderNoteEvidence(
				adminApi,
				authorized
			);
			expect(
				authorizationNote.authorizationNoteCount,
				'one authorization must journal exactly one authorization note'
			).toBe( 1 );
			expect( authorizationNote.authorizationNote ).toContain(
				'was <strong>authorized</strong> using WooPayments'
			);

			const captured = await captureExactOrder(
				pilotRuntime,
				page,
				authorized
			);

			expect( captured.orderId ).toBe( authorized.orderId );
			expect( captured.intentId ).toBe( authorized.intentId );
			expect( captured.chargeId ).toBe( authorized.chargeId );
			expect( captured.amountMinor ).toBe( AMOUNT_MINOR );
			expect( captured.currency ).toBe( CURRENCY );
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
			await expectCapturedOrderState( pilotRuntime, page, captured );

			// The capture's own terminal state, read twice identically, and
			// then one final cold read: the same intent and charge, still
			// captured exactly once. This is the claim's "a final read proves
			// no second capture".
			const settled = await convergeOnPaymentState( adminApi, orderId, {
				providerStatus: 'succeeded',
				orderStatus: 'processing',
			} );
			expect( paymentSignature( settled ) ).toBe(
				paymentSignature( captured )
			);

			const finalRead = await getPaymentEvidence( adminApi, orderId );
			expect( finalRead.intentId ).toBe( authorized.intentId );
			expect( finalRead.chargeId ).toBe( authorized.chargeId );
			expect( finalRead.amountMinor ).toBe( AMOUNT_MINOR );
			expect( finalRead.currency ).toBe( CURRENCY );
			expect(
				finalRead.captureOccurrenceCount,
				'the authorization must be captured exactly once'
			).toBe( 1 );
			expect( finalRead.occurrenceCount ).toBe( 1 );
			expect(
				( await getCaptureOrderNoteEvidence( adminApi, finalRead ) )
					.captureNoteCount,
				'one capture must journal exactly one capture note'
			).toBe( 1 );
		} );
	}
);
