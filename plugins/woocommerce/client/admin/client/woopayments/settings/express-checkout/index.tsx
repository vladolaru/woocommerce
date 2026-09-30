/**
 * External dependencies
 */
import { useParams } from 'react-router-dom';

/**
 * Internal dependencies
 */
import { WooPaymentsExpressCheckoutSettings } from './express-checkout-settings';
import '../../../settings-payments/settings-payments-body.scss';

const WooPaymentsExpressCheckoutSettingsRoute = () => {
	const { methodId = '' } = useParams();

	return <WooPaymentsExpressCheckoutSettings methodId={ methodId } />;
};

export { WooPaymentsExpressCheckoutSettings };
export default WooPaymentsExpressCheckoutSettingsRoute;
