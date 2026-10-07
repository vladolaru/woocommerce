import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

import PaymentMethodIcons from '../../../ReusableComponents/PaymentMethodIcons';
import { Separator } from '../../../ReusableComponents/Elements';
import Accordion from '../../../ReusableComponents/AccordionSection';
import { CommonHooks } from '../../../../data';
import BusyStateWrapper from '../../../ReusableComponents/BusyStateWrapper';
import HelpSection from '../../../ReusableComponents/HelpSection';
import OnboardingHeader from '../Components/OnboardingHeader';
import WelcomeDocs from '../Components/WelcomeDocs';
import AdvancedOptionsForm from '../Components/AdvancedOptionsForm';
import { usePaymentConfig } from '../hooks/usePaymentConfig';

const WelcomeFeatures = () => {
	return (
		<div className="ppcp-r-welcome-features">
			<div className="ppcp-r-welcome-features__col">
				<span>{ __( 'Deposits', 'woocommerce' ) }</span>
				<p>{ __( 'Instant', 'woocommerce' ) }</p>
			</div>
			<div className="ppcp-r-welcome-features__col">
				<span>{ __( 'Payment Capture', 'woocommerce' ) }</span>
				<p>{ __( 'Authorize only or Capture', 'woocommerce' ) }</p>
			</div>
			<div className="ppcp-r-welcome-features__col">
				<span>{ __( 'Recurring payments', 'woocommerce' ) }</span>
				<p>{ __( 'Supported', 'woocommerce' ) }</p>
			</div>
		</div>
	);
};

const StepWelcome = ( { onNext } ) => {
	const { storeCountry } = CommonHooks.useWooSettings();
	const { icons } = usePaymentConfig( storeCountry );

	const onboardingHeaderDescription = __(
		'Your all-in-one integration for PayPal checkout solutions that enable buyers to pay via PayPal, Pay Later, and more.',
		'woocommerce'
	);

	return (
		<div className="ppcp-r-page-welcome">
			<OnboardingHeader
				title={ __( 'Welcome to PayPal Wallet', 'woocommerce' ) }
				description={ onboardingHeaderDescription }
			/>
			<div className="ppcp-r-inner-container">
				<WelcomeFeatures />
				<PaymentMethodIcons icons={ icons } />
				<p className="ppcp-r-button__description">
					{ __(
						'Click the button below to be guided through connecting your existing PayPal account or creating a new one. You will be able to choose the payment options that are right for your store.',
						'woocommerce'
					) }
				</p>
				<BusyStateWrapper>
					<Button
						className="ppcp-r-button-activate-paypal"
						variant="primary"
						onClick={ onNext }
					>
						{ __( 'Activate PayPal Wallet', 'woocommerce' ) }
					</Button>
				</BusyStateWrapper>
			</div>
			<Separator className="ppcp-r-page-welcome-mode-separator" />
			<WelcomeDocs storeCountry={ storeCountry } />
			<Separator text={ __( 'or', 'woocommerce' ) } />
			<Accordion
				title={ __( 'See advanced options', 'woocommerce' ) }
				className="onboarding-advanced-options"
				noCaps={ true }
				id="advanced-options"
			>
				<AdvancedOptionsForm />
			</Accordion>
			<HelpSection />
		</div>
	);
};

export default StepWelcome;
