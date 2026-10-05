/**
 * Internal dependencies
 */
import { registerSettingsPaymentsProviderRoute } from '~/settings-payments/provider-routes';
import { PayPalWalletSettingsRoute } from './settings-route';

// Core serves the wallet's settings only while it owns the wallet; otherwise the extension serves its own.
if ( window.wcSettings?.admin?.paypalWalletOwned === true ) {
	registerSettingsPaymentsProviderRoute( {
		id: 'paypal-wallet-settings',
		path: '/paypal-wallet',
		order: 80,
		element: <PayPalWalletSettingsRoute />,
	} );
}
