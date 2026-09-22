import { expect, test, type Page } from '@playwright/test';

import type { ProviderWriteSession } from '../../../fixtures/woopayments-native';
import type { PaymentEvidence } from '../record-evidence';
import {
	expectExactMerchantTransaction,
	expectExactMerchantTransactionAmount,
} from './merchant-transactions';

const evidence: PaymentEvidence = {
	runId: 'woopayments-mc-transaction-details',
	orderId: 4821,
	orderKey: 'wc_order_mc_transaction_details',
	intentId: 'pi_mc_transaction_details',
	chargeId: 'ch_mc_transaction_details',
	paymentMethodId: 'pm_mc_transaction_details',
	amountMinor: 1234,
	currency: 'EUR',
	orderStatus: 'processing',
	providerStatus: 'succeeded',
	chargeStatus: 'succeeded',
	chargeCaptured: true,
	occurrenceCount: 1,
	captureOccurrenceCount: 1,
};

const session = {} as ProviderWriteSession;

interface TransactionPageOptions {
	amount?: string;
	currency?: string;
	breakdown?: string;
	duplicateSummaryAmount?: boolean;
	orderId?: number;
	intentId?: string;
	chargeId?: string;
}

async function renderTransactionPage(
	page: Page,
	options: TransactionPageOptions = {}
): Promise< void > {
	const amount = options.amount ?? '€12.34';
	const currency = options.currency ?? 'EUR';
	const summaryAmount = `<p class="woocommerce-woopayments-money-movement__summary-amount"><span>${ amount }</span><span class="woocommerce-woopayments-money-movement__summary-currency">${ currency }</span></p>`;

	await page.setContent( `
		<section class="woocommerce-woopayments-money-movement">
			<h2>Payment details</h2>
			<a href="#">Order #${ options.orderId ?? evidence.orderId }</a>
			<p>${ options.intentId ?? evidence.intentId }</p>
			<p>${ options.chargeId ?? evidence.chargeId }</p>
			<section aria-labelledby="woocommerce-woopayments-payment-summary-heading">
				<h3 id="woocommerce-woopayments-payment-summary-heading">Summary</h3>
				${ summaryAmount }
				${ options.duplicateSummaryAmount ? summaryAmount : '' }
				<span>Succeeded</span>
				<div class="woocommerce-woopayments-money-movement__summary-breakdown">${
					options.breakdown ?? 'Converted amount: $14.24 USD'
				}</div>
			</section>
		</section>
	` );
}

test( 'accepts the exact shopper amount and currency in the transaction summary', async ( {
	page,
} ) => {
	await renderTransactionPage( page );

	await expectExactMerchantTransaction( session, page, evidence );
	await expectExactMerchantTransactionAmount( page, {
		formattedAmount: '€12.34',
		currency: 'EUR',
	} );
} );

for ( const invalid of [
	{
		name: 'a different amount and currency',
		options: { amount: '12.34', currency: 'USD' },
	},
	{
		name: 'a dollar-denominated amount with the expected digits',
		options: { amount: '$12.34' },
	},
	{
		name: 'the expected amount only in the settlement breakdown',
		options: {
			amount: '$14.24',
			currency: 'USD',
			breakdown: 'Converted amount: €12.34 EUR',
		},
	},
	{
		name: 'duplicate summary amounts that are not uniquely scoped',
		options: { duplicateSummaryAmount: true },
	},
] ) {
	test( `rejects ${ invalid.name }`, async ( { page } ) => {
		await renderTransactionPage( page, invalid.options );

		await expect(
			expectExactMerchantTransactionAmount( page, {
				formattedAmount: '€12.34',
				currency: 'EUR',
			} )
		).rejects.toThrow();
	} );
}

test( 'rejects a page whose order and provider identifiers are from another graph', async ( {
	page,
} ) => {
	await renderTransactionPage( page, {
		orderId: 9999,
		intentId: 'pi_other',
		chargeId: 'ch_other',
	} );

	await expect(
		expectExactMerchantTransaction( session, page, evidence )
	).rejects.toThrow();
} );
