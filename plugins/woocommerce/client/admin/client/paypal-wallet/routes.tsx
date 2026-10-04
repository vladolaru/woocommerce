/**
 * Internal dependencies
 */
import { registerSettingsPaymentsProviderRoute } from '~/settings-payments/provider-routes';
import { PayPalWalletSettingsRoute } from './settings-route';

registerSettingsPaymentsProviderRoute( {
	id: 'paypal-wallet-settings',
	path: '/paypal-wallet',
	order: 80,
	element: <PayPalWalletSettingsRoute />,
} );
