import type { Page } from '@playwright/test';

import {
	expect,
	getBlocksCardFrameSelector,
	tags,
	test,
	type OwnedProduct,
	type ProviderWriteSession,
} from '../../../fixtures/woopayments-native';
import {
	completeCardAuthentication,
	PlaywrightCardAuthenticationBrowser,
	type CardAuthenticationEvidence,
} from '../../../utils/woopayments-native/drivers/card-authentication';
import { submitBlocksCheckout } from '../../../utils/woopayments-native/drivers/checkout';
import { THREE_DS_2_CARD } from '../../../utils/woopayments-native/test-cards';

/**
 * Proves the provider's 3D Secure challenge is real and that native carries a
 * completed one through to a paid order.
 *
 * This spec is the reason `drivers/card-authentication.ts` exists. The
 * extension suite's twelve authentication contracts rest on a helper that
 * returns silently when no challenge appears, so eight of their recorded
 * residual risks say some version of "a frictionless or skipped challenge can
 * false-pass". Here the challenge is asserted, answered, and its effect on the
 * order is checked; a card that stops triggering authentication fails the run
 * rather than quietly passing it.
 *
 * The excluded-family decision still stands: this is journey coverage, not a
 * thin fidelity check, and it must not be sold as one.
 */

const CONTRACT_IDS = [
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-checkout-purchase.spec.ts:78::Successful purchase › Carding protection false › using a 3DS card',
	'default::chromium::tests/e2e/specs/wcpay/shopper/shopper-wc-blocks-checkout-purchase.spec.ts:42::WooCommerce Blocks › Successful purchase › using a 3DS card',
];

const PROVIDER_CAPABILITY = 'card-authentication';
const PRICE = '10.99';
const PAID_STATUSES = [ 'processing', 'completed' ];

async function fillThreeDsCard(
	session: ProviderWriteSession,
	page: Page
): Promise< void > {
	const frame = page.frameLocator(
		getBlocksCardFrameSelector( session.runtime )
	);

	await frame
		.getByRole( 'textbox', { name: 'Card number' } )
		.fill( THREE_DS_2_CARD.number );
	await frame
		.getByRole( 'textbox', { name: /Expiration date/i } )
		.fill( THREE_DS_2_CARD.expiry );
	await frame
		.getByRole( 'textbox', { name: 'Security code' } )
		.fill( THREE_DS_2_CARD.securityCode );
	await page.getByRole( 'button', { name: /place order/i } ).focus();
}

async function readOrderStatus(
	session: ProviderWriteSession,
	orderId: number
): Promise< string > {
	const response = await session.adminApi.get(
		`/wp-json/wc/v3/orders/${ orderId }`
	);

	if ( ! response.ok() ) {
		throw new Error(
			`Could not read order ${ orderId }: HTTP ${ response.status() } ${ await response.text() }`
		);
	}

	const order = ( await response.json() ) as { status?: unknown };
	if ( typeof order.status !== 'string' ) {
		throw new Error( `Order ${ orderId } carried no status.` );
	}

	return order.status;
}

/**
 * Drives one blocks checkout with the challenge card and answers the
 * challenge the way the caller asks.
 */
async function checkoutWithChallenge(
	session: ProviderWriteSession,
	page: Page,
	product: OwnedProduct,
	response: 'complete' | 'fail'
): Promise< {
	evidence: CardAuthenticationEvidence;
	receiptUrl: string;
} > {
	await session.logInAsCustomer( page );
	await page.goto( product.url );
	await page.getByRole( 'button', { name: /add to cart/i } ).click();
	await page.goto( 'checkout/' );

	await fillThreeDsCard( session, page );

	let evidence: CardAuthenticationEvidence | undefined;

	await session.withProviderSubmissionJournal(
		`3ds-checkout-${ response }`,
		async () => {
			await submitBlocksCheckout( page, async ( button ) => {
				await button.click();
				return 'dispatched';
			} );

			// The challenge is the assertion, not a step to get past. A card
			// that no longer triggers one fails here instead of producing a
			// green run that proves nothing about the authenticated path.
			evidence = await completeCardAuthentication( {
				expectation: 'challenge',
				response,
				browser: new PlaywrightCardAuthenticationBrowser( page ),
			} );
		}
	);

	if ( ! evidence ) {
		throw new Error( 'The challenge produced no evidence.' );
	}

	await page.waitForURL( /order-received|checkout/ );

	return { evidence, receiptUrl: page.url() };
}

test.describe( 'WooPayments native card authentication', () => {
	test.describe.configure( { mode: 'serial' } );

	test( `completes a 3DS challenge and pays the order ${ tags.WOOPAYMENTS_PROVIDER }`, async ( {
		page,
		pilotRuntime,
	} ) => {
		test.info().annotations.push(
			...CONTRACT_IDS.map( ( contractId ) => ( {
				type: 'contract',
				description: contractId,
			} ) )
		);

		pilotRuntime.requireApprovedProviderFixture( PROVIDER_CAPABILITY );
		await pilotRuntime.assertCurrentRuntimeReady( 'native' );

		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-authentication' },
			async () => {
				const product = await pilotRuntime.createOwnedProduct( PRICE );

				const { evidence, receiptUrl } = await checkoutWithChallenge(
					pilotRuntime,
					page,
					product,
					'complete'
				);

				// The challenge happened, was answered, and went away.
				expect( evidence.authenticationSurfacePresented ).toBe( true );
				expect( evidence.challengePresented ).toBe( true );
				expect( evidence.response ).toBe( 'complete' );
				expect( evidence.challengeDismissed ).toBe( true );

				// A completed challenge must produce a paid order, not just a
				// dismissed dialog.
				expect( receiptUrl ).toMatch( /order-received/ );
				const orderId = pilotRuntime.getOrderIdFromUrl( receiptUrl );
				await pilotRuntime.setOrderRunId( orderId, pilotRuntime.runId );

				expect(
					PAID_STATUSES,
					'a completed challenge must leave a paid order'
				).toContain( await readOrderStatus( pilotRuntime, orderId ) );
			}
		);
	} );

	test( `leaves the order unpaid when the challenge is failed ${ tags.WOOPAYMENTS_PROVIDER }`, async ( {
		page,
		pilotRuntime,
	} ) => {
		pilotRuntime.requireApprovedProviderFixture( PROVIDER_CAPABILITY );
		await pilotRuntime.assertCurrentRuntimeReady( 'native' );

		await pilotRuntime.withProviderWriteLocks(
			{ recordEvent: 'shopper-card-authentication-failed' },
			async () => {
				const product = await pilotRuntime.createOwnedProduct( PRICE );

				const { evidence, receiptUrl } = await checkoutWithChallenge(
					pilotRuntime,
					page,
					product,
					'fail'
				);

				expect( evidence.challengePresented ).toBe( true );
				expect( evidence.response ).toBe( 'fail' );

				// The negative control for the test above. Without it, a
				// checkout that pays regardless of the challenge answer would
				// satisfy the positive case and prove nothing.
				expect(
					receiptUrl,
					'a failed challenge must not reach the receipt'
				).not.toMatch( /order-received/ );
				await expect(
					page.getByRole( 'alert' ).first(),
					'a failed challenge must tell the shopper'
				).toBeVisible();
			}
		);
	} );
} );
