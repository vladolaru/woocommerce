import type { APIResponse } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { getWithReadRetry } from '../provider-evidence';

const TERMINAL_INTENT_STATUS = 'requires_payment_method';
const CONVERGENCE_BUDGET_MS = 45_000;
const CONVERGENCE_POLL_MS = 2_000;
const CONVERGENCE_QUIET_MS = 4_000;
const ORDER_SETTLE_INTERVAL_MS = 2_000;
const PAID_ORDER_STATUSES = [
	'processing',
	'completed',
	'on-hold',
	'refunded',
];

export interface OrderIdStatus {
	id: number;
	status: string;
}

export interface OrderIdStatusDelta {
	orders: OrderIdStatus[];
	newOrderIds: number[];
	paidOrderIds: number[];
}

export interface FailedPaymentEvidence {
	orderStatus: string;
	orderTotal: string;
	orderCurrency: string;
	intentIdMeta: string;
	chargeIdMeta: string;
	intentionStatusMeta: string;
	intentId: string;
	paymentMethodId: string;
	intentStatus: unknown;
	intentAmount: unknown;
	intentCurrency: unknown;
	amountReceived: unknown;
	errorCode: unknown;
	declineCode: unknown;
	chargeIds: string[];
	chargeStatuses: string[];
	capturedCharges: number;
	failureNoteCount: number;
	setupFutureUsage: unknown;
	providerCustomerId: string;
	providerAttachedPaymentMethodIds: string[];
	orderCustomerId: number;
	localTokenIds: number[];
}

export interface PaymentReuseEvidence {
	orderCustomerId: number;
	setupFutureUsage: unknown;
	providerCustomerId: string;
	providerAttachedPaymentMethodIds: string[];
	localTokenIds: number[];
}

interface OrderDeltaDependencies {
	delay?: ( milliseconds: number ) => Promise< void >;
}

interface FailedConvergenceDependencies {
	read?: (
		session: ProviderWriteSession,
		orderId: number
	) => Promise< FailedPaymentEvidence >;
	delay?: ( milliseconds: number ) => Promise< void >;
	now?: () => number;
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function readJson(
	response: APIResponse,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

function requireObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		throw new Error(
			`Failed payment evidence requires one ${ label } object.`
		);
	}
	return value as Record< string, unknown >;
}

function requireString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		throw new Error(
			`Failed payment evidence requires a non-empty ${ label }.`
		);
	}
	return value;
}

function assertIntentMatchesOrderMetadata(
	intentIdMeta: string,
	intentId: unknown
): void {
	if ( intentId !== intentIdMeta ) {
		throw new Error(
			`Failed payment evidence intent mismatch: expected ${ intentIdMeta }, received ${ String(
				intentId
			) }.`
		);
	}
}

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const meta = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const entry = meta.find( ( item ) => item.key === key );
	return typeof entry?.value === 'string' ? entry.value : '';
}

function optionalPaymentMethodId( value: unknown ): string | undefined {
	if ( value === null || value === undefined ) {
		return undefined;
	}
	if ( typeof value === 'string' ) {
		return requireString( value, 'PaymentMethod ID' );
	}
	return requireString(
		requireObject( value, 'PaymentMethod' ).id,
		'PaymentMethod ID'
	);
}

function paymentMethodId(
	intent: Record< string, unknown >,
	orderPaymentMethodId: string
): string {
	const lastPaymentError =
		typeof intent.last_payment_error === 'object' &&
		intent.last_payment_error !== null
			? ( intent.last_payment_error as Record< string, unknown > )
			: {};
	const providerPaymentMethodId =
		optionalPaymentMethodId( lastPaymentError.payment_method ) ??
		optionalPaymentMethodId( intent.payment_method );
	if (
		providerPaymentMethodId !== undefined &&
		orderPaymentMethodId !== '' &&
		providerPaymentMethodId !== orderPaymentMethodId
	) {
		throw new Error(
			'Failed payment evidence PaymentMethod mismatch between provider intent and order metadata.'
		);
	}
	return providerPaymentMethodId ?? requireString(
		orderPaymentMethodId,
		'order PaymentMethod ID'
	);
}

function providerCustomerId( intent: Record< string, unknown > ): string {
	const customer = intent.customer;
	if ( customer === null || customer === undefined || customer === '' ) {
		return '';
	}
	if ( typeof customer === 'string' ) {
		return customer;
	}
	return requireString(
		requireObject( customer, 'provider customer' ).id,
		'provider customer ID'
	);
}

function requirePositiveInteger( value: unknown, label: string ): number {
	if ( ! Number.isSafeInteger( value ) || Number( value ) <= 0 ) {
		throw new Error(
			`Failed payment evidence requires a positive ${ label }.`
		);
	}
	return Number( value );
}

async function readProviderPaymentMethodIds(
	session: ProviderWriteSession,
	customerId: string
): Promise< string[] > {
	if ( customerId === '' ) {
		return [];
	}
	const paymentMethods = await readJson(
		await getWithReadRetry(
			session.adminApi,
			`/wp-json/wc/v3/payments/customers/${ encodeURIComponent(
				customerId
			) }/payment_methods`,
			`payment methods for provider customer ${ customerId }`
		),
		`Provider payment methods for customer ${ customerId }`
	);
	if ( ! Array.isArray( paymentMethods ) ) {
		throw new Error(
			'Failed payment evidence requires a provider payment-method collection.'
		);
	}
	return paymentMethods.map( ( value, index ) =>
		requireString(
			requireObject( value, `provider payment method ${ index + 1 }` ).id,
			`provider payment method ${ index + 1 } ID`
		)
	);
}

async function readLocalTokenIds(
	session: ProviderWriteSession,
	orderCustomerId: number
): Promise< number[] > {
	if ( orderCustomerId === 0 ) {
		return [];
	}
	const customer = requireObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc/v3/customers/${ orderCustomerId }`
			),
			`WooCommerce customer ${ orderCustomerId }`
		),
		'WooCommerce customer'
	);
	const username = requireString( customer.username, 'customer username' );
	const savedCardEvidence = requireObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc-native-payments-e2e/v1/saved-card-evidence?customer_username=${ encodeURIComponent(
					username
				) }`
			),
			`saved-card evidence for ${ username }`
		),
		'saved-card evidence'
	);
	if ( ! Array.isArray( savedCardEvidence.tokens ) ) {
		throw new Error(
			'Failed payment evidence requires a local token collection.'
		);
	}
	return savedCardEvidence.tokens.map( ( value, index ) =>
		requirePositiveInteger(
			requireObject( value, `local token ${ index + 1 }` ).token_id,
			`local token ${ index + 1 } ID`
		)
	);
}

export async function readPaymentReuseEvidence(
	session: ProviderWriteSession,
	orderId: number,
	intentId: string
): Promise< PaymentReuseEvidence > {
	const order = requireObject(
		await readJson(
			await session.adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		),
		'order'
	);
	const customerIdValue = Number( order.customer_id );
	if ( ! Number.isSafeInteger( customerIdValue ) || customerIdValue < 0 ) {
		throw new Error(
			'Failed payment evidence requires a non-negative order customer ID.'
		);
	}
	const intent = requireObject(
		await readJson(
			await getWithReadRetry(
				session.adminApi,
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					intentId
				) }`,
				`payment intent ${ intentId }`
			),
			`Provider intent ${ intentId }`
		),
		'provider intent'
	);
	if ( intent.id !== intentId ) {
		throw new Error(
			`Failed payment evidence intent mismatch: expected ${ intentId }, received ${ String(
				intent.id
			) }.`
		);
	}
	const customerId = providerCustomerId( intent );
	return {
		orderCustomerId: customerIdValue,
		setupFutureUsage: intent.setup_future_usage ?? null,
		providerCustomerId: customerId,
		providerAttachedPaymentMethodIds: await readProviderPaymentMethodIds(
			session,
			customerId
		),
		localTokenIds: await readLocalTokenIds( session, customerIdValue ),
	};
}

async function readIntentCharges(
	session: ProviderWriteSession,
	intent: Record< string, unknown >
): Promise< Array< Record< string, unknown > > > {
	const charges = intent.charges;
	if (
		typeof charges === 'object' &&
		charges !== null &&
		'data' in charges &&
		Array.isArray( charges.data )
	) {
		return charges.data.map( ( value, index ) =>
			requireObject( value, `intent charge ${ index + 1 }` )
		);
	}
	if ( typeof intent.charge === 'object' && intent.charge !== null ) {
		return [ requireObject( intent.charge, 'intent charge' ) ];
	}
	if ( typeof intent.latest_charge === 'string' && intent.latest_charge ) {
		return [
			requireObject(
				await readJson(
					await getWithReadRetry(
						session.adminApi,
						`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
							intent.latest_charge
						) }`,
						`charge ${ intent.latest_charge }`
					),
					`Provider charge ${ intent.latest_charge }`
				),
				'provider charge'
			),
		];
	}
	return [];
}

async function readOrdersAfter(
	session: ProviderWriteSession,
	afterOrderId: number
): Promise< OrderIdStatus[] > {
	const listed = await readJson(
		await session.adminApi.get(
			'/wp-json/wc/v3/orders?status=any&per_page=50&orderby=id&order=desc'
		),
		'WooCommerce order list'
	);
	if ( ! Array.isArray( listed ) ) {
		throw new Error( 'The order list did not return a collection.' );
	}
	return listed
		.map( ( value, index ) => {
			const order = requireObject(
				value,
				`order list entry ${ index + 1 }`
			);
			const id = Number( order.id );
			if ( ! Number.isSafeInteger( id ) || id <= 0 ) {
				throw new Error(
					`Order list entry ${ index + 1 } carries no exact ID.`
				);
			}
			return {
				id,
				status: requireString( order.status, 'order status' ),
			};
		} )
		.filter( ( order ) => order.id > afterOrderId )
		.toSorted( ( left, right ) => left.id - right.id );
}

function summarizeOrders( orders: OrderIdStatus[] ): OrderIdStatusDelta {
	return {
		orders,
		newOrderIds: orders.map( ( order ) => order.id ),
		paidOrderIds: orders
			.filter( ( order ) => PAID_ORDER_STATUSES.includes( order.status ) )
			.map( ( order ) => order.id ),
	};
}

export async function readOrderIdStatusDelta(
	session: ProviderWriteSession,
	afterOrderId: number,
	dependencies: OrderDeltaDependencies = {}
): Promise< OrderIdStatusDelta > {
	const wait = dependencies.delay ?? delay;
	const first = summarizeOrders(
		await readOrdersAfter( session, afterOrderId )
	);
	await wait( ORDER_SETTLE_INTERVAL_MS );
	const second = summarizeOrders(
		await readOrdersAfter( session, afterOrderId )
	);
	if ( JSON.stringify( first ) !== JSON.stringify( second ) ) {
		throw new Error(
			`Order identity/status state did not converge: ${ JSON.stringify(
				first
			) } then ${ JSON.stringify( second ) }.`
		);
	}
	return second;
}

export async function readFailedPaymentEvidence(
	session: ProviderWriteSession,
	orderId: number
): Promise< FailedPaymentEvidence > {
	const order = requireObject(
		await readJson(
			await session.adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		),
		'order'
	);
	const intentIdMeta = orderMeta( order, '_intent_id' );
	const orderCustomerId = Number( order.customer_id ?? 0 );
	if ( ! Number.isSafeInteger( orderCustomerId ) || orderCustomerId < 0 ) {
		throw new Error(
			'Failed payment evidence requires a non-negative order customer ID.'
		);
	}
	const localTokenIds = await readLocalTokenIds( session, orderCustomerId );
	const notes = await readJson(
		await session.adminApi.get(
			`/wp-json/wc/v3/orders/${ orderId }/notes?context=edit&per_page=100`
		),
		`WooCommerce order ${ orderId } notes`
	);
	if ( ! Array.isArray( notes ) ) {
		throw new Error( 'The order notes route did not return a collection.' );
	}
	const failureNoteCount = notes.filter( ( value ) => {
		const note = ( value as { note?: unknown } ).note;
		return (
			typeof note === 'string' &&
			note.includes( '<strong>failed</strong> using WooPayments' ) &&
			( intentIdMeta === '' || note.includes( intentIdMeta ) )
		);
	} ).length;
	const base = {
		orderStatus: String( order.status ),
		orderTotal: String( order.total ),
		orderCurrency: String( order.currency ).toUpperCase(),
		intentIdMeta,
		chargeIdMeta: orderMeta( order, '_charge_id' ),
		intentionStatusMeta: orderMeta( order, '_intention_status' ),
		paymentMethodIdMeta: orderMeta( order, '_payment_method_id' ),
		failureNoteCount,
	};
	if ( intentIdMeta === '' ) {
		return {
			...base,
			intentId: '',
			paymentMethodId: '',
			intentStatus: null,
			intentAmount: null,
			intentCurrency: null,
			amountReceived: null,
			errorCode: null,
			declineCode: null,
			chargeIds: [],
			chargeStatuses: [],
			capturedCharges: 0,
			setupFutureUsage: null,
			providerCustomerId: '',
			providerAttachedPaymentMethodIds: [],
			orderCustomerId,
			localTokenIds,
		};
	}

	const intent = requireObject(
		await readJson(
			await getWithReadRetry(
				session.adminApi,
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					intentIdMeta
				) }`,
				`payment intent ${ intentIdMeta }`
			),
			`Provider intent ${ intentIdMeta }`
		),
		'provider intent'
	);
	assertIntentMatchesOrderMetadata( intentIdMeta, intent.id );
	const lastPaymentError =
		typeof intent.last_payment_error === 'object' &&
		intent.last_payment_error !== null
			? ( intent.last_payment_error as Record< string, unknown > )
			: {};
	const charges = await readIntentCharges( session, intent );

	const customerId = providerCustomerId( intent );
	return {
		...base,
		intentId: String( intent.id ),
		paymentMethodId: paymentMethodId( intent, base.paymentMethodIdMeta ),
		intentStatus: intent.status,
		intentAmount: intent.amount,
		intentCurrency:
			typeof intent.currency === 'string'
				? intent.currency.toLowerCase()
				: intent.currency,
		amountReceived: intent.amount_received ?? null,
		errorCode: lastPaymentError.code ?? null,
		declineCode: lastPaymentError.decline_code ?? null,
		chargeIds: charges.map( ( charge, index ) =>
			requireString( charge.id, `charge ${ index + 1 } ID` )
		),
		chargeStatuses: charges.map( ( charge ) => String( charge.status ) ),
		capturedCharges: charges.filter(
			( charge ) => charge.captured === true
		).length,
		setupFutureUsage: intent.setup_future_usage ?? null,
		providerCustomerId: customerId,
		providerAttachedPaymentMethodIds: await readProviderPaymentMethodIds(
			session,
			customerId
		),
		orderCustomerId,
		localTokenIds,
	};
}

export async function convergeFailedPayment(
	session: ProviderWriteSession,
	orderId: number,
	dependencies: FailedConvergenceDependencies = {}
): Promise< FailedPaymentEvidence > {
	const read = dependencies.read ?? readFailedPaymentEvidence;
	const wait = dependencies.delay ?? delay;
	const now = dependencies.now ?? Date.now;
	const deadline = now() + CONVERGENCE_BUDGET_MS;
	let previous = '';
	let converged: FailedPaymentEvidence | undefined;

	for (;;) {
		const current = await read( session, orderId );
		if ( current.intentId !== '' ) {
			assertIntentMatchesOrderMetadata(
				current.intentIdMeta,
				current.intentId
			);
		}
		const serialized = JSON.stringify( current );
		if (
			current.intentId !== '' &&
			current.intentStatus === TERMINAL_INTENT_STATUS &&
			serialized === previous
		) {
			converged = current;
			break;
		}
		previous = serialized;
		if ( now() >= deadline ) {
			throw new Error(
				`Order ${ orderId } did not reach a stable terminal failed payment within ${ CONVERGENCE_BUDGET_MS }ms; ` +
					`last read: ${ serialized }\n` +
					'An empty intentIdMeta here almost always means the provider event listener is not running. ' +
					'Native persists no intent identity on a decline, so the only thing that ever writes _intent_id ' +
					'onto a failed order is the ingested payment_intent.payment_failed event, and that event only ' +
					'reaches the store while the operator-run listener (`wpcom-local transact listen`) is forwarding ' +
					'Stripe events into local WPCOM. The pull fallback cannot substitute: with the listener down the ' +
					'platform never receives the event, so it has none queued to hand back.'
			);
		}
		await wait( CONVERGENCE_POLL_MS );
	}

	await wait( CONVERGENCE_QUIET_MS );
	const afterQuietInterval = await read( session, orderId );
	if ( afterQuietInterval.intentId !== '' ) {
		assertIntentMatchesOrderMetadata(
			afterQuietInterval.intentIdMeta,
			afterQuietInterval.intentId
		);
	}
	if (
		JSON.stringify( afterQuietInterval ) !== JSON.stringify( converged )
	) {
		throw new Error(
			`Failed payment changed during the ${ CONVERGENCE_QUIET_MS }ms quiet interval: ${ JSON.stringify(
				converged
			) } then ${ JSON.stringify( afterQuietInterval ) }.`
		);
	}
	return converged;
}
