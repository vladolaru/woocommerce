import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

import { CommonHooks, OnboardingHooks } from '@ppcp-settings/data';
import { OptionSelector } from '@ppcp-settings/Components/ReusableComponents/Fields';
import PricingDescription from '../Components/PricingDescription';
import OnboardingHeader from '../Components/OnboardingHeader';
import PaymentFlow from '../Components/PaymentFlow';

const StepPaymentMethods = () => {
	const { optionalMethods, setOptionalMethods } =
		OnboardingHooks.useOptionalPaymentMethods();
	const { ownBrandOnly } = CommonHooks.useWooSettings();
	const { isCasualSeller } = OnboardingHooks.useBusiness();
	const { canUseCardPayments } = OnboardingHooks.useFlags();

	const hasAdvancedMethods = canUseCardPayments;

	const optionalMethodTitle = useMemo( () => {
		// The BCDC flow does not show a title. No ACDC does not show a title.
		if ( isCasualSeller || ! hasAdvancedMethods ) {
			return null;
		}

		return __(
			'Available with additional application',
			'woocommerce'
		);
	}, [ isCasualSeller, hasAdvancedMethods ] );

	const methodChoices = [
		{
			value: true,
			title: optionalMethodTitle,
			description: <OptionalMethodDescription />,
		},
		{
			title: __(
				'No thanks, I prefer to use a different provider for local payment methods',
				'woocommerce'
			),
			value: false,
		},
	];

	const handleMethodChange = ( value ) => {
		setOptionalMethods( value, 'user' );
	};

	return (
		<div className="ppcp-r-page-optional-payment-methods">
			<OnboardingHeader
				title={ <PaymentStepTitle isBrandedOnly={ ownBrandOnly } /> }
			/>
			<div className="ppcp-r-inner-container">
				<OptionSelector
					multiSelect={ false }
					options={ methodChoices }
					onChange={ handleMethodChange }
					value={ optionalMethods }
				/>

				<PricingDescription />
			</div>
		</div>
	);
};

export default StepPaymentMethods;

const PaymentStepTitle = ( ownBrandOnly ) => {
	if ( ownBrandOnly.isBrandedOnly ) {
		return __(
			'Add Expanded Checkout for more ways to pay',
			'woocommerce'
		);
	}
	return __(
		'Add Expanded Checkout for more ways to pay',
		'woocommerce'
	);
};

const OptionalMethodDescription = () => {
	const { isCasualSeller } = OnboardingHooks.useBusiness();
	const { storeCountry, storeCurrency, ownBrandOnly } =
		CommonHooks.useWooSettings();
	const { canUseCardPayments, canUseFastlane } = OnboardingHooks.useFlags();

	return (
		<PaymentFlow
			onlyOptional={ true }
			useAcdc={ ! isCasualSeller && canUseCardPayments }
			isFastlane={ canUseFastlane }
			isPayLater={ true }
			ownBrandOnly={ ownBrandOnly }
			storeCountry={ storeCountry }
			storeCurrency={ storeCurrency }
		/>
	);
};
