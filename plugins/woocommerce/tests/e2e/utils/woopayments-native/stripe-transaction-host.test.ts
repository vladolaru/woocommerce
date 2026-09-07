import { expect, test } from '@playwright/test';

import { isStripeTransactionHost } from './stripe-transaction-host';

test( 'classifies Stripe payment API traffic without treating telemetry as a transaction', () => {
	expect( isStripeTransactionHost( 'api.stripe.com' ) ).toBe( true );
	expect( isStripeTransactionHost( 'm.stripe.com' ) ).toBe( false );
	expect( isStripeTransactionHost( 'js.stripe.com' ) ).toBe( false );
} );
