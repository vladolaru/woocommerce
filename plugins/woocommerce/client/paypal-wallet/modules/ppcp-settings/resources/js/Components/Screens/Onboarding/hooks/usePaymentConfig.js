import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

import { learnMoreLinks } from '@ppcp-settings/utils/countryInfoLinks';
import {
	PayWithPayPal,
	PayLater,
	Venmo,
	PayInThree,
} from '../Components/PaymentOptions';

// List of all payment icons and which requirements they have.
const PAYMENT_ICONS = [
	{ name: 'paypal', always: true },
	{ name: 'venmo', isOwnBrand: true, onlyAcdc: false, countries: [ 'US' ] },
	{ name: 'visa', isOwnBrand: false, onlyAcdc: false },
	{ name: 'mastercard', isOwnBrand: false, onlyAcdc: false },
	{ name: 'amex', isOwnBrand: false, onlyAcdc: false },
	{ name: 'discover', isOwnBrand: false, onlyAcdc: false },
];

// Default configuration, used for all countries, unless they override individual attributes below.
const DEFAULT_CONFIG = {
	includedMethods: [
		{ name: 'PayWithPayPal', Component: PayWithPayPal },
		{ name: 'PayLater', Component: PayLater },
	],
	extendedMethods: [],
};

// Country-specific configurations.
const COUNTRY_CONFIGS = {
	US: {
		includedMethods: [
			{ name: 'PayWithPayPal', Component: PayWithPayPal },
			{ name: 'PayLater', Component: PayLater },
			{ name: 'Venmo', Component: Venmo },
		],
	},
	GB: {
		includedMethods: [
			{ name: 'PayWithPayPal', Component: PayWithPayPal },
			{ name: 'PayInThree', Component: PayInThree },
		],
	},
};

/**
 * Gets all UI text elements based on country and branding options.
 *
 * @param {string}  country            - The country code
 * @param {boolean} canUseCardPayments - Whether merchant can use card payments (ACDC)
 * @param {boolean} onlyBranded        - Whether to show only branded payment methods
 * @return {Object} All UI text elements
 */
const getUIText = ( country, canUseCardPayments, onlyBranded ) => {
	const TITLES = {
		EXPANDED: __( 'Expanded Checkout', 'woocommerce' ),
		OPTIONAL: __( 'Optional payment methods', 'woocommerce' ),
	};

	const OPTIONAL_DESCRIPTIONS = {
		WITH_APPLICATION: __( 'with additional application', 'woocommerce' ),
		US_EXPANDED: __(
			'Accept more ways to pay. Note: additional application required for some methods',
			'woocommerce'
		),
	};

	const CORE_DESCRIPTIONS = {
		DEFAULT_CHECKOUT: __(
			'Our all-in-one checkout solution lets you offer PayPal, Pay Later options, and more to help maximise conversion',
			'woocommerce'
		),
		US_CHECKOUT: __(
			'Our all-in-one checkout solution lets you offer PayPal, Venmo, Pay Later options, and more to help maximise conversion',
			'woocommerce'
		),
	};

	// Base text configuration for all countries.
	const texts = {
		paypalCheckoutDescription: CORE_DESCRIPTIONS.DEFAULT_CHECKOUT,
		optionalTitle: canUseCardPayments ? TITLES.EXPANDED : TITLES.OPTIONAL,
		optionalDescription: OPTIONAL_DESCRIPTIONS.WITH_APPLICATION,
	};

	// Country-specific overrides.
	if ( country === 'US' ) {
		texts.paypalCheckoutDescription = CORE_DESCRIPTIONS.US_CHECKOUT;
		texts.optionalDescription = OPTIONAL_DESCRIPTIONS.US_EXPANDED;
	}

	// Branded-only mode overrides.
	if ( onlyBranded ) {
		texts.optionalTitle = TITLES.EXPANDED;
		texts.optionalDescription = OPTIONAL_DESCRIPTIONS.US_EXPANDED;
	}

	return texts;
};

/**
 * Filters payment icons based on country and configuration.
 *
 * @param {string}  country     - The country code
 * @param {boolean} includeAcdc - Whether to include advanced card payment methods
 * @param {boolean} onlyBranded - Whether to show only branded payment methods
 * @return {string[]} List of icon names
 */
const getRelevantIcons = ( country, includeAcdc, onlyBranded ) =>
	PAYMENT_ICONS.filter(
		( { always, isOwnBrand, onlyAcdc, countries = [] } ) => {
			if ( always ) {
				return true;
			}

			if ( onlyBranded && ! isOwnBrand ) {
				return false;
			}

			if ( ! includeAcdc && onlyAcdc ) {
				return false;
			}

			return ! countries.length || countries.includes( country );
		}
	).map( ( icon ) => icon.name );

/**
 * Filters payment methods based on provided conditions.
 *
 * @param {Array}           methods    - The methods to filter
 * @param {Array<Function>} conditions - List of filter conditions
 * @return {Array} Filtered methods
 */
const filterMethods = ( methods, conditions ) => {
	return methods.filter( ( method ) =>
		conditions.every( ( condition ) => condition( method ) )
	);
};

/**
 * Custom hook that generates payment configuration based on merchant settings.
 *
 * @param {string}  country            - Merchant country code
 * @param {boolean} canUseCardPayments - Whether merchant can use card payments
 * @param {boolean} ownBrandOnly       - Whether to show only branded payment methods
 * @return {Object} Complete payment configuration
 */
export const usePaymentConfig = (
	country,
	canUseCardPayments,
	ownBrandOnly
) => {
	return useMemo( () => {
		// Merge country-specific config with default.
		const countryConfig = COUNTRY_CONFIGS[ country ] || {};
		const config = { ...DEFAULT_CONFIG, ...countryConfig };

		// Get "learn more" links for the country
		const learnMoreConfig = learnMoreLinks[ country ] || {};

		// Filter out conditional methods.
		const availableOptionalMethods = filterMethods(
			config.extendedMethods,
			[
				// Include ACDC methods when card payments available, non-ACDC otherwise.
				( method ) => method.isAcdc === canUseCardPayments,
				// Only include own-brand methods when ownBrandOnly is true.
				( method ) => ! ownBrandOnly || method.isOwnBrand === true,
			]
		);

		// Get all UI text elements.
		const uiText = getUIText( country, canUseCardPayments, ownBrandOnly );

		// Get icons appropriate for this configuration.
		const icons = getRelevantIcons(
			country,
			canUseCardPayments,
			ownBrandOnly
		);

		// Return the complete configuration.
		return {
			// Payment methods configuration.
			includedMethods: config.includedMethods,
			basicMethods: config.basicMethods,
			optionalMethods: availableOptionalMethods,

			// UI text configuration.
			paypalCheckoutDescription: uiText.paypalCheckoutDescription,
			optionalTitle: uiText.optionalTitle,
			optionalDescription: uiText.optionalDescription,

			// Additional configuration.
			learnMoreConfig,
			icons,
		};
	}, [ country, canUseCardPayments, ownBrandOnly ] );
};
