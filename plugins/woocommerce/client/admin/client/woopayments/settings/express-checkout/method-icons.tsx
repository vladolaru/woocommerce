/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';
import WooPayLogoImage from './assets/woopay-preview-logo.svg';

type ExpressCheckoutMethod = 'woopay' | 'payment_request' | 'amazon_pay';
type ExpressCheckoutIconMethod = ExpressCheckoutMethod | 'link';

const assetUrl = ( path: string ) => `${ WC_ASSET_URL || '' }${ path }`;

export const EXPRESS_CHECKOUT_METHOD_ICONS: Record<
	ExpressCheckoutIconMethod,
	Array< { alt: string; src: string } >
> = {
	woopay: [ { alt: 'WooPay', src: WooPayLogoImage } ],
	payment_request: [
		{
			alt: 'Apple Pay',
			src: assetUrl( 'images/payment-methods/applepay.svg' ),
		},
		{
			alt: 'Google Pay',
			src: assetUrl( 'images/payment-methods/googlepay.svg' ),
		},
	],
	amazon_pay: [
		{
			alt: 'Amazon Pay',
			src: assetUrl( 'images/payment-methods/amazon-pay.svg' ),
		},
	],
	link: [
		{
			alt: 'Link',
			src: assetUrl( 'images/payment-methods/link.svg' ),
		},
	],
};

export const ExpressCheckoutMethodIcons = ( {
	methodId,
}: {
	methodId: ExpressCheckoutMethod;
} ) => (
	<div className="woopayments-express-checkout-settings__icons">
		{ EXPRESS_CHECKOUT_METHOD_ICONS[ methodId ].map( ( icon ) => (
			<div
				className="woopayments-express-checkout-settings__icon"
				key={ icon.alt }
			>
				<img src={ icon.src } alt={ icon.alt } />
			</div>
		) ) }
	</div>
);
