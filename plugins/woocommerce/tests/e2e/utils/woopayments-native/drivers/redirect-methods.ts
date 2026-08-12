import { createHash } from 'node:crypto';

import {
	expect,
	type APIRequestContext,
	type APIResponse,
	type Page,
	type Request,
	type Response,
} from '@playwright/test';

import type {
	OwnedProduct,
	ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { getPaymentEvidence, type PaymentEvidence } from '../record-evidence';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import {
	PlaywrightClassicCardCheckoutBrowser,
	type PublicTokenDigest,
} from './classic-card-checkout';
import type { ClassicCheckoutTarget } from './classic-checkout-page';

/**
 * The redirect payment methods, and the store state one of them needs.
 *
 * Two provider-fidelity families drive the same five methods for different
 * reasons. `refund-settlement` creates a redirect source charge so it has
 * something to refund; `redirect-method-provider-outcome` drives the handoff
 * itself and asserts what the provider was asked for. Both need the same
 * catalog, the same billing addresses, the same enabled-method snapshot and the
 * same foreign-currency snapshot, so those live here rather than in either
 * spec.
 *
 * What is deliberately *not* shared is what each family asserts. This module
 * observes and reports; every expectation about a method, an amount, a currency
 * or a return URL belongs to the case that fixed it.
 *
 * Two shapes of shopper journey are driven here:
 *
 * - Classic. The store answers the checkout POST with the provider's hosted
 *   URL, WooCommerce's script navigates to it, the shopper authorizes once, and
 *   the provider returns to the URL native put in the intent. The window
 *   between the checkout response and the authorization is where the request
 *   evidence lives: the PaymentIntent is readable in `requires_action`, and
 *   `next_action.redirect_to_url` still carries both the hosted URL and the
 *   return URL native sent. After the intent succeeds that object is gone, so a
 *   post-hoc read cannot say what was asked for.
 * - Blocks. The same proposition through the Store API, whose checkout response
 *   carries the order and the same provider redirect.
 */

const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const STORE_CART_API = '/wp-json/wc/store/v1/cart';
const CLASSIC_CHECKOUT_FORM = 'form.checkout.woocommerce-checkout';
const CLASSIC_NOTICE_GROUP = `${ CLASSIC_CHECKOUT_FORM } .woocommerce-NoticeGroup-checkout`;
const BLOCKS_CHECKOUT_PATH = '/wp-json/wc/store/v1/checkout';
const ORDER_RECEIVED_PATH = /\/order-received\/([1-9]\d*)\/?$/;

/**
 * A manual rate of exactly 1.0 with no rounding or charm adjustment, so a
 * converted order total is the catalog price and independent of any
 * provider-fetched rate.
 */
const MANUAL_RATE = 1;

const CHECKOUT_EXCHANGE_TIMEOUT_MS = 90_000;
const HOSTED_PAGE_TIMEOUT_MS = 90_000;
const RECEIPT_TIMEOUT_MS = 90_000;
const ORDER_INTENT_TIMEOUT_MS = 60_000;
const REJECTION_NOTICE_TIMEOUT_MS = 30_000;
const POLL_INTERVAL_MS = 500;

export interface BillingAddress {
	country: string;
	state?: string;
	city: string;
	address: string;
	postcode: string;
}

export const US_BILLING: BillingAddress = {
	country: 'US',
	state: 'CA',
	city: 'San Francisco',
	address: '123 Test Street',
	postcode: '94107',
};

export const BE_BILLING: BillingAddress = {
	country: 'BE',
	city: 'Brussels',
	address: 'Rue de la Loi 16',
	postcode: '1000',
};

export interface RedirectMethod {
	/** The provider method ID, as `enabled_payment_method_ids` names it. */
	id: string;
	/** The split gateway ID native registers for it. */
	gatewayId: string;
	/** The label the shopper reads on the classic checkout. */
	label: RegExp;
	billing: BillingAddress;
	currency: string;
	price: string;
	amountMinor: number;
}

export const ALIPAY: RedirectMethod = {
	id: 'alipay',
	gatewayId: 'woocommerce_payments_alipay',
	label: /alipay/i,
	billing: US_BILLING,
	currency: 'USD',
	price: '12.00',
	amountMinor: 1200,
};

export const AFFIRM: RedirectMethod = {
	id: 'affirm',
	gatewayId: 'woocommerce_payments_affirm',
	label: /affirm/i,
	billing: US_BILLING,
	currency: 'USD',
	price: '100.00',
	amountMinor: 10000,
};

export const BANCONTACT: RedirectMethod = {
	id: 'bancontact',
	gatewayId: 'woocommerce_payments_bancontact',
	label: /bancontact/i,
	billing: BE_BILLING,
	currency: 'EUR',
	price: '12.34',
	amountMinor: 1234,
};

export const AFTERPAY: RedirectMethod = {
	id: 'afterpay_clearpay',
	gatewayId: 'woocommerce_payments_afterpay_clearpay',
	label: /cash app afterpay|afterpay|clearpay/i,
	billing: US_BILLING,
	currency: 'USD',
	price: '100.00',
	amountMinor: 10000,
};

export const KLARNA: RedirectMethod = {
	id: 'klarna',
	gatewayId: 'woocommerce_payments_klarna',
	label: /klarna/i,
	billing: US_BILLING,
	currency: 'USD',
	price: '100.00',
	amountMinor: 10000,
};

function fail( message: string ): never {
	throw new Error( `WooPayments redirect method ${ message }` );
}

/**
 * Used only after a submission has left the browser. Before that point a
 * failure costs nothing and is reported plainly; afterwards, an outcome this
 * run cannot account for is provider state rather than a test failure.
 */
function quarantine(
	message: string,
	primaryError?: unknown
): ResourceQuarantineRequiredError {
	return new ResourceQuarantineRequiredError(
		`WooPayments redirect method ${ message }`,
		'uncertain-provider-write',
		primaryError
	);
}

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function readJson< Result >(
	response: APIResponse,
	description: string
): Promise< Result > {
	if ( ! response.ok() ) {
		throw new Error(
			`${ description } failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as Result;
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `evidence requires a ${ label }.` );
	}
	return value;
}

function requiredObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `evidence requires exactly one ${ label } object.` );
	}
	return value as Record< string, unknown >;
}

/* -------------------------------------------------------------------------
 * Enabled-payment-method snapshot and byte restoration
 * ---------------------------------------------------------------------- */

export async function readEnabledPaymentMethodIds(
	restApi: APIRequestContext
): Promise< string[] > {
	const settings = await readJson< Record< string, unknown > >(
		await restApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	const enabled = settings.enabled_payment_method_ids;
	if ( ! Array.isArray( enabled ) ) {
		fail( 'payments settings exposed no enabled-payment-method list.' );
	}
	return enabled.map( ( value, index ) =>
		requiredString( value, `enabled payment method ${ index + 1 }` )
	);
}

async function writeEnabledPaymentMethodIds(
	session: ProviderWriteSession,
	ids: string[],
	description: string
): Promise< void > {
	await readJson(
		await session.performWrite( () =>
			session.adminApi.post( PAYMENTS_SETTINGS_API, {
				data: { enabled_payment_method_ids: ids },
			} )
		),
		description
	);
	// The route answers 200 with the unchanged list when it declines a value,
	// so the cold re-read is the proof rather than the response.
	const echoed = await readEnabledPaymentMethodIds( session.adminApi );
	if ( echoed.toSorted().join( ',' ) !== ids.toSorted().join( ',' ) ) {
		fail(
			`enabled-payment-method write did not take effect; requested ${ ids
				.toSorted()
				.join( ', ' ) } but the store reports ${ echoed
				.toSorted()
				.join( ', ' ) }.`
		);
	}
}

/**
 * Run `callback` with one extra provider method enabled, then restore the
 * recorded set byte-for-byte in its original order and prove the restoration on
 * a cold read.
 *
 * A method that is already enabled is left alone and restored to enabled: the
 * store's own configuration is the baseline, never a value a suite prefers.
 */
export async function withEnabledPaymentMethod< Result >(
	session: ProviderWriteSession,
	method: RedirectMethod,
	capability: string,
	callback: () => Promise< Result >
): Promise< Result > {
	session.requireApprovedProviderFixture( capability );
	const original = await readEnabledPaymentMethodIds( session.adminApi );
	const alreadyEnabled = original.includes( method.id );

	if ( ! alreadyEnabled ) {
		await writeEnabledPaymentMethodIds(
			session,
			[ ...original, method.id ],
			`${ method.id } enable`
		);
	}

	let scenarioError: unknown;
	let result: Result | undefined;
	try {
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}

	// Restoration must never mask the scenario's own failure: a `finally` that
	// throws replaces the original error, which would hide exactly the finding
	// the run exists to produce.
	let restorationError: unknown;
	try {
		if ( ! alreadyEnabled ) {
			await writeEnabledPaymentMethodIds(
				session,
				original,
				`${ method.id } restore`
			);
		}
		const restored = await readEnabledPaymentMethodIds( session.adminApi );
		if ( restored.join( ',' ) !== original.join( ',' ) ) {
			restorationError = new ResourceQuarantineRequiredError(
				`The enabled-payment-method set was not restored: the store reports ${ restored.join(
					', '
				) } against a recorded ${ original.join( ', ' ) }.`,
				'restoration-failed'
			);
		}
	} catch ( error ) {
		restorationError = new ResourceQuarantineRequiredError(
			`Restoring the enabled-payment-method set after ${ method.id } failed.`,
			'restoration-failed',
			error
		);
	}

	if ( scenarioError !== undefined ) {
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}

/* -------------------------------------------------------------------------
 * Enabled-currency snapshot and byte restoration
 * ---------------------------------------------------------------------- */

interface CurrencyRecord {
	code: string;
	name: string;
	rate: number;
	is_default: boolean;
}

export interface StoreCurrencies {
	available: Record< string, CurrencyRecord >;
	enabled: Record< string, CurrencyRecord >;
	default: CurrencyRecord;
}

interface SingleCurrencySettings {
	exchange_rate_type: string;
	manual_rate: unknown;
	price_rounding: unknown;
	price_charm: unknown;
}

interface CurrencySnapshot {
	enabledCodes: string[];
	currencySettings: SingleCurrencySettings;
}

export async function readStoreCurrencies(
	restApi: APIRequestContext
): Promise< StoreCurrencies > {
	return readJson< StoreCurrencies >(
		await restApi.get( `${ MULTI_CURRENCY_API }/currencies` ),
		'Multi-currency state read'
	);
}

async function readSingleCurrencySettings(
	restApi: APIRequestContext,
	code: string
): Promise< SingleCurrencySettings > {
	return readJson< SingleCurrencySettings >(
		await restApi.get( `${ MULTI_CURRENCY_API }/currencies/${ code }` ),
		`${ code } settings read`
	);
}

async function setEnabledCurrencies(
	session: ProviderWriteSession,
	codes: string[],
	description: string
): Promise< void > {
	const updated = await readJson< StoreCurrencies >(
		await session.performWrite( () =>
			session.adminApi.post(
				`${ MULTI_CURRENCY_API }/update-enabled-currencies`,
				{ data: { enabled: codes } }
			)
		),
		description
	);
	// The route answers HTTP 200 with the unchanged list when the payload is
	// not a non-empty array, so assert the returned state rather than trusting
	// a green response.
	const echoed = Object.keys( updated.enabled ?? {} ).toSorted();
	const expectedCodes = [ ...new Set( codes ) ].toSorted();
	if ( echoed.join( ',' ) !== expectedCodes.join( ',' ) ) {
		fail(
			`enabled-currency write did not take effect; requested ${ expectedCodes.join(
				', '
			) } but the store reports ${ echoed.join( ', ' ) }.`
		);
	}
}

/**
 * Read the multi-currency feature flag as the store itself derives it.
 *
 * `WooPaymentsSettingsService` projects this from `'1' === get_option(
 * '_wcpay_feature_customer_multi_currency', '0' )`, so the payload value is a
 * strict boolean and anything else means the settings surface changed shape
 * under us — which must fail rather than be read as "off".
 */
async function readMultiCurrencyEnabled(
	restApi: APIRequestContext
): Promise< boolean > {
	const paymentsSettings = await readJson< Record< string, unknown > >(
		await restApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	const enabled = paymentsSettings.is_multi_currency_enabled;
	if ( typeof enabled !== 'boolean' ) {
		fail(
			`the payments settings surface reported a non-boolean multi-currency flag (${ JSON.stringify(
				enabled
			) }), so its state cannot be recorded or restored.`
		);
	}
	return enabled;
}

/**
 * Run `callback` with one foreign currency enabled at a pinned manual rate of
 * 1.0 and no rounding or charm adjustment, so the converted order total is
 * exactly the catalog price and independent of any provider-fetched rate. Then
 * restore the recorded enabled set and per-currency settings, and prove the
 * restoration — including that the feature flag is untouched — on cold reads,
 * quarantining rather than continuing if any of it did not take.
 *
 * The multi-currency feature flag is deliberately never written. It is a
 * store-wide switch, and its off state is not a value this helper can put back:
 * disabling it discards the derived multi-currency runtime — available set,
 * enabled set and default — so an off-on-off round trip leaves the store
 * emptier than it found it while every value this helper recorded still reads
 * as restored. A run once did exactly that here. So the flag is a precondition
 * instead: a store with multi-currency off fails before the first write, with
 * the repair named, rather than being silently toggled and silently damaged.
 */
export async function withForeignCurrency< Result >(
	session: ProviderWriteSession,
	code: string,
	capability: string,
	callback: () => Promise< Result >
): Promise< Result > {
	session.requireApprovedProviderFixture( capability );
	const restApi = session.adminApi;

	if ( ! ( await readMultiCurrencyEnabled( restApi ) ) ) {
		fail(
			`multi-currency is disabled on this store, and enabling it is not reversible: turning the flag back off discards the available, enabled and default currency runtime it governs. Enable multi-currency on the store first (option _wcpay_feature_customer_multi_currency = "1") and re-run; this helper will not toggle it.`
		);
	}

	const currencies = await readStoreCurrencies( restApi );
	if ( ! Object.keys( currencies.available ?? {} ).includes( code ) ) {
		fail(
			`${ code } is not in this store's available currency catalog, so no ${ code } charge can be created here.`
		);
	}
	const snapshot: CurrencySnapshot = {
		enabledCodes: Object.keys( currencies.enabled ?? {} ),
		currencySettings: await readSingleCurrencySettings( restApi, code ),
	};

	await readJson(
		await session.performWrite( () =>
			restApi.post( `${ MULTI_CURRENCY_API }/currencies/${ code }`, {
				data: {
					exchange_rate_type: 'manual',
					manual_rate: MANUAL_RATE,
					price_rounding: 0,
					price_charm: 0,
				},
			} )
		),
		`${ code } manual-rate pin`
	);
	if ( ! snapshot.enabledCodes.includes( code ) ) {
		await setEnabledCurrencies(
			session,
			[ ...snapshot.enabledCodes, code ],
			`${ code } enable`
		);
	}

	let scenarioError: unknown;
	let result: Result | undefined;
	try {
		result = await callback();
	} catch ( error ) {
		scenarioError = error;
	}

	let restorationError: unknown;
	try {
		if ( snapshot.enabledCodes.includes( code ) ) {
			// The currency stays enabled, so its per-currency options are
			// restored in place rather than deleted by removal.
			await readJson(
				await session.performWrite( () =>
					restApi.post(
						`${ MULTI_CURRENCY_API }/currencies/${ code }`,
						{ data: snapshot.currencySettings }
					)
				),
				`${ code } settings restore`
			);
		}
		await setEnabledCurrencies(
			session,
			snapshot.enabledCodes,
			'Enabled-currencies restore'
		);

		// Cold reads of everything this helper could have moved, plus the flag
		// it deliberately did not. The flag is verified rather than written
		// because a run that turned it off — directly, or as a side effect of
		// some future write on this surface — would leave the whole
		// multi-currency runtime empty for every later spec, and that must
		// quarantine the store rather than pass as "restored".
		const rereadCurrencies = await readStoreCurrencies( restApi );
		const restoredCodes = Object.keys( rereadCurrencies.enabled ?? {} );
		const restoredSettings = await readSingleCurrencySettings(
			restApi,
			code
		);
		const flagStillEnabled = await readMultiCurrencyEnabled( restApi );
		const drift: string[] = [];
		if ( ! flagStillEnabled ) {
			drift.push(
				'the multi-currency feature flag is off, which empties the available, enabled and default currency runtime'
			);
		}
		if ( restoredCodes.join( ',' ) !== snapshot.enabledCodes.join( ',' ) ) {
			drift.push(
				`enabled currencies are ${ restoredCodes.join(
					', '
				) } against a recorded ${ snapshot.enabledCodes.join( ', ' ) }`
			);
		}
		if (
			JSON.stringify( restoredSettings ) !==
			JSON.stringify( snapshot.currencySettings )
		) {
			drift.push( `${ code } per-currency settings differ` );
		}
		if ( drift.length > 0 ) {
			restorationError = new ResourceQuarantineRequiredError(
				`The ${ code } currency configuration was not restored to its recorded state: ${ drift.join(
					'; '
				) }.`,
				'restoration-failed'
			);
		}
	} catch ( error ) {
		restorationError = new ResourceQuarantineRequiredError(
			`Restoring the ${ code } currency configuration failed.`,
			'restoration-failed',
			error
		);
	}

	if ( scenarioError !== undefined ) {
		throw scenarioError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}

/* -------------------------------------------------------------------------
 * Shopper-session currency
 * ---------------------------------------------------------------------- */

export interface ShopperCartState {
	itemsCount: number;
	/** The currency the shopper's own session is quoting in. */
	currency: string;
}

/**
 * What the shopper's session currently holds, read from the Store API cart
 * through the page's own cookie jar rather than from a rendered symbol.
 */
export async function readShopperCartState(
	page: Page
): Promise< ShopperCartState > {
	const cart = await readJson< Record< string, unknown > >(
		await page.request.get( STORE_CART_API ),
		'Store API cart read'
	);
	const totals = requiredObject( cart.totals, 'cart totals' );
	if ( typeof cart.items_count !== 'number' ) {
		fail( 'cart evidence requires an item count.' );
	}

	return {
		itemsCount: cart.items_count,
		currency: requiredString(
			totals.currency_code,
			'cart currency code'
		).toUpperCase(),
	};
}

export async function readShopperSessionCurrency(
	page: Page
): Promise< string > {
	return ( await readShopperCartState( page ) ).currency;
}

/**
 * Switches the shopper's session to `code` through the storefront's own
 * `?currency=` switch and proves the switch took before anything is bought.
 */
export async function setShopperSessionCurrency(
	page: Page,
	code: string
): Promise< void > {
	await page.goto( `shop/?currency=${ encodeURIComponent( code ) }` );
	const observed = await readShopperSessionCurrency( page );
	if ( observed !== code.toUpperCase() ) {
		fail(
			`shopper session currency switch to ${ code } did not take; the cart quotes ${ observed }.`
		);
	}
}

/* -------------------------------------------------------------------------
 * Provider intent evidence
 * ---------------------------------------------------------------------- */

/**
 * What the provider records about the request native sent it.
 *
 * Read while the intent still requires action, which is the only window in
 * which `next_action.redirect_to_url` exists: once the intent succeeds the
 * provider drops it, and with it the only direct account of the return URL
 * native supplied.
 */
export interface RedirectIntentRequest {
	id: string;
	status: string;
	paymentMethodTypes: string[];
	amountMinor: number;
	/** Lower-case, exactly as the provider states it. */
	currency: string;
	nextActionType: string;
	providerRedirectUrl: string;
	returnUrl: string;
	chargeCount: number;
}

function chargeCount( intent: Record< string, unknown > ): number {
	const charges = intent.charges;
	if (
		typeof charges === 'object' &&
		charges !== null &&
		'data' in charges &&
		Array.isArray( ( charges as { data: unknown[] } ).data )
	) {
		return ( charges as { data: unknown[] } ).data.length;
	}
	if ( typeof intent.charge === 'object' && intent.charge !== null ) {
		return 1;
	}
	if ( typeof intent.latest_charge === 'string' && intent.latest_charge ) {
		return 1;
	}
	return 0;
}

export async function readProviderIntent(
	session: ProviderWriteSession,
	intentId: string
): Promise< Record< string, unknown > > {
	return requiredObject(
		await readJson(
			await session.adminApi.get(
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					intentId
				) }`
			),
			`provider intent ${ intentId }`
		),
		'provider intent'
	);
}

/**
 * Reads the intent's account of the request, including the redirect object.
 *
 * `strict` is what separates the two families that call this. The redirect
 * family names the return URL in its fixed contract, so an unreadable
 * `next_action.redirect_to_url` has to fail the case rather than be reported as
 * an empty string; the refund family only wants a source charge and takes the
 * read as best-effort context.
 */
export async function readRedirectIntentRequest(
	session: ProviderWriteSession,
	intentId: string,
	strict: boolean
): Promise< RedirectIntentRequest > {
	const intent = await readProviderIntent( session, intentId );
	const nextAction =
		typeof intent.next_action === 'object' && intent.next_action !== null
			? ( intent.next_action as Record< string, unknown > )
			: {};
	// The provider does not use one next-action shape for every redirect
	// method. `redirect_to_url` is the generic one; a method the provider
	// models explicitly gets its own — Alipay produces
	// `alipay_handle_redirect`, and the `*_handle_redirect` family carries the
	// same `url` and `return_url` pair under its own key. Binding to the
	// generic name alone reads a real, correctly-formed redirect as an
	// unreadable one, and quarantines the account for it.
	const redirectActionKey = Object.keys( nextAction ).find(
		( key ) =>
			( key === 'redirect_to_url' ||
				key.endsWith( '_handle_redirect' ) ) &&
			typeof nextAction[ key ] === 'object' &&
			nextAction[ key ] !== null
	);
	const redirectToUrl = redirectActionKey
		? ( nextAction[ redirectActionKey ] as Record< string, unknown > )
		: {};
	const types = Array.isArray( intent.payment_method_types )
		? intent.payment_method_types.map( ( value, index ) =>
				requiredString( value, `payment method type ${ index + 1 }` )
		  )
		: [];

	if ( strict ) {
		// Still binding: the action has to *be* a redirect, and it has to be
		// the one whose payload was read above. Anything else — a QR code, a
		// card challenge, no action at all — is not this case.
		if (
			! redirectActionKey ||
			String( nextAction.type ) !== redirectActionKey
		) {
			throw quarantine(
				`intent ${ intentId } carries next action ${ String(
					nextAction.type
				) } rather than the provider redirect this case is about, so the request it received cannot be read.`
			);
		}
		if (
			typeof redirectToUrl.url !== 'string' ||
			typeof redirectToUrl.return_url !== 'string'
		) {
			throw quarantine(
				`intent ${ intentId } carries a provider redirect with no readable hosted URL and return URL, so the request it received cannot be read.`
			);
		}
		if ( types.length === 0 ) {
			throw quarantine(
				`intent ${ intentId } names no payment method type, so the method the provider received cannot be read.`
			);
		}
	}

	return {
		id: requiredString( intent.id, 'intent ID' ),
		status: requiredString( intent.status, 'intent status' ),
		paymentMethodTypes: types,
		amountMinor:
			typeof intent.amount === 'number' ? intent.amount : Number.NaN,
		currency:
			typeof intent.currency === 'string'
				? intent.currency.toLowerCase()
				: '',
		nextActionType:
			typeof nextAction.type === 'string' ? nextAction.type : '',
		providerRedirectUrl:
			typeof redirectToUrl.url === 'string' ? redirectToUrl.url : '',
		returnUrl:
			typeof redirectToUrl.return_url === 'string'
				? redirectToUrl.return_url
				: '',
		chargeCount: chargeCount( intent ),
	};
}

/**
 * Waits for the order to carry the intent native created for it.
 *
 * Native writes `_intent_id` as part of the redirect outcome, before the
 * shopper ever reaches the hosted page, so this resolves inside the handoff
 * window rather than after the payment settles.
 */
async function readOrderIntentId(
	session: ProviderWriteSession,
	orderId: number
): Promise< string > {
	const deadline = Date.now() + ORDER_INTENT_TIMEOUT_MS;
	for (;;) {
		const order = ( await readJson< {
			meta_data?: unknown;
		} >(
			await session.adminApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
			`WooCommerce order ${ orderId }`
		) ) as { meta_data?: unknown };
		const meta = Array.isArray( order.meta_data )
			? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
			: [];
		const entry = meta.find( ( item ) => item.key === '_intent_id' );
		if ( typeof entry?.value === 'string' && entry.value.trim() ) {
			return entry.value;
		}
		if ( Date.now() >= deadline ) {
			throw quarantine(
				`order ${ orderId } never carried the PaymentIntent native created for its redirect handoff.`
			);
		}
		await delay( POLL_INTERVAL_MS );
	}
}

/* -------------------------------------------------------------------------
 * Classic redirect checkout
 * ---------------------------------------------------------------------- */

export async function fillClassicBilling(
	page: Page,
	runId: string,
	address: BillingAddress
): Promise< void > {
	const billing = page.locator( '.woocommerce-billing-fields' );
	await expect( billing ).toBeVisible();
	await billing.getByLabel( /^First name/i ).fill( 'E2E' );
	await billing.getByLabel( /^Last name/i ).fill( 'WooPayments' );
	// Select2 enhances country and state into a second labelled control, so the
	// accessible name matches two elements. Address the underlying select by id,
	// as the rest of the Core classic-checkout suite does.
	await billing.locator( '#billing_country' ).selectOption( address.country );
	await billing
		.getByLabel( /^Street address/i )
		.first()
		.fill( address.address );
	await billing.getByLabel( /^(?:Town \/ City|City)/i ).fill( address.city );
	if ( address.state ) {
		await billing.locator( '#billing_state' ).selectOption( address.state );
	}
	await billing
		.getByLabel( /^(?:ZIP Code|Postcode)/i )
		.fill( address.postcode );
	await billing.getByLabel( /^Phone/i ).fill( '5555550100' );
	await billing
		.getByLabel( /^Email address/i )
		.fill( `woopayments-${ runId }@example.com` );
}

/**
 * Chooses one redirect method on the classic checkout and proves the shopper
 * was actually offered it under its own name.
 */
export async function selectClassicRedirectGateway(
	page: Page,
	method: RedirectMethod
): Promise< void > {
	const methodRadio = page.locator(
		`input[name="payment_method"][value="${ method.gatewayId }"]`
	);
	await expect(
		methodRadio,
		`${ method.id } is not offered on this store's checkout, so this case cannot be driven here; the account must actually carry the ${ method.id } capability and the method must be enabled`
	).toHaveCount( 1 );
	await methodRadio.check();
	await expect(
		page.locator( `label[for="payment_method_${ method.gatewayId }"]` )
	).toHaveText( method.label );
}

interface CheckoutExchange {
	requestCount: number;
	responseCount: number;
	status: number;
	body: Record< string, unknown >;
	/** The URL-encoded fields the submission carried. */
	fields: URLSearchParams;
	/** Milliseconds from the single activation to the store's answer. */
	elapsedMs: number;
}

function readSubmittedFields( body: string | null ): URLSearchParams {
	return new URLSearchParams( typeof body === 'string' ? body : '' );
}

/**
 * How a submission carried the fraud-prevention token, without carrying the
 * token itself into any report.
 */
export type SubmittedFraudPreventionToken =
	| { presence: 'absent' | 'empty' }
	| { presence: 'present'; digest: PublicTokenDigest };

function readSubmittedFraudPreventionToken(
	fields: URLSearchParams
): SubmittedFraudPreventionToken {
	const token = fields.get( 'wcpay-fraud-prevention-token' );
	if ( token === null ) {
		return { presence: 'absent' };
	}
	if ( token === '' ) {
		return { presence: 'empty' };
	}
	return {
		presence: 'present',
		digest: {
			length: token.length,
			sha256: createHash( 'sha256' ).update( token ).digest( 'hex' ),
		},
	};
}

function isClassicCheckoutRequest(
	request: Pick< Request, 'method' | 'url' >,
	baseURL: string
): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const requestUrl = new URL( request.url() );
		if ( requestUrl.origin !== new URL( baseURL ).origin ) {
			return false;
		}
		const values = requestUrl.searchParams.getAll( 'wc-ajax' );
		return values.length === 1 && values[ 0 ] === 'checkout';
	} catch {
		return false;
	}
}

function isBlocksCheckoutRequest(
	request: Pick< Request, 'method' | 'url' >,
	baseURL: string
): boolean {
	if ( request.method() !== 'POST' ) {
		return false;
	}
	try {
		const requestUrl = new URL( request.url() );
		return (
			requestUrl.origin === new URL( baseURL ).origin &&
			requestUrl.pathname.replace( /\/+$/, '' ) === BLOCKS_CHECKOUT_PATH
		);
	} catch {
		return false;
	}
}

/**
 * Activates one submission and returns the single checkout exchange it caused.
 *
 * The listener stays attached for the whole interval the caller needs, so a
 * second submission dispatched at any point inside it is still counted rather
 * than passing unseen.
 */
async function observeCheckoutExchange< Result >(
	page: Page,
	baseURL: string,
	matches: ( request: Pick< Request, 'method' | 'url' > ) => boolean,
	activate: () => Promise< void >,
	settle: ( exchange: CheckoutExchange ) => Promise< Result >
): Promise< { exchange: CheckoutExchange; result: Result } > {
	const requests: Request[] = [];
	const responses: Response[] = [];
	/**
	 * Each observed response's body, read at the instant it arrived.
	 *
	 * A redirect method's checkout response is immediately followed by a
	 * navigation to the provider, and once the browser leaves the document
	 * Chromium discards the buffer: `Network.getResponseBody` then answers "No
	 * resource with given identifier found" for a response this driver has
	 * already seen and counted. Reading here rather than after the await keeps
	 * the body and the observation in the same instant. The settled shape is
	 * kept so a genuine parse failure still surfaces its own reason instead of
	 * being flattened into "no body".
	 */
	const bodies = new Map<
		Response,
		Promise< { ok: true; value: unknown } | { ok: false; error: unknown } >
	>();
	let resolveResponse = () => {};
	let rejectResponse: ( error: Error ) => void = () => {};
	const responseSignal = new Promise< void >( ( resolve, reject ) => {
		resolveResponse = resolve;
		rejectResponse = reject;
	} );
	void responseSignal.catch( () => undefined );

	const onRequest = ( request: Request ) => {
		if ( matches( request ) ) {
			requests.push( request );
		}
	};
	const onResponse = ( response: Response ) => {
		if ( matches( response.request() ) ) {
			responses.push( response );
			bodies.set(
				response,
				response.json().then(
					( value: unknown ) => ( { ok: true as const, value } ),
					( error: unknown ) => ( { ok: false as const, error } )
				)
			);
			resolveResponse();
		}
	};
	page.on( 'request', onRequest );
	page.on( 'response', onResponse );
	const responseTimer = setTimeout( () => {
		rejectResponse(
			new Error(
				'WooPayments redirect checkout response observation timed out.'
			)
		);
	}, CHECKOUT_EXCHANGE_TIMEOUT_MS );
	const clearResponseTimer = () => clearTimeout( responseTimer );

	const activatedAt = Date.now();

	try {
		await activate();
		await responseSignal;
		if ( responses.length === 0 ) {
			throw quarantine( 'submission produced no checkout response.' );
		}

		const first = responses[ 0 ];
		const firstBody = await bodies.get( first );
		if ( ! firstBody?.ok ) {
			throw quarantine(
				`checkout response body could not be read (${ String(
					firstBody?.error ?? 'no read was started'
				) }), so the submission has no proven outcome.`
			);
		}
		const exchange: CheckoutExchange = {
			requestCount: requests.length,
			responseCount: responses.length,
			status: first.status(),
			body: requiredObject( firstBody.value, 'checkout response body' ),
			fields: readSubmittedFields( first.request().postData() ),
			elapsedMs: Date.now() - activatedAt,
		};
		const result = await settle( exchange );

		return {
			exchange: {
				...exchange,
				requestCount: requests.length,
				responseCount: responses.length,
			},
			result,
		};
	} finally {
		clearResponseTimer();
		page.off( 'request', onRequest );
		page.off( 'response', onResponse );
	}
}

function readSuccessfulCheckoutOrder( exchange: CheckoutExchange ): {
	orderId: number;
	redirectUrl: string;
} {
	if ( exchange.status < 200 || exchange.status >= 300 ) {
		throw quarantine(
			`checkout response is HTTP ${ exchange.status }, not a success.`
		);
	}
	const { body } = exchange;
	if ( body.result !== 'success' ) {
		throw quarantine(
			`the store answered ${ String(
				body.result
			) }; a redirect handoff cannot start from a rejected submission.`
		);
	}
	if (
		! Number.isSafeInteger( body.order_id ) ||
		Number( body.order_id ) <= 0
	) {
		throw quarantine( 'checkout response carries no exact order ID.' );
	}
	if ( typeof body.redirect !== 'string' || ! body.redirect.trim() ) {
		throw quarantine( 'checkout response carries no redirect.' );
	}

	return { orderId: body.order_id as number, redirectUrl: body.redirect };
}

/**
 * Follows the provider's hosted test page exactly once.
 *
 * This page is the one surface here native does not own. It is activated by its
 * own authorization control, once and only once — a second handoff would be a
 * second provider interaction — and the journey then has to land back on this
 * store. Everything asserted afterwards is read from the store and the
 * provider, never from the hosted page.
 *
 * The control is addressed by its visible text rather than by role: it belongs
 * to the provider's page, whose markup is outside this programme's control and
 * outside the surfaces its accessibility contracts cover.
 */
async function authorizeHostedRedirectOnce(
	session: ProviderWriteSession,
	page: Page
): Promise< string > {
	const authorize = page.getByText( 'Authorize Test Payment' ).first();
	await authorize.waitFor( {
		state: 'visible',
		timeout: HOSTED_PAGE_TIMEOUT_MS,
	} );
	await session.performWrite( () => authorize.click() );
	await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
		timeout: RECEIPT_TIMEOUT_MS,
	} );
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i )
	).toBeVisible();

	return page.url();
}

export interface RedirectHandoffObservation {
	orderId: number;
	/** The URL the store answered the submission with. */
	storeRedirectUrl: string;
	/** The provider's own account of the request, read before any follow. */
	request: RedirectIntentRequest;
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	/** Milliseconds from the single Place order activation to that answer. */
	handoffElapsedMs: number;
	/** Present only when the redirect was followed. */
	landedUrl?: string;
	/** Present only when the redirect was followed. */
	paid?: PaymentEvidence;
	/** The fraud-prevention token the submission carried, if any. */
	submittedFraudPreventionToken: SubmittedFraudPreventionToken;
}

/**
 * Refuses every cross-origin *navigation* for the duration of `callback`, so a
 * case whose contract stops before hosted authorization provably stops there.
 *
 * Only navigations are refused. The checkout still loads the provider's own
 * scripts, because the payment element that produces the handoff is mounted by
 * them, and a run that blocked those would be proving something else.
 */
async function withoutProviderNavigation< Result >(
	page: Page,
	baseURL: string,
	callback: () => Promise< Result >
): Promise< Result > {
	const storeOrigin = new URL( baseURL ).origin;
	const matcher = ( url: URL ) => url.origin !== storeOrigin;
	await page.route( matcher, async ( route ) => {
		const request = route.request();
		if (
			request.isNavigationRequest() &&
			request.frame() === page.mainFrame()
		) {
			await route.abort();
			return;
		}
		await route.continue();
	} );

	try {
		return await callback();
	} finally {
		await page.unroute( matcher );
	}
}

export interface ClassicRedirectCheckoutOptions {
	method: RedirectMethod;
	product: OwnedProduct;
	runId: string;
	checkout: ClassicCheckoutTarget;
	/** The provider-write journal description for the submission interval. */
	journal: string;
	/** Follow the provider's hosted page once, or stop at the handoff. */
	follow: boolean;
	/** Appended to the product and checkout URLs, as the shopper currency. */
	currencyQuery?: string;
	/** Fail when the provider's account of the request cannot be read. */
	requireRequestEvidence?: boolean;
	/**
	 * Runs once the checkout is filled and the method chosen, and before Place
	 * order is activated. The protection-on twins read the shopper's session
	 * token here: the session exists only after the cart does, and the token has
	 * to be recorded before the submission that must carry it.
	 */
	beforeSubmit?: () => Promise< void >;
}

/**
 * Drives one redirect-method purchase on the classic checkout and reports what
 * the provider was asked for, what it answered, and — when the redirect is
 * followed — the settled payment.
 */
export async function driveClassicRedirectCheckout(
	session: ProviderWriteSession,
	page: Page,
	options: ClassicRedirectCheckoutOptions
): Promise< RedirectHandoffObservation > {
	const { method, product, runId, checkout } = options;
	await session.assertCanWrite();
	if ( ! runId || runId !== session.runId ) {
		fail( 'checkout requires the exact active run ID.' );
	}
	if ( checkout.slug !== 'classic-checkout' ) {
		fail( 'checkout requires the marker-bound Classic checkout page.' );
	}

	const currencySuffix = options.currencyQuery
		? `?currency=${ encodeURIComponent( options.currencyQuery ) }`
		: '';
	await page.goto(
		`?post_type=product&p=${ product.id }${
			options.currencyQuery
				? `&currency=${ encodeURIComponent( options.currencyQuery ) }`
				: ''
		}`
	);
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( `${ checkout.path }${ currencySuffix }` );
	await expect( page.locator( CLASSIC_CHECKOUT_FORM ) ).toBeVisible();
	await fillClassicBilling( page, runId, method.billing );
	await selectClassicRedirectGateway( page, method );
	await options.beforeSubmit?.();

	let activationCount = 0;

	const submit = () =>
		session.withProviderSubmissionJournal( options.journal, async () => {
			const observed = await observeCheckoutExchange(
				page,
				session.baseURL,
				( request ) =>
					isClassicCheckoutRequest( request, session.baseURL ),
				async () => {
					activationCount += 1;
					if ( activationCount !== 1 ) {
						throw quarantine(
							'checkout must activate Place order exactly once.'
						);
					}
					await session.performWrite( () =>
						page
							.getByRole( 'button', {
								name: 'Place order',
								exact: true,
							} )
							.click()
					);
				},
				async ( exchange ) => {
					const success = readSuccessfulCheckoutOrder( exchange );
					// Attribute the order before judging anything about it.
					// Whatever the store did, this run caused it, and the run ID
					// is how a later reader tells this order from someone else's.
					await session.setOrderRunId( success.orderId, runId );

					const intentId = await readOrderIntentId(
						session,
						success.orderId
					);
					const request = await readRedirectIntentRequest(
						session,
						intentId,
						options.requireRequestEvidence === true
					);

					if ( ! options.follow ) {
						return { success, request, landedUrl: undefined };
					}

					return {
						success,
						request,
						landedUrl: await authorizeHostedRedirectOnce(
							session,
							page
						),
					};
				}
			);

			const { success, request, landedUrl } = observed.result;

			return {
				orderId: success.orderId,
				storeRedirectUrl: success.redirectUrl,
				request,
				checkoutRequestCount: observed.exchange.requestCount,
				checkoutResponseCount: observed.exchange.responseCount,
				handoffElapsedMs: observed.exchange.elapsedMs,
				landedUrl,
				paid: options.follow
					? await getPaymentEvidence(
							session.adminApi,
							success.orderId
					  )
					: undefined,
				submittedFraudPreventionToken:
					readSubmittedFraudPreventionToken(
						observed.exchange.fields
					),
			};
		} );

	return options.follow
		? submit()
		: withoutProviderNavigation( page, session.baseURL, submit );
}

/**
 * Drive one redirect-method purchase end to end on the classic checkout and
 * hand back the proven payment identity.
 *
 * Kept for callers that only need a settled redirect source charge. The
 * provider's account of the request is still read on the way through — a
 * redirect intent that carries no readable `next_action` is not a failure for
 * this caller — so both families share one drive rather than two.
 */
export async function completeRedirectCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	runId: string,
	method: RedirectMethod,
	currencyQuery: string,
	checkout: ClassicCheckoutTarget,
	journal: string
): Promise< PaymentEvidence > {
	const observation = await driveClassicRedirectCheckout( session, page, {
		method,
		product,
		runId,
		checkout,
		journal,
		follow: true,
		currencyQuery,
		requireRequestEvidence: false,
	} );

	const paid = observation.paid;
	if ( ! paid ) {
		throw quarantine( 'followed checkout produced no payment evidence.' );
	}

	expect( paid.amountMinor ).toBe( method.amountMinor );
	expect( paid.currency ).toBe( method.currency );
	expect( paid.providerStatus ).toBe( 'succeeded' );
	expect( paid.chargeStatus ).toBe( 'succeeded' );
	expect( paid.chargeCaptured ).toBe( true );
	expect( paid.occurrenceCount ).toBe( 1 );
	expect( [ 'processing', 'completed' ] ).toContain( paid.orderStatus );

	return paid;
}

/* -------------------------------------------------------------------------
 * Blocks redirect checkout
 * ---------------------------------------------------------------------- */

export interface BlocksRedirectCheckoutOptions {
	method: RedirectMethod;
	product: OwnedProduct;
	runId: string;
	journal: string;
	follow: boolean;
	requireRequestEvidence?: boolean;
}

async function fillBlocksBilling(
	page: Page,
	runId: string,
	address: BillingAddress
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const group = ( await shipping.isVisible() ) ? shipping : billing;

	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ runId }@example.com` );
	await group
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( address.country );
	await group.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await group
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await group
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( address.address );
	await group
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( address.city );
	if ( address.state ) {
		await group
			.getByRole( 'combobox', { name: 'State', exact: true } )
			.selectOption( address.state );
	}
	await group
		.getByRole( 'textbox', { name: /^(?:ZIP Code|Postcode)/ } )
		.fill( address.postcode );
	await group
		.getByRole( 'textbox', { name: 'Phone (optional)' } )
		.fill( '5555550100' );
}

/**
 * Drives the same proposition through the native Blocks checkout surface.
 *
 * The method being absent from the Blocks payment options fails the case: a
 * conditional skip here would report a green run for a surface nobody drove,
 * which is exactly the residual the Blocks row records.
 */
export async function driveBlocksRedirectCheckout(
	session: ProviderWriteSession,
	page: Page,
	options: BlocksRedirectCheckoutOptions
): Promise< RedirectHandoffObservation > {
	const { method, product, runId } = options;
	await session.assertCanWrite();
	if ( ! runId || runId !== session.runId ) {
		fail( 'checkout requires the exact active run ID.' );
	}

	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksBilling( page, runId, method.billing );

	const option = page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: method.label } );
	await expect(
		option,
		`${ method.id } is not offered on this store's Blocks checkout, so this case cannot be driven here; the method must be enabled and its Blocks payment method registered`
	).toHaveCount( 1 );
	await option.check();

	let activationCount = 0;

	return session.withProviderSubmissionJournal( options.journal, async () => {
		const observed = await observeCheckoutExchange(
			page,
			session.baseURL,
			( request ) => isBlocksCheckoutRequest( request, session.baseURL ),
			async () => {
				activationCount += 1;
				if ( activationCount !== 1 ) {
					throw quarantine(
						'checkout must activate Place order exactly once.'
					);
				}
				await session.performWrite( () =>
					page.getByRole( 'button', { name: /place order/i } ).click()
				);
			},
			async ( exchange ) => {
				if ( exchange.status < 200 || exchange.status >= 300 ) {
					throw quarantine(
						`Blocks checkout response is HTTP ${ exchange.status }, not a success.`
					);
				}
				const orderId = exchange.body.order_id;
				if (
					! Number.isSafeInteger( orderId ) ||
					Number( orderId ) <= 0
				) {
					throw quarantine(
						'Blocks checkout response carries no exact order ID.'
					);
				}
				const paymentResult = requiredObject(
					exchange.body.payment_result,
					'Blocks payment result'
				);
				const redirectUrl = paymentResult.redirect_url;
				if ( typeof redirectUrl !== 'string' || ! redirectUrl.trim() ) {
					throw quarantine(
						'Blocks checkout response carries no redirect.'
					);
				}
				await session.setOrderRunId( Number( orderId ), runId );

				const intentId = await readOrderIntentId(
					session,
					Number( orderId )
				);
				const request = await readRedirectIntentRequest(
					session,
					intentId,
					options.requireRequestEvidence === true
				);
				const success = {
					orderId: Number( orderId ),
					redirectUrl,
				};

				if ( ! options.follow ) {
					return { success, request, landedUrl: undefined };
				}

				return {
					success,
					request,
					landedUrl: await authorizeHostedRedirectOnce(
						session,
						page
					),
				};
			}
		);

		const { success, request, landedUrl } = observed.result;

		return {
			orderId: success.orderId,
			storeRedirectUrl: success.redirectUrl,
			request,
			checkoutRequestCount: observed.exchange.requestCount,
			checkoutResponseCount: observed.exchange.responseCount,
			handoffElapsedMs: observed.exchange.elapsedMs,
			landedUrl,
			paid: options.follow
				? await getPaymentEvidence( session.adminApi, success.orderId )
				: undefined,
			// The Store API carries the token in the payment-method data rather
			// than as a form field, so the classic field reading does not apply.
			submittedFraudPreventionToken: { presence: 'absent' },
		};
	} );
}

/* -------------------------------------------------------------------------
 * Tokenless classic submission, for the protection-on twins
 * ---------------------------------------------------------------------- */

export interface TokenlessRedirectRejection {
	status: number;
	result: unknown;
	messages: string;
	alertCount: number;
	noticeMessages: string[];
	checkoutRequestCount: number;
	checkoutResponseCount: number;
	fraudPreventionToken: SubmittedFraudPreventionToken;
	url: string;
}

function readRejectionMessages( body: Record< string, unknown > ): string {
	return typeof body.messages === 'string' ? body.messages : '';
}

/**
 * Submits one redirect-method checkout with no session token and reports how
 * native turned it away.
 *
 * The token is removed from every place the classic script reads it and the
 * effective value is re-read before anything is clicked: a submission that
 * still carried a valid token would settle a real payment rather than prove an
 * enforcement boundary, so it must not be dispatched at all.
 */
export async function submitTokenlessRedirectCheckout(
	session: ProviderWriteSession,
	page: Page,
	options: {
		method: RedirectMethod;
		product: OwnedProduct;
		runId: string;
		checkout: ClassicCheckoutTarget;
		journal: string;
		currencyQuery?: string;
	}
): Promise< TokenlessRedirectRejection > {
	const { method, product, runId, checkout } = options;
	await session.assertCanWrite();
	// The token helpers belong to the classic card driver, which reads and
	// clears the token through the same fallback chain the classic script uses.
	// Redirect methods submit that field from the same script, so reproducing
	// the chain here would only invite it to drift.
	const tokenReader = new PlaywrightClassicCardCheckoutBrowser(
		page,
		session.baseURL,
		checkout.pageId
	);

	const currencySuffix = options.currencyQuery
		? `?currency=${ encodeURIComponent( options.currencyQuery ) }`
		: '';
	await page.goto(
		`?post_type=product&p=${ product.id }${
			options.currencyQuery
				? `&currency=${ encodeURIComponent( options.currencyQuery ) }`
				: ''
		}`
	);
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( `${ checkout.path }${ currencySuffix }` );
	await expect( page.locator( CLASSIC_CHECKOUT_FORM ) ).toBeVisible();
	await fillClassicBilling( page, runId, method.billing );
	await selectClassicRedirectGateway( page, method );

	await tokenReader.clearFraudPreventionToken();
	if (
		( await tokenReader.captureEffectiveFraudPreventionTokenDigest() )
			.present
	) {
		// Nothing has been dispatched, so no provider resource is at stake.
		fail(
			'tokenless submission was not dispatched because the page still exposes a fraud-prevention token.'
		);
	}

	let activationCount = 0;

	return session.withProviderSubmissionJournal( options.journal, async () => {
		const observed = await observeCheckoutExchange(
			page,
			session.baseURL,
			( request ) => isClassicCheckoutRequest( request, session.baseURL ),
			async () => {
				activationCount += 1;
				if ( activationCount !== 1 ) {
					throw quarantine(
						'tokenless submission must activate Place order exactly once.'
					);
				}
				await session.performWrite( () =>
					page
						.getByRole( 'button', {
							name: 'Place order',
							exact: true,
						} )
						.click()
				);
			},
			async () => {
				const alert = page.locator(
					`${ CLASSIC_NOTICE_GROUP } [role="alert"]`
				);
				try {
					await alert.waitFor( {
						state: 'visible',
						timeout: REJECTION_NOTICE_TIMEOUT_MS,
					} );
				} catch ( error ) {
					throw quarantine(
						'tokenless submission saw no rejection notice, so whether the submission was refused is unknown.',
						error
					);
				}

				// Core prints checkout errors through one of two templates:
				// `notices/error.php` is a `<ul role="alert">` of messages and
				// `block-notices/error.php` — what block themes get — is a
				// banner carrying a single message as its own content.
				const items = alert.first().getByRole( 'listitem' );
				const messages =
					( await items.count() ) > 0
						? await items.allInnerTexts()
						: [ await alert.first().innerText() ];

				return {
					alertCount: await alert.count(),
					noticeMessages: messages.map( ( message ) =>
						message.replace( /\s+/g, ' ' ).trim()
					),
				};
			}
		);

		return {
			status: observed.exchange.status,
			result: observed.exchange.body.result,
			messages: readRejectionMessages( observed.exchange.body ),
			alertCount: observed.result.alertCount,
			noticeMessages: observed.result.noticeMessages,
			checkoutRequestCount: observed.exchange.requestCount,
			checkoutResponseCount: observed.exchange.responseCount,
			fraudPreventionToken: readSubmittedFraudPreventionToken(
				observed.exchange.fields
			),
			url: page.url(),
		};
	} );
}

/* -------------------------------------------------------------------------
 * Return-URL evidence
 * ---------------------------------------------------------------------- */

export interface ReturnUrlFacts {
	origin: string;
	orderId: number;
	orderKey: string;
	paymentMethod: string;
	noncePresent: boolean;
}

/**
 * Reads the run-identifying half of a return URL.
 *
 * The nonce the URL also carries is a credential for this one payment: its
 * presence is reported, its value never leaves this function.
 */
export function readReturnUrlFacts( value: string ): ReturnUrlFacts {
	let parsed: URL;
	try {
		parsed = new URL( value );
	} catch {
		fail( `return URL ${ value } is not a URL.` );
	}
	const match = parsed.pathname.match( ORDER_RECEIVED_PATH );
	if ( ! match ) {
		fail( 'return URL does not name an order-received page.' );
	}

	return {
		origin: parsed.origin,
		orderId: Number( match[ 1 ] ),
		orderKey: parsed.searchParams.get( 'key' ) ?? '',
		paymentMethod: parsed.searchParams.get( 'wc_payment_method' ) ?? '',
		noncePresent: ( parsed.searchParams.get( '_wpnonce' ) ?? '' ) !== '',
	};
}
