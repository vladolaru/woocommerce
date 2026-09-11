import { expect, type Page } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import { ResourceQuarantineRequiredError } from '../resource-locks';
import type { SavedCardIdentity } from './saved-cards';

export async function payWithPreselectedHistoricalDefault(
	session: ProviderWriteSession,
	page: Page,
	card: SavedCardIdentity,
	runId: string
): Promise< number > {
	await session.assertCanWrite();
	session.requireApprovedProviderFixture( 'historical-tokens' );
	session.requireApprovedProviderFixture( 'saved-card-classic' );
	const product = await session.createOwnedProduct( '10.99' );
	await page.goto( `?post_type=product&p=${ product.id }` );
	await session.performWrite( () =>
		page.getByRole( 'button', { name: 'Add to cart', exact: true } ).click()
	);
	await page.goto( 'classic-checkout/' );

	const savedTokenSelector =
		'input.woocommerce-SavedPaymentMethods-tokenInput[name="wc-woocommerce_payments-payment-token"]';
	const exactToken = page.locator(
		`${ savedTokenSelector }[value="${ card.tokenId }"]`
	);
	await expect( exactToken ).toHaveCount( 1 );
	await expect( exactToken ).toBeChecked();
	await expect(
		page.locator( `${ savedTokenSelector }:checked` )
	).toHaveCount( 1 );

	await session.withProviderSubmissionJournal(
		'historical-default-classic-checkout',
		async () => {
			await session.performWrite( () =>
				page.getByRole( 'button', { name: /place order/i } ).click()
			);
			try {
				await page.waitForURL(
					/\/order-received\/[1-9]\d*\/?(?:\?.*)?$/
				);
				await expect(
					page.getByText( 'Your order has been received' )
				).toBeVisible();
			} catch ( error ) {
				throw new ResourceQuarantineRequiredError(
					'WooPayments historical default checkout submission has no proven outcome.',
					'uncertain-provider-write',
					error instanceof Error
						? error
						: new Error( String( error ) )
				);
			}
		}
	);
	const orderId = session.getOrderIdFromUrl( page.url() );
	await session.setOrderRunId( orderId, runId );
	return orderId;
}
