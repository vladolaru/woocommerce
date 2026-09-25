import { expect, type Page } from '@playwright/test';

import type { PaymentEvidence } from '../record-evidence';
import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';

interface ExactMerchantTransactionAmount {
	formattedAmount: string;
	currency: string;
}

function escapeRegularExpression( value: string ): string {
	return value.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
}

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
	// F-TX (N-085): native renders "Authorized"/title-cased provider status labels here
	// where client 11.1.0 renders "Payment authorized"/"Paid"
	// (payment-status-chip/mappings.ts:45-51); asserting native's current label would pin
	// the divergence, so that assertion is left for Task T.7 Step 5.
}

export async function expectExactMerchantTransactionAmount(
	page: Page,
	expected: ExactMerchantTransactionAmount
): Promise< void > {
	const summary = page.locator(
		'section[aria-labelledby="woocommerce-woopayments-payment-summary-heading"]'
	);
	await expect( summary ).toHaveCount( 1 );

	const amount = summary.locator(
		'.woocommerce-woopayments-money-movement__summary-amount'
	);
	await expect( amount ).toHaveCount( 1 );
	await expect( amount ).toBeVisible();
	await expect( amount ).toHaveText(
		new RegExp(
			`^${ escapeRegularExpression(
				expected.formattedAmount
			) }\\s*${ escapeRegularExpression( expected.currency ) }$`
		)
	);

	const currency = amount.locator(
		'.woocommerce-woopayments-money-movement__summary-currency'
	);
	await expect( currency ).toHaveCount( 1 );
	await expect( currency ).toHaveText( expected.currency );
}
