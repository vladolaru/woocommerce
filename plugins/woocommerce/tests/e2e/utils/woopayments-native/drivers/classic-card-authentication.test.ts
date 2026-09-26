import { expect, test } from '@playwright/test';

import { providerIntentReadTarget } from './classic-card-authentication';

test( 'reads PaymentIntents and SetupIntents from their native provider routes', () => {
	expect( providerIntentReadTarget( 'pi_exact' ) ).toEqual( {
		kind: 'payment',
		path: '/wp-json/wc/v3/payments/payment_intents/pi_exact',
	} );
	expect( providerIntentReadTarget( 'seti_exact' ) ).toEqual( {
		kind: 'setup',
		path: '/wp-json/wc-native-payments-e2e/v1/subscription-evidence?setup_intent_id=seti_exact',
	} );
} );
