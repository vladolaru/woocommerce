/**
 * External dependencies
 */
import { useDispatch, useSelect } from '@wordpress/data';
import type { MapSelect } from '@wordpress/data/build-types/types';

/**
 * Internal dependencies
 */
import { registerWooPaymentsSettingsStore, STORE_NAME } from './register';

const useRegisteredDispatch = () => {
	registerWooPaymentsSettingsStore();

	return useDispatch( STORE_NAME );
};

const useRegisteredSelect = ( mapSelect: MapSelect, deps?: unknown[] ) => {
	registerWooPaymentsSettingsStore();

	// eslint-disable-next-line react-hooks/exhaustive-deps -- This wrapper preserves useSelect's caller-provided dependency contract.
	return useSelect( mapSelect, deps );
};

const makeSettingHook = ( selectorName: string, actionName: string ) => () => {
	const actions = useRegisteredDispatch();
	const value = useRegisteredSelect( ( select ) =>
		select( STORE_NAME )[ selectorName ]()
	);

	return [ value, actions[ actionName ] ];
};

export const useSavedCards = makeSettingHook(
	'getIsSavedCardsEnabled',
	'updateIsSavedCardsEnabled'
);
export const useCardPresentEligible = makeSettingHook(
	'getIsCardPresentEligible',
	'updateIsCardPresentEligible'
);
export const useEnabledPaymentMethodIds = makeSettingHook(
	'getEnabledPaymentMethodIds',
	'updateEnabledPaymentMethodIds'
);
export const useDebugLog = makeSettingHook(
	'getIsDebugLogEnabled',
	'updateIsDebugLogEnabled'
);
export const useTestMode = makeSettingHook(
	'getIsTestModeEnabled',
	'updateIsTestModeEnabled'
);
export const useMultiCurrency = makeSettingHook(
	'getIsMultiCurrencyEnabled',
	'updateIsMultiCurrencyEnabled'
);
export const useAccountStatementDescriptor = makeSettingHook(
	'getAccountStatementDescriptor',
	'updateAccountStatementDescriptor'
);
export const useAccountStatementDescriptorKanji = makeSettingHook(
	'getAccountStatementDescriptorKanji',
	'updateAccountStatementDescriptorKanji'
);
export const useAccountStatementDescriptorKana = makeSettingHook(
	'getAccountStatementDescriptorKana',
	'updateAccountStatementDescriptorKana'
);
export const useAccountBusinessSupportEmail = makeSettingHook(
	'getAccountBusinessSupportEmail',
	'updateAccountBusinessSupportEmail'
);
export const useAccountBusinessSupportPhone = makeSettingHook(
	'getAccountBusinessSupportPhone',
	'updateAccountBusinessSupportPhone'
);
export const useDepositScheduleInterval = makeSettingHook(
	'getDepositScheduleInterval',
	'updateDepositScheduleInterval'
);
export const useDepositScheduleWeeklyAnchor = makeSettingHook(
	'getDepositScheduleWeeklyAnchor',
	'updateDepositScheduleWeeklyAnchor'
);
export const useDepositScheduleMonthlyAnchor = makeSettingHook(
	'getDepositScheduleMonthlyAnchor',
	'updateDepositScheduleMonthlyAnchor'
);
export const useManualCapture = makeSettingHook(
	'getIsManualCaptureEnabled',
	'updateIsManualCaptureEnabled'
);
export const useIsWCPayEnabled = makeSettingHook(
	'getIsWCPayEnabled',
	'updateIsWCPayEnabled'
);
export const usePaymentRequestEnabledSettings = makeSettingHook(
	'getIsPaymentRequestEnabled',
	'updateIsPaymentRequestEnabled'
);
export const useExpressCheckoutInPaymentMethodsEnabledSettings =
	makeSettingHook(
		'getIsExpressCheckoutInPaymentMethodsEnabled',
		'updateIsExpressCheckoutInPaymentMethodsEnabled'
	);
export const usePaymentRequestButtonType = makeSettingHook(
	'getPaymentRequestButtonType',
	'updatePaymentRequestButtonType'
);
export const usePaymentRequestButtonSize = makeSettingHook(
	'getPaymentRequestButtonSize',
	'updatePaymentRequestButtonSize'
);
export const usePaymentRequestButtonTheme = makeSettingHook(
	'getPaymentRequestButtonTheme',
	'updatePaymentRequestButtonTheme'
);
export const usePaymentRequestButtonBorderRadius = makeSettingHook(
	'getPaymentRequestButtonBorderRadius',
	'updatePaymentRequestButtonBorderRadius'
);
export const useWooPayEnabledSettings = makeSettingHook(
	'getIsWooPayEnabled',
	'updateIsWooPayEnabled'
);
export const useWooPayGlobalThemeSupportEnabledSettings = makeSettingHook(
	'getIsWooPayGlobalThemeSupportEnabled',
	'updateIsWooPayGlobalThemeSupportEnabled'
);
export const useWooPayCustomMessage = makeSettingHook(
	'getWooPayCustomMessage',
	'updateWooPayCustomMessage'
);
export const useWooPayStoreLogo = makeSettingHook(
	'getWooPayStoreLogo',
	'updateWooPayStoreLogo'
);
export const useCurrentProtectionLevel = makeSettingHook(
	'getCurrentProtectionLevel',
	'updateProtectionLevel'
);
export const useAdvancedFraudProtectionSettings = makeSettingHook(
	'getAdvancedFraudProtectionSettings',
	'updateAdvancedFraudProtectionSettings'
);
export const useAccountCommunicationsEmail = makeSettingHook(
	'getAccountCommunicationsEmail',
	'updateAccountCommunicationsEmail'
);

export const useAccountDomesticCurrency = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getAccountDomesticCurrency()
	);

export const useSelectedPaymentMethod = () => {
	const { updateSelectedPaymentMethod } = useRegisteredDispatch();
	const enabledPaymentMethodIds = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getEnabledPaymentMethodIds()
	);

	return [ enabledPaymentMethodIds, updateSelectedPaymentMethod ];
};

export const useUnselectedPaymentMethod = () => {
	const { updateUnselectedPaymentMethod } = useRegisteredDispatch();
	const enabledPaymentMethodIds = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getEnabledPaymentMethodIds()
	);

	return [ enabledPaymentMethodIds, updateUnselectedPaymentMethod ];
};

export const useTestModeOnboarding = () =>
	useRegisteredSelect(
		( select ) => select( STORE_NAME ).getIsTestModeOnboarding(),
		[]
	);

export const useDevMode = () =>
	useRegisteredSelect(
		( select ) => select( STORE_NAME ).getIsDevModeEnabled(),
		[]
	);

export const useWCPaySubscriptions = () => {
	const { updateIsWCPaySubscriptionsEnabled } = useRegisteredDispatch();
	const isWCPaySubscriptionsEnabled = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getIsWCPaySubscriptionsEnabled()
	);
	const isWCPaySubscriptionsEligible = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getIsWCPaySubscriptionsEligible()
	);

	return [
		isWCPaySubscriptionsEnabled,
		isWCPaySubscriptionsEligible,
		updateIsWCPaySubscriptionsEnabled,
	];
};

export const useDepositDelayDays = () =>
	useRegisteredSelect(
		( select ) => select( STORE_NAME ).getDepositDelayDays(),
		[]
	);

export const useCompletedWaitingPeriod = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getCompletedWaitingPeriod()
	);

export const useDepositStatus = () =>
	useRegisteredSelect(
		( select ) => select( STORE_NAME ).getDepositStatus(),
		[]
	);

export const useDepositRestrictions = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getDepositRestrictions()
	);

export const useGetAvailablePaymentMethodIds = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getAvailablePaymentMethodIds()
	);

export const useGetPaymentMethodStatuses = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getPaymentMethodStatuses()
	);

export const useGetDuplicatedPaymentMethodIds = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getDuplicatedPaymentMethodIds()
	);

export const useDismissedDuplicatePaymentMethodNotices = () => {
	const { updateDismissedDuplicatePaymentMethodNotices } =
		useRegisteredDispatch();
	const dismissedNotices = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getDismissedDuplicatePaymentMethodNotices()
	);

	return [ dismissedNotices, updateDismissedDuplicatePaymentMethodNotices ];
};

export const useGetAccountFees = () =>
	useRegisteredSelect( ( select ) => select( STORE_NAME ).getAccountFees() );

export const useGetSettings = () =>
	useRegisteredSelect( ( select ) => select( STORE_NAME ).getSettings() );

export const useSettings = () => {
	const { saveSettings } = useRegisteredDispatch();
	const isSaving = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).isSavingSettings()
	);
	const isDirty = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).isDirty()
	);
	const isLoading = useRegisteredSelect( ( select ) => {
		select( STORE_NAME ).getSettings();
		const isResolving = select( STORE_NAME ).isResolving( 'getSettings' );
		const hasFinishedResolving =
			select( STORE_NAME ).hasFinishedResolution( 'getSettings' );

		return isResolving || ! hasFinishedResolving;
	} );

	return {
		isLoading,
		saveSettings,
		isSaving,
		isDirty,
	};
};

const makeExpressCheckoutLocationHook = ( methodId: string ) => () => {
	type ExpressCheckoutLocation = 'product' | 'cart' | 'checkout';
	const {
		updateExpressCheckoutProductMethods,
		updateExpressCheckoutCartMethods,
		updateExpressCheckoutCheckoutMethods,
	} = useRegisteredDispatch();

	const productMethods = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getExpressCheckoutProductMethods()
	);
	const cartMethods = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getExpressCheckoutCartMethods()
	);
	const checkoutMethods = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getExpressCheckoutCheckoutMethods()
	);

	const methodsListMap: Record< ExpressCheckoutLocation, string[] > = {
		product: productMethods,
		cart: cartMethods,
		checkout: checkoutMethods,
	};
	const methodsUpdatersMap: Record<
		ExpressCheckoutLocation,
		( methods: string[] ) => void
	> = {
		product: updateExpressCheckoutProductMethods,
		cart: updateExpressCheckoutCartMethods,
		checkout: updateExpressCheckoutCheckoutMethods,
	};
	const enabledLocations = [
		productMethods.includes( methodId ) && 'product',
		cartMethods.includes( methodId ) && 'cart',
		checkoutMethods.includes( methodId ) && 'checkout',
	].filter( Boolean );
	const locationUpdater = (
		location: ExpressCheckoutLocation,
		isChecked: boolean
	) => {
		methodsUpdatersMap[ location ](
			isChecked
				? [ ...methodsListMap[ location ], methodId ]
				: methodsListMap[ location ].filter(
						( method: string ) => method !== methodId
				  )
		);
	};

	return [ enabledLocations, locationUpdater ];
};

export const usePaymentRequestLocations =
	makeExpressCheckoutLocationHook( 'payment_request' );
export const useWooPayLocations = makeExpressCheckoutLocationHook( 'woopay' );
export const useAmazonPayLocations =
	makeExpressCheckoutLocationHook( 'amazon_pay' );

const usePaymentMethodEnabled = ( methodId: string ) => {
	const { updateEnabledPaymentMethodIds } = useRegisteredDispatch();
	const enabledPaymentMethodIds = useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getEnabledPaymentMethodIds()
	);
	const isEnabled = enabledPaymentMethodIds.includes( methodId );
	const updateIsEnabled = ( shouldEnable: boolean ) => {
		const enabledMethodIds = new Set( enabledPaymentMethodIds );

		if ( shouldEnable ) {
			enabledMethodIds.add( methodId );
		} else {
			enabledMethodIds.delete( methodId );
		}

		updateEnabledPaymentMethodIds( Array.from( enabledMethodIds ) );
	};

	return [ isEnabled, updateIsEnabled ];
};

export const useAmazonPayEnabledSettings = () =>
	usePaymentMethodEnabled( 'amazon_pay' );

export const useLinkEnabledSettings = ( isWooPayBlockingLink?: boolean ) => {
	const [ isLinkEnabled, updateIsLinkEnabled ] = usePaymentMethodEnabled(
		'link'
	) as [ boolean, ( isEnabled: boolean ) => void ];
	const [ isWooPayEnabled ] = useWooPayEnabledSettings() as [ boolean ];
	const shouldBlockLink = isWooPayBlockingLink ?? isWooPayEnabled;

	const updateStripeLinkCheckout = ( isEnabled: boolean ) => {
		if ( shouldBlockLink ) {
			return;
		}

		if ( isEnabled ) {
			updateIsLinkEnabled( true );
		} else {
			updateIsLinkEnabled( false );
		}
	};

	return [ isLinkEnabled, updateStripeLinkCheckout, shouldBlockLink ];
};

export const useWooPayShowIncompatibilityNotice = () =>
	useRegisteredSelect( ( select ) =>
		select( STORE_NAME ).getShowWooPayIncompatibilityNotice()
	);

export const useGetSavingError = () =>
	useRegisteredSelect(
		( select ) => select( STORE_NAME ).getSavingError(),
		[]
	);
