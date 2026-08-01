import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
} from '../../../fixtures/woopayments-native';
import {
	createPluginOwnedSavedCard,
	deleteExactSavedCards,
	getSavedCardState,
	makeSavedCardDefault,
	payWithExactSavedCard,
	type SavedCardIdentity,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'plugin default saved method survives ephemeral native cutover',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:93::Saved cards › When using a basic card added on checkout › should process a payment with the saved card',
			},
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-saved-card-checkout-and-usage.spec.ts:71::WooCommerce Blocks › Saved cards › should process a payment with the saved card from Blocks checkout',
			},
		],
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_TRANSITION,
			tags.WOOPAYMENTS_PR,
		],
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-method-cutover' },
			async () => {
				pilotRuntime.requireApprovedProviderFixture(
					'saved-method-cutover'
				);
				pilotRuntime.requireEphemeralTransitionAllocation();
				const savedCards: SavedCardIdentity[] = [];
				let scenarioFailure: unknown;
				let providerCustomerId: string | undefined;

				try {
					const firstCard = await createPluginOwnedSavedCard(
						pilotRuntime,
						page,
						`${ runId }-first`
					);
					savedCards.push( firstCard );
					const defaultCard = await createPluginOwnedSavedCard(
						pilotRuntime,
						page,
						`${ runId }-default`
					);
					savedCards.push( defaultCard );
					await makeSavedCardDefault(
						pilotRuntime,
						page,
						defaultCard.tokenId
					);
					await pilotRuntime.softCutOverEphemeralStore( page );
					const nativeDefaultCard = await getSavedCardState(
						pilotRuntime,
						[ firstCard, defaultCard ]
					);
					providerCustomerId = nativeDefaultCard.providerCustomerId;

					const classicOrderId = await payWithExactSavedCard(
						pilotRuntime,
						page,
						defaultCard,
						'classic',
						runId
					);
					const blocksOrderId = await payWithExactSavedCard(
						pilotRuntime,
						page,
						defaultCard,
						'blocks',
						runId
					);
					const classicEvidence = await getPaymentEvidence(
						adminApi,
						classicOrderId
					);
					const blocksEvidence = await getPaymentEvidence(
						adminApi,
						blocksOrderId
					);

					expect( firstCard.tokenId ).not.toBe( defaultCard.tokenId );
					expect( nativeDefaultCard.tokenId ).toBe(
						defaultCard.tokenId
					);
					expect( nativeDefaultCard.paymentMethodId ).toBe(
						defaultCard.paymentMethodId
					);
					expect( nativeDefaultCard.isDefault ).toBe( true );
					expect( classicEvidence.orderId ).toBe( classicOrderId );
					expect( blocksEvidence.orderId ).toBe( blocksOrderId );
					expect( classicEvidence.intentId ).not.toBe(
						blocksEvidence.intentId
					);
					for ( const evidence of [
						classicEvidence,
						blocksEvidence,
					] ) {
						expect( evidence.runId ).toBe( runId );
						expect( evidence.paymentMethodId ).toBe(
							defaultCard.paymentMethodId
						);
						expect( [ 'processing', 'completed' ] ).toContain(
							evidence.orderStatus
						);
						expect( evidence.providerStatus ).toBe( 'succeeded' );
						expect( evidence.chargeStatus ).toBe( 'succeeded' );
						expect( evidence.chargeCaptured ).toBe( true );
						expect( evidence.amountMinor ).toBe( 1099 );
						expect( evidence.currency ).toBe( 'USD' );
						expect( evidence.occurrenceCount ).toBe( 1 );
						expect( evidence.captureOccurrenceCount ).toBe( 1 );
					}
				} catch ( error ) {
					scenarioFailure = error;
				}

				try {
					await deleteExactSavedCards(
						pilotRuntime,
						page,
						savedCards,
						providerCustomerId
					);
				} catch ( cleanupFailure ) {
					if ( scenarioFailure ) {
						throw new ResourceQuarantineRequiredError(
							'The saved-method scenario and exact saved-card cleanup both failed.',
							'cleanup-failed',
							new AggregateError(
								[
									scenarioFailure,
									cleanupFailure instanceof
									ResourceQuarantineRequiredError
										? cleanupFailure.primaryError ??
										  cleanupFailure
										: cleanupFailure,
								],
								'The saved-method scenario and exact saved-card cleanup both failed.',
								{ cause: cleanupFailure }
							)
						);
					}
					throw cleanupFailure instanceof
						ResourceQuarantineRequiredError
						? cleanupFailure
						: new ResourceQuarantineRequiredError(
								'Exact saved-card cleanup failed.',
								'cleanup-failed',
								cleanupFailure
						  );
				}

				if ( scenarioFailure ) {
					throw scenarioFailure;
				}
			}
		);
	}
);
