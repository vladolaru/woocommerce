import { expect, tags, test } from '../../../fixtures/woopayments-native';
import { completeCardCheckoutWithExistingCart } from '../../../utils/woopayments-native/drivers/checkout';
import {
	BANCONTACT,
	BE_BILLING,
	driveBlocksRedirectCheckoutWithExistingCart,
	fillBlocksBilling,
	withEnabledPaymentMethod,
	withForeignCurrency,
} from '../../../utils/woopayments-native/drivers/redirect-methods';
import {
	EUR_TO_USD,
	expectNoReusablePaymentCredential,
	observeEurToUsdPaymentMethodEligibility,
	observeUsdToEurPaymentMethodEligibility,
	readPaymentMethodEligibilityProviderGraph,
	readConfiguredStoreDefaultCurrency,
	USD_TO_EUR,
	validatePaymentMethodEligibilityObservation,
	withStoreDefaultCurrency,
} from '../../../utils/woopayments-native/drivers/payment-method-eligibility';

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:payment-method-eligibility',
];
const ROW_94 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:122::Multi-currency checkout › Available payment methods › should display EUR payment methods when switching to EUR and default is USD';
const ROW_95 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:162::Multi-currency checkout › Available payment methods › should display USD payment methods when switching to USD and default is EUR';

test.describe( 'WooPayments native payment-method eligibility fidelity', () => {
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test(
		'A same-session switch from USD to EUR adds Bancontact while retaining Card and one selected Bancontact checkout settles exactly 1099 eur',
		{
			annotation: [
				{ type: 'woopayments-contract', description: ROW_94 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime, runId } ) => {
			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'payment-method-eligibility',
					recordEvent: 'payment-method-eligibility-94',
				},
				async () =>
					withStoreDefaultCurrency( pilotRuntime, 'USD', async () =>
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
										expect(
											await readConfiguredStoreDefaultCurrency(
												pilotRuntime
											)
										).toBe( 'USD' );
										const product =
											await pilotRuntime.createOwnedProduct(
												'10.99',
												{ taxStatus: 'none' }
											);
										await page.goto(
											`?post_type=product&p=${ product.id }`
										);
										await pilotRuntime.performWrite( () =>
											page
												.getByRole( 'button', {
													name: 'Add to cart',
													exact: true,
												} )
												.click()
										);
										await page.goto( 'checkout/' );
										const observation =
											await observeUsdToEurPaymentMethodEligibility(
												page,
												{
													currencyLabel: page.locator(
														'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
													),
													prepareCheckout: () =>
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
												storeDefaultCurrency: 'USD',
												...observation,
												providerGraph: graph,
											}
										);
									}
								)
						)
					)
			);
		}
	);

	test(
		'A same-session switch from EUR to USD removes Bancontact while retaining Card and one selected Card checkout settles exactly 1099 usd',
		{
			annotation: [
				{ type: 'woopayments-contract', description: ROW_95 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, pilotRuntime, runId } ) => {
			expect(
				await readConfiguredStoreDefaultCurrency( pilotRuntime )
			).toBe( 'USD' );
			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'payment-method-eligibility',
					recordEvent: 'payment-method-eligibility-95',
				},
				async () =>
					withStoreDefaultCurrency( pilotRuntime, 'EUR', async () =>
						withForeignCurrency(
							pilotRuntime,
							'USD',
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
												{ taxStatus: 'none' }
											);
										await page.goto(
											`?post_type=product&p=${ product.id }`
										);
										await pilotRuntime.performWrite( () =>
											page
												.getByRole( 'button', {
													name: 'Add to cart',
													exact: true,
												} )
												.click()
										);
										await page.goto( 'checkout/' );
										const observation =
											await observeEurToUsdPaymentMethodEligibility(
												page,
												{
													currencyLabel: page.locator(
														'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
													),
													prepareCheckout: () =>
														fillBlocksBilling(
															page,
															runId,
															BE_BILLING
														),
												}
											);
										const orderId =
											await completeCardCheckoutWithExistingCart(
												pilotRuntime,
												page,
												runId
											);
										const graph =
											await readPaymentMethodEligibilityProviderGraph(
												pilotRuntime,
												orderId
											);
										await expectNoReusablePaymentCredential(
											pilotRuntime,
											graph
										);
										validatePaymentMethodEligibilityObservation(
											EUR_TO_USD,
											{
												storeDefaultCurrency: 'EUR',
												...observation,
												providerGraph: graph,
											}
										);
									}
								)
						)
					)
			);
		}
	);
} );
