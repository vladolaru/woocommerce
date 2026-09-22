import { expect, type Locator, type Page } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { getPaymentEvidence, type PaymentEvidence } from '../record-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	BANCONTACT,
	readShopperCartState,
	readRedirectIntentRequest,
	setShopperSessionCurrency,
	type ShopperCartState,
	type RedirectIntentRequest,
} from './redirect-methods';
import {
	readProviderCustomerId,
	readProviderCustomerPaymentMethodIds,
} from './classic-card-authentication';

export type PaymentMethodEligibilityCurrency = 'USD' | 'EUR';
export type PaymentMethodEligibilityMethod = 'card' | 'bancontact';

export interface PaymentMethodEligibilityTransition {
	readonly name: 'USD-to-EUR' | 'EUR-to-USD';
	readonly beforeCurrency: PaymentMethodEligibilityCurrency;
	readonly afterCurrency: PaymentMethodEligibilityCurrency;
	readonly beforeMethods: readonly PaymentMethodEligibilityMethod[];
	readonly afterMethods: readonly PaymentMethodEligibilityMethod[];
	readonly paymentMethodId: PaymentMethodEligibilityMethod;
	readonly providerCurrency: Lowercase< PaymentMethodEligibilityCurrency >;
}

export const USD_TO_EUR: PaymentMethodEligibilityTransition = {
	name: 'USD-to-EUR',
	beforeCurrency: 'USD',
	afterCurrency: 'EUR',
	beforeMethods: [ 'card' ],
	afterMethods: [ 'card', 'bancontact' ],
	paymentMethodId: 'bancontact',
	providerCurrency: 'eur',
};

export const EUR_TO_USD: PaymentMethodEligibilityTransition = {
	name: 'EUR-to-USD',
	beforeCurrency: 'EUR',
	afterCurrency: 'USD',
	beforeMethods: [ 'card', 'bancontact' ],
	afterMethods: [ 'card' ],
	paymentMethodId: 'card',
	providerCurrency: 'usd',
};

export interface PaymentMethodControlObservation {
	readonly accessibleCount: number;
	readonly hiddenEnabledCount: number;
}

export interface PaymentMethodEligibilitySnapshot {
	readonly currencyLabel: PaymentMethodEligibilityCurrency;
	readonly cart: ShopperCartState;
	readonly controls: Readonly<
		Record<
			PaymentMethodEligibilityMethod,
			PaymentMethodControlObservation
		>
	>;
	readonly accessibleRadioCount?: number;
}

export interface PaymentMethodEligibilityProviderGraph {
	readonly intentId: string;
	readonly amountMinor: number;
	readonly currency: string;
	/** Durable provider object ID, distinct from its payment-method type. */
	readonly paymentMethodId: string;
	readonly paymentMethodTypes: readonly PaymentMethodEligibilityMethod[];
	readonly occurrenceCount: number;
	readonly captureOccurrenceCount: number;
	readonly providerStatus: string;
	readonly chargeStatus: string;
	readonly chargeCaptured: boolean;
}

export interface PaymentMethodEligibilityObservation {
	/** Configured settings evidence, independent of the shopper Store API read. */
	readonly storeDefaultCurrency: PaymentMethodEligibilityCurrency;
	readonly before: PaymentMethodEligibilitySnapshot;
	readonly after: PaymentMethodEligibilitySnapshot;
	readonly providerGraph: PaymentMethodEligibilityProviderGraph;
}

export interface UsdToEurEligibilityObservation
	extends Omit< PaymentMethodEligibilityObservation, 'providerGraph' > {
	readonly transition: typeof USD_TO_EUR;
}

export interface EurToUsdEligibilityObservation
	extends Omit< PaymentMethodEligibilityObservation, 'providerGraph' > {
	readonly transition: typeof EUR_TO_USD;
}

export interface PaymentMethodEligibilityBrowserOptions {
	readonly currencyLabel: Locator;
	readonly checkoutPath?: string;
	readonly prepareCheckout?: () => Promise< void >;
}

function fail( message: string ): never {
	throw new Error( `WooPayments payment-method eligibility ${ message }` );
}

const STORE_CURRENCY_API =
	'/wp-json/wc/v3/settings/general/woocommerce_currency';

function describeError( error: unknown ): string {
	return error instanceof Error ? error.message : String( error );
}

/** Read the configured store default from WooCommerce's supported settings API. */
export async function readConfiguredStoreDefaultCurrency(
	session: ProviderWriteSession
): Promise< PaymentMethodEligibilityCurrency > {
	const response = await session.adminApi.get( STORE_CURRENCY_API );
	if ( ! response.ok() ) {
		throw new Error(
			'Unable to read the configured store default currency.'
		);
	}
	const payload = ( await response.json() ) as { value?: unknown };
	if ( payload.value !== 'USD' && payload.value !== 'EUR' ) {
		fail( 'configured store default is not USD or EUR.' );
	}
	return payload.value;
}

async function writeConfiguredStoreDefaultCurrency(
	session: ProviderWriteSession,
	currency: PaymentMethodEligibilityCurrency
): Promise< void > {
	await session.assertCanWrite();
	await session.performWrite( async () => {
		const response = await session.adminApi.put( STORE_CURRENCY_API, {
			data: { value: currency },
		} );
		if ( ! response.ok() ) {
			throw new Error(
				'Unable to write the configured store default currency.'
			);
		}
	} );
}

/** Scope a reversible configured default-currency change through settings REST. */
export async function withStoreDefaultCurrency< Result >(
	session: ProviderWriteSession,
	target: PaymentMethodEligibilityCurrency,
	callback: () => Promise< Result >
): Promise< Result > {
	session.requireApprovedProviderFixture(
		'multi-currency-settlement-currency'
	);
	const baseline = await readConfiguredStoreDefaultCurrency( session );
	let scenarioError: unknown;
	let result: Result | undefined;
	try {
		if ( target !== baseline ) {
			await writeConfiguredStoreDefaultCurrency( session, target );
		}
		if (
			( await readConfiguredStoreDefaultCurrency( session ) ) !== target
		) {
			fail(
				`configured store default did not cold-read as ${ target }.`
			);
		}
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}
	let restorationError: unknown;
	try {
		if ( target !== baseline ) {
			await writeConfiguredStoreDefaultCurrency( session, baseline );
		}
		if (
			( await readConfiguredStoreDefaultCurrency( session ) ) !== baseline
		) {
			fail(
				`configured store default did not cold-read as ${ baseline }.`
			);
		}
	} catch ( error ) {
		restorationError = error;
	}
	if ( scenarioError !== undefined && restorationError !== undefined ) {
		throw new ResourceQuarantineRequiredError(
			`Scenario failed: ${ describeError(
				scenarioError
			) }. Default-currency restoration also failed: ${ describeError(
				restorationError
			) }.`,
			'restoration-failed',
			scenarioError
		);
	}
	if ( scenarioError !== undefined ) {
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw new ResourceQuarantineRequiredError(
			`Default-currency restoration failed: ${ describeError(
				restorationError
			) }.`,
			'restoration-failed',
			restorationError
		);
	}
	return result as Result;
}

function expectedMethodSet(
	methods: readonly PaymentMethodEligibilityMethod[]
): string {
	return methods.join( ', ' );
}

function assertSnapshot(
	snapshot: PaymentMethodEligibilitySnapshot,
	expectedCurrency: PaymentMethodEligibilityCurrency,
	expectedMethods: readonly PaymentMethodEligibilityMethod[],
	phase: 'before' | 'after'
): void {
	if ( snapshot.currencyLabel !== expectedCurrency ) {
		fail(
			`${ phase } currency label must be ${ expectedCurrency }, received ${ snapshot.currencyLabel }.`
		);
	}
	if ( snapshot.cart.currency !== expectedCurrency ) {
		fail(
			`${ phase } Store API shopper cart currency must be ${ expectedCurrency } after the currency label changes.`
		);
	}
	if ( snapshot.cart.itemsCount < 1 ) {
		fail( `${ phase } shopper cart must retain an item.` );
	}

	for ( const method of [ 'card', 'bancontact' ] as const ) {
		const control = snapshot.controls[ method ];
		const expected = expectedMethods.includes( method );
		if (
			! Number.isSafeInteger( control.accessibleCount ) ||
			control.accessibleCount < 0
		) {
			fail( `${ phase } ${ method } accessibility count is invalid.` );
		}
		if (
			! Number.isSafeInteger( control.hiddenEnabledCount ) ||
			control.hiddenEnabledCount < 0
		) {
			fail( `${ phase } ${ method } hidden-enabled count is invalid.` );
		}
		if ( expected && control.accessibleCount !== 1 ) {
			fail(
				`${ phase } requires exactly one accessible ${
					method === 'card' ? 'Card' : 'Bancontact'
				} control.`
			);
		}
		if ( ! expected && control.accessibleCount !== 0 ) {
			fail(
				`${
					method === 'card' ? 'Card' : 'Bancontact'
				} must be unavailable ${ phase } the ${ expectedCurrency } transition.`
			);
		}
		if ( ! expected && control.hiddenEnabledCount !== 0 ) {
			fail(
				`${ phase } contains a hidden enabled ${
					method === 'card' ? 'Card' : 'Bancontact'
				} control.`
			);
		}
	}

	if (
		snapshot.accessibleRadioCount !== undefined &&
		snapshot.accessibleRadioCount !== expectedMethods.length
	) {
		fail(
			`${ phase } accessible method set must be exactly ${ expectedMethodSet(
				expectedMethods
			) }.`
		);
	}
}

/**
 * Validate the stable evidence a same-session currency transition must leave.
 */
export function validatePaymentMethodEligibilityObservation<
	Transition extends PaymentMethodEligibilityTransition,
>(
	transition: Transition,
	observation: PaymentMethodEligibilityObservation
): PaymentMethodEligibilityObservation {
	if ( observation.storeDefaultCurrency !== transition.beforeCurrency ) {
		fail(
			`configured store default currency must remain ${ transition.beforeCurrency }, received ${ observation.storeDefaultCurrency }.`
		);
	}
	assertSnapshot(
		observation.before,
		transition.beforeCurrency,
		transition.beforeMethods,
		'before'
	);
	assertSnapshot(
		observation.after,
		transition.afterCurrency,
		transition.afterMethods,
		'after'
	);

	const graph = observation.providerGraph;
	if (
		graph.amountMinor !== 1099 ||
		graph.currency !== transition.providerCurrency ||
		graph.paymentMethodTypes.length !== 1 ||
		graph.paymentMethodTypes[ 0 ] !== transition.paymentMethodId
	) {
		fail(
			`provider graph must be exactly 1099 ${ transition.providerCurrency } with singleton ${ transition.paymentMethodId } method type.`
		);
	}
	if ( ! /^pm_.+/u.test( graph.paymentMethodId ) ) {
		fail(
			'provider graph must retain a non-empty durable pm_ payment-method ID.'
		);
	}
	if ( graph.occurrenceCount !== 1 || graph.captureOccurrenceCount !== 1 ) {
		fail( 'requires exactly one provider graph and one captured charge.' );
	}
	if (
		graph.providerStatus !== 'succeeded' ||
		graph.chargeStatus !== 'succeeded' ||
		! graph.chargeCaptured
	) {
		fail( 'requires one succeeded PaymentIntent and captured charge.' );
	}

	return observation;
}

export function parseRenderedCheckoutCurrency(
	value: string
): PaymentMethodEligibilityCurrency {
	const hasEuro = value.includes( '€' );
	const hasDollar = /(?:US)?\$/u.test( value );
	if ( hasEuro === hasDollar ) {
		fail(
			`checkout total must contain exactly one USD or EUR currency symbol, received ${
				value.trim() || 'empty text'
			}.`
		);
	}
	return hasEuro ? 'EUR' : 'USD';
}

async function readCurrencyLabel(
	locator: Locator
): Promise< PaymentMethodEligibilityCurrency > {
	return parseRenderedCheckoutCurrency( await locator.innerText() );
}

async function readControl(
	paymentOptions: Locator,
	name: RegExp
): Promise< PaymentMethodControlObservation > {
	const controls = paymentOptions.getByRole( 'radio', {
		name,
		includeHidden: true,
	} );
	const count = await controls.count();
	let accessibleCount = 0;
	let hiddenEnabledCount = 0;
	for ( let index = 0; index < count; index += 1 ) {
		const control = controls.nth( index );
		if ( await control.isVisible() ) {
			accessibleCount += 1;
		} else if ( await control.isEnabled() ) {
			hiddenEnabledCount += 1;
		}
	}
	return { accessibleCount, hiddenEnabledCount };
}

async function readSnapshot(
	page: Page,
	options: PaymentMethodEligibilityBrowserOptions,
	expectedMethods: readonly PaymentMethodEligibilityMethod[]
): Promise< PaymentMethodEligibilitySnapshot > {
	await options.prepareCheckout?.();
	const paymentOptions = page.getByRole( 'group', {
		name: 'Payment options',
		exact: true,
	} );
	await expect( paymentOptions ).toBeVisible();
	const allRadios = paymentOptions.getByRole( 'radio' );
	await expect(
		allRadios,
		`accessible payment methods must settle as exactly ${ expectedMethodSet(
			expectedMethods
		) }`
	).toHaveCount( expectedMethods.length );
	const [ currencyLabel, cart, controls, accessibleRadioCount ] =
		await Promise.all( [
			readCurrencyLabel( options.currencyLabel ),
			readShopperCartState( page ),
			Promise.all( [
				readControl( paymentOptions, /Card/i ),
				readControl( paymentOptions, BANCONTACT.label ),
			] ),
			allRadios.count(),
		] );
	return {
		currencyLabel,
		cart,
		controls: {
			card: controls[ 0 ],
			bancontact: controls[ 1 ],
		},
		accessibleRadioCount,
	};
}

async function observeTransition<
	Transition extends PaymentMethodEligibilityTransition,
>(
	page: Page,
	transition: Transition,
	options: PaymentMethodEligibilityBrowserOptions
): Promise< Omit< PaymentMethodEligibilityObservation, 'providerGraph' > > {
	const before = await readSnapshot(
		page,
		options,
		transition.beforeMethods
	);
	await setShopperSessionCurrency( page, transition.afterCurrency );
	await page.goto( options.checkoutPath ?? 'checkout/' );
	const after = await readSnapshot( page, options, transition.afterMethods );
	assertSnapshot(
		before,
		transition.beforeCurrency,
		transition.beforeMethods,
		'before'
	);
	assertSnapshot(
		after,
		transition.afterCurrency,
		transition.afterMethods,
		'after'
	);
	return { before, after };
}

export async function observeUsdToEurPaymentMethodEligibility(
	page: Page,
	options: PaymentMethodEligibilityBrowserOptions
): Promise< UsdToEurEligibilityObservation > {
	return {
		transition: USD_TO_EUR,
		...( await observeTransition( page, USD_TO_EUR, options ) ),
	};
}

export async function observeEurToUsdPaymentMethodEligibility(
	page: Page,
	options: PaymentMethodEligibilityBrowserOptions
): Promise< EurToUsdEligibilityObservation > {
	return {
		transition: EUR_TO_USD,
		...( await observeTransition( page, EUR_TO_USD, options ) ),
	};
}

/**
 * Read the existing provider graph rather than duplicating its REST reader.
 */
export function createPaymentMethodEligibilityProviderGraph(
	evidence: Pick<
		PaymentEvidence,
		| 'intentId'
		| 'paymentMethodId'
		| 'providerStatus'
		| 'chargeStatus'
		| 'chargeCaptured'
		| 'occurrenceCount'
		| 'captureOccurrenceCount'
	>,
	request: Pick<
		RedirectIntentRequest,
		'id' | 'amountMinor' | 'currency' | 'paymentMethodTypes'
	>
): PaymentMethodEligibilityProviderGraph {
	if ( request.id !== evidence.intentId ) {
		fail( 'provider graph request does not match the order intent ID.' );
	}
	return {
		intentId: evidence.intentId,
		amountMinor: request.amountMinor,
		currency: request.currency,
		paymentMethodId: evidence.paymentMethodId,
		paymentMethodTypes:
			request.paymentMethodTypes as PaymentMethodEligibilityMethod[],
		occurrenceCount: evidence.occurrenceCount,
		captureOccurrenceCount: evidence.captureOccurrenceCount,
		providerStatus: evidence.providerStatus,
		chargeStatus: evidence.chargeStatus,
		chargeCaptured: evidence.chargeCaptured,
	};
}

export async function readPaymentMethodEligibilityProviderGraph(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaymentMethodEligibilityProviderGraph > {
	const evidence: PaymentEvidence = await getPaymentEvidence(
		session.adminApi,
		orderId
	);
	return createPaymentMethodEligibilityProviderGraph(
		evidence,
		await readRedirectIntentRequest( session, evidence.intentId, false )
	);
}

/**
 * Assert that the paid provider customer has no reusable credentials attached.
 */
export async function expectNoReusablePaymentCredential(
	session: ProviderWriteSession,
	graph: Pick< PaymentMethodEligibilityProviderGraph, 'intentId' >
): Promise< void > {
	const customerId = await readProviderCustomerId( session, graph.intentId );
	expect(
		await readProviderCustomerPaymentMethodIds( session, customerId ),
		'payment must not leave a reusable provider payment method attached'
	).toEqual( [] );
}

/**
 * Keep the provider submission inside the session-owned durable journal.
 */
export async function withPaymentMethodEligibilitySubmission< Result >(
	session: ProviderWriteSession,
	description: string,
	submit: () => Promise< Result >
): Promise< Result > {
	return session.withProviderSubmissionJournal( description, submit );
}
