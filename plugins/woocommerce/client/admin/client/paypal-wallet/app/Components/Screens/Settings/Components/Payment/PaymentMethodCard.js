import SettingsCard from '../../../../ReusableComponents/SettingsCard';
import { PaymentMethodsBlock } from '../../../../ReusableComponents/SettingsBlocks';
import usePaymentDependencyState from '../../../../../hooks/usePaymentDependencyState';
import useSettingDependencyState from '../../../../../hooks/useSettingDependencyState';
import useDependencyMessages from '../../../../../hooks/useDependencyMessages';
import useMethodWarnings from '../../../../../hooks/useMethodWarnings';
import SpinnerOverlay from '../../../../ReusableComponents/SpinnerOverlay';
import {
	PaymentHooks,
	SettingsHooks,
	OnboardingHooks,
} from '../../../../../data';
import usePaymentGatewayRefresh from '../../../../../hooks/usePaymentGatewayRefresh';

/**
 * Renders a payment method card with dependency handling
 *
 * @param {Object}   props                - Component props
 * @param {string}   props.id             - Unique identifier for the card
 * @param {string}   props.title          - Title of the payment method card
 * @param {string}   props.description    - Description of the payment method
 * @param {string}   props.icon           - Icon path for the payment method
 * @param {Array}    props.methods        - List of payment methods to display
 * @param {Object}   props.methodsMap     - Map of all payment methods by ID
 * @param {Function} props.onTriggerModal - Callback when a method is clicked
 * @param {boolean}  props.isDisabled     - Whether the entire card is disabled
 * @return {React.ReactElement} The rendered component
 */
const PaymentMethodCard = ( {
	id,
	title,
	description,
	icon,
	methods,
	methodsMap = {},
	onTriggerModal,
	isDisabled = false,
} ) => {
	const { isReady: isPaymentStoreReady } = PaymentHooks.useStore();
	const { isReady: isSettingsStoreReady } = SettingsHooks.useStore();
	const { gatewaysRefreshed } = OnboardingHooks.useGatewayRefresh();

	// Re-fetch payment gateway data to hide methods based on exclusion conditions.
	usePaymentGatewayRefresh();

	const paymentDependencies = usePaymentDependencyState(
		methods,
		methodsMap
	);

	const settingDependencies = useSettingDependencyState( methods );

	const dependencyMessagesMap = useDependencyMessages(
		methods,
		paymentDependencies,
		settingDependencies,
		isDisabled
	);

	// Evaluate reactive warning visibility conditions against store data.
	const methodsWithWarnings = useMethodWarnings( methods );

	if (
		! isPaymentStoreReady ||
		! isSettingsStoreReady ||
		! gatewaysRefreshed
	) {
		return <SpinnerOverlay asModal={ true } />;
	}

	// Process methods with dependencies from the pre-computed map.
	const processedMethods = methodsWithWarnings.map( ( method ) => {
		const dependencyInfo = dependencyMessagesMap[ method.id ] || {};

		return {
			...method,
			isDisabled:
				dependencyInfo.isMethodDisabled ||
				method.isDisabled ||
				isDisabled,
			disabledMessage: dependencyInfo.dependencyMessage,
		};
	} );

	return (
		<SettingsCard
			id={ id }
			title={ title }
			description={ description }
			icon={ icon }
			contentContainer={ false }
		>
			<PaymentMethodsBlock
				paymentMethods={ processedMethods }
				onTriggerModal={ onTriggerModal }
			/>
		</SettingsCard>
	);
};

export default PaymentMethodCard;
