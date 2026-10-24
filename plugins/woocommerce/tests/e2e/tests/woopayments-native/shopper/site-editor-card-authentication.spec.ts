import type { Page } from '@playwright/test';
import type { ApiClient } from '@woocommerce/e2e-utils-playwright';

import { expect, tags, test } from '../../../fixtures/fixtures';
import { wpEvalJson } from '../../../utils/cli';
import { random } from '../../../utils/helpers';
import {
	expectSettledCardPayment,
	fillCardDetails,
	requireTestModeAccount,
	TEST_CARDS,
} from '../../../utils/woopayments';

/**
 * Proves the mandatory 3DS challenge stays a real, operable overlay under a
 * supported Site Editor (block) theme, both with and without card-testing
 * protection (T.4 Batch P4a rewrite).
 *
 * The distinguishing residual this family exists for is theme-specific
 * overlay/focus/stacking behaviour, not 3DS or card-testing protection in the
 * abstract - both are already proven elsewhere. So every case here keeps the
 * three checks a generic completed-challenge smoke would not: the challenge
 * iframe is topmost at its own interior midpoint (not merely `visible`,
 * which a fully-obscured element also satisfies), the shopper can reach and
 * activate its Complete action by keyboard alone, and the block-theme body
 * class survives the modal opening rather than only being true before it.
 */

const CONTRACT_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:95::Successful purchase, site builder theme › card prevention: false › 3DS card';
const CONTRACT_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:95::Successful purchase, site builder theme › card prevention: true › 3DS card';

const PRICE = '10.99';
const AMOUNT_MINOR = 1099;
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const THEME_STYLESHEET = 'twentytwentyfour';
const THEME_NAME = 'Twenty Twenty-Four';
const BLOCK_THEME_BODY_CLASS = 'woocommerce-uses-block-theme';
const OUTER_CHALLENGE_FRAME =
	'body > div > iframe[name^="__privateStripeFrame"]';
const NESTED_CHALLENGE_FRAME = 'iframe[name="stripe-challenge-frame"]';
const LOADING_INDICATOR = '.LightboxModalLoadingIndicator';
const CHALLENGE_FRAME_TIMEOUT_MS = 20_000;
const CHALLENGE_SETTLE_TIMEOUT_MS = 20_000;
const RECEIPT_TIMEOUT_MS = 60_000;

const PRODUCTS_ROUTE = 'wc/v3/products';

async function createRunProduct(
	restApi: ApiClient
): Promise< { id: number } > {
	return (
		await restApi.post( PRODUCTS_ROUTE, {
			name: `WooPayments site-editor 3DS ${ random() }`,
			type: 'simple',
			virtual: true,
			regular_price: PRICE,
			status: 'publish',
		} )
	).data as { id: number };
}

async function deleteProduct(
	restApi: ApiClient,
	productId: number
): Promise< void > {
	await restApi.delete( `${ PRODUCTS_ROUTE }/${ productId }`, {
		force: true,
	} );
}

async function readCardTestingProtectionEligibility(
	restApi: ApiClient
): Promise< unknown > {
	const response = await restApi.get( 'wc/v3/payments/accounts' );
	return ( response.data as { card_testing_protection_eligible?: unknown } )
		.card_testing_protection_eligible;
}

interface PaidOrder {
	id: unknown;
	status: unknown;
	total: unknown;
	currency: unknown;
	payment_method: unknown;
	transaction_id: unknown;
}

async function readOrder(
	restApi: ApiClient,
	orderId: number
): Promise< PaidOrder > {
	return ( await restApi.get( `wc/v3/orders/${ orderId }` ) )
		.data as PaidOrder;
}

async function expectSiteEditorBody( page: Page ): Promise< void > {
	await expect(
		page.locator(
			`body.${ BLOCK_THEME_BODY_CLASS }.wp-theme-${ THEME_STYLESHEET }`
		),
		'the checkout must render through the activated block theme'
	).toHaveCount( 1 );
}

/**
 * Reads the effective token through the same compatibility order as Core's
 * Blocks payment method: the client-era global first, then native settings.
 */
async function expectFraudPreventionToken(
	page: Page,
	toBeDefined: boolean
): Promise< void > {
	const token = await page.evaluate( () => {
		const browserWindow = window as Window & {
			wcpayFraudPreventionToken?: unknown;
			wcSettings?: {
				paymentMethodData?: Record< string, unknown >;
			};
		};
		const settings = browserWindow.wcSettings?.paymentMethodData
			?.woocommerce_payments as
			| { fraudPreventionToken?: unknown }
			| undefined;
		return (
			browserWindow.wcpayFraudPreventionToken ??
			settings?.fraudPreventionToken
		);
	} );
	if ( toBeDefined ) {
		expect( token ).toEqual( expect.any( String ) );
		expect( token ).not.toBe( '' );
		return;
	}
	expect( token ?? '' ).toBe( '' );
}

/**
 * Whether the store's cached account data carries the
 * `card_testing_protection_eligible` key at all, and its raw value when it
 * does. Absence, an explicit `null`, and `false` are three different stored
 * shapes, and only carrying all three lets a restore put back exactly what
 * was there instead of collapsing "the key was never set" into "the key was
 * set to null".
 */
interface CardTestingProtectionFlag {
	existed: boolean;
	value: unknown;
}

const READ_CARD_TESTING_PROTECTION_FLAG_PHP = `
	$account = get_option( 'wcpay_account_data', array() );
	if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
		throw new RuntimeException( 'wcpay_account_data has no cached data to read.' );
	}
	$existed = array_key_exists( 'card_testing_protection_eligible', $account['data'] );
	return array(
		'existed' => $existed,
		'value' => $existed ? $account['data']['card_testing_protection_eligible'] : null,
	);
`;

/** Reads the raw stored flag without changing it, for an exact post-restore comparison. */
async function readCardTestingProtectionFlag(): Promise< CardTestingProtectionFlag > {
	return wpEvalJson< CardTestingProtectionFlag >(
		READ_CARD_TESTING_PROTECTION_FLAG_PHP
	);
}

/**
 * Forces (or restores) the account's card-testing-protection eligibility by
 * writing the store's own cached mirror of the account flag directly,
 * through `wpEvalJson` against the run's own wp-env config
 * (`E2E_WP_ENV_CONFIG`). Returns the prior key presence and value, for an
 * exact restore. The same pattern `shopper/provider-fidelity-basic-card.spec.ts`
 * (T.4 Batch P1) uses for the same flag.
 */
async function setCardTestingProtectionEligible(
	eligible: boolean
): Promise< CardTestingProtectionFlag > {
	return wpEvalJson< CardTestingProtectionFlag >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to force.' );
		}
		$existed = array_key_exists( 'card_testing_protection_eligible', $account['data'] );
		$original = $existed ? $account['data']['card_testing_protection_eligible'] : null;
		$account['data']['card_testing_protection_eligible'] = ${
			eligible ? 'true' : 'false'
		};
		update_option( 'wcpay_account_data', $account );
		return array( 'existed' => $existed, 'value' => $original );
	` );
}

/** Restores the flag to exactly the shape `setCardTestingProtectionEligible` read: present or absent. */
async function restoreCardTestingProtectionEligible(
	original: CardTestingProtectionFlag
): Promise< void > {
	await wpEvalJson< unknown >( `
		$account = get_option( 'wcpay_account_data', array() );
		if ( ! is_array( $account ) || ! is_array( $account['data'] ?? null ) ) {
			throw new RuntimeException( 'wcpay_account_data has no cached data to restore.' );
		}
		if ( ${ original.existed ? 'true' : 'false' } ) {
			$account['data']['card_testing_protection_eligible'] = json_decode( '${ JSON.stringify(
				original.value ?? null
			) }' );
		} else {
			unset( $account['data']['card_testing_protection_eligible'] );
		}
		update_option( 'wcpay_account_data', $account );
		return true;
	` );
}

interface ActiveTheme {
	stylesheet: string;
	template: string;
	name: string;
	isBlockTheme: boolean;
}

async function readActiveTheme(): Promise< ActiveTheme > {
	return wpEvalJson< ActiveTheme >( `
		$theme = wp_get_theme();
		return array(
			'stylesheet' => $theme->get_stylesheet(),
			'template' => $theme->get_template(),
			'name' => (string) $theme->get( 'Name' ),
			'isBlockTheme' => function_exists( 'wp_is_block_theme' ) ? (bool) wp_is_block_theme() : false,
		);
	` );
}

async function activateTheme( stylesheet: string ): Promise< void > {
	await wpEvalJson< boolean >( `
		switch_theme( ${ JSON.stringify( stylesheet ) } );
		return true;
	` );
}

/**
 * Runs `callback` with `stylesheet` active, and returns the store to the
 * theme it was on with a verified read - the same snapshot/activate/restore
 * contract the retired `drivers/block-theme.ts` enforced, minus its
 * lock/quarantine plumbing. Refuses to run when the target theme is already
 * active: a store found there means an earlier run did not restore it, and
 * adopting that as the baseline would launder an unrestored store into a
 * passing run.
 */
async function withActiveTheme< Result >(
	stylesheet: string,
	callback: ( active: ActiveTheme ) => Promise< Result >
): Promise< Result > {
	const baseline = await readActiveTheme();
	if ( baseline.stylesheet === stylesheet ) {
		throw new Error(
			`Theme ${ stylesheet } is already active; an earlier run may not have restored it.`
		);
	}

	// Everything from here on may have changed the store's theme, so every
	// path - including a verification throw - must still reach the restore
	// below. An activation that lands but then fails verification (a missing
	// theme, or one that turns out not to be a block theme) must not skip it.
	let result: Result | undefined;
	let primaryError: unknown;
	try {
		await activateTheme( stylesheet );
		const active = await readActiveTheme();
		if (
			active.stylesheet !== stylesheet ||
			active.template !== stylesheet
		) {
			throw new Error(
				`Theme activation did not take for ${ stylesheet }; the store reports ${ active.stylesheet }.`
			);
		}
		if ( active.isBlockTheme === false ) {
			throw new Error(
				`Activated ${ stylesheet }, which WordPress reports is not a block theme.`
			);
		}
		result = await callback( active );
	} catch ( error ) {
		primaryError = error;
	}

	let restorationError: unknown;
	try {
		await activateTheme( baseline.stylesheet );
		const restored = await readActiveTheme();
		if (
			restored.stylesheet !== baseline.stylesheet ||
			restored.template !== baseline.template ||
			restored.name !== baseline.name ||
			restored.isBlockTheme !== baseline.isBlockTheme
		) {
			throw new Error(
				`The store came back on ${ restored.stylesheet }, not ${ baseline.stylesheet }.`
			);
		}
	} catch ( error ) {
		restorationError = error;
	}

	if ( primaryError !== undefined ) {
		if ( restorationError !== undefined ) {
			console.error(
				'WooPayments block-theme restoration failed after the primary case failure:',
				restorationError
			);
		}
		throw primaryError;
	}
	if ( restorationError !== undefined ) {
		throw restorationError;
	}
	return result as Result;
}

/**
 * Answers the site-editor 3DS challenge and proves it stayed topmost and
 * keyboard-operable, on top of the base proof `utils/woopayments.ts`'s
 * `completeThreeDSChallenge` gives every other case: that a challenge was
 * actually presented, ready, and dismissed after being answered. Kept
 * spec-local, not folded into the shared helper, because no other retained
 * case needs the stacking/focus checks (D4 keeps the helper to exports every
 * case uses).
 */
async function completeSiteEditorChallenge( page: Page ): Promise< void > {
	await page
		.locator( OUTER_CHALLENGE_FRAME )
		.waitFor( { state: 'visible', timeout: CHALLENGE_FRAME_TIMEOUT_MS } );
	const outer = page.frameLocator( OUTER_CHALLENGE_FRAME );
	const challengeBody = outer
		.frameLocator( NESTED_CHALLENGE_FRAME )
		.locator( 'body' );
	await challengeBody.waitFor( {
		state: 'visible',
		timeout: CHALLENGE_FRAME_TIMEOUT_MS,
	} );
	await outer
		.locator( LOADING_INDICATOR )
		.waitFor( { state: 'hidden', timeout: CHALLENGE_SETTLE_TIMEOUT_MS } )
		.catch( () => undefined );

	// The exact theme/body proof must survive the modal opening, not merely
	// have been true on the checkout before the provider took focus.
	await expectSiteEditorBody( page );
	const outerFrame = page.locator( OUTER_CHALLENGE_FRAME );
	await expect( outerFrame ).toBeVisible();
	await expect(
		challengeBody,
		'the nested mandatory challenge must be visible'
	).toBeVisible();

	expect(
		await outerFrame.evaluate( ( frame ) => {
			const rect = frame.getBoundingClientRect();
			if ( rect.width <= 2 || rect.height <= 2 ) {
				return false;
			}
			const x = rect.left + rect.width / 2;
			const y = rect.top + rect.height / 2;
			return document.elementFromPoint( x, y ) === frame;
		} ),
		'the provider iframe must be topmost at its interior midpoint'
	).toBe( true );

	const complete = outer
		.frameLocator( NESTED_CHALLENGE_FRAME )
		.getByRole( 'button', { name: 'Complete', exact: true } );
	await complete.focus();
	await expect(
		complete,
		'the Complete action must receive keyboard focus'
	).toBeFocused();
	await complete.press( 'Enter' );

	await challengeBody.waitFor( {
		state: 'hidden',
		timeout: CHALLENGE_SETTLE_TIMEOUT_MS,
	} );
}

/** Blocks checkout address fill for a guest shopper. */
async function fillBlocksCheckoutAddress(
	page: Page,
	email: string
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const address = ( await shipping.isVisible() ) ? shipping : billing;

	await page.getByRole( 'textbox', { name: 'Email address' } ).fill( email );
	await address
		.getByRole( 'combobox', { name: 'Country/Region' } )
		.selectOption( 'US' );
	await address.getByRole( 'textbox', { name: 'First name' } ).fill( 'E2E' );
	await address
		.getByRole( 'textbox', { name: 'Last name' } )
		.fill( 'WooPayments' );
	await address
		.getByRole( 'textbox', { name: 'Address', exact: true } )
		.fill( '123 Test Street' );
	await address
		.getByRole( 'textbox', { name: 'City', exact: true } )
		.fill( 'San Francisco' );
	await address
		.getByRole( 'combobox', { name: 'State', exact: true } )
		.selectOption( 'CA' );
	await address.getByRole( 'textbox', { name: 'ZIP Code' } ).fill( '94107' );
	await address
		.getByRole( 'textbox', { name: 'Phone (optional)' } )
		.fill( '5555550100' );
}

async function openCheckout(
	page: Page,
	product: { id: number }
): Promise< void > {
	await page.goto( `?post_type=product&p=${ product.id }` );
	await page
		.getByRole( 'button', { name: 'Add to cart', exact: true } )
		.click();
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutAddress(
		page,
		`woopayments-${ random() }@example.com`
	);
	await expectSiteEditorBody( page );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
	await fillCardDetails( page, TEST_CARDS.threeDSChallenge, 'blocks' );
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

async function completePurchase(
	page: Page,
	product: { id: number },
	toBeCardTestingProtected: boolean
): Promise< number > {
	await openCheckout( page, product );
	await expectFraudPreventionToken( page, toBeCardTestingProtected );

	await page.getByRole( 'button', { name: /place order/i } ).click();
	await completeSiteEditorChallenge( page );
	await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
		timeout: RECEIPT_TIMEOUT_MS,
	} );

	const orderId = Number( /order-received\/(\d+)/.exec( page.url() )?.[ 1 ] );
	if ( ! Number.isSafeInteger( orderId ) || orderId <= 0 ) {
		throw new Error(
			`The Site Editor checkout receipt URL carried no exact order identity: ${ page.url() }.`
		);
	}
	return orderId;
}

async function expectPaidOrder(
	restApi: ApiClient,
	page: Page,
	orderId: number
): Promise< void > {
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i ),
		'the completed challenge must reach the receipt'
	).toBeVisible();

	const order = await readOrder( restApi, orderId );
	expect( order.id ).toBe( orderId );
	expect( PAID_ORDER_STATUSES ).toContain( order.status );
	expect( order.total ).toBe( PRICE );
	expect( order.currency ).toBe( CURRENCY );
	expect( order.payment_method ).toBe( 'woocommerce_payments' );
	expect(
		order.transaction_id,
		'the paid WooPayments order must carry its provider transaction ID'
	).toEqual( expect.stringMatching( /^(?:pi_|ch_|py_)/ ) );

	await expectSettledCardPayment( restApi, orderId, {
		amountMinor: AMOUNT_MINOR,
		currency: CURRENCY,
	} );
}

async function runUnderTwentyTwentyFour(
	page: Page,
	restApi: ApiClient,
	toBeCardTestingProtected: boolean
): Promise< void > {
	await withActiveTheme( THEME_STYLESHEET, async ( active ) => {
		expect( active.stylesheet ).toBe( THEME_STYLESHEET );
		expect( active.template ).toBe( THEME_STYLESHEET );
		expect( active.name ).toBe( THEME_NAME );
		expect( active.isBlockTheme ).not.toBe( false );

		const product = await createRunProduct( restApi );
		try {
			const orderId = await completePurchase(
				page,
				product,
				toBeCardTestingProtected
			);
			await expectPaidOrder( restApi, page, orderId );
		} finally {
			await deleteProduct( restApi, product.id );
		}
	} );
}

test.describe(
	'WooPayments native Site Editor card authentication',
	{ tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ] },
	() => {
		// Provider routing enforces one worker and zero retries. Serial mode also
		// prevents a second global-theme mutation after an unclear first case.
		test.describe.configure( { mode: 'serial', timeout: 300_000 } );

		test.beforeAll( async ( { restApi } ) => {
			await requireTestModeAccount( restApi );
		} );

		test(
			'a mandatory 3DS challenge remains topmost, keyboard-operable, and pays the exact order under Twenty Twenty-Four with card-testing protection off',
			{
				annotation: [
					{
						type: 'woopayments-contract',
						description: CONTRACT_PROTECTION_FALSE,
					},
				],
			},
			async ( { page, restApi } ) => {
				expect(
					await readCardTestingProtectionEligibility( restApi ),
					'the protection-off smoke requires strict effective false'
				).toBe( false );
				await runUnderTwentyTwentyFour( page, restApi, false );
			}
		);

		test(
			'card-testing protection admits a token-bearing mandatory 3DS challenge that remains topmost, keyboard-operable, and pays the exact order under Twenty Twenty-Four',
			{
				annotation: [
					{
						type: 'woopayments-contract',
						description: CONTRACT_PROTECTION_TRUE,
					},
				],
			},
			async ( { page, restApi } ) => {
				// FORCED ELIGIBILITY BOUNDARY: the target account normally reports
				// false. This forces native's local premise, byte-restores it, and
				// verifies restoration. This proves native's protected path, not a
				// provider grant to this account.
				let original: CardTestingProtectionFlag | undefined;
				let caseError: unknown;
				try {
					original = await setCardTestingProtectionEligible( true );
					expect(
						await readCardTestingProtectionEligibility( restApi ),
						'the forced protection premise must be strictly effective'
					).toBe( true );
					await runUnderTwentyTwentyFour( page, restApi, true );
				} catch ( error ) {
					caseError = error;
				} finally {
					if ( original !== undefined ) {
						try {
							await restoreCardTestingProtectionEligible(
								original
							);
							expect(
								await readCardTestingProtectionFlag()
							).toEqual( original );
						} catch ( restoreError ) {
							if ( caseError === undefined ) {
								caseError = restoreError;
							} else {
								console.error(
									'WooPayments card-testing-protection restore failed after the primary case failure:',
									restoreError
								);
							}
						}
					}
				}
				if ( caseError !== undefined ) {
					throw caseError;
				}
			}
		);
	}
);
