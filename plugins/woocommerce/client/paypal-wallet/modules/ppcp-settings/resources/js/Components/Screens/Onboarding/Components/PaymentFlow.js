import { __ } from '@wordpress/i18n';

import BadgeBox from '@ppcp-settings/Components/ReusableComponents/BadgeBox';
import PaymentMethodsGroup from './PaymentMethodsGroup';
import { PayPalCheckout } from './PaymentOptions';
import { usePaymentConfig } from '../hooks/usePaymentConfig';

/**
 * Displays the payment method details, tailored to the defined merchant.
 *
 * @param {Object}  props
 * @param {boolean} props.useAcdc      Whether to include advanced card payments. When false, only BCDC items are included.
 * @param {boolean} props.isFastlane   Whether Fastlane should be included.
 * @param {string}  props.storeCountry The merchant's store country. 2-character ISO code.
 * @param {boolean} props.ownBrandOnly Whether to show only PayPal's own payment methods.
 * @return {JSX.Element} The payment options component.
 * @class
 */
const PaymentFlow = ( {
	useAcdc,
	isFastlane,
	storeCountry,
	ownBrandOnly,
} ) => {
	const {
		includedMethods,
		optionalMethods,
		optionalTitle,
		optionalDescription,
		learnMoreConfig,
		paypalCheckoutDescription,
	} = usePaymentConfig(
		storeCountry,
		useAcdc,
		isFastlane,
		ownBrandOnly
	);

	const description = useAcdc ? optionalDescription : '';
	return (
		<div className="ppcp-r-welcome-docs__wrapper">
			<DefaultMethodsSection
				methods={ includedMethods }
				learnMoreConfig={ learnMoreConfig }
				paypalCheckoutDescription={ paypalCheckoutDescription }
			/>

			<OptionalMethodsSection
				title={ optionalTitle }
				description={ description }
				methods={ optionalMethods }
				learnMoreConfig={ learnMoreConfig }
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
				title={ __(
					'Included in PayPal Checkout',
					'woocommerce'
				) }
			/>
			<PaymentMethodsGroup
				methods={ methods }
				learnMoreConfig={ learnMoreConfig }
			/>
		</div>
	);
};

const OptionalMethodsSection = ( {
	title = '',
	description = '',
	methods,
	learnMoreConfig,
} ) => {
	if ( ! methods.length ) {
		return null;
	}

	return (
		<div className="ppcp-r-welcome-docs__col">
			{ title && (
				<BadgeBox
					title={ title }
					description={ description }
					learnMoreLink={ learnMoreConfig.OptionalMethods }
				/>
			) }
			<PaymentMethodsGroup
				methods={ methods }
				learnMoreConfig={ learnMoreConfig }
			/>
		</div>
	);
};
