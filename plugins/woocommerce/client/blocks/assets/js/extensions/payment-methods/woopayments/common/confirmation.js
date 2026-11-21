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
 * The URL resolved against the page when it is an http: or https: one, so a payment answer never sends the shopper to a
 * javascript: or data: URL.
 *
 * @param {string} url Absolute or page-relative URL.
 * @return {string} The resolved URL, or '' for an empty, invalid or non-http(s) one.
 */
export const getHttpUrl = ( url ) => {
	const trimmed = typeof url === 'string' ? url.trim() : '';

	if ( ! trimmed ) {
		return '';
	}

	try {
		const resolved = new window.URL( trimmed, window.location.href );

		return [ 'http:', 'https:' ].includes( resolved.protocol )
			? resolved.href
			: '';
	} catch {
		return '';
	}
};

/**
 * Read the store's update_order_status answer by the rule all four checkout scripts share (the classic scripts carry
 * the same function): a readable message is shown, except a 5xx one after a payment that holds money; only a 2xx
 * answer's http(s) return URL is followed.
 *
 * @param {{ok: boolean, status?: number, body: *}|null} answer The answer, or null when it never arrived or was not JSON.
 * @param {boolean}                                      isPaid Whether Stripe confirmed a payment that holds money.
 * @return {{message: string}|{url: string}|null} The message to show, the page to follow, or null for neither.
 */
export const readOrderStatusAnswer = ( answer, isPaid ) => {
	const message = answer?.body?.error?.message;
	if (
		typeof message === 'string' &&
		message.trim() &&
		! ( isPaid && ( answer?.status ?? 0 ) >= 500 )
	) {
		return { message };
	}

	const url = answer?.ok ? getHttpUrl( answer.body?.return_url ) : '';

	return url ? { url } : null;
};

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
