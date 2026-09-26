export interface BlocksPaymentIntentConfirmation {
	orderId: number;
	intentId: string;
}

/**
 * Reads the durable order and PaymentIntent identities from the customer-action
 * fragment nested in a Blocks checkout response. The client secret and nonce
 * are deliberately discarded; the browser already owns the confirmation.
 */
export function findBlocksPaymentIntentConfirmation(
	value: unknown
): BlocksPaymentIntentConfirmation | undefined {
	if ( typeof value === 'string' ) {
		const match = value.match(
			/#wcpay-confirm-pi:([^:]+):([^:]+):([^:]+)(?::.*)?$/
		);
		if ( ! match ) {
			return undefined;
		}

		const orderId = Number( decodeURIComponent( match[ 1 ] ) );
		const clientSecret = decodeURIComponent( match[ 2 ] );
		const intentId = clientSecret.split( '_secret_' )[ 0 ];
		if (
			! Number.isSafeInteger( orderId ) ||
			orderId <= 0 ||
			! intentId ||
			intentId === clientSecret
		) {
			throw new Error(
				'The Blocks checkout confirmation carried no usable order and PaymentIntent identity.'
			);
		}
		return { orderId, intentId };
	}

	if ( typeof value !== 'object' || value === null ) {
		return undefined;
	}
	for ( const child of Object.values( value ) ) {
		const confirmation = findBlocksPaymentIntentConfirmation( child );
		if ( confirmation ) {
			return confirmation;
		}
	}
	return undefined;
}
