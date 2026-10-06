/**
 * External dependencies
 */
import { registerCheckoutFilters } from '@woocommerce/blocks-checkout';

/**
 * Show the currency code after the Cart and Checkout block totals while Multi-Currency runs, so a shopper paying in, say,
 * Canadian dollars on a US store sees which dollars (client 11.1.0 `client/checkout/blocks/index.js:159-175`).
 *
 * @param {Object} settings WooPayments payment method data.
 */
export const registerMultiCurrencyTotalValue = ( settings ) => {
	if ( ! settings?.isMultiCurrencyEnabled ) {
		return;
	}

	// The plugin's namespace, so code that targets the plugin's filter still finds it.
	registerCheckoutFilters( 'woocommerce-payments', {
		totalValue: ( defaultValue, extensions, args ) => {
			const currencyCode = args?.cart?.cartTotals?.currency_code;

			return currencyCode ? `<price/> ${ currencyCode }` : defaultValue;
		},
	} );
};
