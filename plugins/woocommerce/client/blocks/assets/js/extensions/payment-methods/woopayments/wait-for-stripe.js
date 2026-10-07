// Client 11.1.0 client/checkout/api/index.js:57-71 waits up to 600 seconds for Stripe.js, checking every 100 ms,
// because page optimizers can defer it past the checkout render.
const STRIPE_WAIT_INTERVAL = 100;
const STRIPE_MAX_WAIT = 600 * 1000;

/**
 * Resolve with `window.Stripe` once Stripe.js has defined it.
 *
 * @return {Promise<Function>} Rejects when Stripe.js has not loaded within the client's wait limit.
 */
export const waitForStripe = () =>
	new Promise( ( resolve, reject ) => {
		if ( typeof window.Stripe === 'function' ) {
			resolve( window.Stripe );
			return;
		}

		let waited = 0;
		const timer = window.setInterval( () => {
			waited += STRIPE_WAIT_INTERVAL;
			if ( typeof window.Stripe === 'function' ) {
				window.clearInterval( timer );
				resolve( window.Stripe );
			} else if ( waited >= STRIPE_MAX_WAIT ) {
				window.clearInterval( timer );
				reject( new Error( 'Stripe object not found' ) );
			}
		}, STRIPE_WAIT_INTERVAL );
	} );
