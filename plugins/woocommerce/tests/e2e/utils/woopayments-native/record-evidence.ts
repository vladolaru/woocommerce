import type { APIRequestContext, APIResponse } from '@playwright/test';

import { getProviderEvidence } from './provider-evidence';

export interface PaymentEvidence {
	runId: string;
	orderId: number;
	orderKey: string;
	intentId: string;
	chargeId: string;
	paymentMethodId: string;
	amountMinor: number;
	currency: string;
	orderStatus: string;
	providerStatus: string;
	chargeStatus: string;
	chargeCaptured: boolean;
	occurrenceCount: number;
	captureOccurrenceCount: number;
}

export interface OrderPaymentEvidence {
	runId: string;
	orderId: number;
	orderKey: string;
	intentId: string;
	chargeId: string;
	paymentMethodId: string;
	amountMinor: number;
	currency: string;
	orderStatus: string;
}

interface OrderMeta {
	key?: unknown;
	value?: unknown;
}

interface OrderResponse {
	id?: unknown;
	order_key?: unknown;
	total?: unknown;
	currency?: unknown;
	currency_minor_unit?: unknown;
	status?: unknown;
	meta_data?: unknown;
}

interface OrderNoteResponse {
	note?: unknown;
}

export interface CaptureOrderNoteEvidence {
	captureNoteCount: number;
	captureNote: string;
}

async function readJson(
	response: APIResponse,
	resource: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`Unable to read ${ resource }: HTTP ${ response.status() }.`
		);
	}
	return response.json();
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		throw new Error( `Payment evidence requires a non-empty ${ label }.` );
	}
	return value;
}

function getRequiredMeta(
	metaData: OrderMeta[],
	key: string,
	label: string
): string {
	const entry = metaData.find( ( item ) => item.key === key );
	return requiredString( entry?.value, label );
}

function parseAmountMinor( value: unknown, minorUnit: number ): number {
	if ( typeof value !== 'string' || ! /^\d+(?:\.\d+)?$/.test( value ) ) {
		throw new Error( 'Payment evidence requires a numeric order total.' );
	}

	const [ major, fraction = '' ] = value.split( '.' );
	if ( fraction.length > minorUnit ) {
		throw new Error(
			`Order total ${ value } has more than ${ minorUnit } currency decimal places.`
		);
	}
	const paddedFraction = fraction.padEnd( minorUnit, '0' );
	const amount = Number( `${ major }${ paddedFraction }` );
	if ( ! Number.isSafeInteger( amount ) ) {
		throw new Error(
			`Order total ${ value } cannot be represented safely.`
		);
	}
	return amount;
}

export async function getOrderPaymentEvidence(
	restApi: APIRequestContext,
	orderId: number
): Promise< OrderPaymentEvidence > {
	if ( ! Number.isInteger( orderId ) || orderId <= 0 ) {
		throw new Error( 'Payment evidence requires a positive order ID.' );
	}

	const order = ( await readJson(
		await restApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`WooCommerce order ${ orderId }`
	) ) as OrderResponse;

	if ( order.id !== orderId ) {
		throw new Error(
			`Payment evidence order ID mismatch: expected ${ orderId }, received ${ String(
				order.id
			) }.`
		);
	}

	const metaData = Array.isArray( order.meta_data )
		? ( order.meta_data as OrderMeta[] )
		: [];
	const runId = getRequiredMeta(
		metaData,
		'_e2e_woopayments_run_id',
		'run ID'
	);
	const intentId = getRequiredMeta( metaData, '_intent_id', 'intent ID' );
	const chargeId = getRequiredMeta( metaData, '_charge_id', 'charge ID' );
	const paymentMethodId = getRequiredMeta(
		metaData,
		'_payment_method_id',
		'payment method ID'
	);
	const orderKey = requiredString( order.order_key, 'order key' );
	const currency = requiredString(
		order.currency,
		'order currency'
	).toUpperCase();
	const orderStatus = requiredString( order.status, 'order status' );
	const minorUnit =
		typeof order.currency_minor_unit === 'number' &&
		Number.isInteger( order.currency_minor_unit ) &&
		order.currency_minor_unit >= 0 &&
		order.currency_minor_unit <= 3
			? order.currency_minor_unit
			: 2;
	const amountMinor = parseAmountMinor( order.total, minorUnit );

	return {
		runId,
		orderId,
		orderKey,
		intentId,
		chargeId,
		paymentMethodId,
		amountMinor,
		currency,
		orderStatus,
	};
}

export async function getPaymentEvidence(
	restApi: APIRequestContext,
	orderId: number
): Promise< PaymentEvidence > {
	const orderEvidence = await getOrderPaymentEvidence( restApi, orderId );
	const providerEvidence = await getProviderEvidence(
		restApi,
		orderEvidence
	);

	return {
		...orderEvidence,
		...providerEvidence,
	};
}

export async function getCaptureOrderNoteEvidence(
	restApi: APIRequestContext,
	evidence: Pick< PaymentEvidence, 'orderId' | 'intentId' >
): Promise< CaptureOrderNoteEvidence > {
	if ( ! Number.isInteger( evidence.orderId ) || evidence.orderId <= 0 ) {
		throw new Error(
			'Capture note evidence requires a positive order ID.'
		);
	}
	const intentId = requiredString( evidence.intentId, 'intent ID' );
	const notes = await readJson(
		await restApi.get(
			`/wp-json/wc/v3/orders/${ evidence.orderId }/notes?context=edit&per_page=100`
		),
		`WooCommerce order ${ evidence.orderId } notes`
	);
	if ( ! Array.isArray( notes ) ) {
		throw new Error(
			'Capture note evidence requires an order note array.'
		);
	}

	const captureNotes = ( notes as OrderNoteResponse[] )
		.map( ( note, index ) => {
			if ( typeof note.note !== 'string' ) {
				throw new Error(
					`Capture note evidence requires note ${
						index + 1
					} to be a string.`
				);
			}
			return note.note;
		} )
		.filter(
			( note ) =>
				note.startsWith( 'A payment of ' ) &&
				note.includes(
					' was <strong>successfully captured</strong> using WooPayments ('
				) &&
				note.endsWith( `>${ intentId }</a>).` )
		);

	return {
		captureNoteCount: captureNotes.length,
		captureNote: captureNotes.length === 1 ? captureNotes[ 0 ] : '',
	};
}
