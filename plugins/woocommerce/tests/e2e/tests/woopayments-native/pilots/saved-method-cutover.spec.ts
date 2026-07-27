import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'plugin default saved method survives ephemeral native cutover',
	{
		tag: [
			tags.WOOPAYMENTS_NATIVE,
			tags.WOOPAYMENTS_PROVIDER,
			tags.WOOPAYMENTS_TRANSITION,
			tags.WOOPAYMENTS_PR,
		],
		annotation: [
			{
				type: 'woopayments-contract',
				description:
					'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-save-card-and-purchase.spec.ts:93::Saved cards › When using a 3ds card added on checkout › should process a payment with the saved card',
			},
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
	},
	async ( { adminApi, page, pilotRuntime, runId } ) => {
		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'saved-method-cutover' },
			async () => {
				pilotRuntime.requireApprovedProviderFixture(
					'saved-method-cutover'
				);
				pilotRuntime.requireEphemeralTransitionAllocation();
				const savedCards: Array< {
					tokenId: number;
					paymentMethodId: string;
				} > = [];
				let scenarioFailure: unknown;

				try {
					const firstCard =
						await pilotRuntime.createPluginOwnedSavedCard(
							page,
							`${ runId }-first`
						);
					savedCards.push( firstCard );
					const defaultCard =
						await pilotRuntime.createPluginOwnedSavedCard(
							page,
							`${ runId }-default`
						);
					savedCards.push( defaultCard );
					await pilotRuntime.makeSavedCardDefault(
						page,
						defaultCard.tokenId
					);
					await pilotRuntime.softCutOverEphemeralStore( page );
					const nativeDefaultCard =
						await pilotRuntime.getSavedCardState( [
							firstCard,
							defaultCard,
						] );

					const classicOrderId =
						await pilotRuntime.payWithExactSavedCard(
							page,
							defaultCard,
							'classic',
							runId
						);
					const blocksOrderId =
						await pilotRuntime.payWithExactSavedCard(
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
					expect( classicEvidence.paymentMethodId ).toBe(
						defaultCard.paymentMethodId
					);
					expect( blocksEvidence.paymentMethodId ).toBe(
						defaultCard.paymentMethodId
					);
					expect( classicEvidence.occurrenceCount ).toBe( 1 );
					expect( blocksEvidence.occurrenceCount ).toBe( 1 );
				} catch ( error ) {
					scenarioFailure = error;
				}

				try {
					await pilotRuntime.deleteExactSavedCards(
						page,
						savedCards
					);
				} catch ( cleanupFailure ) {
					if ( scenarioFailure ) {
						throw new AggregateError(
							[ scenarioFailure, cleanupFailure ],
							'The saved-method scenario and exact saved-card cleanup both failed.',
							{ cause: cleanupFailure }
						);
					}
					throw cleanupFailure;
				}

				if ( scenarioFailure ) {
					throw scenarioFailure;
				}
			}
		);
	}
);
