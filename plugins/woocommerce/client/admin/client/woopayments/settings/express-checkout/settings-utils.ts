/**
 * Internal dependencies
 */
import {
	asBoolean,
	asSettingsRecord,
	asStringArray,
	type SettingsRecord,
} from '../settings-values';

export type ExpressCheckoutMethod = 'woopay' | 'payment_request' | 'amazon_pay';

export const EXPRESS_CHECKOUT_METHOD_TITLES: Record<
	ExpressCheckoutMethod,
	string
> = {
	woopay: 'WooPay',
	payment_request: 'Apple Pay / Google Pay',
	amazon_pay: 'Amazon Pay',
};

export type ExpressCheckoutFeatureFlagName =
	| 'woopay'
	| 'woopayExpressCheckout'
	| 'isDynamicCheckoutPlaceOrderButtonEnabled'
	| 'amazonPay';

export type ExpressCheckoutFeatureFlags = Record<
	ExpressCheckoutFeatureFlagName,
	boolean
>;

const DEFAULT_EXPRESS_CHECKOUT_FEATURE_FLAGS: ExpressCheckoutFeatureFlags = {
	woopay: true,
	woopayExpressCheckout: true,
	isDynamicCheckoutPlaceOrderButtonEnabled: true,
	amazonPay: true,
};

export const isExpressFeatureEnabled = (
	settings: SettingsRecord,
	flagName: ExpressCheckoutFeatureFlagName,
	fallback = DEFAULT_EXPRESS_CHECKOUT_FEATURE_FLAGS[ flagName ]
) => {
	const flags = asSettingsRecord( settings.feature_flags );

	return asBoolean( flags[ flagName ], fallback );
};

export const getExpressCheckoutFeatureFlags = (
	settings: SettingsRecord
): ExpressCheckoutFeatureFlags => ( {
	woopay: isExpressFeatureEnabled( settings, 'woopay' ),
	woopayExpressCheckout: isExpressFeatureEnabled(
		settings,
		'woopayExpressCheckout'
	),
	isDynamicCheckoutPlaceOrderButtonEnabled: isExpressFeatureEnabled(
		settings,
		'isDynamicCheckoutPlaceOrderButtonEnabled'
	),
	amazonPay: isExpressFeatureEnabled( settings, 'amazonPay' ),
} );

// Client 11.1.0 offers WooPay on the `woopay` flag alone; `woopayExpressCheckout` only hides the subpage's 'general' section.
export const isWooPayAvailable = ( settings: SettingsRecord ) =>
	isExpressFeatureEnabled( settings, 'woopay' );

export const isAmazonPayExpressCheckoutAvailable = (
	settings: SettingsRecord
) => {
	const flags = getExpressCheckoutFeatureFlags( settings );
	const availablePaymentMethodIds = asStringArray(
		settings.available_payment_method_ids
	);

	return (
		flags.amazonPay && availablePaymentMethodIds.includes( 'amazon_pay' )
	);
};

export const isExpressCheckoutInPaymentMethodsListSupported = (
	settings: SettingsRecord
) =>
	isExpressFeatureEnabled(
		settings,
		'isDynamicCheckoutPlaceOrderButtonEnabled'
	);
