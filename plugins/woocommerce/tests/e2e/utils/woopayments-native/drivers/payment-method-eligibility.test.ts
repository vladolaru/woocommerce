import { expect, test } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import type { PaymentEvidence } from '../record-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	createPaymentMethodEligibilityProviderGraph,
	EUR_TO_USD,
	parseRenderedCheckoutCurrency,
	USD_TO_EUR,
	validatePaymentMethodEligibilityObservation,
	validatePaymentMethodEligibilityReconciliation,
	validatePaymentMethodEligibilityRestoration,
	withStoreDefaultCurrency,
	type PaymentMethodEligibilityObservation,
	type PaymentMethodEligibilityReconciliation,
} from './payment-method-eligibility';

function observation(
	overrides: Partial< PaymentMethodEligibilityObservation > = {}
): PaymentMethodEligibilityObservation {
	return {
		storeDefaultCurrency: 'USD',
		before: {
			currencyLabel: 'USD',
			cart: { currency: 'USD', itemsCount: 1 },
			controls: {
				card: { accessibleCount: 1, hiddenEnabledCount: 0 },
				bancontact: { accessibleCount: 0, hiddenEnabledCount: 0 },
			},
		},
		after: {
			currencyLabel: 'EUR',
			cart: { currency: 'EUR', itemsCount: 1 },
			controls: {
				card: { accessibleCount: 1, hiddenEnabledCount: 0 },
				bancontact: { accessibleCount: 1, hiddenEnabledCount: 0 },
			},
		},
		providerGraph: {
			intentId: 'pi_payment_method_eligibility',
			amountMinor: 1099,
			currency: 'eur',
			paymentMethodId: 'pm_payment_method_eligibility',
			paymentMethodTypes: [ 'bancontact' ],
			occurrenceCount: 1,
			captureOccurrenceCount: 1,
			providerStatus: 'succeeded',
			chargeStatus: 'succeeded',
			chargeCaptured: true,
		},
		...overrides,
	};
}

function currencyResponse( value: string ) {
	return {
		ok: () => true,
		json: async () => ( { value } ),
		text: async () => '',
	};
}

function reconciliation(): {
	record: PaymentMethodEligibilityReconciliation;
	evidence: PaymentEvidence;
} {
	const completeObservation = observation();
	const retainedObservation = {
		storeDefaultCurrency: completeObservation.storeDefaultCurrency,
		before: completeObservation.before,
		after: completeObservation.after,
	};
	return {
		record: {
			sourceResultSha256: 'a'.repeat( 64 ),
			orderId: 4531,
			expectedEvidence: {
				runId: 'woopayments-run-94',
				intentId: 'pi_payment_method_eligibility',
				chargeId: 'py_payment_method_eligibility',
				paymentMethodId: 'pm_payment_method_eligibility',
				orderStatus: 'processing',
			},
			observation: retainedObservation,
		},
		evidence: {
			runId: 'woopayments-run-94',
			orderId: 4531,
			orderKey: 'wc_order_payment_method_eligibility',
			intentId: 'pi_payment_method_eligibility',
			chargeId: 'py_payment_method_eligibility',
			paymentMethodId: 'pm_payment_method_eligibility',
			amountMinor: 1099,
			currency: 'EUR',
			orderStatus: 'processing',
			providerStatus: 'succeeded',
			chargeStatus: 'succeeded',
			chargeCaptured: true,
			occurrenceCount: 1,
			captureOccurrenceCount: 1,
		},
	};
}

function currencySession(
	options: {
		configured?: 'USD' | 'EUR';
		restoreError?: Error;
	} = {}
): {
	session: ProviderWriteSession;
	calls: string[];
	events: string[];
} {
	let configured = options.configured ?? 'USD';
	const calls: string[] = [];
	const events: string[] = [];
	return {
		calls,
		events,
		session: {
			adminApi: {
				get: async () => {
					calls.push( `GET:${ configured }` );
					return currencyResponse( configured );
				},
				put: async (
					_url: string,
					request: { data: { value: 'USD' | 'EUR' } }
				) => {
					calls.push( `PUT:${ request.data.value }` );
					if (
						options.restoreError &&
						request.data.value === ( options.configured ?? 'USD' )
					) {
						throw options.restoreError;
					}
					configured = request.data.value;
					return currencyResponse( configured );
				},
			} as unknown as ProviderWriteSession[ 'adminApi' ],
			requireApprovedProviderFixture: ( capability: string ) =>
				events.push( `capability:${ capability }` ),
			assertCanWrite: async () => {
				events.push( 'assert-write' );
			},
			performWrite: async < Result >(
				write: () => Promise< Result >
			) => {
				events.push( 'write' );
				return write();
			},
		} as unknown as ProviderWriteSession,
	};
}

test( 'accepts the ordered USD-to-EUR and EUR-to-USD eligibility observations', () => {
	const usdToEur = validatePaymentMethodEligibilityObservation(
		USD_TO_EUR,
		observation()
	);
	const eurToUsd = validatePaymentMethodEligibilityObservation(
		EUR_TO_USD,
		observation( {
			before: {
				currencyLabel: 'EUR',
				cart: { currency: 'EUR', itemsCount: 1 },
				controls: {
					card: { accessibleCount: 1, hiddenEnabledCount: 0 },
					bancontact: { accessibleCount: 1, hiddenEnabledCount: 0 },
				},
			},
			after: {
				currencyLabel: 'USD',
				cart: { currency: 'USD', itemsCount: 1 },
				controls: {
					card: { accessibleCount: 1, hiddenEnabledCount: 0 },
					bancontact: { accessibleCount: 0, hiddenEnabledCount: 0 },
				},
			},
			providerGraph: {
				intentId: 'pi_payment_method_eligibility',
				amountMinor: 1099,
				currency: 'usd',
				paymentMethodId: 'pm_payment_method_eligibility',
				paymentMethodTypes: [ 'card' ],
				occurrenceCount: 1,
				captureOccurrenceCount: 1,
				providerStatus: 'succeeded',
				chargeStatus: 'succeeded',
				chargeCaptured: true,
			},
			storeDefaultCurrency: 'EUR',
		} )
	);

	expect( usdToEur.after.currencyLabel ).toBe( 'EUR' );
	expect( eurToUsd.after.controls.bancontact.accessibleCount ).toBe( 0 );
} );

test( 'rejects duplicate accessible payment controls', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			USD_TO_EUR,
			observation( {
				after: {
					...observation().after,
					controls: {
						...observation().after.controls,
						bancontact: {
							accessibleCount: 2,
							hiddenEnabledCount: 0,
						},
					},
				},
			} )
		)
	).toThrow( /exactly one accessible Bancontact control/ );
} );

test( 'rejects a hidden enabled ineligible payment control', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			EUR_TO_USD,
			observation( {
				before: {
					currencyLabel: 'EUR',
					cart: { currency: 'EUR', itemsCount: 1 },
					controls: {
						card: { accessibleCount: 1, hiddenEnabledCount: 0 },
						bancontact: {
							accessibleCount: 1,
							hiddenEnabledCount: 0,
						},
					},
				},
				after: {
					currencyLabel: 'USD',
					cart: { currency: 'USD', itemsCount: 1 },
					controls: {
						card: { accessibleCount: 1, hiddenEnabledCount: 0 },
						bancontact: {
							accessibleCount: 0,
							hiddenEnabledCount: 1,
						},
					},
				},
				providerGraph: {
					intentId: 'pi_payment_method_eligibility',
					amountMinor: 1099,
					currency: 'usd',
					paymentMethodId: 'pm_payment_method_eligibility',
					paymentMethodTypes: [ 'card' ],
					occurrenceCount: 1,
					captureOccurrenceCount: 1,
					providerStatus: 'succeeded',
					chargeStatus: 'succeeded',
					chargeCaptured: true,
				},
				storeDefaultCurrency: 'EUR',
			} )
		)
	).toThrow( /hidden enabled Bancontact control/ );
} );

test( 'rejects stale Bancontact after the EUR-to-USD switch', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			EUR_TO_USD,
			observation( {
				storeDefaultCurrency: 'EUR',
				before: {
					currencyLabel: 'EUR',
					cart: { currency: 'EUR', itemsCount: 1 },
					controls: {
						card: { accessibleCount: 1, hiddenEnabledCount: 0 },
						bancontact: {
							accessibleCount: 1,
							hiddenEnabledCount: 0,
						},
					},
				},
				after: {
					currencyLabel: 'USD',
					cart: { currency: 'USD', itemsCount: 1 },
					controls: {
						card: { accessibleCount: 1, hiddenEnabledCount: 0 },
						bancontact: {
							accessibleCount: 1,
							hiddenEnabledCount: 0,
						},
					},
				},
				providerGraph: {
					intentId: 'pi_payment_method_eligibility',
					amountMinor: 1099,
					currency: 'usd',
					paymentMethodId: 'pm_payment_method_eligibility',
					paymentMethodTypes: [ 'card' ],
					occurrenceCount: 1,
					captureOccurrenceCount: 1,
					providerStatus: 'succeeded',
					chargeStatus: 'succeeded',
					chargeCaptured: true,
				},
			} )
		)
	).toThrow( /Bancontact must be unavailable/ );
} );

test( 'rejects a label that changes before the cart and session converge', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			USD_TO_EUR,
			observation( {
				after: {
					...observation().after,
					cart: { currency: 'USD', itemsCount: 1 },
				},
			} )
		)
	).toThrow( /Store API shopper cart currency must be EUR/ );
} );

test( 'rejects a provider graph with the wrong amount or currency', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			USD_TO_EUR,
			observation( {
				providerGraph: {
					intentId: 'pi_payment_method_eligibility',
					amountMinor: 1100,
					currency: 'usd',
					paymentMethodId: 'pm_payment_method_eligibility',
					paymentMethodTypes: [ 'bancontact' ],
					occurrenceCount: 1,
					captureOccurrenceCount: 1,
					providerStatus: 'succeeded',
					chargeStatus: 'succeeded',
					chargeCaptured: true,
				},
			} )
		)
	).toThrow( /1099 eur/ );
} );

test( 'rejects duplicate provider graphs', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			USD_TO_EUR,
			observation( {
				providerGraph: {
					intentId: 'pi_payment_method_eligibility',
					amountMinor: 1099,
					currency: 'eur',
					paymentMethodId: 'pm_payment_method_eligibility',
					paymentMethodTypes: [ 'bancontact' ],
					occurrenceCount: 2,
					captureOccurrenceCount: 2,
					providerStatus: 'succeeded',
					chargeStatus: 'succeeded',
					chargeCaptured: true,
				},
			} )
		)
	).toThrow( /exactly one provider graph/ );
} );

test( 'does not present Store API cart currency as independent session evidence', () => {
	expect( observation().before ).not.toHaveProperty( 'sessionCurrency' );
} );

test( 'parses EUR and USD symbols from the rendered checkout total', () => {
	expect( parseRenderedCheckoutCurrency( '€10.99' ) ).toBe( 'EUR' );
	expect( parseRenderedCheckoutCurrency( '$10.99' ) ).toBe( 'USD' );
	expect( parseRenderedCheckoutCurrency( 'US$10.99' ) ).toBe( 'USD' );
} );

test( 'rejects missing or ambiguous rendered checkout currency symbols', () => {
	expect( () => parseRenderedCheckoutCurrency( '10.99' ) ).toThrow(
		/exactly one USD or EUR currency symbol/
	);
	expect( () => parseRenderedCheckoutCurrency( '€10.99 / $11.85' ) ).toThrow(
		/exactly one USD or EUR currency symbol/
	);
} );

test( 'rejects a store default copied from the switched shopper cart', () => {
	expect( () =>
		validatePaymentMethodEligibilityObservation(
			USD_TO_EUR,
			observation( { storeDefaultCurrency: 'EUR' } )
		)
	).toThrow( /configured store default currency must remain USD/ );
} );

test( 'sets and restores the configured default currency through settings REST', async () => {
	const { calls, events, session } = currencySession();
	await withStoreDefaultCurrency( session, 'EUR', async () => {} );
	expect( events ).toEqual( [
		'capability:multi-currency-settlement-currency',
		'assert-write',
		'write',
		'assert-write',
		'write',
	] );
	expect( calls ).toEqual( [
		'GET:USD',
		'PUT:EUR',
		'GET:EUR',
		'PUT:USD',
		'GET:USD',
	] );
} );

test( 'does not write a configured default that already matches the target', async () => {
	const { calls, events, session } = currencySession();
	await withStoreDefaultCurrency( session, 'USD', async () => {} );
	expect( events ).toEqual( [
		'capability:multi-currency-settlement-currency',
	] );
	expect( calls ).toEqual( [ 'GET:USD', 'GET:USD', 'GET:USD' ] );
} );

test( 'keeps the scenario failure primary when REST restoration also fails', async () => {
	const scenarioError = new Error( 'scenario failed' );
	const restoreError = new Error( 'restore failed' );
	const { session } = currencySession( { restoreError } );
	const error = await withStoreDefaultCurrency( session, 'EUR', async () => {
		throw scenarioError;
	} ).then(
		() => undefined,
		( rejected: unknown ) => rejected
	);
	expect( error ).toBeInstanceOf( ResourceQuarantineRequiredError );
	expect( error ).toMatchObject( { primaryError: scenarioError } );
} );

test( 'keeps the durable provider payment-method ID distinct from the intent type', () => {
	const graph = createPaymentMethodEligibilityProviderGraph(
		{
			intentId: 'pi_payment_method_eligibility',
			paymentMethodId: 'pm_payment_method_eligibility',
			providerStatus: 'succeeded',
			chargeStatus: 'succeeded',
			chargeCaptured: true,
			occurrenceCount: 1,
			captureOccurrenceCount: 1,
		},
		{
			id: 'pi_payment_method_eligibility',
			amountMinor: 1099,
			currency: 'eur',
			paymentMethodTypes: [ 'bancontact' ],
		}
	);
	expect( graph.paymentMethodId ).toBe( 'pm_payment_method_eligibility' );
	expect( graph.paymentMethodTypes ).toEqual( [ 'bancontact' ] );
	expect( graph.currency ).toBe( 'eur' );
} );

test( 'rejects an invalid durable ID or non-singleton provider method type', () => {
	const eurToUsd = observation( {
		storeDefaultCurrency: 'EUR',
		before: {
			currencyLabel: 'EUR',
			cart: { currency: 'EUR', itemsCount: 1 },
			controls: {
				card: { accessibleCount: 1, hiddenEnabledCount: 0 },
				bancontact: { accessibleCount: 1, hiddenEnabledCount: 0 },
			},
		},
		after: {
			currencyLabel: 'USD',
			cart: { currency: 'USD', itemsCount: 1 },
			controls: {
				card: { accessibleCount: 1, hiddenEnabledCount: 0 },
				bancontact: { accessibleCount: 0, hiddenEnabledCount: 0 },
			},
		},
		providerGraph: {
			...observation().providerGraph,
			currency: 'usd',
			paymentMethodId: 'payment_method_eligibility',
			paymentMethodTypes: [ 'card' ],
		},
	} );
	expect( () =>
		validatePaymentMethodEligibilityObservation( EUR_TO_USD, eurToUsd )
	).toThrow( /durable pm_ payment-method ID/ );
	expect( () =>
		validatePaymentMethodEligibilityObservation( EUR_TO_USD, {
			...eurToUsd,
			providerGraph: {
				...eurToUsd.providerGraph,
				paymentMethodId: 'pm_payment_method_eligibility',
				paymentMethodTypes: [ 'card', 'bancontact' ],
			},
		} )
	).toThrow( /singleton card method type/ );
} );

test( 'accepts a retained observation only when its exact live graph still matches', () => {
	const { record, evidence } = reconciliation();
	const reconciled = validatePaymentMethodEligibilityReconciliation(
		USD_TO_EUR,
		record,
		evidence,
		observation().providerGraph
	);

	expect( reconciled.providerGraph.intentId ).toBe(
		'pi_payment_method_eligibility'
	);
} );

test( 'rejects a retained result digest or live identity that does not match', () => {
	const { record, evidence } = reconciliation();
	expect( () =>
		validatePaymentMethodEligibilityReconciliation(
			USD_TO_EUR,
			{ ...record, sourceResultSha256: 'not-a-sha256' },
			evidence,
			observation().providerGraph
		)
	).toThrow( /source result SHA-256/ );
	expect( () =>
		validatePaymentMethodEligibilityReconciliation(
			USD_TO_EUR,
			record,
			{ ...evidence, chargeId: 'py_different' },
			observation().providerGraph
		)
	).toThrow( /live order and provider identities/ );
} );

test( 'accepts only the exact cold-read restoration state', () => {
	const restored = {
		defaultCurrency: 'USD' as const,
		multiCurrencyEnabled: true,
		enabledPaymentMethodIds: [ 'card', 'klarna' ],
		enabledCurrencyCodes: [ 'USD', 'EUR' ],
		usdSettings: {
			exchange_rate_type: 'automatic',
			manual_rate: null,
			price_rounding: null,
			price_charm: null,
		},
	};
	expect(
		validatePaymentMethodEligibilityRestoration( restored, restored )
	).toEqual( restored );
	expect( () =>
		validatePaymentMethodEligibilityRestoration(
			{ ...restored, enabledCurrencyCodes: [ 'USD' ] },
			restored
		)
	).toThrow( /restored store state/ );
} );
