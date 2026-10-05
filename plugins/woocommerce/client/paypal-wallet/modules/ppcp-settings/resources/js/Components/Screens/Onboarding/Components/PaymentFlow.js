import { __ } from '@wordpress/i18n';

import BadgeBox from '@ppcp-settings/Components/ReusableComponents/BadgeBox';
import PaymentMethodsGroup from './PaymentMethodsGroup';
import { PayPalCheckout } from './PaymentOptions';
import { usePaymentConfig } from '../hooks/usePaymentConfig';

/**
 * Displays the payment method details, tailored to the defined merchant.
 *
 * @param {Object}  props
 * @param {boolean} props.useAcdc      Whether the merchant can use card payments.
 * @param {string}  props.storeCountry The merchant's store country. 2-character ISO code.
 * @param {boolean} props.ownBrandOnly Whether to show only PayPal's own payment methods.
 * @return {React.ReactElement} The payment options component.
 * @class
 */
const PaymentFlow = ( { useAcdc, storeCountry, ownBrandOnly } ) => {
	const { includedMethods, learnMoreConfig, paypalCheckoutDescription } =
		usePaymentConfig( storeCountry, useAcdc, ownBrandOnly );

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
