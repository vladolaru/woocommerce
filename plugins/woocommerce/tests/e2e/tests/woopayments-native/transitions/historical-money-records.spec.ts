import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import type { Page, Request } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpCLI, wpEvalJson } from '../../../utils/cli';
import { admin } from '../../../test-data/data';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import { random } from '../../../utils/helpers';
import { createClassicCheckoutPage } from '../../../utils/pages';
import {
	expectSettledCardPayment,
	fillCardDetails,
	getPaymentIntent,
	getCharge,
	requireTestModeAccount,
	TEST_CARDS,
	type ProviderTestCard,
} from '../../../utils/woopayments';

/**
 * The 11.1.0 plugin's own Payment Element container
 * (`class-wc-payment-gateway-wcpay.php:611`, `.wcpay-upe-element`), used for
 * the plugin-era decline below. Native's `#wcpay-core-payment-element`, which
 * `fillCardDetails` targets for the native-era payment further down, carries
 * the same class too, so this selector does not tell the runtimes apart;
 * plugin ownership is asserted separately before the plugin-era write.
 */
async function fillPluginOwnedCardDetails(
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	const frame = page.frameLocator(
		'.wcpay-upe-element[data-payment-method-type="card"] iframe[name^="__privateStripeFrame"]'
	);
	await frame
		.getByRole( 'textbox', { name: 'Card number' } )
		.fill( card.number );
	await frame.getByRole( 'textbox', { name: /^Expir/i } ).fill( card.expiry );
	await frame
		.getByRole( 'textbox', { name: 'Security code' } )
		.fill( card.cvc );
}

/**
 * The transition `historical-money-records` rows (T.4 Batch T rewrite, D6):
 * a failed order the plugin created stays payable after cutover, and native's
 * card-testing-protection field renders (or doesn't) exactly as the account's
 * eligibility, seeded before cutover, says it should. Two parameterized
 * cases, protection off and on, each on its own fresh plugin-era store
 * (`beforeEach`/`afterEach`), matching the money this row moves.
 */

const CONTRACT_DISABLED =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-pay-for-order.spec.ts:35::Shopper › Pay for Order › should be able to pay for a failed order with card testing protection false';
const CONTRACT_ENABLED =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-pay-for-order.spec.ts:35::Shopper › Pay for Order › should be able to pay for a failed order with card testing protection true';
const FAMILY_TAGS = [
	tags.WOOPAYMENTS_NATIVE,
	tags.WOOPAYMENTS_PROVIDER,
	tags.WOOPAYMENTS_TRANSITION,
	tags.WOOPAYMENTS_PR,
];
const PRODUCTS_ROUTE = 'wc/v3/products';
const ORDERS_ROUTE = 'wc/v3/orders';
const DECLINE_NOTICE = 'Your card was declined.';

function orderMeta( order: Record< string, unknown >, key: string ): string {
	const entries = Array.isArray( order.meta_data )
		? ( order.meta_data as Array< { key?: unknown; value?: unknown } > )
		: [];
	return String(
		entries.find( ( entry ) => entry.key === key )?.value ?? ''
	);
}

async function readRuntimeOwner( restApi: ApiClient ): Promise< string > {
	return String(
		( await restApi.get( 'wc-native-payments-e2e/v1/status' ) ).data
			.runtime_owner
	);
}

async function readOrder(
	restApi: ApiClient,
	orderId: number
): Promise< Record< string, unknown > > {
	return ( await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` ) )
		.data as Record< string, unknown >;
}

async function fillClassicBillingDetails( page: Page ): Promise< void > {
	await page.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await page
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await page
		.getByRole( 'textbox', { name: 'Street address' } )
		.fill( '123 Test Street' );
	await page
		.getByRole( 'textbox', { name: 'Town / City' } )
		.fill( 'San Francisco' );
	await page.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await page.getByRole( 'textbox', { name: 'Phone' } ).fill( '5555550100' );
}

async function runHistoricalMoneyRecords(
	page: Page,
	restApi: ApiClient,
	protectionOn: boolean
): Promise< void > {
	// The post-cutover customer login below reproducibly exceeded the 10s
	// default action timeout: it is the first front-end request under the
	// newly active ACTIVE-tier bootstrap, heavier than the pre-cutover one.
	page.setDefaultTimeout( 30_000 );
	await requireTestModeAccount( restApi );
	expect(
		await readRuntimeOwner( restApi ),
		'the plugin must own runtime before the plugin-era decline'
	).toBe( 'extension' );

	const customer = getFakeUser( 'customer' );
	const created = ( await restApi.post( 'wc/v3/customers', customer ) )
		.data as { id: number };
	const customerId = created.id;
	const product = (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments historical-money ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: '10.01',
			manage_stock: true,
			stock_quantity: 1,
			status: 'publish',
		} )
	).data as { id: number };

	// No REST cleanup in this function: `afterEach` runs a full `db reset`
	// right after each test, which removes this product and customer along
	// with everything else, so a delete call here would only risk masking
	// the case's own error.
	{
		// Plugin era: a declined classic checkout creates exactly one failed order.
		await page.goto( 'wp-login.php' );
		await logIn( page, customer.username, customer.password, false );
		await page.goto( `?post_type=product&p=${ product.id }` );
		await page
			.getByRole( 'button', { name: 'Add to cart', exact: true } )
			.click();
		await page.goto( 'classic-checkout/' );
		await fillClassicBillingDetails( page );
		await page
			.locator(
				'input[name="payment_method"][value="woocommerce_payments"]'
			)
			.check();
		await fillPluginOwnedCardDetails( page, TEST_CARDS.genericDecline );

		let declineSubmissionCount = 0;
		const onDeclineRequest = ( request: Request ) => {
			if ( request.method() !== 'POST' ) {
				return;
			}
			const params = new URLSearchParams( request.postData() ?? '' );
			if ( params.get( 'payment_method' ) === 'woocommerce_payments' ) {
				declineSubmissionCount++;
			}
		};
		page.on( 'request', onDeclineRequest );
		try {
			await page.getByRole( 'button', { name: /place order/i } ).click();
			const notice = page
				.getByRole( 'alert' )
				.filter( { hasText: DECLINE_NOTICE } );
			await expect( notice ).toBeVisible( { timeout: 30_000 } );
		} finally {
			page.off( 'request', onDeclineRequest );
		}
		expect(
			declineSubmissionCount,
			'the decline must be submitted exactly once'
		).toBe( 1 );

		const orders = (
			await restApi.get(
				`${ ORDERS_ROUTE }?customer=${ customerId }&status=any`
			)
		).data as Array< { id: number } >;
		expect(
			orders,
			'the decline must create exactly one order for this run customer'
		).toHaveLength( 1 );
		const orderId = orders[ 0 ].id;
		let order = await readOrder( restApi, orderId );
		expect( order.status ).toBe( 'failed' );
		expect( order.currency ).toBe( 'USD' );
		expect( order.payment_method ).toBe( 'woocommerce_payments' );
		expect( order.total ).toBe( '10.01' );
		expect( order.line_items ).toHaveLength( 1 );
		const declinedLineItem = (
			order.line_items as Array< {
				product_id: number;
				quantity: number;
			} >
		 )[ 0 ];
		expect( declinedLineItem.product_id ).toBe( product.id );
		expect( declinedLineItem.quantity ).toBe( 1 );

		const declineNotes = (
			await restApi.get( `${ ORDERS_ROUTE }/${ orderId }/notes` )
		).data as Array< { note: string } >;
		expect(
			declineNotes.some( ( { note } ) => /declin/i.test( note ) ),
			'the failed order must carry at least one failure note'
		).toBe( true );

		const productAfterDecline = (
			await restApi.get( `${ PRODUCTS_ROUTE }/${ product.id }` )
		).data as { stock_quantity: number };
		expect(
			productAfterDecline.stock_quantity,
			'a declined payment must not reduce stock'
		).toBe( 1 );

		const declinedIntentId = orderMeta( order, '_intent_id' );
		expect( declinedIntentId ).toMatch( /^pi_/ );
		const declinedIntent = await getPaymentIntent(
			restApi,
			declinedIntentId
		);
		expect( declinedIntent.status ).toBe( 'requires_payment_method' );

		// The REST mirror nests the intent's sole charge as a singular
		// `charge` object (not Stripe's raw `charges.data[]` list).
		const declinedChargeSummary = declinedIntent.charge as
			| { id?: unknown }
			| undefined;
		expect(
			typeof declinedChargeSummary?.id === 'string',
			'the declined intent must carry exactly one charge'
		).toBe( true );
		const declinedCharge = await getCharge(
			restApi,
			String( declinedChargeSummary!.id )
		);
		expect( declinedCharge.status ).toBe( 'failed' );
		expect( declinedCharge.captured ).toBe( false );
		expect(
			orderMeta( order, '_charge_id' ),
			'a failed charge must not be recorded as the order charge'
		).toBe( '' );

		const orderKey = String( order.order_key );
		const paymentUrl = String( order.payment_url );
		await page.context().clearCookies();

		// Cutover: admin one click, wait for native.
		await page.goto( 'wp-login.php' );
		await logIn( page, admin.username, admin.password, false );
		await page.goto( 'wp-admin/' );
		await page
			.getByRole( 'link', { name: 'Start the switch', exact: true } )
			.click( { timeout: 30_000 } );
		await expect
			.poll( () => readRuntimeOwner( restApi ), { timeout: 120_000 } )
			.toBe( 'builtin' );
		await page.context().clearCookies();

		// Native era: the same order's pay-for-order link, the fraud
		// prevention field only when the seeded account is eligible, one
		// successful payment landing back on the same order.
		await page.goto( 'wp-login.php' );
		await logIn( page, customer.username, customer.password, false );
		await page.goto( paymentUrl );
		await expect( page ).toHaveURL( paymentUrl );
		await expect( page ).toHaveTitle( /Pay for order/i );
		const totalCell = page
			.locator( '#order_review tfoot tr' )
			.filter( {
				has: page.getByRole( 'rowheader', { name: /^Total:/i } ),
			} )
			.locator( 'td.product-total' );
		await expect( totalCell ).toHaveText( '$10.01' );

		const renderedToken = await page.evaluate(
			() =>
				( window as unknown as Record< string, unknown > )
					.wcpayFraudPreventionToken
		);
		// The store's own session, read server-side, must agree with what the
		// page rendered (R6, DISPOSITION row 22's "exact session/rendered/
		// submitted CTP digest equality").
		const sessionToken = await wpEvalJson< string | null >( `
			$handler = new WC_Session_Handler();
			$session_data = $handler->get_session( ${ customerId }, array() );
			$value = is_array( $session_data ) && isset( $session_data['wcpay-fraud-prevention-token'] )
				? maybe_unserialize( $session_data['wcpay-fraud-prevention-token'] )
				: null;
			return $value;
		` );
		if ( protectionOn ) {
			expect(
				typeof renderedToken === 'string' && renderedToken !== '',
				'card-testing protection on must render a fraud-prevention token'
			).toBe( true );
			expect(
				renderedToken as string,
				'the rendered fraud-prevention token must be 16 characters'
			).toHaveLength( 16 );
			expect(
				sessionToken,
				'the session must hold the exact same token the page rendered'
			).toBe( renderedToken );
		} else {
			expect(
				renderedToken ?? '',
				'card-testing protection off must render no fraud-prevention token'
			).toBe( '' );
			expect(
				sessionToken,
				'card-testing protection off must hold no session token'
			).toBeNull();
		}

		// When the gateway offers a saved-vs-new-card choice, "Use a new
		// payment method" must be selected before the card fields are wired
		// to the submission.
		const newMethodRadio = page.locator(
			'input[name="wc-woocommerce_payments-payment-token"][value="new"]'
		);
		if ( ( await newMethodRadio.count() ) > 0 ) {
			await newMethodRadio.check();
		}
		await fillCardDetails( page, TEST_CARDS.basic, 'classic' );

		let submittedToken: string | null = null;
		let payForOrderSubmissionCount = 0;
		const onRequest = ( request: Request ) => {
			if ( request.method() !== 'POST' ) {
				return;
			}
			const params = new URLSearchParams( request.postData() ?? '' );
			if ( ! params.has( 'payment_method' ) ) {
				return;
			}
			payForOrderSubmissionCount++;
			submittedToken = params.get( 'wcpay-fraud-prevention-token' );
		};
		page.on( 'request', onRequest );
		try {
			// A plain .click() on this button can be swallowed by the
			// payment element's out-of-process iframe; focus and press
			// Enter instead, the proven workaround on this store.
			const payButton = page.getByRole( 'button', {
				name: /pay for order/i,
			} );
			await payButton.focus();
			await payButton.press( 'Enter' );
			await page.waitForURL( /\/order-received\/[1-9]\d*/, {
				timeout: 60_000,
			} );
		} finally {
			page.off( 'request', onRequest );
		}
		expect(
			payForOrderSubmissionCount,
			'the pay-for-order form must submit exactly once'
		).toBe( 1 );
		if ( protectionOn ) {
			expect(
				submittedToken,
				'the admitted submission must carry the exact rendered token'
			).toBe( renderedToken );
		} else {
			expect(
				submittedToken,
				'card-testing protection off must submit no token'
			).toBeFalsy();
		}

		const finishedOrderId = Number(
			/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
		);
		expect( finishedOrderId ).toBe( orderId );
		order = await readOrder( restApi, orderId );
		expect( String( order.order_key ) ).toBe( orderKey );
		expect( order.customer_id ).toBe( customerId );
		expect( [ 'processing', 'completed' ] ).toContain( order.status );

		const payment = await expectSettledCardPayment( restApi, orderId, {
			amountMinor: 1001,
			currency: 'USD',
		} );
		// The successful payment must be a genuinely new provider attempt,
		// not a replay of the declined one (R5, HEAD driver :739-742).
		const declinedPaymentMethodId = String(
			(
				declinedIntent.last_payment_error as
					| { payment_method?: { id?: unknown } }
					| undefined
			 )?.payment_method?.id ??
				declinedIntent.payment_method ??
				''
		);
		expect( payment.intentId ).not.toBe( declinedIntentId );
		expect( payment.paymentMethodId ).not.toBe( declinedPaymentMethodId );
	}
}

for ( const [ title, contractId, protectionOn ] of [
	[
		'plugin-origin failed order pays in place after cutover with card-testing protection disabled',
		CONTRACT_DISABLED,
		false,
	],
	[
		'plugin-origin failed order pays in place after cutover with card-testing protection enabled',
		CONTRACT_ENABLED,
		true,
	],
] as const ) {
	test.describe( `WooPayments transition: historical money records (protection ${
		protectionOn ? 'on' : 'off'
	})`, () => {
		test.describe.configure( { timeout: 8 * 60_000 } );

		test.beforeEach( async () => {
			process.env.E2E_WP_ENV_CONFIG ??=
				'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
			await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
			const identity = JSON.parse(
				(
					await wpCLI( [
						'wp',
						'woopayments-e2e-transition',
						'seed',
						'historical-money-records',
						'--version=11.1.0',
						`--ctp=${ protectionOn ? 'on' : 'off' }`,
					] )
				).stdout
					.trim()
					.split( '\n' )
					.pop()!
			);
			expect( identity.plugin_version ).toBe( '11.1.0' );
			expect( identity.plugin_active ).toBe( true );
			expect( identity.is_live ).toBe( false );
			// The seed does not own this profile's classic-checkout page
			// (its spec makes its own, per D6 4a); the plugin-era decline
			// below needs it before it can navigate there.
			await createClassicCheckoutPage();
		} );

		test.afterEach( async () => {
			await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		} );

		test(
			title,
			{
				annotation: [
					{ type: 'woopayments-contract', description: contractId },
				],
				tag: FAMILY_TAGS,
			},
			async ( { page, restApi } ) => {
				await runHistoricalMoneyRecords( page, restApi, protectionOn );
			}
		);
	} );
}
