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
const RECEIPT_TIMEOUT_MS = 30_000;
// Native's own copy for payment_intent_authentication_failure.
const AUTHENTICATION_FAILURE_TEXT =
	'We are unable to authenticate your payment method. Please choose a different payment method and try again.';

/**
 * Selects the WooPayments card option, which a returning shopper's checkout
 * may already have selected.
 */
async function selectCardPaymentOption( page: Page ): Promise< void > {
	await page
		.getByRole( 'group', { name: 'Payment options' } )
		.getByRole( 'radio', { name: /Card/i } )
		.first()
		.check();
}

/**
 * Blocks checkout address and payment-method selection.
 *
 * The card checkout driver has an equivalent, but it is private and lives in a
 * file inside closed contracts' source bundles, where adding an export would
 * invalidate their evidence hashes. Kept in step with that helper by hand until
 * one of those closures is re-attested for its own reason.
 */
async function fillBlocksCheckoutDetails(
	page: Page,
	runId: string
): Promise< void > {
	const shipping = page.getByRole( 'group', { name: 'Shipping address' } );
	const billing = page.getByRole( 'group', { name: 'Billing address' } );
	const address = ( await shipping.isVisible() ) ? shipping : billing;
	const country = address.getByRole( 'combobox', {
		name: 'Country/Region',
	} );

	// A shopper who has checked out before arrives with the address already
	// resolved: the group still renders, collapsed behind an Edit button, with
	// no editable country field. Gate on the field the fill actually needs
	// rather than on the group, which is present either way. Supplying an
	// address is a precondition for reaching payment, not something this spec
	// asserts, so there is nothing to prove by re-entering one.
	if ( ! ( await country.isVisible() ) ) {
		await selectCardPaymentOption( page );
		return;
	}

	await page
		.getByRole( 'textbox', { name: 'Email address' } )
		.fill( `woopayments-${ runId }@example.com` );
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
	await selectCardPaymentOption( page );
}

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
	reachedReceipt: boolean;
	receiptUrl: string;
} > {
	await session.logInAsCustomer( page );

	// Same navigation and add-to-cart shape the card checkout driver uses, so
	// this journey differs from a plain card purchase only in the card and the
	// challenge that follows.
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'checkout/' );
	await fillBlocksCheckoutDetails( page, session.runId );

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

	// Wait for the receipt specifically, and treat not arriving as an answer
	// rather than an error. Waiting on a pattern that also matches the checkout
	// URL would resolve immediately - the page is already there - so the
	// failed-challenge assertion below would pass before the payment had a
	// chance to succeed, which is no assertion at all.
	const reachedReceipt = await page
		.waitForURL( /order-received/, { timeout: RECEIPT_TIMEOUT_MS } )
		.then( () => true )
		.catch( () => false );

	return { evidence, reachedReceipt, receiptUrl: page.url() };
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

				const { evidence, reachedReceipt, receiptUrl } =
					await checkoutWithChallenge(
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
				expect(
					reachedReceipt,
					`a completed challenge must reach the receipt; stopped at ${ receiptUrl }`
				).toBe( true );
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

				const { evidence, reachedReceipt } =
					await checkoutWithChallenge(
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
					reachedReceipt,
					'a failed challenge must not reach the receipt'
				).toBe( false );
				// The shopper is told, and the wording is native's own
				// `payment_intent_authentication_failure` copy, so this
				// asserts the failure was mapped rather than that some error
				// appeared.
				await expect(
					page.getByText( AUTHENTICATION_FAILURE_TEXT ).first(),
					'a failed challenge must tell the shopper'
				).toBeVisible();

				// Recorded, not asserted: that message renders as plain text
				// with no alert role or live region, so a screen reader user
				// is never told the payment failed. The copy is native's but
				// the surface is the checkout block's generic error area, so
				// fixing it reaches every payment error and belongs outside
				// this contract.
			}
		);
	} );
} );
