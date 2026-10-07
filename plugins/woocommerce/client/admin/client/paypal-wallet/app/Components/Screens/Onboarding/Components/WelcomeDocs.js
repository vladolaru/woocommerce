import { __ } from '@wordpress/i18n';

import PricingDescription from './PricingDescription';
import PaymentFlow from './PaymentFlow';

const WelcomeDocs = ( { storeCountry } ) => {
	return (
		<div className="ppcp-r-welcome-docs">
			<h2 className="ppcp-r-welcome-docs__title">
				{ __(
					'Want to know more about PayPal Wallet?',
					'woocommerce'
				) }
			</h2>
			<PaymentFlow storeCountry={ storeCountry } />
			<PricingDescription />
		</div>
	);
};

export default WelcomeDocs;
