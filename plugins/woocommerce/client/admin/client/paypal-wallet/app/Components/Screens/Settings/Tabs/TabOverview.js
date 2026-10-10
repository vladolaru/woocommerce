import { __ } from '@wordpress/i18n';
import { TodosHooks, CommonHooks, FeaturesHooks } from '../../../../data';
import SpinnerOverlay from '../../../ReusableComponents/SpinnerOverlay';
import usePaymentGatewaySync from '../../../../hooks/usePaymentGatewaySync';
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
			{ /* POC seam (PayPal Wallet in core): the collecting panel; the import below is hoisted. */ }
			{ /* eslint-disable-next-line @typescript-eslint/no-use-before-define */ }
			<CollectingPanel />
			<Todos />
			<Features />
		</div>
	);
};

// POC seam (PayPal Wallet in core): the panel renders only when `ppcpSettings.collecting` is present. The import sits
// next to the mount so the seam stays one hunk.
import { CollectingPanel } from '../../../../../collecting/CollectingPanel';

export default TabOverview;
