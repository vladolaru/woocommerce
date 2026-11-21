const CONFIRMATION_HASH =
	/#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/;

/**
 * Read the intent confirmation the store asks for in a `#wcpay-confirm-` redirect.
 *
 * @param {string} value Redirect URL, or the page hash after the store's redirect.
 * @return {Object|null} The intent type, order ID, client secret, nonce, confirmation token and intent ID, or null.
 */
export const parseConfirmationHash = ( value ) => {
	if ( typeof value !== 'string' ) {
		return null;
	}

	const match = value.match( CONFIRMATION_HASH );
	if ( ! match ) {
		return null;
	}

	const clientSecret = decodeURIComponent( match[ 3 ] );

	return {
		type: match[ 1 ],
		orderId: decodeURIComponent( match[ 2 ] ),
		clientSecret,
		nonce: decodeURIComponent( match[ 4 ] ),
		confirmationToken: match[ 5 ] ? decodeURIComponent( match[ 5 ] ) : '',
		intentId: clientSecret.split( '_secret_' )[ 0 ],
	};
};

// The payment intent statuses that hold the shopper's money (WooPaymentsIntentCodec::holds_money()).
const MONEY_HOLDING_STATUSES = [
	'succeeded',
	'requires_capture',
	'processing',
];

/**
 * Whether Stripe confirmed a payment intent without an error and the intent now holds the shopper's money.
 *
 * @param {{type?: string}|null}                                     confirmation The parsed confirmation (parseConfirmationHash()).
 * @param {{error?: Object, paymentIntent?: {status?: string}}|null} result       Stripe's result for the confirmation.
 * @return {boolean} False after a setup intent, an error, or a status that holds no money.
 */
export const isConfirmedPayment = ( confirmation, result ) =>
	confirmation?.type === 'pi' &&
	! result?.error &&
	MONEY_HOLDING_STATUSES.includes( result?.paymentIntent?.status ?? '' );

/**
 * Ask Stripe to complete the intent's next action, as client 11.2.0 confirmIntent() does: a setup intent with a
 * confirmation token is confirmed with it, every other intent has its next action handled.
 *
 * Only the Stripe call: the calling script reports the result to the store and sends the shopper on.
 *
 * @param {Object} confirmation The parsed confirmation (parseConfirmationHash()).
 * @param {Object} stripe       Stripe.js instance for the intent's account.
 * @return {Promise<Object>|null} Stripe's result, or null when the Stripe.js instance lacks the method.
 */
export const confirmWithStripe = ( confirmation, stripe ) => {
	if (
		confirmation.type === 'si' &&
		confirmation.confirmationToken &&
		stripe.confirmSetup
	) {
		return stripe.confirmSetup( {
			clientSecret: confirmation.clientSecret,
			confirmParams: {
				confirmation_token: confirmation.confirmationToken,
			},
			redirect: 'if_required',
		} );
	}

	if ( stripe.handleNextAction ) {
		return stripe.handleNextAction( {
			clientSecret: confirmation.clientSecret,
		} );
	}

	return null;
};
