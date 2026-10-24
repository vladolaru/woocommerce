/**
 * Internal dependencies
 */
import { SettingsPaymentsWooPaymentsWrapper } from '~/settings-payments';
import { WooPaymentsSettingsPage } from './settings-page';

/**
 * The WooPayments settings route of the Payments settings shell, under the same header as the `section` URL.
 */
const WooPaymentsSettingsRoute = () => (
	<SettingsPaymentsWooPaymentsWrapper>
		<WooPaymentsSettingsPage />
	</SettingsPaymentsWooPaymentsWrapper>
);

export default WooPaymentsSettingsRoute;
