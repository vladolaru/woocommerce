import { __ } from '@wordpress/i18n';
import { useCallback } from '@wordpress/element';

import { CommonHooks, PaymentHooks } from '@ppcp-settings/data';
import { useActiveModal } from '@ppcp-settings/data/common/hooks';
import Modal from '../Components/Payment/Modal';
import PaymentMethodCard from '../Components/Payment/PaymentMethodCard';

const TabPaymentMethods = () => {
	const methods = PaymentHooks.usePaymentMethods();
	const store = PaymentHooks.useStore();
	const { setPersistent, changePaymentSettings } = store;
	const { activeModal, setActiveModal } = useActiveModal();

	// Get all methods as a map for dependency checking
	const methodsMap = {};
	methods.all.forEach( ( method ) => {
		methodsMap[ method.id ] = method;
	} );

	const getActiveMethod = () => {
		if ( ! activeModal ) {
			return null;
		}
		return methods.all.find( ( method ) => method.id === activeModal );
	};

	const handleSave = useCallback(
		( methodId, settings ) => {
			changePaymentSettings( methodId, {
				title: settings.checkoutPageTitle,
				description: settings.checkoutPageDescription,
			} );

			const persistentSettings = [
				'paypalShowLogo',
				'fastlaneDisplayWatermark',
				'puiBrandName',
				'puiLogoUrl',
				'puiCustomerServiceInstructions',
			];

			persistentSettings.forEach( ( setting ) => {
				if ( setting in settings ) {
					// TODO: Create a dedicated setter for those values.
					setPersistent( setting, settings[ setting ] );
				}
			} );

			setActiveModal( null );
		},
		[ changePaymentSettings, setActiveModal, setPersistent ]
	);

	const merchant = CommonHooks.useMerchant();

	const showApms = methods.apm.length > 0 && merchant.isBusinessSeller;

	return (
		<div className="ppcp-r-payment-methods">
			<PaymentMethodCard
				id="ppcp-paypal-checkout-card"
				title={ __( 'PayPal Checkout', 'woocommerce' ) }
				description={ __(
					'Select your preferred checkout option with PayPal for easy payment processing.',
					'woocommerce'
				) }
				icon="icon-checkout-standard.svg"
				methods={ methods.paypal }
				onTriggerModal={ setActiveModal }
				methodsMap={ methodsMap }
			/>

			{ showApms && (
				<PaymentMethodCard
					id="ppcp-alternative-payments-card"
					title={ __(
						'Alternative Payment Methods',
						'woocommerce'
					) }
					description={ __(
						'With alternative payment methods, customers across the globe can pay with their bank accounts and other local payment methods.',
						'woocommerce'
					) }
					icon="icon-checkout-alternative-methods.svg"
					methods={ methods.apm }
					onTriggerModal={ setActiveModal }
					methodsMap={ methodsMap }
					showBulkToggle={ methods.apm.length > 1 }
					groupName="Alternative Payment"
				/>
			) }

			{ activeModal && (
				<Modal
					method={ getActiveMethod() }
					setModalIsVisible={ () => setActiveModal( null ) }
					onSave={ handleSave }
				/>
			) }
		</div>
	);
};

export default TabPaymentMethods;
