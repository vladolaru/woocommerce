/**
 * External dependencies
 */
import { Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { WooPaymentsPaymentMethodsList } from '../payment-methods-list';
import { SettingsSection } from '../settings-shell';
import {
	useEnabledPaymentMethodIds,
	useGetAccountFees,
	useGetAvailablePaymentMethodIds,
	useGetPaymentMethodStatuses,
	useGetSettings,
	useManualCapture,
	useSelectedPaymentMethod as usePaymentMethodSelection,
	useUnselectedPaymentMethod as usePaymentMethodDeselection,
} from '../data/hooks';
import {
	asBoolean,
	asSettingsRecord,
	asString,
	asStringArray,
} from '../settings-values';
import { FieldGroup } from './field-group';
import { PaymentMethodsSectionDescription } from './section-descriptions';
import {
	type BooleanSetting,
	type StringArraySetting,
	type PaymentMethodStatus,
	BNPL_METHOD_IDS,
	asAccountFees,
	asPmPromotions,
	useDuplicatePaymentMethodNotices,
} from './utils';

const EXPRESS_METHOD_IDS = [ 'payment_request', 'woopay', 'amazon_pay' ];
const STANDARD_PAYMENT_METHOD_EXCLUDED_IDS = [
	...EXPRESS_METHOD_IDS,
	'apple_pay',
	'google_pay',
	'link',
];

/**
 * The payment methods list for the given method ids, wired to the settings store; the payment methods and the buy now,
 * pay later sections each pass their own ids.
 *
 * @param props           The component props.
 * @param props.methodIds The method ids to list.
 */
const SettingsPaymentMethodsList = ( {
	methodIds,
}: {
	methodIds: string[];
} ) => {
	const settings = asSettingsRecord( useGetSettings() );
	const statuses = asSettingsRecord( useGetPaymentMethodStatuses() );
	const accountFees = asAccountFees( useGetAccountFees() );
	const {
		duplicatedPaymentMethodIds,
		dismissedDuplicatePaymentMethodNotices,
		onDismissDuplicateNotice,
	} = useDuplicatePaymentMethodNotices();
	const pmPromotions = asPmPromotions( settings.pm_promotions );
	const [ enabledMethodIds ] =
		useEnabledPaymentMethodIds() as StringArraySetting;
	const [ , addPaymentMethod ] = usePaymentMethodSelection() as [
		string[],
		( id: string ) => void,
	];
	const [ , removePaymentMethod ] = usePaymentMethodDeselection() as [
		string[],
		( id: string ) => void,
	];
	const [ isManualCaptureEnabled ] = useManualCapture() as BooleanSetting;
	const accountCountry = asString( settings.account_country );
	const storeCurrency = asString( settings.store_currency );
	const isMultiCurrencyEnabled = asBoolean(
		settings.is_multi_currency_enabled,
		false
	);

	return (
		<WooPaymentsPaymentMethodsList
			methodIds={ methodIds }
			enabledMethodIds={ enabledMethodIds }
			statuses={
				statuses as Record< string, PaymentMethodStatus | undefined >
			}
			accountFees={ accountFees }
			pmPromotions={ pmPromotions }
			duplicatedPaymentMethodIds={ duplicatedPaymentMethodIds }
			dismissedDuplicatePaymentMethodNotices={
				dismissedDuplicatePaymentMethodNotices
			}
			isManualCaptureEnabled={ Boolean( isManualCaptureEnabled ) }
			isMultiCurrencyEnabled={ isMultiCurrencyEnabled }
			storeCurrency={ storeCurrency }
			accountCountry={ accountCountry }
			onEnable={ addPaymentMethod }
			onDisable={ removePaymentMethod }
			onDismissDuplicateNotice={ onDismissDuplicateNotice }
		/>
	);
};

const PaymentMethodsSettingsSection = () => {
	const standardPaymentMethodIds = asStringArray(
		useGetAvailablePaymentMethodIds()
	).filter(
		( methodId ) =>
			! STANDARD_PAYMENT_METHOD_EXCLUDED_IDS.includes( methodId ) &&
			! BNPL_METHOD_IDS.includes( methodId )
	);
	const [ isManualCaptureEnabled ] = useManualCapture() as BooleanSetting;

	return (
		<SettingsSection
			id="payment-methods"
			title={ __( 'Payments accepted on checkout', 'woocommerce' ) }
			description={ <PaymentMethodsSectionDescription /> }
		>
			<FieldGroup title={ __( 'Payment methods', 'woocommerce' ) }>
				{ isManualCaptureEnabled && (
					<Notice
						status="warning"
						isDismissible={ false }
						className="woopayments-settings-payment-methods__manual-capture-notice"
					>
						{ __(
							"Manual capture is enabled, so any payment methods that don't support it have been automatically disabled.",
							'woocommerce'
						) }
					</Notice>
				) }
				<SettingsPaymentMethodsList
					methodIds={ standardPaymentMethodIds }
				/>
			</FieldGroup>
		</SettingsSection>
	);
};

export { PaymentMethodsSettingsSection, SettingsPaymentMethodsList };
