import type {
	APIRequestContext,
	APIResponse,
	Browser,
	BrowserContext,
	Locator,
	Page,
} from '@playwright/test';

import {
	expect,
	ResourceQuarantineRequiredError,
	tags,
	test,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { completeCardCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import {
	readHighestOrderId,
	readOrderDeltaAfter,
	readProviderCustomerId,
	readProviderCustomerPaymentMethodIds,
} from '../../../utils/woopayments-native/drivers/classic-card-authentication';
import { readProviderCardEvidence } from '../../../utils/woopayments-native/provider-card-evidence';
import { waitForPaymentState } from '../../../utils/woopayments-native/provider-evidence';
import {
	getOrderPaymentEvidence,
	getPaymentEvidence,
	type PaymentEvidence,
} from '../../../utils/woopayments-native/record-evidence';

/**
 * The `multi-currency-settlement` provider-fidelity family, as fixed in
 * `tests/woopayments-native/FIDELITY-CLAIMS.md`.
 *
 * The claim these three cases exist to establish: each listed order carries
 * exactly one provider amount and currency graph; the converted money metadata
 * WooCommerce stores equals the authoritative provider balance transaction
 * rather than a locally recomputed figure; and a later shopper-currency change
 * leaves the stored order and its provider graph unchanged.
 *
 * What each case adds over the client suite rows it discharges is where the
 * money is read. Those originals asserted a rendered price, a currency symbol,
 * or a string equality on a page - all of which a store can satisfy while the
 * provider charged a different amount in a different currency, or while the
 * stored conversion metadata was recomputed locally from the order total. Every
 * money assertion below names an exact order, an exact PaymentIntent, an exact
 * charge and an exact balance transaction, and compares minor units and ISO
 * codes, not glyphs.
 *
 * FORCED PREMISE - `M2`/`M3` only.
 *
 * The standing store's available-currency catalog is two codes wide, USD and
 * EUR, because `MultiCurrencyStateBuilder::build()` composes it from the store
 * default plus the provider rate cache (empty here) plus already-enabled
 * currencies carrying a manual rate. EUR is therefore already available and
 * already enabled, so `utils/woopayments-native/multi-currency-catalog.ts` is
 * deliberately NOT used by this family: its precondition guard refuses a code
 * the store already offers, and this family needs no code outside {USD, EUR}.
 * What `M2`/`M3` do supply as a premise is narrower - EUR's exchange rate. Each
 * runs inside `withPinnedShopperCurrency()`, which snapshots the store's EUR
 * per-currency options, pins a manual rate of 1.0 with no rounding or charm,
 * and restores the recorded bytes with a verified cold read. The boundary that
 * follows is the same one the catalog driver records for its own forcing: these
 * cases prove what native settles GIVEN a EUR rate, not that the provider
 * serves one - the run supplies that half itself. Nothing here depends on the
 * provider's FX for the *order* amount; the provider's own FX is what the
 * settlement assertions read, and pinning the local rate to 1.0 is exactly what
 * makes those two rates separable (see `M2`).
 *
 * That pinned 1.0 is also why `shopper/multi-currency.spec.ts` is untouched.
 * That spec is frozen at EUR manual rate 0.80 and its EUR 8.00 conversion; no
 * assertion here reuses that rate as an oracle, no case removes EUR (removal
 * deletes its per-currency options and takes it out of the catalog
 * irrecoverably through the REST surface), and the pinned rate is restored to
 * whatever the store recorded before the case ran. The same shape and the same
 * EUR 12.34 fixture are already established by the sibling `refund-settlement`
 * suite's `R3`.
 *
 * Cost note. One full pass creates two run-owned products, two orders, two
 * PaymentIntents, two captured charges and two balance transactions - one USD
 * 10.99 graph and one EUR 12.34 graph. `M3` buys nothing new: it re-reads
 * `M2`'s graph after a shopper-currency change, which is the point of the case.
 * Nothing is retried; `--retries=0` is mandatory.
 *
 * Surface and scope boundary. Every purchase here is a guest checkout on the
 * native Blocks checkout with the `4242` basic card, so no shopper account
 * currency preference (`wcpay_currency` user meta) is ever written and no
 * reusable payment method is created. This family deliberately excludes
 * settings and onboarding, switcher and UI formatting, payment-method
 * visibility and eligibility, transaction-page navigation and link
 * compatibility, historical migration and cutover, and refunds - the last of
 * which the sibling `refund-settlement` family owns. The My Account
 * order-history rendering row is deliberately not claimed: the partition marks
 * it not-dischargeable, so `M3` proves order-received and merchant order
 * administration only.
 */

/** Grep tag that selects this family as a unit. */
const FAMILY_TAG = '@fidelity:multi-currency-settlement';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	FAMILY_TAG,
];

/** The journey capability every case in this family needs. */
const CAPABILITY_FAMILY = 'multi-currency-settlement';
/** The capability for mutating the enabled-currency configuration. */
const CAPABILITY_CURRENCY = 'multi-currency-settlement-currency';
/** The capabilities the shared card-checkout driver itself preflights. */
const CAPABILITY_PRODUCT = 'product/payment';
const CAPABILITY_CARD = 'basic-card';
const CAPABILITY_CARD_ENTRY = 'basic-card-entry';

const M1_CAPABILITIES = [
	CAPABILITY_FAMILY,
	CAPABILITY_PRODUCT,
	CAPABILITY_CARD,
	CAPABILITY_CARD_ENTRY,
];
const M2_CAPABILITIES = [ ...M1_CAPABILITIES, CAPABILITY_CURRENCY ];
const M3_CAPABILITIES = [ CAPABILITY_FAMILY, CAPABILITY_CURRENCY ];

const CONTRACT_M1_USD =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:51::Multi-currency checkout › Checkout with multiple currencies › checkout with USD';
const CONTRACT_M2_EUR =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:51::Multi-currency checkout › Checkout with multiple currencies › checkout with EUR';
const CONTRACT_M2_TRANSACTION =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-multi-currency.spec.ts:88::Admin Multi-Currency Orders › transaction page shows converted merchant currency';
const CONTRACT_M3_ADMIN =
	'default::chromium::tests/e2e/specs/wcpay/merchant/merchant-orders-multi-currency.spec.ts:54::Admin Multi-Currency Orders › order should display in shopper currency';
const CONTRACT_M3_RECEIPT =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-multi-currency-widget.spec.ts:84::Shopper Multi-Currency widget › Should not affect prices › at the order received page';

const MULTI_CURRENCY_API = '/wp-json/wc/v3/payments/multi-currency';
const PAYMENTS_SETTINGS_API = '/wp-json/wc/v3/payments/settings';
const STORE_CART_API = '/wp-json/wc/store/v1/cart';
const DECIMAL_SEPARATOR_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_decimal_sep';
const NUM_DECIMALS_API =
	'/wp-json/wc/v3/settings/general/woocommerce_price_num_decimals';

/** `M1`: the store's own settlement currency, at the contract's USD amount. */
const USD_PRICE = '10.99';
const USD_MINOR = 1099;

/**
 * `M2`/`M3`: EUR 12.34, priced through a pinned manual rate of 1.0 so the
 * converted order total is exactly the catalog price and independent of any
 * provider-fetched rate. That identity rate is also the sharpest falsifier this
 * family has: with the store's own conversion rate pinned at 1, a settlement
 * exchange rate that native recomputed locally could only be 1, so a stored
 * settlement rate that differs from 1 and equals the provider's own is proof
 * the figure came from the balance transaction.
 */
const EUR_PRICE = '12.34';
const EUR_MINOR = 1234;
const EUR_MANUAL_RATE = 1;

const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];

/** The claim's convergence rule: poll every 2 seconds. */
const POLL_INTERVAL_MS = 2_000;
/** The claim's convergence budget. */
const SETTLEMENT_BUDGET_MS = 60_000;

/**
 * The order metadata this family reads. Every key is written by
 * `WooPaymentsOrderEffects` or `MultiCurrencyPriceProjectionService`, and the
 * three multi-currency keys are absent by design on a default-currency order.
 */
const WATCHED_META_KEYS = [
	'_wcpay_intent_currency',
	'_wcpay_payment_transaction_id',
	'_wcpay_transaction_fee',
	'_wcpay_net',
	'_wcpay_multi_currency_order_exchange_rate',
	'_wcpay_multi_currency_order_default_currency',
	'_wcpay_multi_currency_stripe_exchange_rate',
] as const;

const META_ORDER_RATE = '_wcpay_multi_currency_order_exchange_rate';
const META_ORDER_DEFAULT_CURRENCY =
	'_wcpay_multi_currency_order_default_currency';
const META_SETTLEMENT_RATE = '_wcpay_multi_currency_stripe_exchange_rate';
const META_INTENT_CURRENCY = '_wcpay_intent_currency';
const META_BALANCE_TRANSACTION = '_wcpay_payment_transaction_id';
const META_FEE = '_wcpay_transaction_fee';
const META_NET = '_wcpay_net';

/**
 * The two admissible renderings of the EUR order total in wp-admin. The order
 * screen formats with `wc_price( $total, array( 'currency' => 'EUR' ) )`, which
 * takes the symbol from EUR but the position and separators from the store, so
 * a USD-defaulted store prints `€12.34` while a store that also carries EUR's
 * own locale prints `12,34 €`. Both name the same money; the digits are pinned
 * either way and no dollar rendering can match.
 */
const EUR_ADMIN_TOTAL = /^(?:€\s?12\.34|12,34\s?€)$/;

/* -------------------------------------------------------------------------
 * Shared readers
 * ---------------------------------------------------------------------- */

function fail( message: string ): never {
	throw new Error( `Multi-currency settlement evidence ${ message }` );
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

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

function requiredString( value: unknown, label: string ): string {
	if ( typeof value !== 'string' || ! value.trim() ) {
		fail( `requires a ${ label }.` );
	}
	return value;
}

function requiredInteger( value: unknown, label: string ): number {
	if ( ! Number.isSafeInteger( value ) ) {
		fail( `requires an integer ${ label }.` );
	}
	return value as number;
}

function requiredNumericText( value: string, label: string ): number {
	const parsed = Number( value );
	if ( ! Number.isFinite( parsed ) ) {
		fail( `requires a numeric ${ label }; received "${ value }".` );
	}
	return parsed;
}

function plainObject(
	value: unknown,
	label: string
): Record< string, unknown > {
	if (
		typeof value !== 'object' ||
		value === null ||
		Array.isArray( value )
	) {
		fail( `requires ${ label } to be one object.` );
	}
	return value as Record< string, unknown >;
}

/**
 * Asserts a whole capability set before any provider interval opens, so an
 * incomplete approval costs nothing rather than a paid run.
 */
function requireCapabilities(
	session: ProviderWriteSession,
	capabilities: readonly string[]
): void {
	for ( const capability of capabilities ) {
		session.requireApprovedProviderFixture( capability );
	}
}

/**
 * Assert the store formats money the way this suite's amounts assume. Every
 * expected amount here is a two-decimal figure compared as minor units against
 * the provider, and the wp-admin rendering assertion spells one of them out; a
 * store configured differently must fail here rather than shift the contract.
 */
async function assertMoneyFormatAssumptions(
	restApi: APIRequestContext
): Promise< void > {
	const decimalSeparator = await readJson< { value?: unknown } >(
		await restApi.get( DECIMAL_SEPARATOR_API ),
		'Price decimal separator read'
	);
	expect(
		decimalSeparator.value,
		'this family states two-decimal amounts with a dot separator'
	).toBe( '.' );
	const numDecimals = await readJson< { value?: unknown } >(
		await restApi.get( NUM_DECIMALS_API ),
		'Price decimals read'
	);
	expect(
		String( numDecimals.value ),
		'this family states its amounts at two decimal places'
	).toBe( '2' );
}

/* -------------------------------------------------------------------------
 * Multi-currency configuration: snapshot, pinned rate, byte restoration
 * ---------------------------------------------------------------------- */

interface CurrencyRecord {
	code: string;
	name: string;
	rate: number;
	is_default: boolean;
}

interface StoreCurrencies {
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
	multiCurrencyEnabled: boolean;
	currencySettings: SingleCurrencySettings;
}

async function readStoreCurrencies(
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

/**
 * The settlement premise this whole family is stated against: a USD store
 * currency *and* a USD account default.
 *
 * Both halves matter and neither implies the other.
 * `WooPaymentsOrderDataService::get_settlement_exchange_rate_order_meta()`
 * writes settlement metadata only when the store currency equals the account
 * default and the order currency does not, so an account settling something
 * other than USD would silently produce a converted order with no settlement
 * rate to compare - a green run that proved nothing. That must fail loudly,
 * before any provider object exists.
 */
async function assertUsdSettlementPremise(
	restApi: APIRequestContext
): Promise< void > {
	const currencies = await readStoreCurrencies( restApi );
	expect(
		currencies.default?.code,
		'this family is stated against a USD store currency'
	).toBe( 'USD' );

	const account = await readJson< {
		store_currencies?: { default?: unknown };
	} >(
		await restApi.get( '/wp-json/wc/v3/payments/accounts' ),
		'WooPayments account read'
	);
	expect(
		requiredString(
			account.store_currencies?.default,
			'account default currency'
		).toUpperCase(),
		'the connected account must settle in the store currency, or native writes no settlement metadata to compare'
	).toBe( 'USD' );
}

/**
 * A shopper-selected currency context only exists where multi-currency is
 * running and offers something to select. `M1`'s row is about a *selected* USD
 * context being honored through payment, so a store with the runtime off, or
 * with USD as the only enabled currency, cannot establish it - and must say so
 * rather than pass on a store where the selection was never possible.
 */
async function assertSelectableCurrencyContext(
	restApi: APIRequestContext
): Promise< void > {
	const paymentsSettings = await readJson< Record< string, unknown > >(
		await restApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	expect(
		paymentsSettings.is_multi_currency_enabled,
		'a shopper-selected currency context requires the multi-currency runtime to be on'
	).toBe( true );
	const enabledCodes = Object.keys(
		( await readStoreCurrencies( restApi ) ).enabled ?? {}
	);
	expect( enabledCodes ).toContain( 'USD' );
	expect(
		enabledCodes.length,
		'a shopper-selected currency context requires more than one enabled currency to choose between'
	).toBeGreaterThan( 1 );
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
 * Run `callback` with `code` enabled at a pinned manual rate of 1.0 and no
 * rounding or charm adjustment, then restore the recorded enabled set,
 * per-currency options and multi-currency feature flag and prove the
 * restoration on cold reads.
 *
 * Restoration runs on the failure path too and never replaces the scenario's
 * own error: a `finally` that throws would hide exactly the finding the run
 * exists to produce. It is unconditional rather than fail-closed because the
 * currency this family pins is EUR, which a frozen readonly spec depends on -
 * residue here would not be diagnostic, it would be contamination.
 */
async function withPinnedShopperCurrency< Result >(
	session: ProviderWriteSession,
	code: string,
	callback: () => Promise< Result >
): Promise< Result > {
	session.requireApprovedProviderFixture( CAPABILITY_CURRENCY );
	const restApi = session.adminApi;

	const paymentsSettings = await readJson< Record< string, unknown > >(
		await restApi.get( PAYMENTS_SETTINGS_API ),
		'Payments settings read'
	);
	const multiCurrencyEnabled =
		paymentsSettings.is_multi_currency_enabled === true;
	if ( ! multiCurrencyEnabled ) {
		await readJson(
			await session.performWrite( () =>
				restApi.post( PAYMENTS_SETTINGS_API, {
					data: { is_multi_currency_enabled: true },
				} )
			),
			'Multi-currency enable'
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
		multiCurrencyEnabled,
		currencySettings: await readSingleCurrencySettings( restApi, code ),
	};

	await readJson(
		await session.performWrite( () =>
			restApi.post( `${ MULTI_CURRENCY_API }/currencies/${ code }`, {
				data: {
					exchange_rate_type: 'manual',
					manual_rate: EUR_MANUAL_RATE,
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
		if ( ! multiCurrencyEnabled ) {
			await readJson(
				await session.performWrite( () =>
					restApi.post( PAYMENTS_SETTINGS_API, {
						data: { is_multi_currency_enabled: false },
					} )
				),
				'Multi-currency restore'
			);
		}
		// Cold reads: the restore must hold on fresh requests, not only in the
		// mutating calls' own responses.
		const restoredCodes = Object.keys(
			( await readStoreCurrencies( restApi ) ).enabled ?? {}
		);
		const restoredSettings = await readSingleCurrencySettings(
			restApi,
			code
		);
		const restoredPaymentsSettings = await readJson<
			Record< string, unknown >
		>(
			await restApi.get( PAYMENTS_SETTINGS_API ),
			'Payments settings restoration re-read'
		);
		if (
			restoredCodes.join( ',' ) !== snapshot.enabledCodes.join( ',' ) ||
			JSON.stringify( restoredSettings ) !==
				JSON.stringify( snapshot.currencySettings ) ||
			restoredPaymentsSettings.is_multi_currency_enabled !==
				multiCurrencyEnabled
		) {
			restorationError = new ResourceQuarantineRequiredError(
				`The ${ code } currency configuration was not restored to its recorded state.`,
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
 * Shopper-currency selection
 * ---------------------------------------------------------------------- */

/**
 * The shopper's active currency, read from the store rather than from rendered
 * prices. The Store API cart is the authoritative projection of the selected
 * currency for the session that asks - native prepares that state on every
 * `/wc/store/` dispatch - and unlike a storefront price it needs no product and
 * no locale assumption.
 */
async function readShopperCurrency( page: Page ): Promise< string > {
	const cart = await readJson< { totals?: { currency_code?: unknown } } >(
		await page.request.get( STORE_CART_API ),
		'Store API cart read'
	);
	return requiredString(
		cart.totals?.currency_code,
		'shopper cart currency code'
	).toUpperCase();
}

async function readShopperCartItemCount( page: Page ): Promise< number > {
	const cart = await readJson< { items_count?: unknown } >(
		await page.request.get( STORE_CART_API ),
		'Store API cart read'
	);
	return requiredInteger( cart.items_count, 'cart item count' );
}

/**
 * Select a shopper currency the way the storefront itself selects it, and prove
 * the selection took before anything is bought.
 */
async function selectShopperCurrency(
	page: Page,
	code: string
): Promise< void > {
	await page.goto( `shop/?currency=${ code }` );
	expect(
		await readShopperCurrency( page ),
		`the shopper currency must be ${ code } before the purchase; another code means the selection never took`
	).toBe( code );
}

/* -------------------------------------------------------------------------
 * The money graph: order record, provider objects, balance transaction
 * ---------------------------------------------------------------------- */

interface OrderRecord {
	id: number;
	status: string;
	total: string;
	currency: string;
	customerId: number;
	orderKey: string;
	meta: Record< string, string >;
}

interface ProviderBalanceTransaction {
	id: string;
	currency: string;
	amountMinor: number;
	feeMinor: number;
	netMinor: number;
	exchangeRate: number | null;
}

/**
 * Where the fee and net WooCommerce stored came from in the provider's own
 * response, following the precedence `WooPaymentsOrderEffects` applies: the
 * expanded fee breakdown when the platform sends one, otherwise the charge's
 * application fee in the charge's own currency.
 */
interface ChargeFeeSource {
	origin: 'fee-breakdown' | 'application-fee';
	currency: string;
	feeMinor: number;
	netMinor: number;
}

/**
 * The settlement side of one charge.
 *
 * `balanceTransactionId` is always readable: every provider response names the
 * balance transaction, expanded or not, and native writes exactly that
 * identifier to `_wcpay_payment_transaction_id`. The expanded record and the
 * fee source are optional here because the fixed contract only requires them
 * for the converted case - `M1`'s contract names no settlement field, and
 * demanding an expansion it does not need would turn a provider response shape
 * into an `M1` failure. `M2` and `M3` require the expansion explicitly.
 */
interface SettlementEvidence {
	balanceTransactionId: string;
	balanceTransaction: ProviderBalanceTransaction | null;
	feeSource: ChargeFeeSource | null;
	presentmentFee: ChargeFeeSource | null;
}

interface MoneyGraph {
	payment: PaymentEvidence;
	order: OrderRecord;
	settlement: SettlementEvidence;
}

/**
 * The presentment-currency fee, read straight off the charge.
 *
 * Deliberately ignores `fee_breakdown_v1`. This is the figure that actually
 * reaches order meta on a converted charge: the platform builds its envelope
 * from `charge.balance_transaction`, and a forwarded event carries that as a
 * bare identifier rather than an expanded record, so every fallback lands on
 * the charge's own currency and application fee. The WooPayments client and
 * native derive it identically. See the `M2` fee-and-net note below and
 * TRAPLAT-4144.
 */
function readPresentmentFeeFrom(
	charge: Record< string, unknown >
): ChargeFeeSource | null {
	if (
		typeof charge.application_fee_amount !== 'number' ||
		typeof charge.amount !== 'number'
	) {
		return null;
	}
	const currency = requiredString(
		charge.currency,
		'charge currency'
	).toUpperCase();
	const feeMinor = requiredInteger(
		charge.application_fee_amount,
		'charge application fee amount'
	);
	const amountMinor = requiredInteger( charge.amount, 'charge amount' );

	return {
		origin: 'application-fee',
		currency,
		feeMinor,
		netMinor: amountMinor - feeMinor,
	};
}

/**
 * The expanded settlement record `M2` and `M3` reconcile against, or a loud
 * failure naming the missing oracle. Without amount, fee, net, currency and
 * rate there is nothing to compare the stored settlement metadata to; that is
 * not a weaker oracle, it is no oracle, and it must not be downgraded to a
 * green run.
 */
function requireExpandedSettlement( graph: MoneyGraph ): {
	balanceTransaction: ProviderBalanceTransaction;
	feeSource: ChargeFeeSource;
	presentmentFee: ChargeFeeSource;
} {
	const { balanceTransaction, feeSource, presentmentFee } = graph.settlement;
	if ( ! balanceTransaction ) {
		fail(
			`requires the platform to expand balance transaction ${ graph.settlement.balanceTransactionId }; it returned only the identifier.`
		);
	}
	if ( ! feeSource ) {
		fail(
			`requires the platform to report a fee breakdown or an application fee on charge ${ graph.payment.chargeId }.`
		);
	}
	if ( ! presentmentFee ) {
		fail(
			`requires the platform to report an application fee on charge ${ graph.payment.chargeId }; without it the figure the order stores has no independent source.`
		);
	}
	return { balanceTransaction, feeSource, presentmentFee };
}

async function readOrderRecord(
	restApi: APIRequestContext,
	orderId: number
): Promise< OrderRecord > {
	const order = await readJson< Record< string, unknown > >(
		await restApi.get( `/wp-json/wc/v3/orders/${ orderId }` ),
		`Order ${ orderId } read`
	);
	if ( order.id !== orderId ) {
		fail(
			`order identity mismatch: expected ${ orderId }, received ${ String(
				order.id
			) }.`
		);
	}
	const metaData = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	const meta: Record< string, string > = {};
	for ( const entry of metaData ) {
		if (
			typeof entry.key === 'string' &&
			( WATCHED_META_KEYS as readonly string[] ).includes( entry.key )
		) {
			meta[ entry.key ] = String( entry.value ?? '' );
		}
	}

	return {
		id: orderId,
		status: requiredString( order.status, 'order status' ),
		total: requiredString( order.total, 'order total' ),
		currency: requiredString(
			order.currency,
			'order currency'
		).toUpperCase(),
		customerId: requiredInteger( order.customer_id, 'order customer ID' ),
		orderKey: requiredString( order.order_key, 'order key' ),
		meta,
	};
}

function relatedObjectId( value: unknown ): string {
	if (
		typeof value === 'object' &&
		value !== null &&
		! Array.isArray( value ) &&
		'id' in value
	) {
		const id = ( value as { id?: unknown } ).id;
		return typeof id === 'string' ? id : '';
	}
	return typeof value === 'string' ? value : '';
}

function readBalanceTransactionFrom(
	charge: Record< string, unknown >
): ProviderBalanceTransaction | null {
	const raw = charge.balance_transaction;
	if ( typeof raw !== 'object' || raw === null || Array.isArray( raw ) ) {
		return null;
	}
	const transaction = raw as Record< string, unknown >;
	const exchangeRate = transaction.exchange_rate;

	return {
		id: requiredString( transaction.id, 'balance transaction ID' ),
		currency: requiredString(
			transaction.currency,
			'balance transaction currency'
		).toUpperCase(),
		amountMinor: requiredInteger(
			transaction.amount,
			'balance transaction amount'
		),
		feeMinor: requiredInteger( transaction.fee, 'balance transaction fee' ),
		netMinor: requiredInteger( transaction.net, 'balance transaction net' ),
		exchangeRate:
			typeof exchangeRate === 'number' && Number.isFinite( exchangeRate )
				? exchangeRate
				: null,
	};
}

function readFeeSourceFrom(
	charge: Record< string, unknown >
): ChargeFeeSource | null {
	const breakdown = charge.fee_breakdown_v1;
	if ( typeof breakdown === 'object' && breakdown !== null ) {
		const totals = ( breakdown as Record< string, unknown > ).totals;
		if ( typeof totals === 'object' && totals !== null ) {
			const totalsRecord = totals as Record< string, unknown >;
			const feeTotal = totalsRecord.fee;
			const netTotal = totalsRecord.net;
			if (
				typeof feeTotal === 'object' &&
				feeTotal !== null &&
				typeof netTotal === 'object' &&
				netTotal !== null
			) {
				const fee = feeTotal as Record< string, unknown >;
				const net = netTotal as Record< string, unknown >;
				return {
					origin: 'fee-breakdown',
					currency: requiredString(
						fee.currency,
						'fee breakdown currency'
					).toUpperCase(),
					feeMinor: requiredInteger(
						fee.amount,
						'fee breakdown fee amount'
					),
					netMinor: requiredInteger(
						net.amount,
						'fee breakdown net amount'
					),
				};
			}
		}
	}

	return readPresentmentFeeFrom( charge );
}

/**
 * Read the settlement side of one charge from both surfaces that can report it.
 *
 * The PaymentIntent's own charge is preferred as the expanded record because it
 * is the object native itself read when it wrote the settlement metadata, so
 * the comparison is against the provider data that produced the meta rather
 * than a second rendering of it. The merchant charge route - the surface a
 * merchant's transaction view reads - must name the same balance transaction,
 * so neither surface can be the only witness, and it supplies the expansion if
 * it is the one that carries it.
 */
async function readSettlementEvidence(
	restApi: APIRequestContext,
	payment: Pick< PaymentEvidence, 'chargeId' | 'intentId' >
): Promise< SettlementEvidence > {
	const charge = plainObject(
		await readJson< unknown >(
			await restApi.get(
				`/wp-json/wc/v3/payments/charges/${ encodeURIComponent(
					payment.chargeId
				) }`
			),
			`Provider charge ${ payment.chargeId } read`
		),
		'the provider charge'
	);
	if ( charge.id !== payment.chargeId ) {
		fail(
			`charge identity mismatch: expected ${
				payment.chargeId
			}, received ${ String( charge.id ) }.`
		);
	}

	const intent = plainObject(
		await readJson< unknown >(
			await restApi.get(
				`/wp-json/wc/v3/payments/payment_intents/${ encodeURIComponent(
					payment.intentId
				) }`
			),
			`Provider payment intent ${ payment.intentId } read`
		),
		'the provider payment intent'
	);
	const charges = plainObject(
		intent.charges,
		"the intent's charge collection"
	);
	const chargeList = Array.isArray( charges.data ) ? charges.data : [];
	if ( chargeList.length !== 1 ) {
		fail(
			`requires exactly one charge on intent ${ payment.intentId }; found ${ chargeList.length }.`
		);
	}
	const intentCharge = plainObject( chargeList[ 0 ], "the intent's charge" );

	const chargeSurfaceId = relatedObjectId( charge.balance_transaction );
	const intentSurfaceId = relatedObjectId( intentCharge.balance_transaction );
	if ( chargeSurfaceId !== intentSurfaceId ) {
		fail(
			`balance transaction mismatch between the intent and charge surfaces for ${ payment.chargeId }.`
		);
	}

	return {
		balanceTransactionId: requiredString(
			chargeSurfaceId,
			'balance transaction identifier on the proved charge'
		),
		balanceTransaction:
			readBalanceTransactionFrom( intentCharge ) ??
			readBalanceTransactionFrom( charge ),
		feeSource:
			readFeeSourceFrom( charge ) ?? readFeeSourceFrom( intentCharge ),
		presentmentFee:
			readPresentmentFeeFrom( charge ) ??
			readPresentmentFeeFrom( intentCharge ),
	};
}

/**
 * Upper-case `_wcpay_intent_currency` so two reads of a settled order can be
 * compared for equality.
 *
 * The value flips on a settled order without anything about the money
 * changing. Native writes the order's uppercase code synchronously, and then
 * `WooPaymentsEventIngestor` overwrites it with the provider's own lowercase
 * code when the event lands — so a terminal-state check that compares raw
 * reads sees `USD` become `usd` and reports a graph that is "still settling"
 * when it has settled.
 *
 * Normalising rather than excluding keeps the field under the check: a change
 * of *currency* still fails, only a change of case does not. The case is not a
 * contract on either side — native writes this key `strtoupper`ed from
 * `WooPaymentsOrderEffects…:106` and `strtolower`ed from `…:266`, and the
 * WooPayments client is the same mixture, with
 * `WC_Payments_Utils::set_order_intent_currency()` storing the order's
 * uppercase code and `attach_intent_info_to_order__legacy()` the intent's
 * lowercase one. Both read it back through a getter that falls back to
 * `$order->get_currency()`, and currency codes are case-insensitive at the
 * provider.
 */
function normalizeIntentCurrencyCase< T extends OrderRecord >( order: T ): T {
	const value = order.meta[ META_INTENT_CURRENCY ];
	if ( typeof value !== 'string' ) {
		return order;
	}
	return {
		...order,
		meta: { ...order.meta, [ META_INTENT_CURRENCY ]: value.toUpperCase() },
	};
}

async function readMoneyGraph(
	session: ProviderWriteSession,
	orderId: number
): Promise< MoneyGraph > {
	const payment = await getPaymentEvidence( session.adminApi, orderId );
	const order = await readOrderRecord( session.adminApi, orderId );
	const settlement = await readSettlementEvidence(
		session.adminApi,
		payment
	);

	return {
		payment,
		order: normalizeIntentCurrencyCase( order ),
		settlement,
	};
}

/**
 * Wait for one order to carry a settled, captured provider payment whose
 * balance transaction is readable, polling on the claim's two-second cadence
 * inside its sixty-second budget.
 */
async function readSettledMoneyGraph(
	session: ProviderWriteSession,
	orderId: number
): Promise< MoneyGraph > {
	const deadline = Date.now() + SETTLEMENT_BUDGET_MS;
	let lastError: unknown;
	for (;;) {
		try {
			const order = await getOrderPaymentEvidence(
				session.adminApi,
				orderId
			);
			await waitForPaymentState(
				session.adminApi,
				{ orderId, intentId: order.intentId },
				'succeeded',
				deadline
			);
			return await readMoneyGraph( session, orderId );
		} catch ( error ) {
			lastError = error;
			if ( Date.now() >= deadline ) {
				throw new Error(
					`Order ${ orderId } never carried a settled provider payment with a readable balance transaction.`,
					{ cause: lastError }
				);
			}
			await delay( POLL_INTERVAL_MS );
		}
	}
}

/**
 * The claim's convergence rule: two consecutive reads, two seconds apart, must
 * return the same terminal identities, amounts, currencies and statuses.
 */
async function readConvergedMoneyGraph(
	session: ProviderWriteSession,
	orderId: number,
	reason: string
): Promise< MoneyGraph > {
	const first = await readSettledMoneyGraph( session, orderId );
	await delay( POLL_INTERVAL_MS );
	const second = await readMoneyGraph( session, orderId );
	expect( second, reason ).toEqual( first );
	return second;
}

/**
 * The single-graph shape every case in this family requires: one succeeded
 * intent, one captured charge, one capture occurrence, on the exact order, for
 * the exact minor amount and ISO currency.
 */
function expectSingleSettledGraph(
	graph: MoneyGraph,
	expected: {
		orderId: number;
		runId: string;
		amountMinor: number;
		currency: string;
		total: string;
	}
): void {
	expect( graph.payment.orderId ).toBe( expected.orderId );
	expect( graph.payment.orderKey ).toBe( graph.order.orderKey );
	expect(
		graph.payment.runId,
		'the retained financial graph must carry this run ID'
	).toBe( expected.runId );
	expect(
		graph.payment.amountMinor,
		'the provider must be charged the exact minor amount the shopper saw'
	).toBe( expected.amountMinor );
	expect(
		graph.payment.currency,
		'the provider must be charged in the exact currency the shopper saw'
	).toBe( expected.currency );
	expect( graph.payment.providerStatus ).toBe( 'succeeded' );
	expect( graph.payment.chargeStatus ).toBe( 'succeeded' );
	expect( graph.payment.chargeCaptured ).toBe( true );
	expect(
		graph.payment.occurrenceCount,
		'one submission must leave exactly one charge on the intent'
	).toBe( 1 );
	expect( graph.payment.captureOccurrenceCount ).toBe( 1 );
	expect( PAID_ORDER_STATUSES ).toContain( graph.payment.orderStatus );

	expect( graph.order.currency ).toBe( expected.currency );
	expect( graph.order.total ).toBe( expected.total );
	expect(
		graph.order.customerId,
		'this family buys as a guest, so no shopper account currency preference is written'
	).toBe( 0 );
	// Compared case-insensitively, because the case of this key is not a
	// contract on either side. Native writes it `strtoupper`ed from
	// `WooPaymentsOrderEffects::…:106`, `strtolower`ed from `…:266`, and raw
	// from the event ingestor and the mobile controller — where the provider's
	// own lowercase code comes through. The WooPayments client is the same
	// mixture: `WC_Payments_Utils::set_order_intent_currency()` stores the
	// order's uppercase code while `attach_intent_info_to_order__legacy()`
	// stores the intent's lowercase one. Both read it back through a getter
	// that falls back to `$order->get_currency()`, and currency codes are
	// case-insensitive at the provider, so the code is what this asserts.
	expect(
		String( graph.order.meta[ META_INTENT_CURRENCY ] ).toUpperCase()
	).toBe( expected.currency );
	expect(
		graph.order.meta[ META_BALANCE_TRANSACTION ],
		'the order must store the exact balance transaction the provider bound to this charge'
	).toBe( graph.settlement.balanceTransactionId );
}

/**
 * The part of the money graph a later shopper-currency change must leave
 * byte-identical: the order's identity, its stored money and every provider
 * object bound to it.
 *
 * The fulfilment status is deliberately outside this projection and asserted by
 * class instead. The claim is that a currency change does not reprice, relabel
 * or re-settle a completed order; a merchant or a store automation moving a
 * paid order from `processing` to `completed` is neither, and folding it in
 * would make the immutability oracle fail for a reason the claim does not
 * name. Everything a currency change could plausibly corrupt - currency, total,
 * minor amount, intent, charge, balance transaction, fee, net, rates - is in.
 */
function settlementIdentity( graph: MoneyGraph ): Record< string, unknown > {
	const { orderStatus: _paymentOrderStatus, ...payment } = graph.payment;
	const { status: _orderStatus, ...order } = graph.order;

	return { payment, order, settlement: graph.settlement };
}

/**
 * Nothing reusable was created on the way: the intent set up no future usage
 * and its provider customer holds no attached payment method. A stray
 * attachment would be an unowned delta this family's cleanup rule fails on.
 */
async function expectNoReusableCredential(
	session: ProviderWriteSession,
	payment: PaymentEvidence
): Promise< void > {
	const providerCustomerId = await readProviderCustomerId(
		session,
		payment.intentId
	);
	expect(
		await readProviderCustomerPaymentMethodIds(
			session,
			providerCustomerId
		),
		'a one-off card purchase must attach no reusable payment method'
	).toEqual( [] );
}

/* -------------------------------------------------------------------------
 * Shopper receipt
 * ---------------------------------------------------------------------- */

interface ReceiptSummary {
	orderNumber: string;
	total: string;
	payment: string;
}

function normalizeText( value: string ): string {
	return value.replace( /\s+/g, ' ' ).trim();
}

function receiptSummaryList( page: Page ): Locator {
	return page
		.getByRole( 'list' )
		.filter( { hasText: /(?:Order number|Order #):/i } )
		.filter( { hasText: /Total:/i } )
		.filter( { hasText: /(?:Payment method|Payment):/i } );
}

async function readSummaryRow(
	summary: Locator,
	label: RegExp
): Promise< string > {
	const row = summary.getByRole( 'listitem' ).filter( { hasText: label } );
	await expect(
		row,
		`the order receipt must expose exactly one ${ label.source } row`
	).toHaveCount( 1 );
	return normalizeText( await row.innerText() );
}

/**
 * The shopper-visible facts of an order receipt, read as three semantic rows
 * rather than as one blob: the whole summary also carries a date, and this
 * family compares receipts across cases rather than within one page load.
 */
async function readReceiptSummary( page: Page ): Promise< ReceiptSummary > {
	const summary = receiptSummaryList( page );
	await expect(
		summary,
		'the order receipt must expose exactly one semantic order summary'
	).toHaveCount( 1 );

	return {
		orderNumber: await readSummaryRow(
			summary,
			/^\s*(?:Order number|Order #):/i
		),
		total: await readSummaryRow( summary, /^\s*Total:/i ),
		payment: await readSummaryRow(
			summary,
			/^\s*(?:Payment method|Payment):/i
		),
	};
}

/* -------------------------------------------------------------------------
 * Merchant order administration
 * ---------------------------------------------------------------------- */

async function openOrderEditScreen(
	page: Page,
	orderId: number
): Promise< void > {
	await page.goto(
		`wp-admin/admin.php?page=wc-orders&action=edit&id=${ orderId }`
	);
	const itemsBox = page.locator( '#woocommerce-order-items' );
	if ( ( await itemsBox.count() ) === 0 ) {
		await page.goto( `wp-admin/post.php?post=${ orderId }&action=edit` );
	}
	await expect( itemsBox ).toBeVisible();
}

/**
 * The order total exactly as the merchant reads it on the order screen. The
 * screen renders it with `wc_price()` bound to the order's own currency, so
 * this cell is where "the order preserves and displays the shopper's currency
 * in merchant order administration" is either true or false.
 */
function merchantOrderTotalCell( page: Page ): Locator {
	return page
		.getByRole( 'row' )
		.filter( { hasText: /Order Total:/ } )
		.getByRole( 'cell' )
		.last();
}

/* -------------------------------------------------------------------------
 * Cross-case state
 * ---------------------------------------------------------------------- */

interface EurPurchase {
	graph: MoneyGraph;
	receipt: ReceiptSummary;
	receiptUrl: string;
	shopperSession: Awaited< ReturnType< BrowserContext[ 'storageState' ] > >;
}

let eurPurchase: EurPurchase | undefined;

function requireEurPurchase(): EurPurchase {
	if ( ! eurPurchase ) {
		fail(
			'M3 re-reads the EUR order M2 created, and M2 recorded none; run the family in order.'
		);
	}
	return eurPurchase;
}

/**
 * Reopen the exact shopper session that placed the EUR order. `M3`'s contract
 * is about the same session changing its currency, so the run restores that
 * session's cookies rather than inventing a second shopper.
 */
async function withRestoredShopperSession< Result >(
	browser: Browser,
	baseURL: string,
	shopperSession: EurPurchase[ 'shopperSession' ],
	callback: ( page: Page ) => Promise< Result >
): Promise< Result > {
	const context = await browser.newContext( {
		baseURL,
		storageState: shopperSession,
	} );
	try {
		return await callback( await context.newPage() );
	} finally {
		await context.close();
	}
}

/* -------------------------------------------------------------------------
 * The family
 * ---------------------------------------------------------------------- */

test.describe( 'WooPayments native multi-currency settlement fidelity', () => {
	// Serial on purpose: M3 asserts that the exact order M2 created is
	// unchanged by a later currency switch, so a re-created order would make
	// its contract unprovable. A failure also stops the family rather than
	// spending more provider budget on a store whose state is no longer
	// described.
	test.describe.configure( { mode: 'serial', timeout: 420_000 } );

	test(
		'A USD shopper-currency purchase settles at the provider as exactly one succeeded 1099 usd PaymentIntent and captured charge bound to the exact order, and the order stores no converted-currency metadata',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_M1_USD,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			requireCapabilities( pilotRuntime, M1_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{ recordEvent: 'multi-currency-settlement-m1' },
				async () => {
					await assertMoneyFormatAssumptions( adminApi );
					await assertUsdSettlementPremise( adminApi );
					await assertSelectableCurrencyContext( adminApi );
					const highestOrderId = await readHighestOrderId(
						pilotRuntime
					);
					const product = await pilotRuntime.createOwnedProduct(
						USD_PRICE
					);

					// The store's settlement currency, selected explicitly as
					// the shopper currency rather than left to default, so the
					// case proves a selected USD context rather than the
					// absence of a selection.
					await selectShopperCurrency( page, 'USD' );

					const orderId = await completeCardCheckout(
						pilotRuntime,
						page,
						product,
						runId
					);
					const receipt = await readReceiptSummary( page );
					expect(
						receipt.orderNumber,
						'the receipt must name the exact order'
					).toContain( String( orderId ) );
					expect(
						receipt.total,
						'the receipt must state the exact USD total'
					).toMatch( /10\.99/ );
					expect(
						receipt.total,
						'a USD receipt must carry no euro rendering'
					).not.toContain( '€' );

					const graph = await readConvergedMoneyGraph(
						pilotRuntime,
						orderId,
						'the USD money graph must be terminal, not still settling'
					);
					expectSingleSettledGraph( graph, {
						orderId,
						runId,
						amountMinor: USD_MINOR,
						currency: 'USD',
						total: USD_PRICE,
					} );

					// The card the provider actually charged, not the card the
					// browser was told to type.
					expect(
						await readProviderCardEvidence(
							adminApi,
							graph.payment
						)
					).toEqual( { type: 'card', brand: 'visa', last4: '4242' } );

					// The order is bound to one balance transaction of its own.
					// `M1`'s fixed contract names no settlement field beyond
					// that, so nothing here requires the platform to expand the
					// record; `M2` is where the expansion is the contract.
					expect(
						graph.settlement.balanceTransactionId,
						'a captured charge must name its own balance transaction'
					).toMatch( /^txn_/ );

					// A default-currency order is not a converted order, so
					// native must store no conversion metadata for it at all.
					expect(
						graph.order.meta[ META_ORDER_RATE ],
						'a default-currency order must store no order exchange rate'
					).toBeUndefined();
					expect(
						graph.order.meta[ META_ORDER_DEFAULT_CURRENCY ],
						'a default-currency order must store no default-currency marker'
					).toBeUndefined();
					expect(
						graph.order.meta[ META_SETTLEMENT_RATE ],
						'a default-currency order must store no settlement exchange rate'
					).toBeUndefined();

					await expectNoReusableCredential(
						pilotRuntime,
						graph.payment
					);

					// Exactly one order, and the cart the purchase emptied
					// stays empty: no unowned delta, nothing left in session.
					expect(
						(
							await readOrderDeltaAfter(
								pilotRuntime,
								highestOrderId
							)
						 ).newOrderIds,
						'one submission must create exactly one order'
					).toEqual( [ orderId ] );
					expect( await readShopperCartItemCount( page ) ).toBe( 0 );
				}
			);
		}
	);

	test(
		'A EUR shopper-currency purchase settles at the provider as exactly one succeeded 1234 eur PaymentIntent and captured charge; the order stores the settlement exchange rate and USD settlement amount the provider balance transaction reports, and stores the presentment-currency fee and net the platform delivers to the order-writing path',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_M2_EUR,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_M2_TRANSACTION,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, page, pilotRuntime, runId } ) => {
			requireCapabilities( pilotRuntime, M2_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );

			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'multi-currency',
					recordEvent: 'multi-currency-settlement-m2',
				},
				async () => {
					await assertMoneyFormatAssumptions( adminApi );
					await assertUsdSettlementPremise( adminApi );

					// FORCED PREMISE (see the file header): EUR's rate is
					// supplied by the run and byte-restored afterwards.
					await withPinnedShopperCurrency(
						pilotRuntime,
						'EUR',
						async () => {
							const highestOrderId = await readHighestOrderId(
								pilotRuntime
							);
							const product =
								await pilotRuntime.createOwnedProduct(
									EUR_PRICE
								);
							await selectShopperCurrency( page, 'EUR' );

							const orderId = await completeCardCheckout(
								pilotRuntime,
								page,
								product,
								runId
							);
							const receipt = await readReceiptSummary( page );
							expect(
								receipt.orderNumber,
								'the receipt must name the exact order'
							).toContain( String( orderId ) );
							expect(
								receipt.total,
								'the receipt must state the exact EUR total'
							).toMatch( /12[.,]34/ );
							expect(
								receipt.total,
								'a EUR receipt must be denominated in euro'
							).toContain( '€' );

							const graph = await readConvergedMoneyGraph(
								pilotRuntime,
								orderId,
								'the EUR money graph must be terminal, not still settling'
							);
							expectSingleSettledGraph( graph, {
								orderId,
								runId,
								amountMinor: EUR_MINOR,
								currency: 'EUR',
								total: EUR_PRICE,
							} );
							expect(
								await readProviderCardEvidence(
									adminApi,
									graph.payment
								)
							).toEqual( {
								type: 'card',
								brand: 'visa',
								last4: '4242',
							} );

							// The store's own conversion, recorded on the
							// order: the pinned identity rate and the default
							// currency the amount was converted from.
							expect(
								requiredNumericText(
									graph.order.meta[ META_ORDER_RATE ] ?? '',
									'stored order exchange rate'
								),
								'a converted order must record the store conversion rate this run pinned'
							).toBe( EUR_MANUAL_RATE );
							expect(
								graph.order.meta[ META_ORDER_DEFAULT_CURRENCY ],
								'a converted order must record the currency it was converted from'
							).toBe( 'USD' );

							// The provider's settlement, which is a different
							// number in a different direction. The charge is
							// EUR; the account settles USD.
							const {
								balanceTransaction: settlement,
								feeSource,
								presentmentFee,
							} = requireExpandedSettlement( graph );
							expect(
								settlement.id,
								'the expanded settlement record must be the balance transaction the order stores'
							).toBe( graph.settlement.balanceTransactionId );
							expect(
								settlement.currency,
								'a EUR charge on a USD account must settle in USD'
							).toBe( 'USD' );
							expect(
								settlement.currency,
								'the settlement currency must differ from the presentment currency, or there is no conversion to prove'
							).not.toBe( graph.order.currency );
							expect(
								settlement.exchangeRate,
								'a converted settlement must report the provider exchange rate it applied'
							).not.toBeNull();
							const providerRate = settlement.exchangeRate ?? 0;
							expect( providerRate ).toBeGreaterThan( 0 );

							// The heart of the claim. With the store's own
							// conversion rate pinned to 1, a settlement rate
							// native recomputed locally could only be 1; a
							// stored rate that is not 1 and is exactly the
							// provider's own is proof the figure came from the
							// balance transaction.
							const storedSettlementRate =
								graph.order.meta[ META_SETTLEMENT_RATE ] ?? '';
							expect(
								requiredNumericText(
									storedSettlementRate,
									'stored settlement exchange rate'
								),
								'the stored settlement rate must equal the provider balance transaction rate exactly'
							).toBe( providerRate );
							expect(
								requiredNumericText(
									storedSettlementRate,
									'stored settlement exchange rate'
								),
								'a settlement rate equal to the pinned store conversion rate would be a local recomputation, not the provider figure'
							).not.toBe( EUR_MANUAL_RATE );
							expect(
								storedSettlementRate,
								'the stored settlement rate must carry no trailing-zero padding'
							).not.toMatch( /\.\d*0$/ );

							// The USD settlement amount is the EUR charge put
							// through that same provider rate, within the one
							// minor unit the provider's own rounding allows.
							expect(
								Math.abs(
									Math.round( EUR_MINOR * providerRate ) -
										settlement.amountMinor
								),
								'the USD settlement amount must be the EUR charge converted at the provider rate'
							).toBeLessThanOrEqual( 1 );
							expect(
								settlement.amountMinor,
								'a converted settlement amount cannot equal the presentment amount'
							).not.toBe( EUR_MINOR );
							expect(
								settlement.netMinor,
								'the authoritative settlement record must reconcile'
							).toBe(
								settlement.amountMinor - settlement.feeMinor
							);

							// Fee and net. This claim originally required the
							// stored figures to equal the balance
							// transaction's. A run disproved that for the
							// converted case, and the cause is not native: see
							// the 2026-08-13 M2 correction in
							// FIDELITY-CLAIMS.md, and TRAPLAT-4144.
							//
							// What the run established is a split. The
							// platform's read surfaces carry the settlement
							// figures; the charge that writes order meta carries
							// only the presentment-currency application fee,
							// because a forwarded event never expands the
							// balance transaction and every fallback in the
							// platform's envelope builder then lands on the
							// charge's own currency. The WooPayments client
							// stores exactly what native stores.
							//
							// So the assertions below fix parity in both
							// directions: the settlement figures are correct
							// where the platform reports them, the stored
							// figures are the presentment ones, and the two
							// genuinely differ.
							expect(
								feeSource.origin,
								'the read surface must carry the platform fee-breakdown envelope; without it there is no settlement fee to reconcile against'
							).toBe( 'fee-breakdown' );
							expect(
								feeSource.currency,
								'the envelope the read surface carries must report the fee in the settlement currency'
							).toBe( settlement.currency );
							expect(
								feeSource.feeMinor,
								'the envelope fee must be the balance transaction fee'
							).toBe( settlement.feeMinor );
							expect(
								feeSource.netMinor,
								'the envelope net must be the balance transaction net'
							).toBe( settlement.netMinor );

							expect(
								presentmentFee.currency,
								'the fee that reaches the order is the application fee in the charge currency'
							).toBe( graph.order.currency );
							expect(
								Math.round(
									requiredNumericText(
										graph.order.meta[ META_FEE ] ?? '',
										'stored transaction fee'
									) * 100
								),
								'the stored transaction fee must equal the presentment-currency application fee, which is what the WooPayments client also stores (TRAPLAT-4144)'
							).toBe( presentmentFee.feeMinor );
							expect(
								Math.round(
									requiredNumericText(
										graph.order.meta[ META_NET ] ?? '',
										'stored net'
									) * 100
								),
								'the stored net must equal the presentment-currency net, which is what the WooPayments client also stores (TRAPLAT-4144)'
							).toBe( presentmentFee.netMinor );

							// The tripwire. The whole point of scoping this
							// case to parity is that it stops asking the
							// question worth asking, so it must say so out loud
							// the moment the answer changes. When TRAPLAT-4144
							// lands, the stored fee becomes the settlement fee,
							// this fails, and the claim goes back to requiring
							// the balance transaction.
							expect(
								presentmentFee.feeMinor,
								'the stored fee now equals the settlement fee: TRAPLAT-4144 appears fixed, so restore the original M2 claim and delete this parity scoping'
							).not.toBe( settlement.feeMinor );

							await expectNoReusableCredential(
								pilotRuntime,
								graph.payment
							);
							expect(
								(
									await readOrderDeltaAfter(
										pilotRuntime,
										highestOrderId
									)
								 ).newOrderIds,
								'one submission must create exactly one order'
							).toEqual( [ orderId ] );
							expect(
								await readShopperCartItemCount( page )
							).toBe( 0 );

							// Handed to M3: the exact graph, the exact receipt
							// rows, the exact receipt URL and the exact shopper
							// session that bought it.
							eurPurchase = {
								graph,
								receipt,
								receiptUrl: page.url(),
								shopperSession: await page
									.context()
									.storageState(),
							};
						}
					);
				}
			);
		}
	);

	test(
		'Switching the shopper session to USD after a EUR purchase leaves that order, its provider settlement graph, its receipt and its merchant-administration currency unchanged, and creates no second order or charge',
		{
			annotation: [
				{
					type: 'woopayments-contract',
					description: CONTRACT_M3_RECEIPT,
				},
				{
					type: 'woopayments-contract',
					description: CONTRACT_M3_ADMIN,
				},
			],
			tag: FAMILY_TAGS,
		},
		async ( { adminApi, baseURL, browser, page, pilotRuntime } ) => {
			requireCapabilities( pilotRuntime, M3_CAPABILITIES );
			await pilotRuntime.assertCurrentRuntimeReady( 'native' );
			const purchase = requireEurPurchase();
			const orderId = purchase.graph.order.id;

			await pilotRuntime.withProviderWriteLocks(
				{
					featureSetting: 'multi-currency',
					recordEvent: 'multi-currency-settlement-m3',
				},
				async () => {
					await assertUsdSettlementPremise( adminApi );

					// FORCED PREMISE (see the file header): the same pinned EUR
					// premise M2 ran under, so "the session is still on EUR" is
					// a fact about the session and not about whether a restored
					// configuration happened to keep EUR enabled.
					await withPinnedShopperCurrency(
						pilotRuntime,
						'EUR',
						async () => {
							const highestOrderId = await readHighestOrderId(
								pilotRuntime
							);

							await withRestoredShopperSession(
								browser,
								requiredString( baseURL, 'store base URL' ),
								purchase.shopperSession,
								async ( shopperPage ) => {
									expect(
										await readShopperCurrency(
											shopperPage
										),
										'M3 changes the currency of the session that bought the order, so that session must still be on EUR'
									).toBe( 'EUR' );

									// The contract's gesture: the same shopper
									// session switches to USD.
									await selectShopperCurrency(
										shopperPage,
										'USD'
									);

									// A hard reload of the exact receipt, in a
									// session whose current currency is now
									// USD.
									await shopperPage.goto(
										purchase.receiptUrl
									);
									expect(
										await readReceiptSummary( shopperPage ),
										'a completed order must not be repriced or relabelled by a later currency change'
									).toEqual( purchase.receipt );
									expect(
										await readShopperCurrency(
											shopperPage
										),
										'reading a historical EUR receipt must not push the session back to EUR'
									).toBe( 'USD' );
								}
							);

							// The record and the provider, re-read and required
							// to be byte-identical to what M2 proved.
							const graph = await readConvergedMoneyGraph(
								pilotRuntime,
								orderId,
								'the EUR money graph must be terminal after the currency change, not still settling'
							);
							expect(
								settlementIdentity( graph ),
								'a shopper-currency change must leave the stored order and its provider graph unchanged'
							).toEqual( settlementIdentity( purchase.graph ) );
							expect(
								PAID_ORDER_STATUSES,
								'the order must remain a paid order across the currency change'
							).toContain( graph.order.status );

							// The merchant half: order administration still
							// reads the order in the currency the shopper
							// bought in.
							await pilotRuntime.logInAsAdmin( page );
							await page.waitForURL( '**/wp-admin/**' );
							await openOrderEditScreen( page, orderId );
							await expect(
								merchantOrderTotalCell( page ),
								'the merchant order screen must display the order total in the shopper-selected currency'
							).toHaveText( EUR_ADMIN_TOTAL );

							expect(
								(
									await readOrderDeltaAfter(
										pilotRuntime,
										highestOrderId
									)
								 ).newOrderIds,
								'a currency change must create no order'
							).toEqual( [] );
						}
					);
				}
			);
		}
	);
} );
