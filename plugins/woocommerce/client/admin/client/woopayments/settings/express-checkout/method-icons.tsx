/**
 * Internal dependencies
 */
import { WC_ASSET_URL } from '~/utils/admin-settings';

type ExpressCheckoutMethod = 'woopay' | 'payment_request' | 'amazon_pay';
type ExpressCheckoutIconMethod = ExpressCheckoutMethod | 'link';

const assetUrl = ( path: string ) => `${ WC_ASSET_URL || '' }${ path }`;

export const EXPRESS_CHECKOUT_METHOD_ICONS: Record<
	ExpressCheckoutIconMethod,
	Array< { alt: string; src: string } >
> = {
	woopay: [
		{
			alt: 'WooPay',
			src: assetUrl( 'images/payment-methods/woopay.svg' ),
		},
	],
	payment_request: [
		{
			alt: 'Apple Pay',
			src: assetUrl( 'images/payment-methods/apple-pay-color.svg' ),
		},
		{
			alt: 'Google Pay',
			src: assetUrl( 'images/payment-methods/google-pay-color.svg' ),
		},
	],
	amazon_pay: [
		{
			alt: 'Amazon Pay',
			src: assetUrl( 'images/payment-methods/amazon-pay-color.svg' ),
		},
	],
	link: [
		{
			alt: 'Link',
			src: assetUrl( 'images/payment-methods/link-color.svg' ),
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
				// Like the client, the WooPay logo has no badge outline.
				className={
					methodId === 'woopay'
						? 'woopayments-express-checkout-settings__icon'
						: 'woopayments-express-checkout-settings__icon woopayments-express-checkout-settings__icon--badge'
				}
				key={ icon.alt }
			>
				<img src={ icon.src } alt={ icon.alt } />
			</div>
		) ) }
	</div>
);
