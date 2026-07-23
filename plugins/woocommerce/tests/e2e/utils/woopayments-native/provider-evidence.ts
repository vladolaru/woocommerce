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
	order: OrderPaymentEvidence,
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
		throw new Error(
			'Provider occurrence count cannot be proved without the intent charge collection.'
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

export async function getProviderEvidence(
	restApi: APIRequestContext,
	order: OrderPaymentEvidence
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

	const occurrenceCount = getChargeIds( intent ).length;
	if ( occurrenceCount !== 1 ) {
		throw new Error(
			`Provider occurrence count mismatch: expected 1 charge, received ${ occurrenceCount }.`
		);
	}
	if ( getChargeIds( intent )[ 0 ] !== order.chargeId ) {
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

	for (;;) {
		const current = await getPaymentEvidence( restApi, evidence.orderId );
		if ( current.intentId !== evidence.intentId ) {
			throw new Error(
				`Payment intent changed while polling: expected ${ evidence.intentId }, received ${ current.intentId }.`
			);
		}
		if ( current.providerStatus === expectedStatus ) {
			return current;
		}

		const remainingMs = deadlineMs - Date.now();
		if ( remainingMs <= 0 ) {
			throw new Error(
				`Payment state deadline reached waiting for ${ evidence.intentId } to become ${ expectedStatus }; last state was ${ current.providerStatus }.`
			);
		}
		await delay( Math.min( 500, remainingMs ) );
	}
}
