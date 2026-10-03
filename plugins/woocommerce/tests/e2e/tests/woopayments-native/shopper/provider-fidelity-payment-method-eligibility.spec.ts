import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { random } from '../../../utils/helpers';
import {
	expectSettledCardPayment,
	getPaymentIntent,
	requireTestModeAccount,
} from '../../../utils/woopayments';

/**
 * The `payment-method-eligibility` provider-fidelity family (T.4 Batch P3
 * rewrite): the retained browser smoke.
 *
 * `FIDELITY-CLAIMS.md` row 94: a same-session currency switch from USD to
 * EUR must add Bancontact to the offered methods while keeping Card, and a
 * checkout completed with the newly-eligible method must settle at the
 * provider in the switched currency. This case also closes the
 * multi-currency family's end-to-end converted-checkout row, since it is the
 * one case in the suite that actually pays in a switched currency.
 *
 * Eligibility here is driven by the shopper's Blocks billing country, not by
 * the connected account's country: native's `canMakePayment` for a
 * non-domestic method reads `billingAddress.country` off the Blocks cart
 * state (`client/blocks/assets/js/extensions/payment-methods/woopayments/index.js:1167-1186`),
 * exactly as the client plugin's own filter does
 * (`client/checkout/blocks/index.js:82-91` at WooPayments 11.1.0). Neither
 * `NativeWooPaymentsGateway::is_available()` nor the checkout bridge gates
 * Bancontact on the merchant account's country; the account's own
 * `bancontact_payments` capability being active is what the split gateway
 * checks. `NativeWooPaymentsGatewayTest::test_gateway_availability_keeps_shopper_country_rules_separate_from_merchant_country_rules`
 * pins exactly this: a US-country merchant account still admits Bancontact
 * for a Belgian shopper. This account settled a live Bancontact 1099 EUR
 * charge on 2026-09-22 (`evidence/multi-currency-payment-method-eligibility-reconciliation.json`,
 * commit 6344b5da9df), so the claim below is provably reachable here.
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:payment-method-eligibility',
];
const ROW_94 =
	'default::chromium::tests/e2e/specs/wcpay/shopper/multi-currency-checkout.spec.ts:122::Multi-currency checkout › Available payment methods › should display EUR payment methods when switching to EUR and default is USD';

const PAYMENTS_SETTINGS_ROUTE = 'wc/v3/payments/settings';
const MULTI_CURRENCY_ROUTE = 'wc/v3/payments/multi-currency';
const STORE_CURRENCY_ROUTE = 'wc/v3/settings/general/woocommerce_currency';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const BANCONTACT = { id: 'bancontact', label: /bancontact/i };
const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const HOSTED_PAGE_TIMEOUT_MS = 90_000;
const RECEIPT_TIMEOUT_MS = 90_000;

async function readStoreDefaultCurrency(
	restApi: ApiClient
): Promise< string > {
	return (
		( await restApi.get( STORE_CURRENCY_ROUTE ) ).data as {
			value: string;
		}
	 ).value;
}

async function readEnabledPaymentMethodIds(
	restApi: ApiClient
): Promise< string[] > {
	return (
		( await restApi.get( PAYMENTS_SETTINGS_ROUTE ) ).data as {
			enabled_payment_method_ids: string[];
		}
	 ).enabled_payment_method_ids;
}

async function writeEnabledPaymentMethodIds(
	restApi: ApiClient,
	ids: string[]
): Promise< void > {
	await restApi.post( PAYMENTS_SETTINGS_ROUTE, {
		enabled_payment_method_ids: ids,
	} );
	const echoed = await readEnabledPaymentMethodIds( restApi );
	if ( echoed.toSorted().join( ',' ) !== ids.toSorted().join( ',' ) ) {
		throw new Error( 'enabled-payment-method write did not take effect.' );
	}
}

async function readMultiCurrencyEnabled(
	restApi: ApiClient
): Promise< boolean > {
	return (
		( await restApi.get( PAYMENTS_SETTINGS_ROUTE ) ).data as {
			is_multi_currency_enabled: boolean;
		}
	 ).is_multi_currency_enabled;
}

interface CurrencyRecord {
	code: string;
	rate: number | null;
}

interface StoreCurrencies {
	available: Record< string, CurrencyRecord >;
	enabled: Record< string, CurrencyRecord >;
}

async function readStoreCurrencies(
	restApi: ApiClient
): Promise< StoreCurrencies > {
	return ( await restApi.get( `${ MULTI_CURRENCY_ROUTE }/currencies` ) )
		.data as StoreCurrencies;
}

async function readEnabledCurrencyCodes(
	restApi: ApiClient
): Promise< string[] > {
	return Object.keys(
		( await readStoreCurrencies( restApi ) ).enabled ?? {}
	);
}

async function setEnabledCurrencies(
	restApi: ApiClient,
	codes: string[]
): Promise< void > {
	await restApi.post( `${ MULTI_CURRENCY_ROUTE }/update-enabled-currencies`, {
		enabled: codes,
	} );
	const echoed = await readEnabledCurrencyCodes( restApi );
	if (
		echoed.toSorted().join( ',' ) !==
		[ ...new Set( codes ) ].toSorted().join( ',' )
	) {
		throw new Error( 'enabled-currency write did not take effect.' );
	}
}

interface SingleCurrencySettings {
	exchange_rate_type: string;
	manual_rate: unknown;
	price_rounding: unknown;
	price_charm: unknown;
}

async function readEurSettings(
	restApi: ApiClient
): Promise< SingleCurrencySettings > {
	return ( await restApi.get( `${ MULTI_CURRENCY_ROUTE }/currencies/EUR` ) )
		.data as SingleCurrencySettings;
}

/**
 * The single-currency route cannot POST the nullable projection of an
 * absent automatic configuration, so a currency whose settings were never
 * customized (all fields null) can only be restored by removing and
 * re-enabling it, not by replaying the read-back verbatim.
 */
function requiresCurrencySettingsRecreation(
	settings: SingleCurrencySettings
): boolean {
	return (
		settings.exchange_rate_type === 'automatic' &&
		settings.manual_rate === null &&
		settings.price_rounding === null &&
		settings.price_charm === null
	);
}

async function pinManualEurRate( restApi: ApiClient ): Promise< void > {
	await restApi.post( `${ MULTI_CURRENCY_ROUTE }/currencies/EUR`, {
		exchange_rate_type: 'manual',
		manual_rate: 1,
		price_rounding: 0,
		price_charm: 0,
	} );
	const pinned = await readEurSettings( restApi );
	if (
		pinned.exchange_rate_type !== 'manual' ||
		Number( pinned.manual_rate ) !== 1
	) {
		throw new Error( 'EUR manual-rate pin did not take effect.' );
	}
}

interface CartState {
	itemsCount: number;
	currency: string;
	billingCountry: string;
}

async function readCartState( page: Page ): Promise< CartState > {
	const cart = ( await (
		await page.request.get( '/wp-json/wc/store/v1/cart' )
	).json() ) as {
		items_count: number;
		totals: { currency_code: string };
		billing_address?: { country?: string };
	};
	return {
		itemsCount: cart.items_count,
		currency: cart.totals.currency_code.toUpperCase(),
		billingCountry: cart.billing_address?.country ?? '',
	};
}

async function readNewOrderIds(
	restApi: ApiClient,
	baselineOrderId: number
): Promise< number[] > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=20&status=any`
		)
	).data as Array< { id: number } >;
	return orders
		.filter( ( order ) => order.id > baselineOrderId )
		.map( ( order ) => order.id )
		.toSorted( ( a, b ) => a - b );
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

interface MethodControl {
	accessibleCount: number;
	hiddenEnabledCount: number;
}

/**
 * Fills the Belgian address the claim's checkout uses, on both the
 * pre-switch and post-switch checkout render - the old spec's
 * `prepareCheckout` ran before each of its two eligibility reads, not only
 * before the purchase, so a fresh reload here gets the same fill.
 *
 * The virtual, nontaxable product this case owns needs no shipping, so
 * Blocks renders one "Billing address" section rather than a separate
 * "Shipping address" one; the shopper enters whichever the block renders.
 * When Blocks has already stored an address for the session it renders that
 * address as a read-only card instead of the form; the "Edit" control opens
 * the form back up so the fields below are reachable on the second render.
 */
async function fillEligibilityBilling(
	page: Page,
	runId: string
): Promise< void > {
	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ runId }@example.com` );
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	for ( const selector of [
		'.wc-block-checkout__shipping-fields .wc-block-components-address-address-wrapper:not(.is-editing) .wc-block-components-address-card__edit',
		'.wc-block-checkout__billing-fields .wc-block-components-address-address-wrapper:not(.is-editing) .wc-block-components-address-card__edit',
	] ) {
		const edit = page.locator( selector );
		if ( await edit.isVisible() ) {
			await edit.click();
			break;
		}
	}
	const group = ( await shipping.isVisible() ) ? shipping : billing;
	await expect( group ).toBeVisible();
	await group
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( 'BE' );
	await group.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await group
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await group
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( 'Rue de la Loi 16' );
	await group
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( 'Brussels' );
	await group
		.getByRole( 'textbox', {
			name: /^(?:ZIP Code|Postcode|Postal code)/,
		} )
		.fill( '1000' );
}

/**
 * Waits for the payment-options group to hold exactly `expectedCount`
 * accessible-or-hidden radios before reading any of them.
 *
 * Blocks re-evaluates every method's `canMakePayment` against the billing
 * address asynchronously once it changes; a bare `count()` right after the
 * fill can catch the DOM before that re-evaluation lands and read a stale
 * set. `toHaveCount` auto-retries, so this is the wait the old driver made
 * and the rewrite had dropped.
 */
async function waitForMethodSetToSettle(
	page: Page,
	expectedCount: number
): Promise< void > {
	const paymentOptions = page.getByRole( 'group', {
		name: 'Payment options',
		exact: true,
	} );
	await expect( paymentOptions ).toBeVisible();
	await expect
		.poll( async () => ( await readCartState( page ) ).billingCountry )
		.toBe( 'BE' );
	await expect( paymentOptions.getByRole( 'radio' ) ).toHaveCount(
		expectedCount
	);
}

async function readMethodControl(
	page: Page,
	name: RegExp
): Promise< MethodControl > {
	const paymentOptions = page.getByRole( 'group', {
		name: 'Payment options',
		exact: true,
	} );
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

/**
 * D3a preflight (plan-task-t3): confirm the shopper session carries no
 * leftover multi-currency selection before this EUR-switching case runs, and
 * again afterwards, since a stale `wcpay_currency` cookie or session has
 * previously leaked a non-default currency into an unrelated run.
 */
async function assertFreshUsdSession(
	restApi: ApiClient,
	page: Page
): Promise< void > {
	await page.context().clearCookies();
	expect(
		await readStoreDefaultCurrency( restApi ),
		'the store must default to USD before this case switches currency'
	).toBe( 'USD' );
	await page.goto( 'shop/' );
	const cart = await readCartState( page );
	expect(
		cart.currency,
		'a fresh session must quote the store default currency, not a leaked selection'
	).toBe( 'USD' );
}

/**
 * Runs every restore step independently, in its own `try`, so one failure
 * never masks another or the case's own error. Restoration failures are
 * thrown together, never silently absorbed.
 */
async function restoreAll(
	steps: ReadonlyArray< { name: string; run: () => Promise< void > } >
): Promise< void > {
	const failures: string[] = [];
	for ( const step of steps ) {
		try {
			await step.run();
		} catch ( error ) {
			failures.push(
				`${ step.name }: ${
					error instanceof Error ? error.message : String( error )
				}`
			);
		}
	}
	if ( failures.length > 0 ) {
		throw new Error( `Restoration failed for: ${ failures.join( '; ' ) }` );
	}
}

test.describe( 'WooPayments native payment-method eligibility fidelity', () => {
	test.describe.configure( { timeout: 300_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
	} );

	test(
		'A same-session switch from USD to EUR adds Bancontact while retaining Card and one selected Bancontact checkout settles exactly 1099 eur',
		{
			annotation: [
				{ type: 'woopayments-contract', description: ROW_94 },
			],
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi } ) => {
			const runId = random();
			await assertFreshUsdSession( restApi, page );

			expect(
				await readMultiCurrencyEnabled( restApi ),
				'multi-currency must already be enabled on this store: enabling it here is not reversible'
			).toBe( true );

			const currencies = await readStoreCurrencies( restApi );
			expect(
				Object.keys( currencies.available ?? {} ),
				'EUR must be in the store currency catalog for a EUR charge to exist here'
			).toContain( 'EUR' );

			const originalCurrencies = Object.keys( currencies.enabled ?? {} );
			const eurAlreadyEnabled = originalCurrencies.includes( 'EUR' );
			const originalEurSettings = await readEurSettings( restApi );
			const originalMethods =
				await readEnabledPaymentMethodIds( restApi );
			const bancontactAlreadyEnabled = originalMethods.includes(
				BANCONTACT.id
			);

			// The EUR rate is pinned unconditionally, even when EUR was already
			// enabled: an EUR already sitting at a non-1.0 automatic rate would
			// make the 1099 eur assertion fail for an environmental reason
			// unrelated to the claim.
			await pinManualEurRate( restApi );
			if ( ! eurAlreadyEnabled ) {
				await setEnabledCurrencies( restApi, [
					...originalCurrencies,
					'EUR',
				] );
			}
			if ( ! bancontactAlreadyEnabled ) {
				await writeEnabledPaymentMethodIds( restApi, [
					...originalMethods,
					BANCONTACT.id,
				] );
			}

			try {
				const baselineOrderId = await readHighestOrderId( restApi );
				const product = (
					await restApi.post( PRODUCTS_ROUTE, {
						name: `WooPayments eligibility ${ random() }`,
						type: 'simple',
						virtual: true,
						regular_price: PRICE,
						tax_status: 'none',
						status: 'publish',
					} )
				).data as { id: number };

				try {
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					// The single-product template adds through the client-side
					// Interactivity API rather than a form submission, so the
					// click resolves before the cart update lands; navigating to
					// checkout immediately can race an empty cart. Wait for the
					// Store API cart to actually carry the item first.
					await expect
						.poll(
							async () =>
								( await readCartState( page ) ).itemsCount
						)
						.toBeGreaterThanOrEqual( 1 );
					await page.goto( 'checkout/' );
					await fillEligibilityBilling( page, runId );
					await waitForMethodSetToSettle( page, 1 );

					const totalValue = page.locator(
						'.wc-block-components-totals-footer-item .wc-block-components-totals-item__value'
					);
					await expect( totalValue ).toBeVisible();
					const beforeTotalText = await totalValue.innerText();
					expect( beforeTotalText ).toMatch( /\$/ );
					expect( beforeTotalText ).not.toMatch( /€/ );
					const beforeCart = await readCartState( page );
					expect( beforeCart.currency ).toBe( 'USD' );
					expect( beforeCart.itemsCount ).toBe( 1 );
					const beforeCard = await readMethodControl( page, /Card/i );
					expect( beforeCard.accessibleCount ).toBe( 1 );
					const beforeBancontact = await readMethodControl(
						page,
						BANCONTACT.label
					);
					expect(
						beforeBancontact.accessibleCount,
						'Bancontact must be unavailable before the currency switch'
					).toBe( 0 );
					expect( beforeBancontact.hiddenEnabledCount ).toBe( 0 );

					// The same-session switch the claim is about.
					await page.goto( 'shop/?currency=EUR' );
					const switchedCart = await readCartState( page );
					expect(
						switchedCart.currency,
						'the shopper session currency switch to EUR must take'
					).toBe( 'EUR' );

					await page.goto( 'checkout/' );
					await fillEligibilityBilling( page, runId );
					await waitForMethodSetToSettle( page, 2 );
					await expect( totalValue ).toBeVisible();
					const afterTotalText = await totalValue.innerText();
					expect( afterTotalText ).toMatch( /€/ );
					expect( afterTotalText ).not.toMatch( /\$/ );
					const afterCart = await readCartState( page );
					expect( afterCart.currency ).toBe( 'EUR' );
					expect( afterCart.itemsCount ).toBe( 1 );
					const afterCard = await readMethodControl( page, /Card/i );
					expect(
						afterCard.accessibleCount,
						'Card must stay available after the switch'
					).toBe( 1 );
					const afterBancontact = await readMethodControl(
						page,
						BANCONTACT.label
					);
					expect(
						afterBancontact.accessibleCount,
						'Bancontact must become available after the switch to EUR'
					).toBe( 1 );

					const bancontactRadio = page
						.getByRole( 'group', { name: 'Payment options' } )
						.getByRole( 'radio', { name: BANCONTACT.label } );
					await bancontactRadio.check();
					await expect( bancontactRadio ).toBeChecked();

					await page
						.getByRole( 'button', { name: /place order/i } )
						.click();
					await page
						.getByText( 'Authorize Test Payment' )
						.first()
						.waitFor( {
							state: 'visible',
							timeout: HOSTED_PAGE_TIMEOUT_MS,
						} );

					const newOrderIds = await readNewOrderIds(
						restApi,
						baselineOrderId
					);
					expect(
						newOrderIds,
						'one checkout must create exactly one order'
					).toHaveLength( 1 );
					const [ orderId ] = newOrderIds;

					await page
						.getByText( 'Authorize Test Payment' )
						.first()
						.click();
					await page.waitForURL( /\/order-received\/[1-9]\d*/, {
						timeout: RECEIPT_TIMEOUT_MS,
					} );
					await expect(
						page.getByText(
							/^(Your order has been received|Order received)$/i
						)
					).toBeVisible();

					// Read the settled graph first: an intent read during
					// adjudication can answer the provider's transient 429
					// lock_timeout, which only expectSettledCardPayment's poll
					// tolerates.
					const payment = await expectSettledCardPayment(
						restApi,
						orderId,
						{ amountMinor: AMOUNT_MINOR, currency: 'EUR' }
					);
					const intentId = payment.intentId;
					const intent = await getPaymentIntent( restApi, intentId );
					expect( intent.amount ).toBe( AMOUNT_MINOR );
					expect( String( intent.currency ).toLowerCase() ).toBe(
						'eur'
					);
					expect( intent.payment_method_types ).toEqual( [
						BANCONTACT.id,
					] );
					expect( payment.paymentMethodId ).toMatch( /^pm_/ );

					// Bancontact is not a reusable method: the paid customer
					// must be left with no attached provider payment method.
					const customerId =
						typeof intent.customer === 'string'
							? intent.customer
							: ( intent.customer as { id?: string } )?.id;
					expect( customerId ).toBeTruthy();
					const attachedMethods = (
						await restApi.get(
							`wc/v3/payments/customers/${ customerId }/payment_methods`
						)
					).data as unknown[];
					expect(
						attachedMethods,
						'payment must not leave a reusable provider payment method attached'
					).toEqual( [] );
				} finally {
					await restApi.delete(
						`${ PRODUCTS_ROUTE }/${ product.id }`,
						{ force: true }
					);
				}
			} finally {
				await restoreAll( [
					{
						name: 'enabled-payment-method restore',
						run: async () => {
							if ( ! bancontactAlreadyEnabled ) {
								await writeEnabledPaymentMethodIds(
									restApi,
									originalMethods
								);
							}
						},
					},
					{
						name: 'EUR configuration restore',
						run: async () => {
							if (
								requiresCurrencySettingsRecreation(
									originalEurSettings
								)
							) {
								// The route cannot accept the nullable automatic
								// projection back verbatim; removing and
								// re-adding EUR reproduces it.
								const withoutEur = originalCurrencies.filter(
									( code ) => code !== 'EUR'
								);
								await setEnabledCurrencies(
									restApi,
									withoutEur
								);
								if ( eurAlreadyEnabled ) {
									await setEnabledCurrencies(
										restApi,
										originalCurrencies
									);
								}
							} else {
								await restApi.post(
									`${ MULTI_CURRENCY_ROUTE }/currencies/EUR`,
									originalEurSettings
								);
								if ( ! eurAlreadyEnabled ) {
									await setEnabledCurrencies(
										restApi,
										originalCurrencies
									);
								}
							}
							const restoredSettings =
								await readEurSettings( restApi );
							if (
								JSON.stringify( restoredSettings ) !==
								JSON.stringify( originalEurSettings )
							) {
								throw new Error(
									'EUR settings did not cold-read back to their recorded baseline.'
								);
							}
							const restoredEnabled =
								await readEnabledCurrencyCodes( restApi );
							if (
								restoredEnabled.toSorted().join( ',' ) !==
								originalCurrencies.toSorted().join( ',' )
							) {
								throw new Error(
									'Enabled-currency set did not cold-read back to its recorded baseline.'
								);
							}
							if (
								! ( await readMultiCurrencyEnabled( restApi ) )
							) {
								throw new Error(
									'Multi-currency flag was left disabled by this run.'
								);
							}
						},
					},
					{
						name: 'D3a fresh-session check',
						run: () => assertFreshUsdSession( restApi, page ),
					},
				] );
			}
		}
	);
} );
