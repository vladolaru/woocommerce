import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
} from '../../../fixtures/woopayments-native';
import { payWithPreselectedHistoricalDefault } from '../../../utils/woopayments-native/drivers/historical-tokens';
import {
	createPluginOwnedSavedCard,
	deleteExactSavedCards,
	getSavedCardState,
	makeSavedCardDefault,
	type SavedCardIdentity,
} from '../../../utils/woopayments-native/drivers/saved-cards';
import { softCutOverEphemeralStore } from '../../../utils/woopayments-native/drivers/store-transition';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

const contractId =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:214::Shopper can save and delete cards › Testing card: basic › should be able to set the basic card as default payment method';

test(
	'plugin default saved method remains preselected after ephemeral native cutover',
	{
		annotation: [
			{
				type: 'woopayments-contract',
				description: contractId,
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
			{ recordEvent: 'historical-tokens' },
			async () => {
				pilotRuntime.requireApprovedProviderFixture(
					'historical-tokens'
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
					await softCutOverEphemeralStore( pilotRuntime, page );
					const nativeDefaultCard = await getSavedCardState(
						pilotRuntime,
						[ firstCard, defaultCard ]
					);
					providerCustomerId = nativeDefaultCard.providerCustomerId;
					const orderId = await payWithPreselectedHistoricalDefault(
						pilotRuntime,
						page,
						defaultCard,
						runId
					);
					const evidence = await getPaymentEvidence(
						adminApi,
						orderId
					);

					expect( firstCard.tokenId ).not.toBe( defaultCard.tokenId );
					expect( nativeDefaultCard.tokenId ).toBe(
						defaultCard.tokenId
					);
					expect( nativeDefaultCard.paymentMethodId ).toBe(
						defaultCard.paymentMethodId
					);
					expect( nativeDefaultCard.isDefault ).toBe( true );
					expect( evidence.orderId ).toBe( orderId );
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
							'The historical-default scenario and exact saved-card cleanup both failed.',
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
								'The historical-default scenario and exact saved-card cleanup both failed.',
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
