import type { APIRequestContext, APIResponse } from '@playwright/test';

import type { PaymentEvidence } from './record-evidence';

export interface ProviderCardEvidence {
	type: 'card';
	brand: 'visa';
	last4: '4242';
}

type ProvedProviderCardIdentity = Pick<
	PaymentEvidence,
	'chargeId' | 'paymentMethodId'
>;

interface ProviderCardDetails {
	brand?: unknown;
	last4?: unknown;
}

interface ProviderPaymentMethodDetails {
	type?: unknown;
	card?: unknown;
}

interface ProviderCharge {
	id?: unknown;
	payment_method?: unknown;
	payment_method_details?: unknown;
}

function fail( message: string ): never {
	throw new Error( `Provider card evidence ${ message }` );
}

function requireProvedIdentity( identity: ProvedProviderCardIdentity ): void {
	if (
		typeof identity.chargeId !== 'string' ||
		! identity.chargeId.trim() ||
		typeof identity.paymentMethodId !== 'string' ||
		! identity.paymentMethodId.trim()
	) {
		fail( 'requires a valid proved provider identity.' );
	}
}

async function readCharge( response: APIResponse ): Promise< ProviderCharge > {
	if ( ! response.ok() ) {
		fail( 'could not read the proved charge.' );
	}

	let value: unknown;
	try {
		value = await response.json();
	} catch {
		fail( 'could not decode the proved charge.' );
	}
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( 'requires exactly one charge object.' );
	}
	return value as ProviderCharge;
}

function paymentMethodId( value: unknown ): unknown {
	if (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value )
	) {
		return 'id' in value ? value.id : undefined;
	}
	return value;
}

export async function readProviderCardEvidence(
	restApi: APIRequestContext,
	identity: ProvedProviderCardIdentity
): Promise< ProviderCardEvidence > {
	requireProvedIdentity( identity );
	let response: APIResponse;
	try {
		response = await restApi.get(
			`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
				identity.chargeId
			) }`
		);
	} catch {
		fail( 'could not request the proved charge.' );
	}
	const charge = await readCharge( response );
	if (
		charge.id !== identity.chargeId ||
		paymentMethodId( charge.payment_method ) !== identity.paymentMethodId
	) {
		fail(
			'does not match the proved charge and payment-method relationship.'
		);
	}

	const details = charge.payment_method_details;
	if (
		typeof details !== 'object' ||
		details === null ||
		Array.isArray( details )
	) {
		fail( 'requires exactly one payment-method detail object.' );
	}
	const paymentMethodDetails = details as ProviderPaymentMethodDetails;
	if ( paymentMethodDetails.type !== 'card' ) {
		fail( 'requires payment-method type card.' );
	}

	const card = paymentMethodDetails.card;
	if ( typeof card !== 'object' || card === null || Array.isArray( card ) ) {
		fail( 'requires exactly one card detail object.' );
	}
	const cardDetails = card as ProviderCardDetails;
	if ( cardDetails.brand !== 'visa' ) {
		fail( 'requires card brand visa.' );
	}
	if ( cardDetails.last4 !== '4242' ) {
		fail( 'requires card last4 4242.' );
	}

	return {
		type: 'card',
		brand: 'visa',
		last4: '4242',
	};
}
