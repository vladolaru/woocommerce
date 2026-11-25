/**
 * External dependencies
 */
import { __ } from '@wordpress/i18n';

/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';

const assetUrl = ( path: string ) => `${ WC_ASSET_URL || '' }${ path }`;

export const getLinkBranding = ( accountCountry: unknown ) =>
	typeof accountCountry === 'string' && accountCountry.toUpperCase() === 'GB'
		? {
				label: __( 'Onelink', 'woocommerce' ),
				description: __(
					"Onelink autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
					'woocommerce'
				),
				iconUrl: assetUrl( 'images/payment-methods/onelink.svg' ),
			}
		: {
				label: __( 'Link', 'woocommerce' ),
				description: __(
					"Link autofills your customers' payment and shipping details to deliver an easy and seamless checkout experience.",
					'woocommerce'
				),
				iconUrl: assetUrl( 'images/payment-methods/link-color.svg' ),
			};
