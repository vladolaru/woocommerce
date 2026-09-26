import { expect, test } from '@playwright/test';

import { findBlocksPaymentIntentConfirmation } from './blocks-card-authentication';

test( 'reads the exact order and PaymentIntent from a nested Blocks confirmation', () => {
	expect(
		findBlocksPaymentIntentConfirmation( {
			payment_result: {
				payment_details: [
					{
						redirect:
							'#wcpay-confirm-pi:73:pi_exact_secret_private:nonce_private',
					},
				],
			},
		} )
	).toEqual( { orderId: 73, intentId: 'pi_exact' } );
} );

test( 'does not treat a SetupIntent confirmation as a payment', () => {
	expect(
		findBlocksPaymentIntentConfirmation(
			'#wcpay-confirm-si:73:seti_exact_secret_private:nonce_private'
		)
	).toBeUndefined();
} );

test( 'rejects a payment confirmation with no durable identity', () => {
	expect( () =>
		findBlocksPaymentIntentConfirmation(
			'#wcpay-confirm-pi:0:not-a-client-secret:nonce_private'
		)
	).toThrow( /no usable order and PaymentIntent identity/ );
} );
