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
 * Gets the checkout description for a country.
 *
 * @param {string} country - The country code
 * @return {string} The PayPal Checkout description
 */
const getCheckoutDescription = ( country ) =>
	country === 'US'
		? __(
				'Our all-in-one checkout solution lets you offer PayPal, Venmo, Pay Later options, and more to help maximise conversion',
				'woocommerce'
		  )
		: __(
				'Our all-in-one checkout solution lets you offer PayPal, Pay Later options, and more to help maximise conversion',
				'woocommerce'
		  );

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

			// UI text configuration.
			paypalCheckoutDescription: getCheckoutDescription( country ),

			// Additional configuration.
			learnMoreConfig,
			icons,
		};
	}, [ country, canUseCardPayments, ownBrandOnly ] );
};
