import { __ } from '@wordpress/i18n';
import { useMemo } from '@wordpress/element';

import { learnMoreLinks } from '../../../../utils/countryInfoLinks';
import {
	PayWithPayPal,
	PayLater,
	Venmo,
	PayInThree,
} from '../Components/PaymentOptions';

// List of all payment icons, and the countries they are limited to.
const PAYMENT_ICONS = [
	{ name: 'paypal' },
	{ name: 'venmo', countries: [ 'US' ] },
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
 * Filters payment icons based on country.
 *
 * @param {string} country - The country code
 * @return {string[]} List of icon names
 */
const getRelevantIcons = ( country ) =>
	PAYMENT_ICONS.filter(
		( { countries = [] } ) =>
			! countries.length || countries.includes( country )
	).map( ( icon ) => icon.name );

/**
 * Custom hook that generates payment configuration based on merchant settings.
 *
 * @param {string} country - Merchant country code
 * @return {Object} Complete payment configuration
 */
export const usePaymentConfig = ( country ) => {
	return useMemo( () => {
		// Merge country-specific config with default.
		const countryConfig = COUNTRY_CONFIGS[ country ] || {};
		const config = { ...DEFAULT_CONFIG, ...countryConfig };

		// Get "learn more" links for the country
		const learnMoreConfig = learnMoreLinks[ country ] || {};

		// Get icons appropriate for this configuration.
		const icons = getRelevantIcons( country );

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
	}, [ country ] );
};
