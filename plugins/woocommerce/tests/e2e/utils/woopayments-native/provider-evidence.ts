import type { APIRequestContext, APIResponse } from '@playwright/test';

import {
	getPaymentEvidence,
	type OrderPaymentEvidence,
	type PaymentEvidence,
} from './record-evidence';

interface ProviderObject {
	id?: unknown;
	amount?: unknown;
	currency?: unknown;
	status?: unknown;
	captured?: unknown;
	payment_intent?: unknown;
	payment_method?: unknown;
	charge?: unknown;
	charges?: unknown;
}

export interface ProviderPaymentEvidence {
	providerStatus: string;
	chargeStatus: string;
	chargeCaptured: boolean;
	occurrenceCount: number;
	captureOccurrenceCount: number;
}

async function readJson(
	response: APIResponse,
	resource: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`Unable to read provider ${ resource }: HTTP ${ response.status() }.`
		);
	}
	return response.json();
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		throw new Error( `Provider evidence requires a non-empty ${ label }.` );
	}
	return value;
}

function requiredBoolean( value: unknown, label: string ): boolean {
	if ( typeof value !== 'boolean' ) {
		throw new Error(
			`Provider evidence requires ${ label } to be boolean.`
		);
	}
	return value;
}

function assertExactId(
	value: unknown,
	expected: string,
	label: string
): void {
	const actual = requiredString( value, `${ label } ID` );
	if ( actual !== expected ) {
		throw new Error(
			`Provider ${ label } ID mismatch: expected ${ expected }, received ${ actual }.`
		);
	}
}

function assertAmountCurrency(
	value: ProviderObject,
	order: Pick< OrderPaymentEvidence, 'amountMinor' | 'currency' >,
	label: string
): void {
	if ( value.amount !== order.amountMinor ) {
		throw new Error(
			`Provider ${ label } amount mismatch: expected ${
				order.amountMinor
			}, received ${ String( value.amount ) }.`
		);
	}
	const currency = requiredString(
		value.currency,
		`${ label } currency`
	).toUpperCase();
	if ( currency !== order.currency ) {
		throw new Error(
			`Provider ${ label } currency mismatch: expected ${ order.currency }, received ${ currency }.`
		);
	}
}

function assertPaymentMethod(
	value: unknown,
	expected: string,
	label: string
): void {
	const id =
		typeof value === 'object' && value !== null && 'id' in value
			? value.id
			: value;
	assertExactId( id, expected, `${ label } payment method` );
}

function getChargeIds( intent: ProviderObject ): string[] {
	if (
		typeof intent.charges !== 'object' ||
		intent.charges === null ||
		! ( 'data' in intent.charges ) ||
		! Array.isArray( intent.charges.data )
	) {
		if (
			typeof intent.charge === 'object' &&
			intent.charge !== null &&
			'id' in intent.charge
		) {
			return [
				requiredString(
					intent.charge.id,
					'plugin intent charge occurrence ID'
				),
			];
		}
		throw new Error(
			'Provider occurrence count cannot be proved without the native intent charge collection or plugin intent charge relationship.'
		);
	}

	return intent.charges.data.map( ( item: unknown, index: number ) => {
		if ( typeof item !== 'object' || item === null || ! ( 'id' in item ) ) {
			throw new Error(
				`Provider charge occurrence ${ index + 1 } has no durable ID.`
			);
		}
		return requiredString( item.id, 'charge occurrence ID' );
	} );
}

function getCaptureOccurrenceCount( timeline: unknown ): number {
	if (
		typeof timeline !== 'object' ||
		timeline === null ||
		! ( 'data' in timeline ) ||
		! Array.isArray( timeline.data )
	) {
		throw new Error(
			'Provider capture occurrence count cannot be proved without timeline data.'
		);
	}

	let captureCount = 0;
	for ( const [ index, event ] of timeline.data.entries() ) {
		if (
			typeof event !== 'object' ||
			event === null ||
			! ( 'type' in event ) ||
			typeof event.type !== 'string'
		) {
			throw new Error(
				`Provider timeline event ${ index + 1 } has no valid type.`
			);
		}
		if ( event.type === 'captured' ) {
			captureCount += 1;
		}
	}
	return captureCount;
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function remainingDeadlineMs(
	deadlineMs: number,
	intentId: string,
	expectedStatus: string
): number {
	const remainingMs = deadlineMs - Date.now();
	if ( remainingMs <= 0 ) {
		throw new Error(
			`Payment state deadline reached waiting for ${ intentId } to become ${ expectedStatus }.`
		);
	}
	return remainingMs;
}

function bindRequestDeadline(
	restApi: APIRequestContext,
	deadlineMs: number,
	intentId: string,
	expectedStatus: string
): APIRequestContext {
	return new Proxy( restApi, {
		get( target, property, receiver ) {
			if ( property === 'get' ) {
				return (
					url: string,
					options?: Parameters< APIRequestContext[ 'get' ] >[ 1 ]
				) =>
					target.get( url, {
						...options,
						timeout: remainingDeadlineMs(
							deadlineMs,
							intentId,
							expectedStatus
						),
					} );
			}
			const value = Reflect.get( target, property, receiver );
			return typeof value === 'function' ? value.bind( target ) : value;
		},
	} );
}

export async function getProviderEvidence(
	restApi: APIRequestContext,
	order: Pick<
		OrderPaymentEvidence,
		'intentId' | 'chargeId' | 'paymentMethodId' | 'amountMinor' | 'currency'
	>
): Promise< ProviderPaymentEvidence > {
	const intent = ( await readJson(
		await restApi.get(
			`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
				order.intentId
			) }`
		),
		`payment intent ${ order.intentId }`
	) ) as ProviderObject;
	const charge = ( await readJson(
		await restApi.get(
			`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
				order.chargeId
			) }`
		),
		`charge ${ order.chargeId }`
	) ) as ProviderObject;
	const timeline = await readJson(
		await restApi.get(
			`/wp-json/wc/v3/payments/timeline/${ encodeURIComponent(
				order.intentId
			) }`
		),
		`timeline ${ order.intentId }`
	);

	assertExactId( intent.id, order.intentId, 'intent' );
	assertExactId( charge.id, order.chargeId, 'charge' );
	assertExactId( charge.payment_intent, order.intentId, 'charge intent' );
	assertAmountCurrency( intent, order, 'intent' );
	assertAmountCurrency( charge, order, 'charge' );
	assertPaymentMethod(
		intent.payment_method,
		order.paymentMethodId,
		'intent'
	);
	assertPaymentMethod(
		charge.payment_method,
		order.paymentMethodId,
		'charge'
	);

	const chargeIds = getChargeIds( intent );
	const occurrenceCount = chargeIds.length;
	if ( occurrenceCount !== 1 ) {
		throw new Error(
			`Provider occurrence count mismatch: expected 1 charge, received ${ occurrenceCount }.`
		);
	}
	if ( chargeIds[ 0 ] !== order.chargeId ) {
		throw new Error(
			`Provider charge relationship mismatch: intent ${ order.intentId } does not contain ${ order.chargeId }.`
		);
	}

	return {
		providerStatus: requiredString( intent.status, 'provider status' ),
		chargeStatus: requiredString( charge.status, 'charge status' ),
		chargeCaptured: requiredBoolean(
			charge.captured,
			'charge captured field'
		),
		occurrenceCount,
		captureOccurrenceCount: getCaptureOccurrenceCount( timeline ),
	};
}

export async function waitForPaymentState(
	restApi: APIRequestContext,
	evidence: Pick< PaymentEvidence, 'orderId' | 'intentId' >,
	expectedStatus: string,
	deadlineMs: number
): Promise< PaymentEvidence > {
	if ( ! expectedStatus.trim() ) {
		throw new Error( 'A non-empty expected provider status is required.' );
	}
	const deadlineApi = bindRequestDeadline(
		restApi,
		deadlineMs,
		evidence.intentId,
		expectedStatus
	);

	for (;;) {
		remainingDeadlineMs( deadlineMs, evidence.intentId, expectedStatus );
		const current = await getPaymentEvidence(
			deadlineApi,
			evidence.orderId
		);
		if ( current.intentId !== evidence.intentId ) {
			throw new Error(
				`Payment intent changed while polling: expected ${ evidence.intentId }, received ${ current.intentId }.`
			);
		}
		if ( current.providerStatus === expectedStatus ) {
			return current;
		}

		const remainingMs = remainingDeadlineMs(
			deadlineMs,
			evidence.intentId,
			expectedStatus
		);
		await delay( Math.min( 500, remainingMs ) );
	}
}

/** How long to wait before re-asking a read that came back a non-answer. */
const READ_RETRY_DELAY_MS = 2_000;

/** How many extra times a read is re-asked before its non-answer stands. */
const READ_RETRY_ATTEMPTS = 2;

/**
 * Tell whether a response is the provider refusing to answer *for now*.
 *
 * Stripe answers HTTP 429 `lock_timeout` when another request or an internal
 * process holds the object being read, and its own message says the condition
 * is transient and the request should be retried. That is a non-answer in the
 * same sense a dropped connection is: the object's state was not reported, as
 * opposed to reported as something unexpected.
 */
async function isRetryableProviderLock(
	response: APIResponse
): Promise< boolean > {
	if ( response.status() !== 429 ) {
		return false;
	}
	return ( await response.text().catch( () => '' ) ).includes(
		'lock_timeout'
	);
}

/**
 * Ask the store for one provider object, re-asking while the answer is a
 * non-answer rather than an answer.
 *
 * Two things produce a non-answer under the load these families generate. The
 * request context's keep-alive connection is dropped often enough during a long
 * convergence budget to fail a case with `apiRequestContext.get: socket hang
 * up`, while the same request answers HTTP 200 in about a second server-side.
 * And the provider itself answers 429 `lock_timeout` when something else is
 * touching the object — which is routine while a dispute is being adjudicated,
 * because the adjudication is what the case is waiting on. Between them these
 * have cost the refund family two otherwise-complete runs and the dispute
 * family two.
 *
 * Retrying is safe *because this is a read*. The harness's no-retry rule exists
 * so a submission is never made twice; nothing here writes, so re-asking cannot
 * duplicate anything. A read that keeps coming back a non-answer still fails
 * the case: this closes a transport hole, not an evidence gap.
 *
 * @param restApi  Authenticated store REST context.
 * @param path     Store REST path to read.
 * @param resource What is being read, for the failure message.
 * @return The store's answer.
 */
export async function getWithReadRetry(
	restApi: APIRequestContext,
	path: string,
	resource: string
): Promise< APIResponse > {
	let lastNonAnswer = '';

	for ( let attempt = 0; attempt <= READ_RETRY_ATTEMPTS; attempt += 1 ) {
		if ( attempt > 0 ) {
			await delay( READ_RETRY_DELAY_MS );
		}
		try {
			const response = await restApi.get( path );
			if ( ! ( await isRetryableProviderLock( response ) ) ) {
				return response;
			}
			lastNonAnswer =
				'the provider held the object and answered HTTP 429 lock_timeout';
		} catch ( error ) {
			lastNonAnswer = `the connection failed (${ String( error ) })`;
		}
	}

	throw new Error(
		`WooPayments ${ resource } could not be read: ${ lastNonAnswer } on every one of ${
			READ_RETRY_ATTEMPTS + 1
		} attempts, so the provider's answer is unknown rather than absent.`
	);
}

/**
 * Ask the store for one charge, tolerating a non-answer.
 *
 * @param restApi  Authenticated store REST context.
 * @param chargeId Exact provider charge ID.
 * @return The store's answer.
 */
export async function getChargeWithTransportRetry(
	restApi: APIRequestContext,
	chargeId: string
): Promise< APIResponse > {
	return getWithReadRetry(
		restApi,
		`/wp-json/wc/v3/payments/charges/${ encodeURIComponent( chargeId ) }`,
		`charge ${ chargeId }`
	);
}
