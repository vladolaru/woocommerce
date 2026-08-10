import type { APIRequestContext, APIResponse } from '@playwright/test';

import { ResourceQuarantineRequiredError } from '../resource-locks';
import { AUTHENTICATION_REQUIRED_PAYMENT_METHOD } from '../test-cards';

/**
 * Confirms a PaymentIntent off-session against a card the provider always
 * challenges, and proves the provider refused to settle it silently.
 *
 * This is the renewal shape: no shopper is present, so a card that needs
 * authentication cannot be authenticated, and the provider must say so rather
 * than succeed. Core already maps that outcome against fakes; what needs the
 * real provider is that the outcome it actually returns is the one those fakes
 * assume. No browser is involved - there is no challenge to answer, only a
 * refusal to observe.
 *
 * The caller pins the expected status rather than the driver assuming one.
 * The provider's own documentation is inconsistent about which status
 * accompanies an off-session authentication refusal, so the value belongs to
 * whoever observed a real run, recorded next to the claim it supports.
 */

const INTENTS_ENDPOINT = 'https://api.stripe.com/v1/payment_intents';
const SETTLED_STATUS = 'succeeded';
const CUSTOMER_ACTION_NEXT_ACTION = 'use_stripe_sdk';

export interface OffSessionAuthenticationRequest {
	/** Provider API context, already carrying the test-mode credential. */
	api: APIRequestContext;
	amountMinor: number;
	currency: string;
	/**
	 * The status a real run observed for this refusal, pinned by the caller.
	 */
	expectedStatus: string;
}

export interface OffSessionAuthenticationEvidence {
	intentId: string;
	status: string;
	nextActionType: string;
	amountMinor: number;
	currency: string;
	settled: false;
	chargeId: null;
}

interface ProviderIntent {
	id?: unknown;
	status?: unknown;
	amount?: unknown;
	currency?: unknown;
	next_action?: unknown;
	latest_charge?: unknown;
}

function fail( message: string ): never {
	throw new Error( `Off-session authentication ${ message }` );
}

/**
 * Confirming an intent is itself a provider write, so from the moment the
 * request leaves, an outcome we cannot account for is unaccounted provider
 * state rather than an ordinary failure. Everything checked before the request
 * still fails plainly.
 */
function quarantine(
	message: string,
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments off-session authentication ${ message }`,
		'uncertain-provider-write',
		primaryError
	);
}

/**
 * Every failure here is after the confirmation request, so each one leaves an
 * intent that may exist and cannot be attributed. An error response is not
 * proof that nothing was created: the provider reports a refused off-session
 * payment as an error carrying the intent it just made.
 */
async function readIntent( response: APIResponse ): Promise< ProviderIntent > {
	if ( ! response.ok() ) {
		throw quarantine(
			`could not confirm the intent: HTTP ${ response.status() } ${ await response.text() }`
		);
	}

	let value: unknown;
	try {
		value = await response.json();
	} catch ( error ) {
		throw quarantine( 'could not decode the confirmed intent.', error );
	}
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		throw quarantine( 'requires exactly one intent object.' );
	}
	return value as ProviderIntent;
}

// Both readers below run only after the confirmation request, so they
// quarantine rather than fail.
function nextActionType( value: unknown ): string {
	if ( typeof value !== 'object' || value === null ) {
		throw quarantine(
			'expected the provider to require customer action, but the intent carried no next action. An off-session intent that needs no action proves nothing about the authenticated path.'
		);
	}
	const type = ( value as { type?: unknown } ).type;
	if ( typeof type !== 'string' || type === '' ) {
		throw quarantine( 'requires a named next action type.' );
	}
	return type;
}

function requireString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || value === '' ) {
		throw quarantine( `requires ${ label }.` );
	}
	return value;
}

export async function confirmOffSessionAuthentication(
	request: OffSessionAuthenticationRequest
): Promise< OffSessionAuthenticationEvidence > {
	const { api, amountMinor, currency, expectedStatus } = request;

	if ( ! Number.isInteger( amountMinor ) || amountMinor <= 0 ) {
		fail( 'requires a positive integer minor-unit amount.' );
	}
	if ( expectedStatus === SETTLED_STATUS ) {
		fail(
			'cannot expect a settled intent; the point of this confirmation is that the provider refuses to settle without authentication.'
		);
	}

	// From here on the request has left, so an outcome we cannot account for
	// is provider state rather than a plain failure.
	let response: APIResponse;
	try {
		response = await api.post( INTENTS_ENDPOINT, {
			form: {
				amount: amountMinor,
				currency,
				payment_method: AUTHENTICATION_REQUIRED_PAYMENT_METHOD,
				confirm: 'true',
				off_session: 'true',
			},
		} );
	} catch ( error ) {
		// The request may have reached the provider and confirmed an intent
		// whose id we will never see.
		throw quarantine(
			'could not read the provider response, so a confirmed intent may exist unrecorded.',
			error
		);
	}

	const intent = await readIntent( response );
	const status = requireString( intent.status, 'an intent status' );

	if ( status === SETTLED_STATUS ) {
		// A settled intent is a real captured payment this run did not intend
		// to make, and it is not attributable from a plain failure.
		throw quarantine(
			'expected the provider to refuse an unauthenticated off-session payment, but the intent settled. A payment method that no longer requires authentication cannot prove the renewal path, and the settled intent needs accounting.'
		);
	}
	if ( status !== expectedStatus ) {
		throw quarantine(
			`expected the pinned status ${ expectedStatus }, but the provider returned ${ status }. Re-observe the provider and update the claim rather than widening the assertion.`
		);
	}

	const observedNextAction = nextActionType( intent.next_action );
	if ( observedNextAction !== CUSTOMER_ACTION_NEXT_ACTION ) {
		throw quarantine(
			`expected the ${ CUSTOMER_ACTION_NEXT_ACTION } next action, but the provider returned ${ observedNextAction }.`
		);
	}

	if ( intent.latest_charge ) {
		throw quarantine(
			'expected no charge on a refused off-session intent, but the provider reported one.'
		);
	}

	const observedCurrency = requireString(
		intent.currency,
		'an intent currency'
	);

	// The provider normalizes currency to lower case; a caller passing a store
	// currency should not fail on casing alone, after the intent already exists.
	if (
		intent.amount !== amountMinor ||
		observedCurrency.toLowerCase() !== currency.toLowerCase()
	) {
		throw quarantine(
			`expected ${ amountMinor } ${ currency }, but the intent carried ${ String(
				intent.amount
			) } ${ observedCurrency }.`
		);
	}

	return {
		intentId: requireString( intent.id, 'an intent id' ),
		status,
		nextActionType: observedNextAction,
		amountMinor,
		// The provider's casing, not the caller's, so the evidence records
		// what was observed.
		currency: observedCurrency,
		settled: false,
		chargeId: null,
	};
}
