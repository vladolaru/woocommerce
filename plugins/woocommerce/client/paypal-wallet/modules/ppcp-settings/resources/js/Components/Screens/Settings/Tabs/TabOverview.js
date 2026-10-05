import { __ } from '@wordpress/i18n';
import { TodosHooks, CommonHooks, FeaturesHooks } from '@ppcp-settings/data';
import SpinnerOverlay from '@ppcp-settings/Components/ReusableComponents/SpinnerOverlay';
import usePaymentGatewaySync from '@ppcp-settings/hooks/usePaymentGatewaySync';
import Features from '../Components/Overview/Features/Features';
import Todos from '../Components/Overview/Todos/Todos';

const TabOverview = () => {
	const { isReady: areTodosReady } = TodosHooks.useTodos();
	const { isReady: merchantIsReady } = CommonHooks.useMerchantInfo();
	const { isReady: featuresIsReady } = FeaturesHooks.useFeatures();

	// Enable payment gateways after onboarding based on relevant flags.
	usePaymentGatewaySync();

	if ( ! areTodosReady || ! merchantIsReady || ! featuresIsReady ) {
		return (
			<SpinnerOverlay
				asModal={ true }
				ariaLabel={ __( 'Loading PayPal settings', 'woocommerce' ) }
			/>
		);
	}

	return (
		<div
			className="ppcp-r-tab-overview"
			role="region"
			aria-label={ __( 'PayPal Overview', 'woocommerce' ) }
		>
			<Todos />
			<Features />
		</div>
	);
};

export default TabOverview;
