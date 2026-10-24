/**
 * External dependencies
 */
import { getSetting } from '@woocommerce/settings';

/**
 * Internal dependencies
 */
import enqueueFraudScripts from '../fraud-scripts';

const PAYMENT_METHOD_NAME = 'woocommerce_payments';

/**
 * Get the fraud-services config from the WooPayments Blocks payment method data.
 *
 * Every WooPayments gateway carries the same config, so the card gateway is
 * read first and any other WooPayments gateway stands in when card is off.
 *
 * @return {Object|undefined} Fraud-services config keyed by service ID.
 */
export const getFraudServicesConfig = () => {
	const paymentMethodData = getSetting( 'paymentMethodData', {} );
	const gatewayId = [
		PAYMENT_METHOD_NAME,
		...Object.keys( paymentMethodData ).filter( ( id ) =>
			id.startsWith( `${ PAYMENT_METHOD_NAME }_` )
		),
	].find( ( id ) => paymentMethodData[ id ]?.fraudServices );

	return gatewayId ? paymentMethodData[ gatewayId ].fraudServices : undefined;
};

// Runs on the Blocks cart and checkout, after the page loads, as the
// WooPayments plugin's Blocks bundle does.
window.addEventListener( 'load', () => {
	enqueueFraudScripts( getFraudServicesConfig() );
} );
