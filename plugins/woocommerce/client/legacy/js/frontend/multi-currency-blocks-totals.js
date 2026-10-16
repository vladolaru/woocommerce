( function () {
	'use strict';

	// Show the currency code after the Cart and Checkout block totals while shoppers can pay in other currencies, so a shopper
	// paying in Canadian dollars on a US store sees which dollars (client 11.1.0 `client/checkout/blocks/index.js:159-175`).
	function getTotalValue( defaultValue, extensions, args ) {
		var currencyCode = args && args.cart && args.cart.cartTotals && args.cart.cartTotals.currency_code;

		return currencyCode ? '<price/> ' + currencyCode : defaultValue;
	}

	if ( typeof module !== 'undefined' && module.exports ) {
		module.exports = { getTotalValue: getTotalValue };
	}

	var blocksCheckout = typeof window !== 'undefined' && window.wc && window.wc.blocksCheckout;
	if ( blocksCheckout && blocksCheckout.registerCheckoutFilters ) {
		// The plugin's namespace, so code that targets the plugin's filter still finds it.
		blocksCheckout.registerCheckoutFilters( 'woocommerce-payments', { totalValue: getTotalValue } );
	}
}() );
