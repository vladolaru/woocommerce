/**
 * Internal dependencies
 */
import { confirmWithStripe, parseConfirmationHash } from '../confirmation';

describe( 'parseConfirmationHash', () => {
	it( 'reads a payment intent confirmation, also inside a full URL', () => {
		const expected = {
			type: 'pi',
			orderId: '123',
			clientSecret: 'pi_abc_secret_def',
			nonce: 'a1b2c3',
			confirmationToken: '',
			intentId: 'pi_abc',
		};

		expect(
			parseConfirmationHash(
				'#wcpay-confirm-pi:123:pi_abc_secret_def:a1b2c3'
			)
		).toEqual( expected );
		expect(
			parseConfirmationHash(
				'https://example.test/checkout/#wcpay-confirm-pi:123:pi_abc_secret_def:a1b2c3'
			)
		).toEqual( expected );
	} );

	it( 'reads a setup intent confirmation with its confirmation token', () => {
		expect(
			parseConfirmationHash(
				'#wcpay-confirm-si:9:seti_x_secret_y:nonce:ctoken_z'
			)
		).toMatchObject( {
			type: 'si',
			orderId: '9',
			clientSecret: 'seti_x_secret_y',
			confirmationToken: 'ctoken_z',
			intentId: 'seti_x',
		} );
	} );

	it.each( [ '', '#other', 'https://example.test/order-received/1/', null ] )(
		'returns null for %p',
		( value ) => {
			expect( parseConfirmationHash( value ) ).toBeNull();
		}
	);
} );

describe( 'confirmWithStripe', () => {
	const createStripe = () => ( {
		confirmSetup: jest.fn().mockResolvedValue( { setupIntent: {} } ),
		handleNextAction: jest.fn().mockResolvedValue( { paymentIntent: {} } ),
	} );

	it( 'confirms a setup intent with its confirmation token', async () => {
		const stripe = createStripe();

		await confirmWithStripe(
			{
				type: 'si',
				clientSecret: 'seti_secret',
				confirmationToken: 'ctoken',
			},
			stripe
		);

		expect( stripe.confirmSetup ).toHaveBeenCalledWith( {
			clientSecret: 'seti_secret',
			confirmParams: { confirmation_token: 'ctoken' },
			redirect: 'if_required',
		} );
		expect( stripe.handleNextAction ).not.toHaveBeenCalled();
	} );

	it.each( [
		[ 'pi', 'ctoken' ],
		[ 'pi', '' ],
		[ 'si', '' ],
	] )(
		'handles the next action of a %s intent with token %p',
		async ( type, confirmationToken ) => {
			const stripe = createStripe();

			const result = await confirmWithStripe(
				{ type, clientSecret: 'secret', confirmationToken },
				stripe
			);

			expect( stripe.handleNextAction ).toHaveBeenCalledWith( {
				clientSecret: 'secret',
			} );
			expect( stripe.confirmSetup ).not.toHaveBeenCalled();
			expect( result ).toEqual( { paymentIntent: {} } );
		}
	);

	it( 'returns null when Stripe.js offers no method for the intent', () => {
		expect(
			confirmWithStripe(
				{
					type: 'si',
					clientSecret: 'secret',
					confirmationToken: 'ctoken',
				},
				{}
			)
		).toBeNull();
	} );
} );
