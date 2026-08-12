import type { Page, Request } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { customer } from '../../../test-data/data';
import { ProviderSubmissionNotStartedError } from '../provider-write-journal';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { ProviderTestCard } from '../test-cards';
import {
	PlaywrightClassicCardCheckoutBrowser,
	parseClassicOrderReceivedUrl,
} from './classic-card-checkout';
import type { ClassicCheckoutTarget } from './classic-checkout-page';

/**
 * WooCommerce Subscriptions surfaces, driven against the native WooPayments
 * runtime for the `subscription-provider-lifecycle` fidelity family.
 *
 * Everything here is either an exact-identity read or one journaled gesture.
 * The reads exist because WooCommerce's own REST API cannot serve them: a
 * subscription's recurring credential lives in `_payment_tokens`, which every
 * order data store lists as an internal meta key and therefore strips from the
 * `meta_data` a subscription response carries, and Action Scheduler has no REST
 * surface at all. The E2E runtime mu-plugin adds one authenticated, read-only
 * route for exactly those facts.
 */

/** The gateway ID the native card gateway registers under. */
export const SUBSCRIPTION_GATEWAY = 'woocommerce_payments';

/** The WooCommerce Subscriptions renewal-payment scheduled action hook. */
export const RENEWAL_ACTION_HOOK = 'woocommerce_scheduled_subscription_payment';

const SUBSCRIPTION_EVIDENCE_ROUTE =
	'/wp-json/wc-native-payments-e2e/v1/subscription-evidence';
const PRODUCTS_ROUTE = '/wp-json/wc/v3/products';
const SUBSCRIPTIONS_ROUTE = '/wp-json/wc/v3/subscriptions';
const ORDERS_ROUTE = '/wp-json/wc/v3/orders';
const WP_CRON_PATH = '/wp-cron.php';

const CHANGE_PAYMENT_BUTTON = 'Change payment method';
const UPDATE_ALL_SUBSCRIPTIONS_FIELD =
	'#update_all_subscriptions_payment_method';
const CLASSIC_PAYMENT_ERROR = '#wcpay-core-payment-errors';
const ORDER_ACTIONS_SELECT = 'select[name="wc_order_action"]';
const PROCESS_RENEWAL_ACTION = 'wcs_process_renewal';

const SUBMISSION_TIMEOUT_MS = 60_000;

function fail( message: string ): never {
	throw new Error( `WooPayments native subscriptions driver ${ message }` );
}

function quarantine(
	message: string,
	reasonCode: 'uncertain-provider-write' | 'cleanup-failed',
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments native subscriptions driver ${ message }`,
		reasonCode,
		primaryError
	);
}

export function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function requireString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' ) {
		fail( `requires a string ${ label }.` );
	}
	return value;
}

function requireNumber( value: unknown, label: string ): number {
	if ( typeof value !== 'number' || ! Number.isFinite( value ) ) {
		fail( `requires a numeric ${ label }.` );
	}
	return value;
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
		fail( `requires an object ${ label }.` );
	}
	return value as Record< string, unknown >;
}

function requireArray( value: unknown, label: string ): unknown[] {
	if ( ! Array.isArray( value ) ) {
		fail( `requires an array ${ label }.` );
	}
	return value;
}

async function readJson(
	response: Awaited<
		ReturnType< ProviderWriteSession[ 'adminApi' ][ 'get' ] >
	>,
	description: string
): Promise< unknown > {
	if ( ! response.ok() ) {
		fail(
			`could not read ${ description }: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return response.json();
}

/* ------------------------------------------------------------------------ *
 * Product fixtures
 * ------------------------------------------------------------------------ */

export interface SubscriptionProductSpec {
	/** Suffix that distinguishes this product inside one run. */
	slug: string;
	/** Recurring price, also the catalog price. */
	price: string;
	/** Signup fee charged once on the parent order. Absent means no fee line. */
	signUpFee?: string;
	/** Free-trial length in `trialPeriod` units. Absent means no trial. */
	trialLength?: number;
	trialPeriod?: 'day' | 'week' | 'month' | 'year';
}

export interface OwnedSubscriptionProduct {
	id: number;
	name: string;
	price: string;
	signUpFee: string;
	trialLength: number;
}

/**
 * Creates one run-owned monthly subscription product.
 *
 * `pilotRuntime.createOwnedProduct()` cannot serve this family: it creates a
 * simple product, and a subscription needs its own product type plus the
 * WooCommerce Subscriptions schedule meta. The run ID is written into both the
 * name and the ownership meta so a survivor can be attributed to its run.
 */
export async function createSubscriptionProduct(
	session: ProviderWriteSession,
	spec: SubscriptionProductSpec
): Promise< OwnedSubscriptionProduct > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'subscription-lifecycle-product' );

	const name = `WooPayments native subscription E2E ${ session.runId } ${ spec.slug }`;
	const signUpFee = spec.signUpFee ?? '0';
	const trialLength = spec.trialLength ?? 0;
	const created = requireObject(
		await readJson(
			await session.performWrite( () =>
				session.adminApi.post( PRODUCTS_ROUTE, {
					data: {
						name,
						type: 'subscription',
						virtual: true,
						regular_price: spec.price,
						status: 'publish',
						meta_data: [
							{ key: '_subscription_price', value: spec.price },
							{ key: '_subscription_period', value: 'month' },
							{
								key: '_subscription_period_interval',
								value: '1',
							},
							{ key: '_subscription_length', value: '0' },
							{
								key: '_subscription_sign_up_fee',
								value: signUpFee,
							},
							{
								key: '_subscription_trial_length',
								value: String( trialLength ),
							},
							{
								key: '_subscription_trial_period',
								value: spec.trialPeriod ?? 'day',
							},
							{
								key: '_e2e_woopayments_run_id',
								value: session.runId,
							},
						],
					},
				} )
			),
			'the run-owned subscription product creation'
		),
		'subscription product'
	);

	const id = requireNumber( created.id, 'subscription product ID' );
	if ( created.type !== 'subscription' ) {
		fail(
			`created product ${ id } is a ${ String(
				created.type
			) }, not a subscription.`
		);
	}

	return { id, name, price: spec.price, signUpFee, trialLength };
}

export async function deleteSubscriptionProducts(
	session: ProviderWriteSession,
	productIds: readonly number[]
): Promise< void > {
	for ( const productId of productIds ) {
		const response = await session.performWrite( () =>
			session.adminApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
				data: { force: true },
				failOnStatusCode: false,
			} )
		);
		if ( ! response.ok() && response.status() !== 404 ) {
			fail(
				`could not remove run-owned product ${ productId }: HTTP ${ response.status() }.`
			);
		}
	}
}

/* ------------------------------------------------------------------------ *
 * Evidence reads
 * ------------------------------------------------------------------------ */

export interface SubscriptionTokenEvidence {
	tokenId: number;
	exists: boolean;
	paymentMethodId: string;
	gatewayId: string;
	userId: number;
	isDefault: boolean;
}

export interface SubscriptionLineItemEvidence {
	itemId: number;
	productId: number;
	quantity: number;
	subtotal: string;
	total: string;
}

export interface SubscriptionFeeEvidence {
	itemId: number;
	name: string;
	total: string;
}

export interface ScheduledActionEvidence {
	actionId: number;
	hook: string;
	status: string;
	subscriptionId: number;
	scheduledTimestamp: number;
}

export interface SubscriptionRelatedOrders {
	parent: number[];
	renewal: number[];
	switch: number[];
	resubscribe: number[];
}

export interface SubscriptionEvidence {
	id: number;
	status: string;
	parentId: number;
	customerId: number;
	currency: string;
	total: string;
	paymentMethod: string;
	billingPeriod: string;
	billingInterval: string;
	requiresManualRenewal: boolean;
	paymentCount: number;
	startGmt: string;
	trialEndGmt: string;
	nextPaymentGmt: string;
	paymentTokenIds: number[];
	activeTokenId: number;
	paymentTokens: SubscriptionTokenEvidence[];
	lineItems: SubscriptionLineItemEvidence[];
	feeLines: SubscriptionFeeEvidence[];
	relatedOrders: SubscriptionRelatedOrders;
	scheduledActions: ScheduledActionEvidence[];
}

export interface SetupIntentEvidence {
	id: string;
	status: string;
	usage: string;
	paymentMethodId: string;
	customerId: string;
}

function parseScheduledActions( value: unknown ): ScheduledActionEvidence[] {
	return requireArray( value, 'scheduled action list' ).map(
		( entry, index ) => {
			const action = requireObject(
				entry,
				`scheduled action ${ index }`
			);
			return {
				actionId: requireNumber( action.action_id, 'action ID' ),
				hook: requireString( action.hook, 'action hook' ),
				status: requireString( action.status, 'action status' ),
				subscriptionId: requireNumber(
					action.subscription_id,
					'action subscription ID'
				),
				scheduledTimestamp: requireNumber(
					action.scheduled_timestamp,
					'action scheduled timestamp'
				),
			};
		}
	);
}

function parseSubscription( value: unknown ): SubscriptionEvidence {
	const record = requireObject( value, 'subscription evidence' );
	const related = requireObject(
		record.related_orders,
		'related order evidence'
	);
	const relatedIds = ( relation: string ): number[] =>
		requireArray( related[ relation ], `${ relation } order IDs` ).map(
			( id, index ) =>
				requireNumber( id, `${ relation } order ${ index }` )
		);

	return {
		id: requireNumber( record.id, 'subscription ID' ),
		status: requireString( record.status, 'subscription status' ),
		parentId: requireNumber( record.parent_id, 'parent order ID' ),
		customerId: requireNumber( record.customer_id, 'customer ID' ),
		currency: requireString( record.currency, 'currency' ),
		total: requireString( record.total, 'total' ),
		paymentMethod: requireString( record.payment_method, 'payment method' ),
		billingPeriod: requireString( record.billing_period, 'billing period' ),
		billingInterval: requireString(
			record.billing_interval,
			'billing interval'
		),
		requiresManualRenewal: record.requires_manual_renewal === true,
		paymentCount: requireNumber( record.payment_count, 'payment count' ),
		startGmt: requireString( record.start_gmt, 'start date' ),
		trialEndGmt: requireString( record.trial_end_gmt, 'trial end date' ),
		nextPaymentGmt: requireString(
			record.next_payment_gmt,
			'next payment date'
		),
		paymentTokenIds: requireArray(
			record.payment_token_ids,
			'payment token IDs'
		).map( ( id, index ) =>
			requireNumber( id, `payment token ${ index }` )
		),
		activeTokenId: requireNumber(
			record.active_token_id,
			'active token ID'
		),
		paymentTokens: requireArray(
			record.payment_tokens,
			'payment token evidence'
		).map( ( entry, index ) => {
			const token = requireObject( entry, `payment token ${ index }` );
			return {
				tokenId: requireNumber( token.token_id, 'token ID' ),
				exists: token.exists === true,
				paymentMethodId: requireString(
					token.payment_method_id,
					'token payment method ID'
				),
				gatewayId: requireString(
					token.gateway_id,
					'token gateway ID'
				),
				userId: requireNumber( token.user_id, 'token user ID' ),
				isDefault: token.is_default === true,
			};
		} ),
		lineItems: requireArray( record.line_items, 'line items' ).map(
			( entry, index ) => {
				const item = requireObject( entry, `line item ${ index }` );
				return {
					itemId: requireNumber( item.item_id, 'line item ID' ),
					productId: requireNumber( item.product_id, 'product ID' ),
					quantity: requireNumber( item.quantity, 'quantity' ),
					subtotal: requireString( item.subtotal, 'line subtotal' ),
					total: requireString( item.total, 'line total' ),
				};
			}
		),
		feeLines: requireArray( record.fee_lines, 'fee lines' ).map(
			( entry, index ) => {
				const item = requireObject( entry, `fee line ${ index }` );
				return {
					itemId: requireNumber( item.item_id, 'fee line ID' ),
					name: requireString( item.name, 'fee name' ),
					total: requireString( item.total, 'fee total' ),
				};
			}
		),
		relatedOrders: {
			parent: relatedIds( 'parent' ),
			renewal: relatedIds( 'renewal' ),
			switch: relatedIds( 'switch' ),
			resubscribe: relatedIds( 'resubscribe' ),
		},
		scheduledActions: parseScheduledActions( record.scheduled_actions ),
	};
}

async function requestSubscriptionEvidence(
	session: ProviderWriteSession,
	parameters: URLSearchParams
): Promise< Record< string, unknown > > {
	return requireObject(
		await readJson(
			await session.adminApi.get(
				`${ SUBSCRIPTION_EVIDENCE_ROUTE }?${ parameters.toString() }`
			),
			'subscription evidence'
		),
		'subscription evidence'
	);
}

export async function readSubscriptionEvidence(
	session: ProviderWriteSession,
	subscriptionId: number
): Promise< SubscriptionEvidence > {
	const payload = await requestSubscriptionEvidence(
		session,
		new URLSearchParams( { subscription_id: String( subscriptionId ) } )
	);
	const evidence = parseSubscription( payload.subscription );
	if ( evidence.id !== subscriptionId ) {
		fail(
			`read subscription ${ evidence.id } while asking for ${ subscriptionId }.`
		);
	}
	return evidence;
}

/** True when the store no longer holds the named subscription at all. */
export async function subscriptionIsGone(
	session: ProviderWriteSession,
	subscriptionId: number
): Promise< boolean > {
	const response = await session.adminApi.get(
		`${ SUBSCRIPTION_EVIDENCE_ROUTE }?subscription_id=${ subscriptionId }`
	);
	if ( response.status() === 404 ) {
		return true;
	}
	if ( ! response.ok() ) {
		fail(
			`could not confirm the removal of subscription ${ subscriptionId }: HTTP ${ response.status() }.`
		);
	}
	return false;
}

export async function readPendingRenewalActions(
	session: ProviderWriteSession
): Promise< ScheduledActionEvidence[] > {
	const payload = await requestSubscriptionEvidence(
		session,
		new URLSearchParams()
	);
	return parseScheduledActions( payload.pending_renewal_actions );
}

export interface StoreBaselineEvidence {
	gatewaySettingsHash: string;
	gatewayEnabled: string;
	gatewaySavedCards: string;
	gatewayTestMode: string;
	storeCurrency: string;
	subscriptionSettings: {
		acceptManualRenewals: string;
		turnOffAutomaticPayments: string;
		multiplePurchase: string;
	};
}

/**
 * The store configuration this family must leave exactly as it found it.
 *
 * Nothing in the suite writes any of it, so restoration is a proof rather than
 * an action: the same read has to answer identically after the run.
 */
export async function readStoreBaseline(
	session: ProviderWriteSession
): Promise< StoreBaselineEvidence > {
	const payload = await requestSubscriptionEvidence(
		session,
		new URLSearchParams()
	);
	const baseline = requireObject( payload.store_baseline, 'store baseline' );
	const subscriptionSettings = requireObject(
		baseline.subscription_settings,
		'subscription settings baseline'
	);

	return {
		gatewaySettingsHash: requireString(
			baseline.gateway_settings_hash,
			'gateway settings hash'
		),
		gatewayEnabled: requireString(
			baseline.gateway_enabled,
			'gateway enabled'
		),
		gatewaySavedCards: requireString(
			baseline.gateway_saved_cards,
			'gateway saved cards'
		),
		gatewayTestMode: requireString(
			baseline.gateway_test_mode,
			'gateway test mode'
		),
		storeCurrency: requireString(
			baseline.store_currency,
			'store currency'
		),
		subscriptionSettings: {
			acceptManualRenewals: requireString(
				subscriptionSettings.accept_manual_renewals,
				'manual renewal setting'
			),
			turnOffAutomaticPayments: requireString(
				subscriptionSettings.turn_off_automatic_payments,
				'automatic payment setting'
			),
			multiplePurchase: requireString(
				subscriptionSettings.multiple_purchase,
				'multiple purchase setting'
			),
		},
	};
}

export async function readCustomerSubscriptionIds(
	session: ProviderWriteSession
): Promise< number[] > {
	const payload = await requestSubscriptionEvidence(
		session,
		new URLSearchParams( { customer_username: customer.username } )
	);
	return requireArray(
		payload.customer_subscription_ids,
		'customer subscription IDs'
	).map( ( id, index ) =>
		requireNumber( id, `customer subscription ${ index }` )
	);
}

export async function readProviderSetupIntent(
	session: ProviderWriteSession,
	setupIntentId: string
): Promise< SetupIntentEvidence > {
	const payload = await requestSubscriptionEvidence(
		session,
		new URLSearchParams( { setup_intent_id: setupIntentId } )
	);
	const intent = requireObject(
		payload.setup_intent,
		'SetupIntent evidence'
	);
	return {
		id: requireString( intent.id, 'SetupIntent ID' ),
		status: requireString( intent.status, 'SetupIntent status' ),
		usage: requireString( intent.usage, 'SetupIntent usage' ),
		paymentMethodId: requireString(
			intent.payment_method_id,
			'SetupIntent payment method ID'
		),
		customerId: requireString(
			intent.customer_id,
			'SetupIntent customer ID'
		),
	};
}

/* ------------------------------------------------------------------------ *
 * Order-record reads
 * ------------------------------------------------------------------------ */

export interface OrderLineEvidence {
	itemId: number;
	productId: number;
	quantity: number;
	subtotal: string;
	total: string;
}

export interface OrderRecordEvidence {
	id: number;
	status: string;
	total: string;
	currency: string;
	paymentMethod: string;
	customerId: number;
	meta: Record< string, string >;
	lineItems: OrderLineEvidence[];
	feeLines: SubscriptionFeeEvidence[];
}

export async function readOrderRecord(
	session: ProviderWriteSession,
	orderId: number
): Promise< OrderRecordEvidence > {
	const order = requireObject(
		await readJson(
			await session.adminApi.get( `${ ORDERS_ROUTE }/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		),
		`order ${ orderId }`
	);

	const meta: Record< string, string > = {};
	for ( const entry of requireArray( order.meta_data, 'order meta' ) ) {
		const item = requireObject( entry, 'order meta entry' );
		if ( typeof item.key === 'string' && typeof item.value === 'string' ) {
			meta[ item.key ] = item.value;
		}
	}

	return {
		id: requireNumber( order.id, 'order ID' ),
		status: requireString( order.status, 'order status' ),
		total: requireString( order.total, 'order total' ),
		currency: requireString(
			order.currency,
			'order currency'
		).toUpperCase(),
		paymentMethod: requireString( order.payment_method, 'order gateway' ),
		customerId: requireNumber( order.customer_id, 'order customer ID' ),
		meta,
		lineItems: requireArray( order.line_items, 'order line items' ).map(
			( entry, index ) => {
				const item = requireObject(
					entry,
					`order line item ${ index }`
				);
				return {
					itemId: requireNumber( item.id, 'order line item ID' ),
					productId: requireNumber( item.product_id, 'product ID' ),
					quantity: requireNumber( item.quantity, 'quantity' ),
					subtotal: requireString( item.subtotal, 'line subtotal' ),
					total: requireString( item.total, 'line total' ),
				};
			}
		),
		feeLines: requireArray( order.fee_lines, 'order fee lines' ).map(
			( entry, index ) => {
				const item = requireObject(
					entry,
					`order fee line ${ index }`
				);
				return {
					itemId: requireNumber( item.id, 'order fee line ID' ),
					name: requireString( item.name, 'fee name' ),
					total: requireString( item.total, 'fee total' ),
				};
			}
		),
	};
}

/* ------------------------------------------------------------------------ *
 * Signup
 * ------------------------------------------------------------------------ */

export interface SubscriptionSignupOptions {
	products: readonly OwnedSubscriptionProduct[];
	checkout: ClassicCheckoutTarget;
	card: ProviderTestCard;
	journal: string;
}

export interface SubscriptionSignupResult {
	orderId: number;
	orderKey: string;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
}

/**
 * Signs the standing shopper up for one basket of subscription products on the
 * Classic checkout, with exactly one Place order activation.
 *
 * The Classic surface is used throughout this family because the change-payment
 * surface WooCommerce Subscriptions renders is the Classic order-pay form
 * whatever the store's checkout page is, so one surface keeps `S1`-`S7`
 * comparable to each other.
 */
export async function signUpForSubscriptions(
	session: ProviderWriteSession,
	page: Page,
	options: SubscriptionSignupOptions
): Promise< SubscriptionSignupResult > {
	if ( options.products.length === 0 ) {
		fail( 'requires at least one subscription product to sign up for.' );
	}
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'subscription-lifecycle-signup' );

	// A subscription checkout is never a guest checkout: the recurring
	// credential has to belong to an account, and every identity assertion in
	// this family names that account's tokens.
	await session.logInAsCustomer( page );

	const browser = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		options.checkout.pageId
	);
	await browser.preflightClassicPage(
		options.checkout.path,
		options.checkout.pageId
	);

	let addActivationCount = 0;
	for ( const product of options.products ) {
		await browser.addProductOnce( product.id, ( write ) => {
			addActivationCount += 1;
			return session.performWrite( write );
		} );
	}
	if ( addActivationCount !== options.products.length ) {
		fail( 'must activate Add to cart exactly once per basket product.' );
	}

	await browser.openClassicCheckout( options.checkout.path );
	await browser.fillBillingDetails( session.runId );
	await browser.selectWooPaymentsCard();
	await browser.fillTestCard( options.card );
	await browser.prepareSubmission();

	return session.withProviderSubmissionJournal( options.journal, async () => {
		let submissionAttempted = false;
		try {
			let placeOrderActivationCount = 0;
			const observation = await browser.observeSubmission(
				async ( activate ) => {
					placeOrderActivationCount += 1;
					if ( placeOrderActivationCount !== 1 ) {
						fail( 'must activate Place order exactly once.' );
					}
					submissionAttempted = true;
					await session.performWrite( activate );
				}
			);
			const receipt = parseClassicOrderReceivedUrl(
				observation.receiptUrl,
				session.baseURL
			);
			await session.setOrderRunId( receipt.orderId, session.runId );

			return {
				orderId: receipt.orderId,
				orderKey: receipt.orderKey,
				checkoutRequestCount: observation.requests.length,
				checkoutResponseCount: observation.responses.length,
			};
		} catch ( error ) {
			if ( ! submissionAttempted ) {
				throw new ProviderSubmissionNotStartedError(
					`Subscription signup ${ options.journal } was never dispatched.`,
					{ cause: error }
				);
			}
			if ( error instanceof ResourceQuarantineRequiredError ) {
				throw error;
			}
			throw quarantine(
				`submission ${ options.journal } has no proven outcome.`,
				'uncertain-provider-write',
				error
			);
		}
	} );
}

/* ------------------------------------------------------------------------ *
 * Payment-method change
 * ------------------------------------------------------------------------ */

export type ChangePaymentSelection =
	| { kind: 'new-card'; card: ProviderTestCard }
	| { kind: 'saved-token'; tokenId: number };

export interface ChangePaymentOptions {
	subscriptionId: number;
	selection: ChangePaymentSelection;
	journal: string;
}

export interface ChangePaymentResult {
	submissionCount: number;
	url: string;
	errorText: string;
}

/**
 * Opens the shopper's own change-payment surface for one subscription.
 *
 * Navigated from My Account rather than by building the URL, because the URL
 * carries a nonce and because the button's presence is itself part of what the
 * shopper-facing rows describe.
 */
async function openChangePaymentSurface(
	session: ProviderWriteSession,
	page: Page,
	subscriptionId: number
): Promise< void > {
	await page.goto( `my-account/view-subscription/${ subscriptionId }/` );
	const changePayment = page.getByRole( 'link', {
		name: 'Change payment',
		exact: true,
	} );
	if ( ( await changePayment.count() ) !== 1 ) {
		fail(
			`requires exactly one Change payment action on subscription ${ subscriptionId }.`
		);
	}
	await session.performWrite( () => changePayment.click() );
	await page.waitForURL(
		new RegExp( `[?&]change_payment_method=${ subscriptionId }\\b` )
	);

	const gateway = page.locator( `#payment_method_${ SUBSCRIPTION_GATEWAY }` );
	if ( ( await gateway.count() ) !== 1 ) {
		fail(
			'requires exactly one native WooPayments option on the change-payment surface.'
		);
	}
	if ( await gateway.isVisible() ) {
		await gateway.check();
	} else if ( ! ( await gateway.isChecked() ) ) {
		fail(
			'the sole change-payment gateway is hidden but not selected, so no method is chosen.'
		);
	}

	// WooCommerce Subscriptions renders this control pre-checked whenever the
	// shopper holds more than one subscription, and it would rewrite the
	// recurring credential of every other subscription on the account. This run
	// owns one subscription; anything else on the standing shopper is not
	// ours to touch, so the control is forced off and the state proved.
	const updateAll = page.locator( UPDATE_ALL_SUBSCRIPTIONS_FIELD );
	if ( ( await updateAll.count() ) === 1 ) {
		await updateAll.uncheck();
		if ( await updateAll.isChecked() ) {
			fail(
				'could not clear the update-all-subscriptions control, which would rewrite unowned subscriptions.'
			);
		}
	}
}

async function selectChangePaymentMethod(
	page: Page,
	selection: ChangePaymentSelection
): Promise< void > {
	if ( selection.kind === 'saved-token' ) {
		const exact = page.locator(
			`input[name="wc-${ SUBSCRIPTION_GATEWAY }-payment-token"][value="${ selection.tokenId }"]`
		);
		if ( ( await exact.count() ) !== 1 ) {
			fail(
				`requires exactly one change-payment control bound to local token ${ selection.tokenId }.`
			);
		}
		await exact.check();
		if ( ! ( await exact.isChecked() ) ) {
			fail(
				`could not select the change-payment control for local token ${ selection.tokenId }.`
			);
		}
		return;
	}

	// A new method is only genuinely new when the "use a new payment method"
	// control is the selected one; native reads exactly that field to decide
	// whether it is being asked for a SetupIntent. A shopper holding no stored
	// card is offered no such control, and entering a card is then the only
	// thing the surface can mean.
	const tokenInputs = page.locator(
		`input[name="wc-${ SUBSCRIPTION_GATEWAY }-payment-token"]`
	);
	if ( ( await tokenInputs.count() ) > 0 ) {
		const newMethod = page.locator(
			`#wc-${ SUBSCRIPTION_GATEWAY }-payment-token-new`
		);
		if ( ( await newMethod.count() ) !== 1 ) {
			fail(
				'requires exactly one new-payment-method control on the change-payment surface.'
			);
		}
		await newMethod.check();
		if ( ! ( await newMethod.isChecked() ) ) {
			fail( 'could not select the new-payment-method control.' );
		}
	}
}

/**
 * Submits one payment-method change and reports what the surface did.
 *
 * The whole interval sits inside one provider submission journal: a new-method
 * change asks the provider for a SetupIntent, so a run that dies mid-flight
 * must leave an unresolved attempt rather than an unexplained credential.
 */
export async function changeSubscriptionPaymentMethod(
	session: ProviderWriteSession,
	page: Page,
	options: ChangePaymentOptions
): Promise< ChangePaymentResult > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture(
		'subscription-lifecycle-method-change'
	);
	await session.logInAsCustomer( page );
	await openChangePaymentSurface( session, page, options.subscriptionId );
	await selectChangePaymentMethod( page, options.selection );

	if ( options.selection.kind === 'new-card' ) {
		const frame = page.frameLocator(
			`#payment .payment_method_${ SUBSCRIPTION_GATEWAY } #wcpay-core-payment-element iframe[name^="__privateStripeFrame"], ` +
				`#payment .payment_method_${ SUBSCRIPTION_GATEWAY } .wcpay-upe-element iframe`
		);
		await frame
			.getByRole( 'textbox', { name: 'Card number' } )
			.fill( options.selection.card.number );
		await frame
			.getByRole( 'textbox', { name: /Expiration date/i } )
			.fill( options.selection.card.expiry );
		await frame
			.getByRole( 'textbox', { name: 'Security code' } )
			.fill( options.selection.card.securityCode );
	}

	const submit = page.getByRole( 'button', {
		name: CHANGE_PAYMENT_BUTTON,
		exact: true,
	} );
	if ( ( await submit.count() ) !== 1 ) {
		fail( 'requires exactly one Change payment method submit control.' );
	}

	const changeUrl = page.url();
	let submissionCount = 0;
	const countSubmission = ( request: Request ): void => {
		if ( request.method() !== 'POST' ) {
			return;
		}
		if ( request.url().split( '#' )[ 0 ] === changeUrl.split( '#' )[ 0 ] ) {
			submissionCount += 1;
		}
	};
	page.on( 'request', countSubmission );

	return session.withProviderSubmissionJournal( options.journal, async () => {
		let submissionAttempted = false;
		try {
			submissionAttempted = true;
			await session.performWrite( () => submit.click() );

			// A change either navigates away or reports a failure in native's
			// own Classic error region. Waiting for either keeps a rejected
			// change an observation the caller can assert on rather than a raw
			// timeout after a submission that may have reached the provider.
			await Promise.race( [
				page.waitForURL(
					( candidate ) =>
						! candidate.href.includes( 'change_payment_method=' ),
					{ timeout: SUBMISSION_TIMEOUT_MS }
				),
				page.locator( CLASSIC_PAYMENT_ERROR ).waitFor( {
					state: 'visible',
					timeout: SUBMISSION_TIMEOUT_MS,
				} ),
			] );

			const errorRegion = page.locator( CLASSIC_PAYMENT_ERROR );
			const errorText =
				( await errorRegion.count() ) === 1 &&
				( await errorRegion.isVisible() )
					? ( ( await errorRegion.innerText() ) ?? '' ).trim()
					: '';

			return { submissionCount, url: page.url(), errorText };
		} catch ( error ) {
			if ( ! submissionAttempted ) {
				throw new ProviderSubmissionNotStartedError(
					`Payment-method change ${ options.journal } was never dispatched.`,
					{ cause: error }
				);
			}
			throw quarantine(
				`payment-method change ${ options.journal } has no proven outcome.`,
				'uncertain-provider-write',
				error
			);
		} finally {
			page.off( 'request', countSubmission );
		}
	} );
}

/* ------------------------------------------------------------------------ *
 * Renewals
 * ------------------------------------------------------------------------ */

/**
 * Runs the merchant's own "Process renewal" order action on one subscription.
 *
 * This is the admin gesture WooCommerce Subscriptions offers, and it dispatches
 * `woocommerce_scheduled_subscription_payment` directly rather than through
 * Action Scheduler. The cron half of `S6` deliberately does not use it.
 */
export async function processMerchantRenewal(
	session: ProviderWriteSession,
	page: Page,
	subscriptionId: number,
	journal: string
): Promise< void > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'subscription-lifecycle-renewal' );
	await session.logInAsAdmin( page );
	await page.goto(
		`wp-admin/admin.php?page=wc-orders--shop_subscription&action=edit&id=${ subscriptionId }`
	);

	const actions = page.locator( ORDER_ACTIONS_SELECT );
	if ( ( await actions.count() ) !== 1 ) {
		fail(
			`requires exactly one order-actions control on subscription ${ subscriptionId }.`
		);
	}
	const available = await actions
		.locator( `option[value="${ PROCESS_RENEWAL_ACTION }"]` )
		.count();
	if ( available !== 1 ) {
		fail(
			`requires the Process renewal action on subscription ${ subscriptionId }.`
		);
	}
	await actions.selectOption( PROCESS_RENEWAL_ACTION );

	const apply = page.getByRole( 'button', { name: 'Update', exact: true } );
	if ( ( await apply.count() ) !== 1 ) {
		fail(
			'requires exactly one Update control on the subscription screen.'
		);
	}

	await session.withProviderSubmissionJournal( journal, async () => {
		let submissionAttempted = false;
		try {
			submissionAttempted = true;
			await session.performWrite( () => apply.click() );
			await page.waitForURL( /post\.php|wc-orders/, {
				timeout: SUBMISSION_TIMEOUT_MS,
			} );
		} catch ( error ) {
			if ( ! submissionAttempted ) {
				throw new ProviderSubmissionNotStartedError(
					`Merchant renewal ${ journal } was never dispatched.`,
					{ cause: error }
				);
			}
			throw quarantine(
				`merchant renewal ${ journal } has no proven outcome.`,
				'uncertain-provider-write',
				error
			);
		}
	} );
}

/**
 * Moves one subscription's schedule into the past so its Action Scheduler
 * renewal action becomes due.
 *
 * Both dates go in one request on purpose: WooCommerce Subscriptions validates
 * the whole date set together and rejects a next payment that does not fall
 * after the start date, so a past next payment needs a start date moved with
 * it. Nothing here touches Action Scheduler directly — WooCommerce
 * Subscriptions reschedules its own action off the date change, which is the
 * mechanism the case is about.
 */
export async function seedDueRenewalAction(
	session: ProviderWriteSession,
	subscriptionId: number,
	options: { startSecondsAgo: number; dueSecondsAgo: number }
): Promise< void > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'subscription-lifecycle-renewal' );

	const now = Date.now();
	const asGmt = ( secondsAgo: number ): string =>
		new Date( now - secondsAgo * 1000 )
			.toISOString()
			.replace( 'T', ' ' )
			.replace( /\.\d+Z$/, '' );

	await readJson(
		await session.performWrite( () =>
			session.adminApi.put(
				`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
				{
					data: {
						start_date: asGmt( options.startSecondsAgo ),
						next_payment_date: asGmt( options.dueSecondsAgo ),
					},
				}
			)
		),
		`the due-renewal seeding of subscription ${ subscriptionId }`
	);
}

/**
 * Dispatches WP-Cron until the named Action Scheduler action leaves the pending
 * state, and reports how the queue ran.
 *
 * `wp-cron.php` is the loopback endpoint WordPress itself calls; requesting it
 * runs every due cron hook, `action_scheduler_run_queue` among them, which is
 * what hands the due renewal to the Action Scheduler queue runner. That is the
 * whole point of the case: the renewal must be driven by the scheduler, not by
 * an admin action that fires the hook by hand.
 */
export async function dispatchWpCronUntilActionRan(
	session: ProviderWriteSession,
	subscriptionId: number,
	actionId: number,
	options: { pollIntervalMs: number; timeoutMs: number }
): Promise< ScheduledActionEvidence > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'subscription-lifecycle-renewal' );

	const deadline = Date.now() + options.timeoutMs;
	let lastSeen: ScheduledActionEvidence | undefined;

	for (;;) {
		await session.performWrite( () =>
			session.adminApi.get( WP_CRON_PATH, {
				failOnStatusCode: false,
				timeout: options.pollIntervalMs * 10,
			} )
		);

		const evidence = await readSubscriptionEvidence(
			session,
			subscriptionId
		);
		lastSeen = evidence.scheduledActions.find(
			( action ) => action.actionId === actionId
		);
		if ( ! lastSeen ) {
			fail(
				`lost the seeded renewal action ${ actionId } on subscription ${ subscriptionId }.`
			);
		}
		if (
			lastSeen.status !== 'pending' &&
			lastSeen.status !== 'in-progress'
		) {
			return lastSeen;
		}
		if ( Date.now() >= deadline ) {
			throw quarantine(
				`WP-Cron never ran the seeded renewal action ${ actionId }; it is still ${ lastSeen.status }.`,
				'uncertain-provider-write'
			);
		}
		await delay( options.pollIntervalMs );
	}
}

/* ------------------------------------------------------------------------ *
 * Cleanup
 * ------------------------------------------------------------------------ */

/**
 * Cancels and removes one run-owned subscription.
 *
 * The cancellation is not cosmetic: WooCommerce Subscriptions unschedules every
 * action a subscription owns on the transition to `cancelled`, so a run that
 * only deleted the record could leave recurring billing armed on the account.
 * The removal is then proved, and so is the absence of any pending renewal
 * action still naming it.
 */
export async function removeRunSubscription(
	session: ProviderWriteSession,
	subscriptionId: number
): Promise< void > {
	session.requireApprovedProviderFixture( 'subscription-lifecycle-cleanup' );

	const cancelled = await session.performWrite( () =>
		session.adminApi.put( `${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`, {
			data: { transition_status: 'cancelled' },
			failOnStatusCode: false,
		} )
	);
	if ( ! cancelled.ok() && cancelled.status() !== 404 ) {
		fail(
			`could not cancel run-owned subscription ${ subscriptionId }: HTTP ${ cancelled.status() }.`
		);
	}

	const deleted = await session.performWrite( () =>
		session.adminApi.delete(
			`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
			{
				params: { force: true },
				failOnStatusCode: false,
			}
		)
	);
	if ( ! deleted.ok() && deleted.status() !== 404 ) {
		fail(
			`could not remove run-owned subscription ${ subscriptionId }: HTTP ${ deleted.status() }.`
		);
	}

	if ( ! ( await subscriptionIsGone( session, subscriptionId ) ) ) {
		fail( `run-owned subscription ${ subscriptionId } survived removal.` );
	}
	const pending = await readPendingRenewalActions( session );
	const survivors = pending.filter(
		( action ) => action.subscriptionId === subscriptionId
	);
	if ( survivors.length > 0 ) {
		fail(
			`left ${ survivors.length } pending renewal action(s) armed on removed subscription ${ subscriptionId }.`
		);
	}
}

export async function removeRunOrders(
	session: ProviderWriteSession,
	orderIds: readonly number[]
): Promise< void > {
	session.requireApprovedProviderFixture( 'subscription-lifecycle-cleanup' );

	for ( const orderId of orderIds ) {
		const response = await session.performWrite( () =>
			session.adminApi.delete( `${ ORDERS_ROUTE }/${ orderId }`, {
				data: { force: true },
				failOnStatusCode: false,
			} )
		);
		if ( ! response.ok() && response.status() !== 404 ) {
			fail(
				`could not remove run-owned order ${ orderId }: HTTP ${ response.status() }.`
			);
		}
	}
}

/**
 * Empties the shopper's cart so nothing survives into the next run.
 *
 * Signs in first: a case that ended on an admin screen would otherwise inspect
 * a guest cart and report a shopper cart it never looked at.
 */
export async function emptyShopperCart(
	session: ProviderWriteSession,
	page: Page
): Promise< void > {
	await session.logInAsCustomer( page );
	await page.goto( 'cart/' );
	const removals = page.getByRole( 'link', { name: /^Remove/ } );
	for (;;) {
		const remaining = await removals.count();
		if ( remaining === 0 ) {
			return;
		}
		await session.performWrite( () => removals.first().click() );
		await page.waitForLoadState( 'domcontentloaded' );
		if ( ( await removals.count() ) >= remaining ) {
			fail( 'could not empty the shopper cart.' );
		}
	}
}
