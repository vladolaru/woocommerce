import type { ApiClient } from '@woocommerce/e2e-utils-playwright';
import type { Locator, Page } from '@playwright/test';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpCLI, wpEvalJson } from '../../../utils/cli';
import { admin } from '../../../test-data/data';
import { getFakeUser } from '../../../utils/data';
import { logIn } from '../../../utils/login';
import { random } from '../../../utils/helpers';
import {
	expectSettledCardPayment,
	requireTestModeAccount,
	TEST_CARDS,
	type ProviderTestCard,
} from '../../../utils/woopayments';

/**
 * The 11.1.0 plugin's own Payment Element container
 * (`class-wc-payment-gateway-wcpay.php:611`, `.wcpay-upe-element`), used for
 * every plugin-owned card entry below. Native's `#wcpay-core-payment-element`
 * carries the same class too, so this selector does not tell the runtimes
 * apart; the spec asserts plugin ownership separately before every
 * plugin-era write. The entry stays spec-local rather than routed through
 * `utils/woopayments.ts`'s `fillCardDetails`. It re-fills and reads back
 * the number/expiry/CVC (the same defect `fillCardDetails` guards: the
 * element clears its fields when a deferred `elements/sessions` response
 * lands after typing already finished), then fills the optional
 * country/postcode pair the account's billing-details collection may add.
 */
async function fillPluginOwnedCardDetails(
	page: Page,
	card: ProviderTestCard
): Promise< void > {
	const frame = page.frameLocator(
		'.wcpay-upe-element[data-payment-method-type="card"] iframe[name^="__privateStripeFrame"]'
	);
	const fields: Array< { locator: Locator; value: string } > = [
		{
			locator: frame.getByRole( 'textbox', { name: 'Card number' } ),
			value: card.number,
		},
		{
			locator: frame.getByRole( 'textbox', { name: /^Expir/i } ),
			value: card.expiry,
		},
		{
			locator: frame.getByRole( 'textbox', {
				name: 'Security code',
			} ),
			value: card.cvc,
		},
	];
	let kept = false;
	for ( let attempt = 1; attempt <= 3 && ! kept; attempt++ ) {
		for ( const field of fields ) {
			await field.locator.fill( field.value, { timeout: 5_000 } );
		}
		// Waits for the deferred `elements/sessions` clear to have happened
		// (or not) instead of always idling the same fixed span: checks
		// immediately, then re-checks every 100ms up to a 1.5s budget.
		const deadline = Date.now() + 1_500;
		do {
			kept = true;
			for ( const field of fields ) {
				const value = ( await field.locator.inputValue() ).replace(
					/\D/g,
					''
				);
				if ( value !== field.value.replace( /\D/g, '' ) ) {
					kept = false;
				}
			}
			if ( ! kept && Date.now() < deadline ) {
				await new Promise( ( resolve ) => setTimeout( resolve, 100 ) );
			}
		} while ( ! kept && Date.now() < deadline );
	}
	if ( ! kept ) {
		throw new Error(
			'The plugin-era payment element kept no complete card after 3 entries.'
		);
	}
	// The element renders an inline country/postcode pair when the account's
	// Stripe billing-details collection asks for it; only fill it when shown.
	const country = frame.getByRole( 'combobox', { name: /country/i } );
	if ( ( await country.count() ) > 0 && ( await country.isVisible() ) ) {
		await country.selectOption( 'US' );
	}
	const postcode = frame.getByRole( 'textbox', {
		name: /^(?:ZIP|Postal code)/i,
	} );
	if ( ( await postcode.count() ) > 0 && ( await postcode.isVisible() ) ) {
		await postcode.fill( '94107' );
	}
}

/**
 * The transition `historical-tokens` row (T.4 Batch T rewrite, D6): a saved
 * card created by the real plugin before cutover stays the shopper's default
 * payment method, and pays, after native takes over. Seeded through the
 * transition-seed plugin's `historical-tokens` profile (11.1.0, the version
 * this row's own plan requires).
 */

const CONTRACT_ID =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-myaccount-saved-cards.spec.ts:214::Shopper can save and delete cards › Testing card: basic › should be able to set the basic card as default payment method';

const ADD_FORM = '#add_payment_method';
const ADD_SUCCESS_NOTICE = 'Payment method successfully added.';
const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const ORDERS_ROUTE = 'wc/v3/orders';
const PRODUCTS_ROUTE = 'wc/v3/products';

interface SavedCardToken {
	tokenId: number;
	paymentMethodId: string;
}

interface DefaultTokenState {
	tokenId: number;
	paymentMethodId: string;
}

interface TokenWithDefaultFlag {
	tokenId: number;
	paymentMethodId: string;
	isDefault: boolean;
}

async function addPluginOwnedCard(
	page: Page,
	customerId: number,
	expectedCount: number
): Promise< SavedCardToken[] > {
	await page.goto( 'my-account/payment-methods/' );
	await page.getByRole( 'link', { name: /add payment method/i } ).click();
	await expect( page.locator( ADD_FORM ) ).toBeVisible();
	await fillPluginOwnedCardDetails( page, TEST_CARDS.basic );
	await page
		.getByRole( 'button', { name: 'Add payment method', exact: true } )
		.click();
	await expect(
		page.getByText( ADD_SUCCESS_NOTICE, { exact: true } )
	).toBeVisible( { timeout: 45_000 } );

	// wpEvalJson runs as the CLI's own user (admin), never the browser's
	// logged-in customer, so the tokens must be looked up by the known
	// customer id rather than get_current_user_id().
	// Sorted by token id so the newest add is unambiguous, since
	// get_customer_tokens() does not guarantee insertion order.
	const rows = await wpEvalJson< SavedCardToken[] >( `
		$tokens = WC_Payment_Tokens::get_customer_tokens( ${ customerId } );
		$rows = array_map( function ( $token ) {
			return array( 'tokenId' => $token->get_id(), 'paymentMethodId' => $token->get_token() );
		}, $tokens );
		usort( $rows, function ( $a, $b ) {
			return $a['tokenId'] <=> $b['tokenId'];
		} );
		return array_values( $rows );
	` );
	expect(
		rows,
		`adding a card must leave exactly ${ expectedCount } token(s) for this customer`
	).toHaveLength( expectedCount );
	return rows;
}

/**
 * Reads the default token straight from the `woocommerce_payment_tokens`
 * table's `is_default` column, bypassing
 * `WC_Payment_Tokens::get_customer_tokens()`.
 *
 * Observed on this store (F-TOKENREAD, T.7 Step 4): at 22:15 UTC, the
 * filtered `get_customer_tokens()` read (through `wpEvalJson`, WP-CLI,
 * `--user=1`) returned both of the customer's tokens with `is_default`
 * false. At 22:21 UTC, the raw `is_default` column showed `1` on the
 * second token immediately before that same filtered read, and the
 * filtered read then threw "No default token found" immediately after.
 * The browser checkout below still preselected that exact card. This
 * probe reads the column directly; the checkout radio check below carries
 * the row.
 */
async function readDefaultToken(
	customerId: number
): Promise< DefaultTokenState > {
	return wpEvalJson< DefaultTokenState >( `
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare(
			"SELECT token_id, token FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d AND is_default = 1",
			${ customerId }
		) );
		if ( ! $row ) {
			throw new RuntimeException( 'No default token found.' );
		}
		return array( 'tokenId' => (int) $row->token_id, 'paymentMethodId' => $row->token );
	` );
}

/**
 * Reads every one of the customer's tokens with each row's real
 * `is_default` column value, so the post-cutover default assertion can
 * actually fail (R3): `readDefaultToken`'s own WHERE clause already
 * filters to `is_default = 1`, so its `isDefault` would only ever echo
 * back `true`.
 */
async function readAllTokens(
	customerId: number
): Promise< TokenWithDefaultFlag[] > {
	return wpEvalJson< TokenWithDefaultFlag[] >( `
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT token_id, token, is_default FROM {$wpdb->prefix}woocommerce_payment_tokens WHERE user_id = %d ORDER BY token_id ASC",
			${ customerId }
		) );
		return array_map( function ( $row ) {
			return array(
				'tokenId'         => (int) $row->token_id,
				'paymentMethodId' => $row->token,
				'isDefault'       => (bool) (int) $row->is_default,
			);
		}, $rows );
	` );
}

/**
 * Reads the WooPayments-provider payment method ids attached to this
 * customer's provider (Stripe) customer record, the store's own
 * `wc/v3/payments/customers/<id>/payment_methods` mirror (R3, HEAD
 * `saved-cards.ts:499-521`).
 */
async function readProviderPaymentMethodIds(
	restApi: ApiClient,
	customerId: number
): Promise< string[] > {
	const providerCustomerId = await wpEvalJson< string >( String.raw`
		$service = wc_get_container()->get( Automattic\WooCommerce\Internal\Payments\Providers\WooPayments\WooPaymentsCustomerService::class );
		return (string) $service->get_customer_id_by_user_id( ${ customerId } );
	` );
	const methods = (
		await restApi.get(
			`wc/v3/payments/customers/${ encodeURIComponent(
				providerCustomerId
			) }/payment_methods`
		)
	).data as Array< { id: string } >;
	return methods.map( ( method ) => method.id );
}

async function readRuntimeOwner( restApi: ApiClient ): Promise< string > {
	return String(
		( await restApi.get( 'wc-native-payments-e2e/v1/status' ) ).data
			.runtime_owner
	);
}

async function readHighestOrderId( restApi: ApiClient ): Promise< number > {
	const orders = (
		await restApi.get(
			`${ ORDERS_ROUTE }?orderby=id&order=desc&per_page=1&status=any`
		)
	).data as Array< { id: number } >;
	return orders[ 0 ]?.id ?? 0;
}

test.describe( 'WooPayments transition: historical tokens', () => {
	test.describe.configure( { timeout: 10 * 60_000 } );

	let customer: ReturnType< typeof getFakeUser >;
	let customerId: number;

	test.beforeAll( async ( { restApi } ) => {
		process.env.E2E_WP_ENV_CONFIG ??=
			'tests/e2e/test-plugins/woopayments-transition-seed/wp-env.json';
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
		const identity = JSON.parse(
			(
				await wpCLI( [
					'wp',
					'woopayments-e2e-transition',
					'seed',
					'historical-tokens',
					'--version=11.1.0',
				] )
			).stdout
				.trim()
				.split( '\n' )
				.pop()!
		);
		expect( identity.plugin_version ).toBe( '11.1.0' );
		expect( identity.plugin_active ).toBe( true );
		expect( identity.is_live ).toBe( false );

		customer = getFakeUser( 'customer' );
		const created = ( await restApi.post( 'wc/v3/customers', customer ) )
			.data as { id: number };
		customerId = created.id;
	} );

	test.afterAll( async () => {
		// No REST delete of `customerId` here: the `db reset` below removes
		// it along with everything else, so a delete call here would only
		// risk masking the case's own error.
		await wpCLI( [ 'wp', 'woopayments-e2e-transition', 'reset' ] );
	} );

	test(
		'plugin default saved method remains preselected after ephemeral native cutover',
		{
			annotation: [
				{ type: 'woopayments-contract', description: CONTRACT_ID },
			],
			tag: [
				tags.WOOPAYMENTS_NATIVE,
				tags.WOOPAYMENTS_PROVIDER,
				tags.WOOPAYMENTS_TRANSITION,
				tags.WOOPAYMENTS_PR,
			],
		},
		async ( { page, restApi } ) => {
			await requireTestModeAccount( restApi );
			expect(
				await readRuntimeOwner( restApi ),
				'the plugin must own runtime before its own plugin-era writes'
			).toBe( 'plugin' );

			await page.goto( 'wp-login.php' );
			await logIn( page, customer.username, customer.password, false );
			await page.goto( 'my-account/' );
			await expect(
				page.getByText( new RegExp( `Hello ${ customer.first_name }` ) )
			).toBeVisible();

			const [ firstCard ] = await addPluginOwnedCard(
				page,
				customerId,
				1
			);
			// The plugin's own My Account add-payment-method cooldown
			// (`class-wc-payment-gateway-wcpay.php:4589`,
			// `WC_Rate_Limiter::retried_too_soon`) must clear before a second
			// add from the same customer is admitted; expire it directly
			// rather than idling the run for real time.
			await wpEvalJson< true >( `
				WC_Rate_Limiter::set_rate_limit( 'add_payment_method_' . ${ customerId }, -1 );
				return true;
			` );
			const [ firstCardAfterSecondAdd, defaultCard ] =
				await addPluginOwnedCard( page, customerId, 2 );
			expect(
				firstCardAfterSecondAdd,
				"the first card's mapping must be unchanged by the second add"
			).toEqual( firstCard );
			expect( firstCard.tokenId ).not.toBe( defaultCard.tokenId );

			// Make the second card the default: My Account's per-row "Make
			// default" nonce-bound link (`wc-account-functions.php`), not a
			// form submission.
			await page.goto( 'my-account/payment-methods/' );
			await page
				.locator(
					`a[href*="/set-default-payment-method/${ defaultCard.tokenId }/"]`
				)
				.click();
			// The plugin-era default must take effect before cutover, or the
			// whole point of this row (the default surviving the switch) is
			// unproven from the start.
			await expect
				.poll(
					async () =>
						( await readDefaultToken( customerId ) ).tokenId,
					{ timeout: 15_000 }
				)
				.toBe( defaultCard.tokenId );

			// Cutover: admin one click, wait until native owns the store. The
			// customer session must end first, or a logged-in customer
			// visiting wp-login.php is bounced back to My Account instead of
			// reaching the login form.
			await page.context().clearCookies();
			await page.goto( 'wp-login.php' );
			await logIn( page, admin.username, admin.password, false );
			await page.goto( 'wp-admin/' );
			await page
				.getByRole( 'link', { name: 'Start the switch', exact: true } )
				.click( { timeout: 30_000 } );
			await expect
				.poll( () => readRuntimeOwner( restApi ), {
					timeout: 120_000,
				} )
				.toBe( 'native' );

			const nativeDefaultCard = await readDefaultToken( customerId );
			expect( nativeDefaultCard.tokenId ).toBe( defaultCard.tokenId );
			expect( nativeDefaultCard.paymentMethodId ).toBe(
				defaultCard.paymentMethodId
			);

			// Both tokens, and which one is default, with a check that can
			// actually fail (R3): each row's real is_default column value.
			const tokensAfterCutover = await readAllTokens( customerId );
			expect(
				tokensAfterCutover,
				'both tokens must survive cutover with the same mapping and default flag'
			).toEqual( [
				{
					tokenId: firstCard.tokenId,
					paymentMethodId: firstCard.paymentMethodId,
					isDefault: false,
				},
				{
					tokenId: defaultCard.tokenId,
					paymentMethodId: defaultCard.paymentMethodId,
					isDefault: true,
				},
			] );

			// The provider customer holds each saved card's payment method
			// exactly once (R3, HEAD saved-cards.ts:499-521).
			const providerPaymentMethodIds = await readProviderPaymentMethodIds(
				restApi,
				customerId
			);
			expect(
				providerPaymentMethodIds.filter(
					( id ) => id === firstCard.paymentMethodId
				),
				"the provider customer must hold the first card's payment method exactly once"
			).toHaveLength( 1 );
			expect(
				providerPaymentMethodIds.filter(
					( id ) => id === defaultCard.paymentMethodId
				),
				"the provider customer must hold the default card's payment method exactly once"
			).toHaveLength( 1 );

			// Native era: the customer's classic checkout preselects the
			// default card and pays with it.
			await page.context().clearCookies();
			await page.goto( 'wp-login.php' );
			await logIn( page, customer.username, customer.password, false );
			const product = (
				await restApi.post( PRODUCTS_ROUTE, {
					name: `WooPayments historical-tokens ${ random() }`,
					type: 'simple',
					virtual: true,
					regular_price: PRICE,
					status: 'publish',
				} )
			).data as { id: number };
			const baselineOrderId = await readHighestOrderId( restApi );
			await page.goto( `?post_type=product&p=${ product.id }` );
			await page
				.getByRole( 'button', { name: 'Add to cart', exact: true } )
				.click();
			await page.goto( 'classic-checkout/' );
			const savedRadio = page.locator(
				`input[name="wc-woocommerce_payments-payment-token"][value="${ defaultCard.tokenId }"]`
			);
			await expect( savedRadio ).toBeChecked();
			await page.getByRole( 'button', { name: /place order/i } ).click();
			await page.waitForURL( /\/order-received\/[1-9]\d*/, {
				timeout: 60_000,
			} );
			await expect(
				page.getByText( 'Your order has been received.' )
			).toBeVisible();
			const orderId = Number(
				/order-received\/(\d+)/.exec( page.url() )?.[ 1 ]
			);
			expect( orderId ).toBeGreaterThan( baselineOrderId );

			const orderRecord = (
				await restApi.get( `${ ORDERS_ROUTE }/${ orderId }` )
			).data as Record< string, unknown >;
			expect( [ 'processing', 'completed' ] ).toContain(
				orderRecord.status
			);

			const payment = await expectSettledCardPayment( restApi, orderId, {
				amountMinor: AMOUNT_MINOR,
				currency: CURRENCY,
			} );
			expect( payment.paymentMethodId ).toBe(
				defaultCard.paymentMethodId
			);
		}
	);
} );
