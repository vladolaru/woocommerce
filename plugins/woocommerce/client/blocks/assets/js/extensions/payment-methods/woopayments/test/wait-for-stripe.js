/**
 * Internal dependencies
 */
import { StripeWaitTimeoutError, waitForStripe } from '../wait-for-stripe';

describe( 'waitForStripe', () => {
	beforeEach( () => {
		jest.useFakeTimers();
		delete window.Stripe;
	} );

	afterEach( () => {
		jest.useRealTimers();
		delete window.Stripe;
	} );

	it( 'keeps checking until Stripe.js defines window.Stripe', async () => {
		const onResolve = jest.fn();
		waitForStripe().then( onResolve );

		await jest.advanceTimersByTimeAsync( 500 );
		expect( onResolve ).not.toHaveBeenCalled();

		window.Stripe = jest.fn();
		await jest.advanceTimersByTimeAsync( 100 );

		expect( onResolve ).toHaveBeenCalledWith( window.Stripe );
	} );

	it( 'gives up after the client wait of 600 seconds', async () => {
		const onReject = jest.fn();
		waitForStripe().catch( onReject );

		jest.advanceTimersByTime( 600 * 1000 );
		await Promise.resolve();
		expect( onReject ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 100 );
		await Promise.resolve();

		expect( onReject ).toHaveBeenCalledWith(
			expect.any( StripeWaitTimeoutError )
		);
	} );
} );
