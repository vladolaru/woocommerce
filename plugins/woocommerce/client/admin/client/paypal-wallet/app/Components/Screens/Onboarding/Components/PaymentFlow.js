import { __ } from '@wordpress/i18n';

import BadgeBox from '../../../ReusableComponents/BadgeBox';
import PaymentMethodsGroup from './PaymentMethodsGroup';
import { PayPalCheckout } from './PaymentOptions';
import { usePaymentConfig } from '../hooks/usePaymentConfig';

const DefaultMethodsSection = ( {
	methods,
	learnMoreConfig,
	paypalCheckoutDescription,
} ) => {
	return (
		<div className="ppcp-r-welcome-docs__col">
			<PayPalCheckout
				learnMore={ learnMoreConfig.PayPalCheckout }
				description={ paypalCheckoutDescription }
			/>
			<BadgeBox
				title={ __( 'Included in PayPal Checkout', 'woocommerce' ) }
			/>
			<PaymentMethodsGroup
				methods={ methods }
				learnMoreConfig={ learnMoreConfig }
			/>
		</div>
	);
};

/**
 * Displays the payment method details, tailored to the defined merchant.
 *
 * @param {Object} props
 * @param {string} props.storeCountry The merchant's store country. 2-character ISO code.
 * @return {React.ReactElement} The payment options component.
 */
const PaymentFlow = ( { storeCountry } ) => {
	const { includedMethods, learnMoreConfig, paypalCheckoutDescription } =
		usePaymentConfig( storeCountry );

	return (
		<div className="ppcp-r-welcome-docs__wrapper">
			<DefaultMethodsSection
				methods={ includedMethods }
				learnMoreConfig={ learnMoreConfig }
				paypalCheckoutDescription={ paypalCheckoutDescription }
			/>
		</div>
	);
};

export default PaymentFlow;
