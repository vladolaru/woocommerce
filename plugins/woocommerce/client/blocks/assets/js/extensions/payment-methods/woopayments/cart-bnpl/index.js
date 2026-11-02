/**
 * External dependencies
 */
import { ExperimentalOrderMeta } from '@woocommerce/blocks-checkout';
import { select } from '@wordpress/data';
import { useEffect, useRef, useState } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';

/**
 * Internal dependencies
 */
import {
	dispatchAppearanceEvent,
	getAppearance,
	getCachedAppearance,
	getFontRulesFromPage,
	setCachedAppearance,
} from '../upe-styles';

const APPEARANCE_LOCATION = 'bnpl_cart_block';

const getMessagingConfig = () => window.wcpayStripeSiteMessaging || {};

const isInEditor = () => Boolean( select( 'core/editor' ) );

const normalizeAmount = ( amount, decimalPlaces = 2 ) =>
	Number( amount || 0 ) * Math.pow( 10, 2 - decimalPlaces );

const getCartAmount = ( cart ) => {
	const precision = window.wcSettings?.currency?.precision ?? 2;
	const totalPrice = cart?.cartTotals?.total_price ?? 0;

	return Number.parseInt( normalizeAmount( totalPrice, precision ), 10 ) || 0;
};

const hasAppearance = ( appearance ) =>
	appearance && Object.keys( appearance ).length > 0;

const getStripeOptions = ( config ) => {
	const stripeOptions = {
		locale: config.locale || 'auto',
	};

	if ( config.accountId ) {
		stripeOptions.stripeAccount = config.accountId;
	}

	return stripeOptions;
};

// One Stripe instance for the page, as client 11.2.0's module-level API object (cart/blocks/product-details.js:28-37),
// so a cart change adds no Stripe controller frame. A new Stripe.js load, publishable key or option builds a new one.
let cachedStripe = null;

const getStripe = ( config ) => {
	const options = getStripeOptions( config );
	const key = JSON.stringify( [ config.publishableKey, options ] );

	if (
		cachedStripe?.factory !== window.Stripe ||
		cachedStripe?.key !== key
	) {
		cachedStripe = {
			factory: window.Stripe,
			key,
			stripe: window.Stripe( config.publishableKey, options ),
		};
	}

	return cachedStripe.stripe;
};

/**
 * Render Stripe BNPL payment method messaging in the Cart block order summary.
 *
 * @param {Object} props         Component props.
 * @param {Object} props.cart    Cart data from the Blocks order-meta slot.
 * @param {string} props.context Slot context.
 * @return {JSX.Element|null} The messaging wrapper, or null when not applicable.
 */
export const BNPLCartMessaging = ( { cart, context } ) => {
	const config = getMessagingConfig();
	const mountRef = useRef( null );
	const messagingRef = useRef( null );
	const amount = getCartAmount( cart );
	const amountRef = useRef( amount );
	amountRef.current = amount;
	const [ fontRules ] = useState( () => getFontRulesFromPage() );
	const [ appearance, setAppearance ] = useState( () =>
		getCachedAppearance(
			APPEARANCE_LOCATION,
			config.stylesCacheVersion || '0'
		)
	);

	useEffect( () => {
		if ( hasAppearance( appearance ) ) {
			return;
		}

		const computedAppearance = getAppearance( APPEARANCE_LOCATION );
		dispatchAppearanceEvent( computedAppearance, APPEARANCE_LOCATION );
		setCachedAppearance(
			APPEARANCE_LOCATION,
			config.stylesCacheVersion || '0',
			computedAppearance
		);
		setAppearance( computedAppearance );
	}, [ appearance, config.stylesCacheVersion ] );

	useEffect( () => {
		if (
			context !== 'woocommerce/cart' ||
			! config.shouldInitializePMME ||
			! config.publishableKey ||
			! window.Stripe ||
			! mountRef.current ||
			! hasAppearance( appearance )
		) {
			return undefined;
		}

		const stripe = getStripe( config );
		if ( ! stripe ) {
			return undefined;
		}

		const elements = stripe.elements( {
			appearance,
			fonts: fontRules,
		} );
		const paymentMethodMessaging = elements.create(
			'paymentMethodMessaging',
			{
				amount: amountRef.current,
				countryCode: config.country,
				currency: config.currencyCode || 'USD',
				paymentMethodTypes: Array.isArray( config.paymentMethods )
					? config.paymentMethods
					: [],
			}
		);

		paymentMethodMessaging.mount( mountRef.current );
		messagingRef.current = {
			element: paymentMethodMessaging,
			amount: amountRef.current,
		};

		return () => {
			messagingRef.current = null;
			paymentMethodMessaging.destroy?.();
		};
	}, [ appearance, config, context, fontRules ] );

	// A cart change updates the amount on the mounted element, as client 11.2.0's React Stripe.js element updates its
	// options in place (cart/blocks/product-details.js:109-125), so the message does not collapse and regrow.
	useEffect( () => {
		const messaging = messagingRef.current;
		if ( ! messaging || messaging.amount === amount ) {
			return;
		}

		messaging.amount = amount;
		messaging.element.update?.( { amount } );
	}, [ amount ] );

	if (
		context !== 'woocommerce/cart' ||
		! config.shouldInitializePMME ||
		! hasAppearance( appearance )
	) {
		return null;
	}

	return (
		<div className="wc-block-components-bnpl-wrapper">
			<div ref={ mountRef } />
		</div>
	);
};

export const renderBNPLCartMessaging = () => {
	if ( isInEditor() ) {
		return null;
	}

	return (
		<ExperimentalOrderMeta>
			<BNPLCartMessaging />
		</ExperimentalOrderMeta>
	);
};

registerPlugin( 'bnpl-site-messaging', {
	render: renderBNPLCartMessaging,
	scope: 'woocommerce-checkout',
} );
