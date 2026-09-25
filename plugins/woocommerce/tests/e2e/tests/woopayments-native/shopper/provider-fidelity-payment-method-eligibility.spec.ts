import { createHash } from 'node:crypto';
import { readFile } from 'node:fs/promises';

import { expect, tags, test } from '../../../fixtures/woopayments-native';
import {
	BANCONTACT,
	BE_BILLING,
	driveBlocksRedirectCheckoutWithExistingCart,
	fillBlocksBilling,
	withEnabledPaymentMethod,
	withForeignCurrency,
} from '../../../utils/woopayments-native/drivers/redirect-methods';
import {
	expectNoReusablePaymentCredential,
	observeUsdToEurPaymentMethodEligibility,
	readPaymentMethodEligibilityRestoration,
	readPaymentMethodEligibilityProviderGraph,
	USD_TO_EUR,
	validatePaymentMethodEligibilityObservation,
	validatePaymentMethodEligibilityReconciliation,
	validatePaymentMethodEligibilityRestoration,
	withStoreDefaultCurrency,
	type PaymentMethodEligibilityReconciliation,
	type PaymentMethodEligibilityRestoration,
	type PaymentMethodEligibilityTransition,
} from '../../../utils/woopayments-native/drivers/payment-method-eligibility';
import { getPaymentEvidence } from '../../../utils/woopayments-native/record-evidence';
import retainedEvidence from '../evidence/multi-currency-payment-method-eligibility-reconciliation.json';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:payment-method-eligibility',
];
const ROW_94 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:122::Multi-currency checkout › Available payment methods › should display EUR payment methods when switching to EUR and default is USD';
const RECONCILE_RETAINED_EVIDENCE =
	process.env.E2E_WOOPAYMENTS_PAYMENT_METHOD_ELIGIBILITY_RECONCILIATION ===
	'1';

interface RetainedReconciliation
	extends PaymentMethodEligibilityReconciliation {
	readonly sourceFailure: string;
}

const reconciliation = retainedEvidence as {
	readonly cases: {
		readonly usd_to_eur: RetainedReconciliation;
		readonly eur_to_usd: RetainedReconciliation;
	};
	readonly restoration: PaymentMethodEligibilityRestoration;
};

async function reconcileRetainedCase(
	pilotRuntime: Parameters<
		typeof readPaymentMethodEligibilityProviderGraph
	>[ 0 ],
	transition: PaymentMethodEligibilityTransition,
	retained: RetainedReconciliation,
	sourceLogEnvironmentName: string
): Promise< void > {
	const sourceLogPath = process.env[ sourceLogEnvironmentName ];
	if ( ! sourceLogPath ) {
		throw new Error(
			`${ sourceLogEnvironmentName } is required for read-only payment-method eligibility reconciliation.`
		);
	}
	const sourceLog = await readFile( sourceLogPath );
	expect(
		createHash( 'sha256' ).update( sourceLog ).digest( 'hex' ),
		'retained provider result must match its recorded SHA-256'
	).toBe( retained.sourceResultSha256 );
	expect(
		sourceLog.toString( 'utf8' ),
		'retained provider result must contain its exact post-payment failure'
	).toContain( retained.sourceFailure );

	const evidence = await getPaymentEvidence(
		pilotRuntime.adminApi,
		retained.orderId
	);
	const graph = await readPaymentMethodEligibilityProviderGraph(
		pilotRuntime,
		retained.orderId
	);
	await expectNoReusablePaymentCredential( pilotRuntime, graph );
	validatePaymentMethodEligibilityReconciliation(
		transition,
		retained,
		evidence,
		graph
	);
	validatePaymentMethodEligibilityRestoration(
		await readPaymentMethodEligibilityRestoration( pilotRuntime ),
		reconciliation.restoration
	);
}

async function reconcileOrRunCase(
	pilotRuntime: Parameters<
		typeof readPaymentMethodEligibilityProviderGraph
	>[ 0 ],
	transition: PaymentMethodEligibilityTransition,
	retained: RetainedReconciliation,
	sourceLogEnvironmentName: string,
	run: () => Promise< void >
): Promise< void > {
	if ( RECONCILE_RETAINED_EVIDENCE ) {
		await reconcileRetainedCase(
			pilotRuntime,
			transition,
			retained,
			sourceLogEnvironmentName
		);
		return;
	}
	await run();
}

test.describe( 'WooPayments native payment-method eligibility fidelity', () => {
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	// Assertions live in validatePaymentMethodEligibilityObservation and
	// expectNoReusablePaymentCredential, which fail() on a mismatch rather
	// than call expect() directly.
	// eslint-disable-next-line playwright/expect-expect
	test(
		'A same-session switch from USD to EUR adds Bancontact while retaining Card and one selected Bancontact checkout settles exactly 1099 eur',
		{
			annotation: [
				{ type: 'woopayments-contract', description: ROW_94 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime, runId } ) => {
			await reconcileOrRunCase(
				pilotRuntime,
				USD_TO_EUR,
				reconciliation.cases.usd_to_eur,
				'E2E_WOOPAYMENTS_PAYMENT_METHOD_ELIGIBILITY_ROW_94_LOG',
				async () =>
					pilotRuntime.withProviderWriteLocks(
						{
							featureSetting: 'payment-method-eligibility',
							recordEvent: 'payment-method-eligibility-94',
						},
						async () =>
							withStoreDefaultCurrency(
								pilotRuntime,
								'USD',
								async () =>
									withForeignCurrency(
										pilotRuntime,
										'EUR',
										'multi-currency-settlement-currency',
										async () =>
											withEnabledPaymentMethod(
												pilotRuntime,
												BANCONTACT,
												'redirect-method-provider-outcome-method',
												async () => {
													const product =
														await pilotRuntime.createOwnedProduct(
															'10.99',
															{
																taxStatus:
																	'none',
															}
														);
													await page.goto(
														`?post_type=product&p=${ product.id }`
													);
													await pilotRuntime.performWrite(
														() =>
															page
																.getByRole(
																	'button',
																	{
																		name: 'Add to cart',
																		exact: true,
																	}
																)
																.click()
													);
													await page.goto(
														'checkout/'
													);
													const observation =
														await observeUsdToEurPaymentMethodEligibility(
															page,
															{
																currencyLabel:
																	page.locator(
																		'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
																	),
																prepareCheckout:
																	() =>
																		fillBlocksBilling(
																			page,
																			runId,
																			BE_BILLING
																		),
															}
														);
													const paid =
														await driveBlocksRedirectCheckoutWithExistingCart(
															pilotRuntime,
															page,
															{
																method: BANCONTACT,
																runId,
																journal:
																	'payment-method-eligibility-94',
																follow: true,
															}
														);
													const graph =
														await readPaymentMethodEligibilityProviderGraph(
															pilotRuntime,
															paid.orderId
														);
													await expectNoReusablePaymentCredential(
														pilotRuntime,
														graph
													);
													validatePaymentMethodEligibilityObservation(
														USD_TO_EUR,
														{
															storeDefaultCurrency:
																'USD',
															...observation,
															providerGraph:
																graph,
														}
													);
												}
											)
									)
							)
					)
			);
		}
	);
} );
