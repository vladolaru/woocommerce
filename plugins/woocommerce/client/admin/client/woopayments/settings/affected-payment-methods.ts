/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';
import { AMAZON_PAY_DEFINITION } from './amazon-pay-definition';
import { EXPRESS_CHECKOUT_METHOD_ICONS } from './express-checkout/method-icons';
import { getPaymentMethodDefinition } from './payment-method-definitions';
import { getPaymentMethodAvailability } from './payment-methods-list';
import {
	isAmazonPayExpressCheckoutAvailable,
	isWooPayExpressCheckoutAvailable,
} from './express-checkout/settings-utils';
import {
	useAmazonPayEnabledSettings,
	useEnabledPaymentMethodIds,
	useGetAvailablePaymentMethodIds,
	useGetPaymentMethodStatuses,
	useGetSettings,
	usePaymentRequestEnabledSettings,
	useWooPayEnabledSettings,
} from './data/hooks';

type SettingsRecord = Record< string, unknown >;
type BooleanSetting = [ boolean, ( value: boolean ) => void ];
type StringArraySetting = [ string[], ( value: string[] ) => void ];

export type WooPaymentsAffectedPaymentMethod = {
	id: string;
	label: string;
	iconUrl?: string;
};

const [ APPLE_PAY_ICON, GOOGLE_PAY_ICON ] =
	EXPRESS_CHECKOUT_METHOD_ICONS.payment_request;
const WOOPAY_ICON_URL = `${
	WC_ASSET_URL || ''
}images/payment-methods/woo-short.svg`;

const asSettingsRecord = ( value: unknown ): SettingsRecord =>
	value && typeof value === 'object' ? ( value as SettingsRecord ) : {};

const asString = ( value: unknown, fallback = '' ) =>
	typeof value === 'string' ? value : fallback;

const asStringArray = ( value: unknown ) =>
	Array.isArray( value )
		? value.filter( ( item ): item is string => typeof item === 'string' )
		: [];

const asRequirements = ( value: unknown ) =>
	Array.isArray( value ) ? value : [];

const addAffectedPaymentMethod = (
	methods: Map< string, WooPaymentsAffectedPaymentMethod >,
	method: WooPaymentsAffectedPaymentMethod
) => {
	if ( ! methods.has( method.id ) ) {
		methods.set( method.id, method );
	}
};

export const useWooPaymentsAffectedCheckoutMethods = () => {
	const settings = asSettingsRecord( useGetSettings() );
	const accountCountry = asString( settings.account_country );
	const [ enabledMethodIds ] =
		useEnabledPaymentMethodIds() as StringArraySetting;
	const [ isPaymentRequestEnabled ] =
		usePaymentRequestEnabledSettings() as BooleanSetting;
	const [ isWooPayEnabled ] = useWooPayEnabledSettings() as BooleanSetting;
	const [ isAmazonPayEnabled ] =
		useAmazonPayEnabledSettings() as BooleanSetting;
	const availablePaymentMethodIds = asStringArray(
		useGetAvailablePaymentMethodIds()
	);
	const statuses = asSettingsRecord( useGetPaymentMethodStatuses() );
	const amazonPayStatus = asSettingsRecord(
		statuses[ AMAZON_PAY_DEFINITION.stripeKey ]
	);
	const amazonPayAvailability = getPaymentMethodAvailability(
		AMAZON_PAY_DEFINITION,
		{
			status: asString( amazonPayStatus.status ),
			requirements: asRequirements( amazonPayStatus.requirements ),
		},
		false
	);
	const affectedMethods = new Map<
		string,
		WooPaymentsAffectedPaymentMethod
	>();

	enabledMethodIds
		.filter( ( methodId ) => methodId !== 'link' )
		.forEach( ( methodId ) => {
			const definition = getPaymentMethodDefinition(
				methodId,
				accountCountry
			);

			if ( definition ) {
				addAffectedPaymentMethod( affectedMethods, {
					id: definition.id,
					label: definition.label,
					iconUrl: definition.iconUrl,
				} );
			}
		} );

	if ( isPaymentRequestEnabled ) {
		addAffectedPaymentMethod( affectedMethods, {
			id: 'google_pay',
			label: __( 'Google Pay', 'woocommerce' ),
			iconUrl: GOOGLE_PAY_ICON.src,
		} );
		addAffectedPaymentMethod( affectedMethods, {
			id: 'apple_pay',
			label: __( 'Apple Pay', 'woocommerce' ),
			iconUrl: APPLE_PAY_ICON.src,
		} );
	}

	if (
		isAmazonPayEnabled &&
		isAmazonPayExpressCheckoutAvailable( settings ) &&
		amazonPayAvailability.isActionable &&
		availablePaymentMethodIds.includes( 'amazon_pay' )
	) {
		addAffectedPaymentMethod( affectedMethods, {
			id: 'amazon_pay',
			label: __( 'Amazon Pay', 'woocommerce' ),
			iconUrl: EXPRESS_CHECKOUT_METHOD_ICONS.amazon_pay[ 0 ].src,
		} );
	}

	if ( enabledMethodIds.includes( 'link' ) ) {
		addAffectedPaymentMethod( affectedMethods, {
			id: 'link',
			label: __( 'Link', 'woocommerce' ),
			iconUrl: EXPRESS_CHECKOUT_METHOD_ICONS.link[ 0 ].src,
		} );
	}

	if ( isWooPayEnabled && isWooPayExpressCheckoutAvailable( settings ) ) {
		addAffectedPaymentMethod( affectedMethods, {
			id: 'woopay',
			label: __( 'WooPay', 'woocommerce' ),
			iconUrl: WOOPAY_ICON_URL,
		} );
	}

	return Array.from( affectedMethods.values() );
};
