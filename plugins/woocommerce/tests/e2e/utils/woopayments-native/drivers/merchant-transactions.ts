import { expect, type Page } from '@playwright/test';

import type { PaymentEvidence } from '../record-evidence';
import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';

export async function openExactMerchantTransaction(
	session: ProviderWriteSession,
	page: Page,
	evidence: PaymentEvidence
): Promise< void > {
	await session.assertCanWrite();
	await session.logInAsAdmin( page );
	await page.goto( 'wp-admin/' );

	await page
		.getByRole( 'link', {
			name: 'Payments',
			exact: true,
		} )
		.first()
		.click();
	const transactionsLink = page
		.getByRole( 'link', {
			name: 'Transactions',
			exact: true,
		} )
		.first();
	await expect( transactionsLink ).toHaveAttribute( 'href', /transactions/ );
	const transactionsUrl = await transactionsLink.getAttribute( 'href' );
	if ( ! transactionsUrl ) {
		throw new Error(
			'The WooPayments Transactions menu link has no destination.'
		);
	}
	await page.goto( transactionsUrl );

	if ( session.runtime === 'native' ) {
		await page
			.locator(
				`a[href*="id=${ encodeURIComponent( evidence.intentId ) }"]`
			)
			.first()
			.click();
		return;
	}

	await page
		.getByLabel( 'Search transactions', { exact: true } )
		.fill( evidence.orderId.toString() );
	await page
		.getByRole( 'link', {
			name: new RegExp( `${ evidence.orderId }|${ evidence.chargeId }` ),
		} )
		.first()
		.click();
}

export async function expectExactMerchantTransaction(
	_session: ProviderWriteSession,
	page: Page,
	evidence: PaymentEvidence
): Promise< void > {
	await expect(
		page.getByRole( 'heading', {
			name: /^(Payment details|Transaction details)$/,
		} )
	).toBeVisible();
	await expect(
		page.getByRole( 'link', {
			name: `Order #${ evidence.orderId }`,
			exact: true,
		} )
	).toBeVisible();
	await expect(
		page.getByText( evidence.intentId, { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( evidence.chargeId, { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText( evidence.currency, { exact: true } )
	).toBeVisible();
	await expect(
		page.getByText(
			evidence.providerStatus === 'requires_capture'
				? 'Authorized'
				: evidence.providerStatus
						.replace( /_/g, ' ' )
						.replace( /\b\w/g, ( value ) => value.toUpperCase() ),
			{ exact: true }
		)
	).toBeVisible();
}
