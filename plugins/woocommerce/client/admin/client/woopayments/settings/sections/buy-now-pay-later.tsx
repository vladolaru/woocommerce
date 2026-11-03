/**
 * External dependencies
 */
import { ExternalLink } from '@wordpress/components';
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
import {
	type BooleanSetting,
	type StringArraySetting,
	type PaymentMethodStatus,
	BNPL_METHOD_IDS,
	asAccountFees,
	asPmPromotions,
	useDuplicatePaymentMethodNotices,
} from './utils';

const BNPL_DOC_URL =
	'https://woocommerce.com/document/woopayments/payment-methods/buy-now-pay-later/';

const BuyNowPayLaterSettingsSection = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const availablePaymentMethodIds = asStringArray(
		useGetAvailablePaymentMethodIds()
	);
	const statuses = asSettingsRecord( useGetPaymentMethodStatuses() );
	const accountFees = asAccountFees( useGetAccountFees() );
	const {
		duplicatedPaymentMethodIds,
		dismissedDuplicatePaymentMethodNotices,
		onDismissDuplicateNotice,
	} = useDuplicatePaymentMethodNotices();
	const pmPromotions = asPmPromotions( settings.pm_promotions );
	const availableBuyNowPayLaterMethodIds = availablePaymentMethodIds.filter(
		( methodId ) => BNPL_METHOD_IDS.includes( methodId )
	);
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
		true
	);

	return availableBuyNowPayLaterMethodIds.length === 0 ? null : (
		<SettingsSection
			id="buy-now-pay-later-methods"
			title={ __( 'Buy now, pay later', 'woocommerce' ) }
			description={
				<>
					<p>
						{ __(
							'Boost sales by offering customers additional buying power and flexible payment options.',
							'woocommerce'
						) }
					</p>
					<ExternalLink href={ BNPL_DOC_URL }>
						{ __( 'Learn more', 'woocommerce' ) }
					</ExternalLink>
				</>
			}
		>
			<WooPaymentsPaymentMethodsList
				methodIds={ availableBuyNowPayLaterMethodIds }
				enabledMethodIds={ enabledMethodIds }
				statuses={
					statuses as Record<
						string,
						PaymentMethodStatus | undefined
					>
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
		</SettingsSection>
	);
};

export { BNPL_DOC_URL, BuyNowPayLaterSettingsSection };
