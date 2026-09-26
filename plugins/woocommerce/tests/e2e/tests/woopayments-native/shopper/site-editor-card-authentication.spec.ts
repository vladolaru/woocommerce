import type { Page } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import { withActiveBlockTheme } from '../../../utils/woopayments-native/drivers/block-theme';
import {
	completeCardAuthentication,
	PlaywrightCardAuthenticationBrowser,
	type CardAuthenticationBrowser,
	type CardAuthenticationEvidence,
	type ChallengeResponse,
} from '../../../utils/woopayments-native/drivers/card-authentication';
import { enterProviderCardTriple } from '../../../utils/woopayments-native/drivers/card-entry';
import {
	withCapturedCardTestingProtectionState,
	type CardTestingProtectionScope,
} from '../../../utils/woopayments-native/drivers/card-testing-protection';
import {
	fillBlocksCheckoutAddress,
	submitBlocksCheckout,
} from '../../../utils/woopayments-native/drivers/checkout';
import { THREE_DS_2_CARD } from '../../../utils/woopayments-native/test-cards';

const CONTRACT_PROTECTION_FALSE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:95::Successful purchase, site builder theme › card prevention: false › 3DS card';
const CONTRACT_PROTECTION_TRUE =
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase-site-editor.spec.ts:95::Successful purchase, site builder theme › card prevention: true › 3DS card';

const PRICE = '10.99';
const CURRENCY = 'USD';
const PAID_ORDER_STATUSES = [ 'processing', 'completed' ];
const THEME_STYLESHEET = 'twentytwentyfour';
const THEME_NAME = 'Twenty Twenty-Four';
const BLOCK_THEME_BODY_CLASS = 'woocommerce-uses-block-theme';
const OUTER_CHALLENGE_FRAME =
	'body > div > iframe[name^="__privateStripeFrame"]';
const NESTED_CHALLENGE_FRAME = 'iframe[name="stripe-challenge-frame"]';
const RECEIPT_TIMEOUT_MS = 60_000;

interface PaidOrder {
	id: unknown;
	status: unknown;
	total: unknown;
	currency: unknown;
	payment_method: unknown;
	transaction_id: unknown;
}

async function readCardTestingProtectionEligibility(
	session: ProviderWriteSession
): Promise< unknown > {
	const response = await session.adminApi.get(
		'/wp-json/wc/v3/payments/accounts'
	);
	if ( ! response.ok() ) {
		throw new Error(
			`WooPayments account read failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return (
		( await response.json() ) as {
			card_testing_protection_eligible?: unknown;
		}
	 ).card_testing_protection_eligible;
}

async function readOrder(
	session: ProviderWriteSession,
	orderId: number
): Promise< PaidOrder > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/orders/${ orderId }`
	);
	if ( ! response.ok() ) {
		throw new Error(
			`WooCommerce order ${ orderId } read failed: HTTP ${ response.status() } ${ await response.text() }`
		);
	}
	return ( await response.json() ) as PaidOrder;
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
 * Adds the Site Editor stacking and keyboard assertions while leaving strict
 * discovery, readiness, and dismissal waits to the shared 3DS browser driver.
 */
class SiteEditorCardAuthenticationBrowser implements CardAuthenticationBrowser {
	private readonly delegate: PlaywrightCardAuthenticationBrowser;

	public constructor( private readonly page: Page ) {
		this.delegate = new PlaywrightCardAuthenticationBrowser( page );
	}

	public waitForOuterFrame( timeoutMs: number ): Promise< boolean > {
		return this.delegate.waitForOuterFrame( timeoutMs );
	}

	public waitForChallengeFrame( timeoutMs: number ): Promise< boolean > {
		return this.delegate.waitForChallengeFrame( timeoutMs );
	}

	public waitForChallengeReady( timeoutMs: number ): Promise< boolean > {
		return this.delegate.waitForChallengeReady( timeoutMs );
	}

	public async respondToChallenge(
		response: ChallengeResponse
	): Promise< void > {
		if ( response !== 'complete' ) {
			throw new Error(
				'The Site Editor smoke only permits completing its challenge.'
			);
		}

		// The exact theme/body proof must survive the modal opening, not merely
		// have been true on the checkout before the provider took focus.
		await expectSiteEditorBody( this.page );
		const outerFrame = this.page.locator( OUTER_CHALLENGE_FRAME );
		await expect( outerFrame ).toBeVisible();
		await expect(
			this.page
				.frameLocator( OUTER_CHALLENGE_FRAME )
				.frameLocator( NESTED_CHALLENGE_FRAME )
				.locator( 'body' ),
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

		const complete = this.page
			.frameLocator( OUTER_CHALLENGE_FRAME )
			.frameLocator( NESTED_CHALLENGE_FRAME )
			.getByRole( 'button', { name: 'Complete', exact: true } );
		await complete.focus();
		await expect(
			complete,
			'the Complete action must receive keyboard focus'
		).toBeFocused();
		await complete.press( 'Enter' );
	}

	public waitForChallengeDismissed( timeoutMs: number ): Promise< boolean > {
		return this.delegate.waitForChallengeDismissed( timeoutMs );
	}
}

async function openCheckout(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct
): Promise< void > {
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutAddress( page, session.runId );
	await expectSiteEditorBody( page );
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
	await enterProviderCardTriple(
		page.frameLocator( getBlocksCardFrameSelector( session.runtime ) ),
		THREE_DS_2_CARD,
		'Blocks checkout'
	);
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

async function completePurchase(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	journal: string,
	protectionScope?: CardTestingProtectionScope
): Promise< { challenge: CardAuthenticationEvidence; orderId: number } > {
	await openCheckout( session, page, product );
	await expectFraudPreventionToken( page, Boolean( protectionScope ) );

	if ( protectionScope ) {
		// The controller requires one authoritative session read so it can prove
		// the fresh guest session was owned and remove it during restoration.
		// Token contents are deliberately outside this product smoke.
		await protectionScope.captureGuestSessionToken( page );
	}

	let challenge: CardAuthenticationEvidence | undefined;
	await session.withProviderSubmissionJournal( journal, async () => {
		await submitBlocksCheckout( page, async ( button ) => {
			await session.performWrite( () => button.click() );
			return 'dispatched';
		} );
		challenge = await completeCardAuthentication( {
			expectation: 'challenge',
			response: 'complete',
			browser: new SiteEditorCardAuthenticationBrowser( page ),
		} );
		await page.waitForURL( /\/order-received\/[1-9]\d*\/?(?:\?.*)?$/, {
			timeout: RECEIPT_TIMEOUT_MS,
		} );
	} );

	if ( ! challenge ) {
		throw new Error( 'The mandatory challenge produced no evidence.' );
	}
	const orderId = session.getOrderIdFromUrl( page.url() );
	await session.setOrderRunId( orderId, session.runId );
	return { challenge, orderId };
}

function expectStrictChallenge( challenge: CardAuthenticationEvidence ): void {
	expect( challenge.expectation ).toBe( 'challenge' );
	expect( challenge.authenticationSurfacePresented ).toBe( true );
	expect( challenge.challengePresented ).toBe( true );
	expect( challenge.response ).toBe( 'complete' );
	expect( challenge.challengeDismissed ).toBe( true );
}

async function expectPaidOrder(
	session: ProviderWriteSession,
	page: Page,
	orderId: number
): Promise< void > {
	await expect(
		page.getByText( /^(Your order has been received|Order received)$/i ),
		'the completed challenge must reach the receipt'
	).toBeVisible();
	expect( session.getOrderIdFromUrl( page.url() ) ).toBe( orderId );

	const order = await readOrder( session, orderId );
	expect( order.id ).toBe( orderId );
	expect( PAID_ORDER_STATUSES ).toContain( order.status );
	expect( order.total ).toBe( PRICE );
	expect( order.currency ).toBe( CURRENCY );
	expect( order.payment_method ).toBe( 'woocommerce_payments' );
	expect(
		order.transaction_id,
		'the paid WooPayments order must carry its provider transaction ID'
	).toEqual( expect.stringMatching( /^(?:pi_|ch_|py_)/ ) );
}

async function runUnderTwentyTwentyFour(
	session: ProviderWriteSession,
	page: Page,
	journal: string,
	protectionScope?: CardTestingProtectionScope
): Promise< void > {
	await withActiveBlockTheme(
		session,
		session.runId,
		THEME_STYLESHEET,
		async ( theme ) => {
			expect( theme.active.stylesheet ).toBe( THEME_STYLESHEET );
			expect( theme.active.template ).toBe( THEME_STYLESHEET );
			expect( theme.active.name ).toBe( THEME_NAME );
			expect( theme.active.isBlockTheme ).not.toBe( false );

			const product = await session.createOwnedProduct( PRICE );
			const purchase = await completePurchase(
				session,
				page,
				product,
				journal,
				protectionScope
			);
			expectStrictChallenge( purchase.challenge );
			await expectPaidOrder( session, page, purchase.orderId );
		}
	);
}

test.describe(
	'WooPayments native Site Editor card authentication',
	{ tag: [ tags.WOOPAYMENTS_NATIVE, tags.WOOPAYMENTS_PROVIDER ] },
	() => {
		// Provider routing enforces one worker and zero retries. Serial mode also
		// prevents a second global-theme mutation after an unclear first case.
		test.describe.configure( { mode: 'serial', timeout: 300_000 } );

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
			async ( { page, pilotRuntime } ) => {
				pilotRuntime.requireApprovedProviderFixture(
					'card-authentication'
				);
				await pilotRuntime.assertCurrentRuntimeReady( 'native' );

				await pilotRuntime.withProviderWriteLocks(
					{ recordEvent: 'shopper-site-editor-3ds-unprotected' },
					async () => {
						expect(
							await readCardTestingProtectionEligibility(
								pilotRuntime
							),
							'the protection-off smoke requires strict effective false'
						).toBe( false );
						await runUnderTwentyTwentyFour(
							pilotRuntime,
							page,
							'site-editor-3ds-unprotected'
						);
					}
				);
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
			async ( { page, pilotRuntime } ) => {
				pilotRuntime.requireApprovedProviderFixture(
					'card-authentication'
				);
				await pilotRuntime.assertCurrentRuntimeReady( 'native' );

				// FORCED ELIGIBILITY BOUNDARY: the target account normally reports
				// false. The existing controller forces native's local premise,
				// byte-restores it, and verifies restoration. This proves native's
				// protected path, not a provider grant to this account.
				await withCapturedCardTestingProtectionState(
					pilotRuntime,
					pilotRuntime.runId,
					async ( protectionScope ) => {
						await protectionScope.registerFreshContext( page );
						expect(
							await readCardTestingProtectionEligibility(
								pilotRuntime
							),
							'the forced protection premise must be strictly effective'
						).toBe( true );
						await runUnderTwentyTwentyFour(
							pilotRuntime,
							page,
							'site-editor-3ds-protected',
							protectionScope
						);
					}
				);
			}
		);
	}
);
