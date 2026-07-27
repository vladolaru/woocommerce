import { expect, test } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';

test(
	'plugin default saved method survives ephemeral native cutover',
	{
		tag: [
			'@woopayments-native',
			'@woopayments-provider',
			'@woopayments-transition',
			'@woopayments-pr',
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
