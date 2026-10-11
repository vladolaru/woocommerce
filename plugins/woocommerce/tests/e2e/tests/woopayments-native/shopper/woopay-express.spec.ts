import { execFileSync } from 'node:child_process';

import type { Frame, Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { admin } from '../../../test-data/data';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	expectSettledCardPayment,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * The `woopay-hosted-express` family (T.13): a shopper pays through the
 * hosted WooPay checkout that wpcom-local serves on `woopay.localhost`, and
 * the native store records what client 11.1.0 records for a WooPay order.
 *
 * Client 11.1.0 has no browser test of hosted WooPay, so the oracle is its
 * mechanics: the express button opens the platform's `/otp/` iframe
 * (`client/checkout/woopay/express-button/express-checkout-iframe.js:40-49`,
 * `:203-205`), the WooPay order carries `is_woopay`
 * (`includes/class-payment-information.php:284-287`), and a renewal of a
 * WooPay subscription charges the token the parent order saved
 * (`includes/compat/subscriptions/trait-wc-payment-gateway-wcpay-subscriptions.php:402-425`).
 *
 * Local-only, like the other provider cases: it needs wpcom-local with hosted
 * WooPay enabled and the store wired to it through WCPay Dev Tools. The OTP
 * comes from the wpcom-local SMS sink (`wpcom-local woopay otp list`).
 */

const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	'@fidelity:woopay-hosted-express',
];

const WOOPAY_ORIGIN = 'http://woopay.localhost:30001';
const SETTINGS_ROUTE = 'wc/v3/payments/settings';
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const SUBSCRIPTIONS_ROUTE = 'wc/v3/subscriptions';
const SUBSCRIPTION_EVIDENCE_ROUTE =
	'wc-native-payments-e2e/v1/subscription-evidence';
const PROCESS_RENEWAL_ACTION = 'wcs_process_renewal';
const PRICE = '10.99';
const PRICE_MINOR = 1099;
const CARD = { brand: 'visa', last4: '4242' };
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
/**
 * A fictional US number (201-555-0100..0199) for each new WooPay account,
 * picked from the clock so reruns within a day spread over the range: WooPay
 * caps SMS codes at 8 per phone number a day (OTPThrottler
 * DAILY_LIMIT_PER_PHONE), so one fixed number stops the family after 8 runs.
 */
function shopperPhone(): string {
	const pick = Math.floor( Date.now() / 1000 ) % 100;
	return `20155501${ String( pick ).padStart( 2, '0' ) }`;
}
const OTP_TIMEOUT_MS = 60_000;
const RETURN_TIMEOUT_MS = 120_000;

function delay( milliseconds: number ): Promise< void > {
	return new Promise( ( resolve ) => setTimeout( resolve, milliseconds ) );
}

async function setWooPayEnabled(
	restApi: ApiClient,
	enabled: boolean
): Promise< void > {
	await restApi.post( SETTINGS_ROUTE, { is_woopay_enabled: enabled } );
	const settings = ( await restApi.get( SETTINGS_ROUTE ) ).data as {
		is_woopay_enabled?: boolean;
	};
	expect(
		settings.is_woopay_enabled,
		'the settings route must read back the WooPay state it was given'
	).toBe( enabled );
}

/**
 * Waits for the SMS the platform sends after `sentAfter` and returns its code
 * and sink line.
 */
async function readOtpFromSink(
	sentAfter: number
): Promise< { code: string; line: string } > {
	const deadline = Date.now() + OTP_TIMEOUT_MS;
	for (;;) {
		const result = JSON.parse(
			execFileSync(
				'wpcom-local',
				[ '--json', 'woopay', 'otp', 'list', '--limit=1' ],
				{ encoding: 'utf8' }
			)
		) as {
			context?: { latest_code?: string; latest_captured_at?: string };
			messages?: Array< { text: string } >;
		};
		const capturedAt = Date.parse(
			result.context?.latest_captured_at ?? ''
		);
		if ( result.context?.latest_code && capturedAt >= sentAfter ) {
			return {
				code: result.context.latest_code,
				line: result.messages?.[ 0 ]?.text ?? '',
			};
		}
		if ( Date.now() >= deadline ) {
			throw new Error( 'the WooPay OTP SMS never reached the sink.' );
		}
		await delay( 2_000 );
	}
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
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

/**
 * Fills one of the hosted checkout's Stripe card fields, which live in
 * separate `__privateStripeFrame` frames.
 */
async function fillStripeField(
	page: Page,
	label: RegExp,
	value: string
): Promise< void > {
	let field: Frame | undefined;
	await expect
		.poll(
			async () => {
				for ( const frame of page.frames() ) {
					if (
						frame.name().startsWith( '__privateStripeFrame' ) &&
						( await frame
							.getByRole( 'textbox', { name: label } )
							.count() ) > 0
					) {
						field = frame;
						return true;
					}
				}
				return false;
			},
			{ timeout: 60_000 }
		)
		.toBe( true );
	await ( field as Frame )
		.getByRole( 'textbox', { name: label } )
		.fill( value );
}

/**
 * Clicks the WooPay express button and completes the platform's own flow:
 * the `/otp/` iframe (the client's mechanic, asserted here), a new WooPay
 * account on a test phone number, the SMS code, the hosted checkout on
 * `woopay.localhost` paid with the basic test card, and the return to the
 * store's order-received page. Returns the order id and the OTP sink line.
 */
async function payThroughWooPay(
	page: Page
): Promise< { orderId: number; otpLine: string } > {
	const button = page.locator( '.woopay-express-button' );
	await expect( button ).toBeVisible( { timeout: 30_000 } );
	await button.click();

	const otpFrameElement = page.locator( 'iframe.woopay-otp-iframe' );
	await expect(
		otpFrameElement,
		'the express button must open the platform OTP iframe, as client 11.1.0 does'
	).toBeVisible( { timeout: 30_000 } );
	expect( await otpFrameElement.getAttribute( 'src' ) ).toMatch(
		new RegExp( `^${ WOOPAY_ORIGIN }/otp/\\?` )
	);

	const otp = page.frameLocator( 'iframe.woopay-otp-iframe' );
	await otp.getByLabel( 'Mobile number' ).fill( shopperPhone() );
	const sentAfter = Date.now() - 1_000;
	await otp.getByRole( 'button', { name: 'Continue' } ).click();
	const { code, line } = await readOtpFromSink( sentAfter );
	await otp.getByLabel( 'Verify your number' ).fill( code );

	await page.waitForURL(
		( url ) =>
			url.origin === WOOPAY_ORIGIN &&
			url.pathname.startsWith( '/woopay' ),
		{ timeout: RETURN_TIMEOUT_MS }
	);

	// The hosted page's cookie banner can appear at any point and swallows clicks until it fades out, so Playwright
	// closes it whenever it shows up before an action.
	const cookieBanner = page.getByRole( 'button', {
		name: 'Close and accept',
	} );
	await page.addLocatorHandler( cookieBanner, async () => {
		await cookieBanner.click();
		await expect( cookieBanner ).toBeHidden();
	} );

	// Hosted checkout: Stripe's split card fields, each in its own frame,
	// then the platform's review step before the order is placed.
	await fillStripeField( page, /card number/i, TEST_CARDS.basic.number );
	await fillStripeField( page, /expiration/i, TEST_CARDS.basic.expiry );
	await fillStripeField( page, /CVC/i, TEST_CARDS.basic.cvc );
	// A new WooPay account has no saved address; the platform asks for one. Wait for the page to settle on either the
	// review button or the address prompt before deciding.
	const reviewButton = page.getByRole( 'button', {
		name: 'Review your order',
	} );
	const addAddress = page.getByRole( 'button', { name: 'Add new address' } );
	await expect( reviewButton.or( addAddress ).first() ).toBeVisible();
	if ( await addAddress.isVisible() ) {
		await addAddress.click();
		const form = page.getByRole( 'dialog' );
		await form
			.getByRole( 'combobox', { name: 'Country / Region' } )
			.selectOption( 'US' );
		await form.getByRole( 'textbox', { name: 'First name' } ).fill( 'Woo' );
		await form
			.getByRole( 'textbox', { name: 'Last name' } )
			.fill( 'Shopper' );
		await form
			.getByRole( 'textbox', { name: 'Street address' } )
			.fill( '969 Market' );
		await form
			.getByRole( 'textbox', { name: 'City' } )
			.fill( 'San Francisco' );
		await form
			.getByRole( 'combobox', { name: 'State', exact: true } )
			.selectOption( 'CA' );
		await form.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94103' );
		await form.getByRole( 'button', { name: 'Add', exact: true } ).click();
		await expect( form ).toBeHidden();
	}
	await reviewButton.click();
	await page.getByRole( 'button', { name: /^Place order for/ } ).click();

	await page.waitForURL( /\/order-received\/[1-9]\d*/, {
		timeout: RETURN_TIMEOUT_MS,
	} );
	return {
		orderId: Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] ),
		otpLine: line,
	};
}

/** The WooPay order facts client 11.1.0 records, plus one settled payment. */
async function expectWooPayOrder(
	restApi: ApiClient,
	orderId: number,
	amountMinor: number
): Promise< Awaited< ReturnType< typeof expectSettledCardPayment > > > {
	const order = ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as {
		status: string;
		payment_method: string;
		meta_data: Array< { key: string; value: unknown } >;
	};
	expect( order.payment_method ).toBe( 'woocommerce_payments' );
	expect(
		order.meta_data.find( ( meta ) => meta.key === 'is_woopay' )?.value,
		'a WooPay order carries is_woopay (class-payment-information.php:284-287)'
	).toBeTruthy();
	const payment = await expectSettledCardPayment( restApi, orderId, {
		amountMinor,
		currency: 'USD',
		card: CARD,
	} );
	expect( PAID_ORDER_STATUSES ).toContain( payment.orderStatus );
	return payment;
}

function recordEvidence( evidence: Record< string, unknown > ): void {
	test.info().annotations.push( {
		type: 'woopay-evidence',
		description: JSON.stringify( evidence ),
	} );
}

let customer: ReturnType< typeof getFakeUser >;
let customerId: number;
let wooPayWasEnabled: boolean;

test.describe( 'WooPayments native hosted WooPay express checkout', () => {
	test.describe.configure( { mode: 'serial', timeout: 600_000 } );

	test.beforeAll( async ( { restApi } ) => {
		await requireTestModeAccount( restApi );
		wooPayWasEnabled = Boolean(
			(
				( await restApi.get( SETTINGS_ROUTE ) ).data as {
					is_woopay_enabled?: boolean;
				}
			 ).is_woopay_enabled
		);
		await setWooPayEnabled( restApi, true );
		await createClassicCheckoutPage();
	} );

	// A fresh shopper per case: WooPay remembers the account the previous case
	// created for an email, and a returning account skips the sign-up steps.
	test.beforeEach( async ( { restApi } ) => {
		customer = getFakeUser( 'customer' );
		customerId = (
			( await restApi.post( 'wc/v3/customers', customer ) ).data as {
				id: number;
			}
		 ).id;
	} );

	test.afterEach( async ( { restApi } ) => {
		if ( customerId ) {
			await restApi.delete( `wc/v3/customers/${ customerId }`, {
				force: true,
			} );
		}
	} );

	test.afterAll( async ( { restApi } ) => {
		await setWooPayEnabled( restApi, wooPayWasEnabled );
	} );

	for ( const surface of [
		{
			id: 'W1',
			name: 'Blocks',
			path: 'checkout/',
		},
		{
			id: 'W2',
			name: 'Classic',
			path: 'classic-checkout/',
		},
	] ) {
		test(
			`${ surface.id } a ${ surface.name } checkout paid through the WooPay express button, OTP and hosted WooPay returns to one processing order with is_woopay and one settled 1099 usd payment`,
			{
				tag: FAMILY_TAGS,
			},
			async ( { page, restApi } ) => {
				const product = (
					await restApi.post( PRODUCTS_ROUTE, {
						name: `WooPay express E2E ${ surface.id } ${ customer.username }`,
						type: 'simple',
						virtual: true,
						regular_price: PRICE,
						status: 'publish',
					} )
				).data as { id: number };
				let orderId: number | undefined;
				try {
					await page.goto( 'wp-login.php' );
					await logIn(
						page,
						customer.username,
						customer.password,
						false
					);
					const baselineOrderId = await readHighestOrderId( restApi );
					await page.goto( `?post_type=product&p=${ product.id }` );
					await page
						.getByRole( 'button', {
							name: 'Add to cart',
							exact: true,
						} )
						.click();
					await page.goto( surface.path );

					const result = await payThroughWooPay( page );
					orderId = result.orderId;
					expect(
						await readNewOrderIds( restApi, baselineOrderId ),
						'one WooPay checkout must create exactly one order'
					).toEqual( [ orderId ] );
					const payment = await expectWooPayOrder(
						restApi,
						orderId,
						PRICE_MINOR
					);
					recordEvidence( {
						case: surface.id,
						otpSinkLine: result.otpLine,
						orderId,
						intentId: payment.intentId,
						chargeId: payment.chargeId,
						paymentMethodId: payment.paymentMethodId,
					} );
				} finally {
					if ( orderId ) {
						await restApi.delete(
							`${ ORDERS_ROUTE }/${ orderId }`,
							{
								force: true,
							}
						);
					}
					await restApi.delete(
						`${ PRODUCTS_ROUTE }/${ product.id }`,
						{
							force: true,
						}
					);
				}
			}
		);
	}

	test(
		'W3 a subscription paid through hosted WooPay renews on the WooPay-saved card when the merchant processes a renewal',
		{
			tag: FAMILY_TAGS,
		},
		async ( { page, restApi, baseURL, browser } ) => {
			const product = (
				await restApi.post( PRODUCTS_ROUTE, {
					name: `WooPay subscription E2E ${ customer.username }`,
					type: 'subscription',
					virtual: true,
					regular_price: PRICE,
					status: 'publish',
					meta_data: [
						{ key: '_subscription_price', value: PRICE },
						{ key: '_subscription_period', value: 'month' },
						{ key: '_subscription_period_interval', value: '1' },
						{ key: '_subscription_length', value: '0' },
						{ key: '_subscription_sign_up_fee', value: '0' },
						{ key: '_subscription_trial_length', value: '0' },
					],
				} )
			).data as { id: number };
			const ownedOrderIds: number[] = [];
			let subscriptionId: number | undefined;
			try {
				await page.goto( 'wp-login.php' );
				await logIn(
					page,
					customer.username,
					customer.password,
					false
				);
				await page.goto( `?post_type=product&p=${ product.id }` );
				await page
					.getByRole( 'button', {
						name: /^(Sign up now|Add to cart)$/i,
					} )
					.click();
				await page.goto( 'checkout/' );

				const { orderId: parentOrderId, otpLine } =
					await payThroughWooPay( page );
				ownedOrderIds.push( parentOrderId );
				const parentPayment = await expectWooPayOrder(
					restApi,
					parentOrderId,
					PRICE_MINOR
				);

				const evidence = (
					await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
						customer_username: customer.username,
					} )
				).data as { customer_subscription_ids: number[] };
				expect( evidence.customer_subscription_ids ).toHaveLength( 1 );
				subscriptionId = evidence.customer_subscription_ids[ 0 ];
				const readSubscription = async () =>
					(
						(
							await restApi.get( SUBSCRIPTION_EVIDENCE_ROUTE, {
								subscription_id: String( subscriptionId ),
							} )
						).data as {
							subscription: {
								status: string;
								parent_id: number;
								payment_tokens: Array< {
									token_id: number;
									payment_method_id: string;
								} >;
								related_orders: { renewal: number[] };
							};
						}
					 ).subscription;
				const signedUp = await readSubscription();
				expect( signedUp.status ).toBe( 'active' );
				expect( signedUp.parent_id ).toBe( parentOrderId );
				expect(
					signedUp.payment_tokens,
					'the WooPay parent order must leave one saved card on the subscription'
				).toHaveLength( 1 );
				const savedToken = signedUp.payment_tokens[ 0 ];

				// The merchant renewal, on its own admin session.
				const adminContext = await browser.newContext( {
					baseURL,
					storageState: { cookies: [], origins: [] },
				} );
				try {
					const adminPage = await adminContext.newPage();
					await adminPage.goto( 'wp-login.php' );
					await logIn( adminPage, admin.username, admin.password );
					await adminPage.goto(
						`wp-admin/admin.php?page=wc-orders--shop_subscription&action=edit&id=${ subscriptionId }`
					);
					const actions = adminPage.locator(
						'#woocommerce-order-actions'
					);
					await actions
						.locator( 'select[name="wc_order_action"]' )
						.selectOption( PROCESS_RENEWAL_ACTION );
					adminPage.once( 'dialog', ( dialog ) => dialog.accept() );
					await Promise.all( [
						adminPage.waitForURL( /message=/, { timeout: 60_000 } ),
						actions
							.getByRole( 'button', {
								name: 'Update',
								exact: true,
							} )
							.click(),
					] );
				} finally {
					await adminContext.close();
				}

				let renewed = await readSubscription();
				for (
					const deadline = Date.now() + 120_000;
					renewed.related_orders.renewal.length === 0 &&
					Date.now() < deadline;

				) {
					await delay( 3_000 );
					renewed = await readSubscription();
				}
				expect( renewed.related_orders.renewal ).toHaveLength( 1 );
				const renewalOrderId = renewed.related_orders.renewal[ 0 ];
				ownedOrderIds.push( renewalOrderId );
				const renewalPayment = await expectSettledCardPayment(
					restApi,
					renewalOrderId,
					{ amountMinor: PRICE_MINOR, currency: 'USD', card: CARD }
				);
				expect( PAID_ORDER_STATUSES ).toContain(
					renewalPayment.orderStatus
				);
				expect(
					renewalPayment.paymentMethodId,
					'the renewal must charge the card the WooPay parent order saved'
				).toBe( savedToken.payment_method_id );
				expect( renewalPayment.intentId ).not.toBe(
					parentPayment.intentId
				);

				// O10 live evidence, recorded and not asserted: whether the
				// platform cloned the WooPay payment method for the merchant or
				// reused it is the platform's choice, read from the ids.
				recordEvidence( {
					case: 'W3',
					otpSinkLine: otpLine,
					parentOrderId,
					parentIntentId: parentPayment.intentId,
					parentChargeId: parentPayment.chargeId,
					parentPaymentMethodId: parentPayment.paymentMethodId,
					savedTokenId: savedToken.token_id,
					savedTokenPaymentMethodId: savedToken.payment_method_id,
					renewalOrderId,
					renewalIntentId: renewalPayment.intentId,
					renewalChargeId: renewalPayment.chargeId,
					renewalPaymentMethodId: renewalPayment.paymentMethodId,
				} );
			} finally {
				if ( subscriptionId ) {
					await restApi.put(
						`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
						{
							transition_status: 'cancelled',
						}
					);
					await restApi.delete(
						`${ SUBSCRIPTIONS_ROUTE }/${ subscriptionId }`,
						{ force: true }
					);
				}
				for ( const id of ownedOrderIds ) {
					await restApi.delete( `${ ORDERS_ROUTE }/${ id }`, {
						force: true,
					} );
				}
				await restApi.delete( `${ PRODUCTS_ROUTE }/${ product.id }`, {
					force: true,
				} );
			}
		}
	);
} );
