/**
 * External dependencies
 */
import { registerExpressPaymentMethod } from '@woocommerce/blocks-registry';
import { getPaymentMethodData } from '@woocommerce/settings';
import { cartStore } from '@woocommerce/block-data';
import { extensionCartUpdate } from '@woocommerce/blocks-checkout';
import { decodeEntities } from '@wordpress/html-entities';
import { dispatch } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/**
 * Internal dependencies
 */
import { recordWooPaymentsUserEvent } from '../tracks';
import { getBlocksCheckoutAppearance } from '../upe-styles';
import { transformPrice } from './transform-price';
import { registerSubscriptionsCompatibility } from './compatibility/wc-subscriptions';

const PAYMENT_METHOD_NAME = 'woocommerce_payments';
const EXPRESS_CHECKOUT_PAYMENT_METHOD_NAME =
	'woocommerce_payments_express_checkout';
const REFRESH_UI_NAMESPACE = 'woopayments/express-checkout/refresh-ui';
const settings = getPaymentMethodData( PAYMENT_METHOD_NAME, {} );
const params = settings?.expressCheckoutParams || {};

const METHOD_CONFIG = {
	applePay: {
		name: `${ EXPRESS_CHECKOUT_PAYMENT_METHOD_NAME }_applePay`,
		title: __( 'WooPayments - Apple Pay', 'woocommerce' ),
		enabledMethod: 'payment_request',
		loadEvent: 'applepay_button_load',
		clickEvent: 'applepay_button_click',
	},
	googlePay: {
		name: `${ EXPRESS_CHECKOUT_PAYMENT_METHOD_NAME }_googlePay`,
		title: __( 'WooPayments - Google Pay', 'woocommerce' ),
		enabledMethod: 'payment_request',
		loadEvent: 'gpay_button_load',
		clickEvent: 'gpay_button_click',
	},
	amazonPay: {
		name: `${ EXPRESS_CHECKOUT_PAYMENT_METHOD_NAME }_amazonPay`,
		title: __( 'WooPayments - Amazon Pay', 'woocommerce' ),
		enabledMethod: 'amazon_pay',
	},
};

const EXPRESS_METHOD_TO_PAYMENT_METHOD_TYPE = {
	payment_request: 'card',
	amazon_pay: 'amazon_pay',
};

const SHIPPING_RATES_UPPER_LIMIT_COUNT = 20;

const availabilityCache = new Map();
let availabilityStripe = null;

const getTrackingSettings = () => ( {
	...settings,
	ajaxUrl: params.ajax_url,
	isShopperTrackingEnabled: params.isShopperTrackingEnabled,
	is_shopper_tracking_enabled: params.is_shopper_tracking_enabled,
	platformTrackerNonce: params?.nonce?.platform_tracker,
} );

const clampButtonHeight = ( height ) => {
	const parsedHeight = Number.parseInt( height, 10 );

	if ( ! Number.isFinite( parsedHeight ) ) {
		return 48;
	}

	return Math.min( Math.max( parsedHeight, 40 ), 55 );
};

const getExpressButtonType = ( method ) => {
	const type = params?.button?.type || 'default';

	if ( type === 'default' ) {
		return 'plain';
	}

	if ( method === 'applePay' ) {
		return [ 'buy', 'donate', 'book', 'check-out' ].includes( type )
			? type
			: 'plain';
	}

	return [ 'buy', 'donate', 'book', 'checkout' ].includes( type )
		? type
		: 'plain';
};

const getExpressButtonTheme = ( method ) => {
	const theme = params?.button?.theme || 'dark';

	if ( theme === 'light-outline' ) {
		return method === 'applePay' ? 'white-outline' : 'white';
	}

	return theme === 'light' ? 'white' : 'black';
};

const getExpressButtonOptions = ( method ) => ( {
	buttonHeight: clampButtonHeight( params?.button?.height ),
	buttonTheme: {
		applePay: getExpressButtonTheme( 'applePay' ),
		googlePay: getExpressButtonTheme( 'googlePay' ),
	},
	buttonType: {
		applePay: getExpressButtonType( 'applePay' ),
		googlePay: getExpressButtonType( 'googlePay' ),
	},
	layout: {
		overflow: 'never',
	},
	paymentMethods: {
		applePay: method === 'applePay' ? 'always' : 'never',
		googlePay: method === 'googlePay' ? 'always' : 'never',
		amazonPay: method === 'amazonPay' ? 'auto' : 'never',
		link: 'never',
		paypal: 'never',
		klarna: 'never',
	},
} );

const getLocalizedEnabledMethods = () =>
	Array.isArray( params?.enabled_methods ) ? params.enabled_methods : [];

const getEnabledMethodsForCart = ( cart ) => {
	const localizedMethods = getLocalizedEnabledMethods();
	const cartMethods = cart?.extensions?.wcpay?.express_checkout_methods;

	if ( ! Array.isArray( cartMethods ) ) {
		return localizedMethods;
	}

	return localizedMethods.filter( ( method ) =>
		cartMethods.includes( method )
	);
};

const getAllowedPaymentMethodTypes = () => {
	const paymentMethodTypes = Array.isArray( params?.payment_method_types )
		? params.payment_method_types
		: [ 'card' ];

	return paymentMethodTypes.filter( ( type ) =>
		[ 'card', 'amazon_pay' ].includes( type )
	);
};

const getPaymentMethodTypes = ( cart ) => {
	const allowedTypes = getAllowedPaymentMethodTypes();
	const mappedTypes = getEnabledMethodsForCart( cart )
		.map( ( method ) => EXPRESS_METHOD_TO_PAYMENT_METHOD_TYPE[ method ] )
		.filter( Boolean )
		.filter( ( type ) => allowedTypes.includes( type ) );

	if ( mappedTypes.length ) {
		return Array.from( new Set( mappedTypes ) );
	}

	const hasCartMethodList = Array.isArray(
		cart?.extensions?.wcpay?.express_checkout_methods
	);
	if ( hasCartMethodList ) {
		return [];
	}

	return allowedTypes.length ? allowedTypes : [ 'card' ];
};

const normalizeMinorAmountString = ( value ) => {
	const amount = Number( value );

	return Number.isFinite( amount ) ? String( amount ) : '0';
};

const getBillingCartTotals = ( billing ) => {
	const totals = {};

	if ( Array.isArray( billing?.cartTotalItems ) ) {
		billing.cartTotalItems.forEach( ( item ) => {
			if ( ! item?.key ) {
				return;
			}

			const value = Number( item.value );
			const valueWithTax = Number( item.valueWithTax );
			totals[ item.key ] = normalizeMinorAmountString( item.value );

			if ( item.key !== 'total_tax' ) {
				totals[ `${ item.key }_tax` ] = normalizeMinorAmountString(
					Number.isFinite( valueWithTax ) && Number.isFinite( value )
						? Math.max( valueWithTax - value, 0 )
						: 0
				);
			}
		} );
	}

	if ( billing?.cartTotal?.value !== undefined ) {
		totals.total_price = normalizeMinorAmountString(
			billing.cartTotal.value
		);
	}

	if ( billing?.currency ) {
		totals.currency_code = billing.currency.code;
		totals.currency_minor_unit = billing.currency.minorUnit;
	}

	return totals;
};

const getCartTotals = ( cart, billing ) => {
	const totals = cart?.totals || cart?.cartTotals || {};

	return Object.keys( totals ).length
		? totals
		: getBillingCartTotals( billing );
};

const normalizeStoreApiCart = ( cart = {}, billing, shippingData ) => ( {
	...cart,
	items: cart.items || cart.cartItems || [],
	totals: getCartTotals( cart, billing ),
	shipping_rates:
		cart.shipping_rates ||
		cart.shippingRates ||
		shippingData?.shippingRates ||
		[],
	extensions: cart.extensions || {},
} );

const parseMinorUnitAmount = ( value ) => {
	const amount = Number.parseInt( value, 10 );

	return Number.isFinite( amount ) ? amount : 0;
};

const getCartTotalPrice = ( cart ) => {
	const totals = getCartTotals( cart );
	const totalPrice =
		parseMinorUnitAmount( totals.total_price ) -
		parseMinorUnitAmount( totals.total_refund );

	if ( Number.isFinite( totalPrice ) && totalPrice > 0 ) {
		return transformPrice( totalPrice, totals );
	}

	return Number( params?.checkout?.cart_total || 0 );
};

// Pin the currency the page was rendered with, so cache-optimized or
// geolocation-driven multi-currency setups can't serve the request in a
// different currency than the wallet sheet shows.
const getStoreApiPath = ( path ) =>
	addQueryArgs( path, {
		currency:
			( params?.checkout?.currency_code || '' ).toUpperCase() ||
			undefined,
	} );

const displayLoginConfirmation = ( expressPaymentType ) => {
	const loginConfirmation = params?.login_confirmation;
	const paymentTypesMap = {
		apple_pay: 'Apple Pay',
		google_pay: 'Google Pay',
		amazon_pay: 'Amazon Pay',
		paypal: 'PayPal',
		link: 'Link',
	};

	if ( ! loginConfirmation ) {
		return;
	}

	// Replace the dialog text with the specific express checkout type, and
	// remove the asterisk markers.
	const message = ( loginConfirmation.message || '' )
		.replace( /\*\*.*?\*\*/, paymentTypesMap[ expressPaymentType ] )
		.replace( /\*\*/g, '' );

	// eslint-disable-next-line no-alert
	if ( window.confirm( message ) ) {
		window.location.href = loginConfirmation.redirect_url;
	}
};

const ORDER_ATTRIBUTION_ELEMENT_ID =
	'wcpay-express-checkout__order-attribution-inputs';

const initOrderAttribution = () => {
	// The PHP-rendered element may not be present on block surfaces.
	if ( ! document.getElementById( ORDER_ATTRIBUTION_ELEMENT_ID ) ) {
		const orderAttributionInputs = document.createElement(
			'wc-order-attribution-inputs'
		);
		orderAttributionInputs.id = ORDER_ATTRIBUTION_ELEMENT_ID;
		document.body.appendChild( orderAttributionInputs );
	}

	// Manually calling the helper to ensure the hidden inputs are populated
	// with attribution data.
	window?.wc_order_attribution?.setOrderTracking(
		window?.wc_order_attribution?.params?.allowTracking
	);
};

const getPlaceOrderExtensions = () => {
	let inputs = document.querySelectorAll(
		`#${ ORDER_ATTRIBUTION_ELEMENT_ID } input`
	);
	const orderAttributionData = {};

	// The attribution script can load after our init ran; retry once at
	// collection time so a late load doesn't silently drop attribution.
	if ( ! inputs.length ) {
		initOrderAttribution();
		inputs = document.querySelectorAll(
			`#${ ORDER_ATTRIBUTION_ELEMENT_ID } input`
		);
	}

	inputs.forEach( ( input ) => {
		const name = ( input.name || '' ).replace(
			'wc_order_attribution_',
			''
		);

		if ( name && input.value ) {
			orderAttributionData[ name ] = input.value;
		}
	} );

	return applyFilters(
		'wcpay.express-checkout.cart-place-order-extension-data',
		Object.keys( orderAttributionData ).length
			? { 'woocommerce/order-attribution': orderAttributionData }
			: {}
	);
};

const getStoreApiHeaders = ( includeSessionNonce = false ) => {
	const nonce = params?.nonce || {};
	const headers = {};

	if ( nonce.store_api_nonce ) {
		headers.Nonce = nonce.store_api_nonce;
	}

	if ( nonce.tokenized_cart_nonce ) {
		headers[ 'X-WooPayments-Tokenized-Cart-Nonce' ] =
			nonce.tokenized_cart_nonce;
	}

	if ( includeSessionNonce && nonce.tokenized_cart_session_nonce ) {
		headers[ 'X-WooPayments-Tokenized-Cart-Session-Nonce' ] =
			nonce.tokenized_cart_session_nonce;
	}

	return headers;
};

const getCartTotal = ( billing ) => {
	const cartTotal = Number( billing?.cartTotal?.value );

	if ( Number.isFinite( cartTotal ) && cartTotal > 0 ) {
		return transformPrice( cartTotal, {
			currency_minor_unit: billing?.currency?.minorUnit,
		} );
	}

	return Number( params?.checkout?.cart_total || 0 );
};

const getCartCurrency = ( billing ) =>
	(
		billing?.currency?.code ||
		params?.checkout?.currency_code ||
		'usd'
	).toLowerCase();

const getCartTotalsCurrency = ( cart ) =>
	(
		getCartTotals( cart )?.currency_code ||
		params?.checkout?.currency_code ||
		''
	).toLowerCase();

const getCartTotalsAmount = ( cart ) => getCartTotalPrice( cart );

// Defaults to true when the flag is absent; the platform's
// ece_confirmation_tokens_disabled kill switch turns it off.
const shouldUseConfirmationTokens = () =>
	params?.flags?.isEceUsingConfirmationTokens ?? true;

const isSubscriptionData = ( schedule ) => {
	if ( Array.isArray( schedule ) ) {
		return schedule.length > 0;
	}

	return (
		typeof schedule?.billing_period === 'string' &&
		schedule.billing_period.length > 0 &&
		typeof schedule.billing_interval === 'number' &&
		schedule.billing_interval > 0
	);
};

const cartHasAnySubscription = ( cartData ) => {
	const schedules = cartData?.extensions?.subscriptions;
	if ( Array.isArray( schedules ) && schedules.length > 0 ) {
		return true;
	}

	if ( ! Array.isArray( cartData?.items ) ) {
		return false;
	}

	return cartData.items.some( ( item ) =>
		isSubscriptionData( item?.extensions?.subscriptions )
	);
};

const getSetupFutureUsageForCart = ( cartData ) =>
	cartHasAnySubscription( cartData ) ? 'off_session' : null;

const addReferenceElementOptions = ( options, cartData ) => {
	if ( shouldUseConfirmationTokens() && params?.is_manual_capture ) {
		options.captureMethod = 'manual';
	}

	if ( shouldUseConfirmationTokens() ) {
		options.setupFutureUsage = getSetupFutureUsageForCart( cartData );
	}

	const appearance = getBlocksCheckoutAppearance(
		settings.stylesCacheVersion,
		document,
		settings
	);
	if ( appearance ) {
		options.appearance = appearance;
	}

	if ( params?.stripe?.locale ) {
		options.locale = params.stripe.locale;
	}

	return options;
};

/**
 * Get the Elements options that follow the live cart: payment or setup mode, amount and setupFutureUsage.
 *
 * The client rebuilds these from `billing.cartTotal` on every render (express-checkout-container.js:62-84).
 *
 * @param {Object} billing Blocks billing data.
 * @param {Object} cart    Cart data.
 * @return {Object} Elements options.
 */
const getLiveElementsOptions = ( billing, cart ) => {
	const cartData = normalizeStoreApiCart( cart, billing );
	const amount = applyFilters(
		'wcpay.express-checkout.total-amount',
		getCartTotal( billing ),
		cartData
	);
	const options = { mode: amount > 0 ? 'payment' : 'setup' };

	if ( options.mode === 'payment' ) {
		options.amount = amount;
	}

	if ( shouldUseConfirmationTokens() ) {
		options.setupFutureUsage = getSetupFutureUsageForCart( cartData );
	}

	return options;
};

const getStripeElementsOptions = ( billing, cart ) => {
	const cartData = normalizeStoreApiCart( cart, billing );
	const { mode, amount } = getLiveElementsOptions( billing, cart );
	const options = addReferenceElementOptions(
		{
			mode,
			loader: 'never',
			currency: getCartCurrency( billing ),
			// Without confirmation tokens, the payment method is created
			// manually at confirm time.
			...( shouldUseConfirmationTokens()
				? { paymentMethodTypes: getPaymentMethodTypes( cartData ) }
				: { paymentMethodCreation: 'manual' } ),
		},
		cartData
	);

	if ( options.mode === 'payment' ) {
		options.amount = amount;
	}

	return options;
};

/**
 * Get the options whose values differ from the ones last applied, as react-stripe-js does for the client's
 * `<Elements options>` before calling `elements.update()`.
 *
 * @param {Object} next    Options for the current render.
 * @param {Object} applied Options applied last.
 * @return {Object} Changed options.
 */
const getChangedElementsOptions = ( next, applied ) =>
	Object.keys( next ).reduce( ( changed, key ) => {
		if (
			JSON.stringify( next[ key ] ) !== JSON.stringify( applied[ key ] )
		) {
			changed[ key ] = next[ key ];
		}

		return changed;
	}, {} );

const getAvailabilityElementsOptions = ( cart ) => {
	const cartData = normalizeStoreApiCart( cart );
	const amount = applyFilters(
		'wcpay.express-checkout.total-amount',
		getCartTotalsAmount( cart ),
		cartData
	);
	const options = addReferenceElementOptions(
		{
			mode: amount > 0 ? 'payment' : 'setup',
			loader: 'never',
			currency: getCartTotalsCurrency( cart ),
			paymentMethodTypes: getPaymentMethodTypes( cart ),
		},
		cartData
	);

	if ( options.mode === 'payment' ) {
		options.amount = Math.max( amount, 1 );
	}

	return options;
};

// The reference client's express surfaces share the connected-account Stripe
// instance, which opts into the Link autofill beta when Link is enabled.
const getStripeBetas = () =>
	params?.stripe?.linkEnabled
		? [ 'card_country_event_beta_1', 'link_autofill_modal_beta_1' ]
		: [ 'card_country_event_beta_1' ];

const getStripe = () => {
	if ( availabilityStripe ) {
		return availabilityStripe;
	}

	if ( ! window.Stripe || ! params?.stripe?.publishableKey ) {
		return null;
	}

	availabilityStripe = window.Stripe( params.stripe.publishableKey, {
		locale: params.stripe.locale || 'auto',
		...( params.stripe.accountId
			? { stripeAccount: params.stripe.accountId }
			: {} ),
		betas: getStripeBetas(),
	} );

	return availabilityStripe;
};

const checkAvailablePaymentMethods = ( cart ) => {
	const stripe = getStripe();

	if ( ! stripe ) {
		return Promise.resolve( {} );
	}

	const options = getAvailabilityElementsOptions( cart );
	if ( ! options.currency ) {
		return Promise.resolve( {} );
	}

	const cacheKey = [
		options.mode,
		options.amount || 0,
		options.currency,
		getPaymentMethodTypes( cart ).join( ',' ),
		params?.is_manual_capture ? 'manual' : 'automatic',
		options.setupFutureUsage || 'standard',
	].join( ':' );

	if ( availabilityCache.has( cacheKey ) ) {
		return availabilityCache.get( cacheKey );
	}

	const availabilityPromise = new Promise( ( resolve ) => {
		let container;
		let expressElement;
		let timeoutId;

		const cleanup = () => {
			window.clearTimeout( timeoutId );
			expressElement?.unmount?.();
			container?.remove?.();
		};

		try {
			container = document.createElement( 'div' );
			container.style.position = 'absolute';
			container.style.left = '-9999px';
			container.style.top = '-9999px';
			document.body.appendChild( container );

			const elements = stripe.elements( options );
			expressElement = elements.create( 'expressCheckout', {
				buttonType: {
					applePay: 'plain',
					googlePay: 'plain',
				},
				paymentMethods: {
					applePay: 'always',
					googlePay: 'always',
					amazonPay: getPaymentMethodTypes( cart ).includes(
						'amazon_pay'
					)
						? 'auto'
						: 'never',
					link: 'never',
					paypal: 'never',
					klarna: 'never',
				},
			} );

			timeoutId = window.setTimeout( () => {
				cleanup();
				resolve( {} );
			}, 5000 );

			expressElement.on( 'ready', ( event ) => {
				cleanup();
				resolve( event?.availablePaymentMethods || {} );
			} );
			expressElement.on( 'loaderror', () => {
				cleanup();
				resolve( {} );
			} );
			expressElement.mount( container );
		} catch {
			cleanup();
			resolve( {} );
		}
	} );

	availabilityCache.set( cacheKey, availabilityPromise );

	return availabilityPromise;
};

const checkPaymentMethodIsAvailable = async ( method, cart ) => {
	const availablePaymentMethods = await checkAvailablePaymentMethods( cart );

	return Boolean( availablePaymentMethods?.[ method ] );
};

const splitName = ( name = '' ) => {
	const parts = name.trim().split( /\s+/ ).filter( Boolean );

	return {
		first_name: parts.shift() || '',
		last_name: parts.join( ' ' ),
	};
};

const normalizeAddress = ( address = {}, fallback = {} ) => ( {
	first_name: address.first_name || fallback.first_name || '',
	last_name: address.last_name || fallback.last_name || '',
	company: address.company || fallback.company || '',
	address_1: address.address_1 || address.line1 || fallback.address_1 || '',
	address_2: address.address_2 || address.line2 || fallback.address_2 || '',
	city: address.city || fallback.city || '',
	state: address.state || fallback.state || '',
	postcode:
		address.postcode || address.postal_code || fallback.postcode || '',
	country: address.country || fallback.country || '',
	email: address.email || fallback.email || '',
	phone: address.phone || fallback.phone || '',
} );

const normalizePhone = ( phone ) => ( phone || '' ).replace( /[() -]/g, '' );

const getWalletBillingPhone = ( event ) =>
	normalizePhone( event?.billingDetails?.phone || event?.payerPhone );

const getBillingAddress = ( event, billing ) => {
	const eventBillingDetails = event?.billingDetails;
	const nameParts = splitName( eventBillingDetails?.name || '' );

	// Some wallets provide a single name; checkout requires a last name.
	nameParts.last_name = nameParts.last_name || '-';

	return normalizeAddress(
		{
			...nameParts,
			email: eventBillingDetails?.email || '',
			phone: getWalletBillingPhone( event ),
			...( eventBillingDetails?.address || {} ),
		},
		billing?.billingAddress || {}
	);
};

const getLineItemName = ( item ) =>
	[
		item.name,
		item.quantity > 1 && `(x${ item.quantity })`,
		item.variation?.length > 0 && '-',
		item.variation
			?.map(
				( variation ) =>
					`${ variation.attribute }: ${ variation.value }`
			)
			.join( ', ' ),
		item.item_data?.length > 0 && '-',
		item.item_data
			?.map(
				( itemData ) =>
					`${ itemData.name || itemData.key }: ${ itemData.value }`
			)
			.join( ', ' ),
	]
		.filter( Boolean )
		.map( decodeEntities )
		.join( ' ' );

const getCartDisplayItems = ( cart ) => {
	const displayPriceIncludingTax = Boolean(
		params?.checkout?.display_prices_with_tax
	);
	const cartData = applyFilters(
		'wcpay.express-checkout.map-line-items',
		normalizeStoreApiCart( cart )
	);
	const totals = cartData.totals || {};
	const items = Array.isArray( cartData.items ) ? cartData.items : [];
	const displayItems = items.map( ( item ) => {
		const lineTotals = item.totals || item.prices || {};
		const amount = displayPriceIncludingTax
			? parseMinorUnitAmount( lineTotals.line_subtotal ) +
			  parseMinorUnitAmount( lineTotals.line_subtotal_tax )
			: parseMinorUnitAmount(
					lineTotals.line_subtotal || item.prices?.price
			  );

		return {
			amount: transformPrice( amount, lineTotals ),
			name: getLineItemName( item ),
		};
	} );

	const shippingAmount = parseMinorUnitAmount( totals.total_shipping );
	if ( shippingAmount ) {
		displayItems.push( {
			amount: transformPrice(
				displayPriceIncludingTax
					? shippingAmount +
							parseMinorUnitAmount( totals.total_shipping_tax )
					: shippingAmount,
				totals
			),
			name: __( 'Shipping', 'woocommerce' ),
		} );
	}

	const discountAmount = parseMinorUnitAmount( totals.total_discount );
	if ( discountAmount ) {
		displayItems.push( {
			amount: -transformPrice(
				displayPriceIncludingTax
					? discountAmount +
							parseMinorUnitAmount( totals.total_discount_tax )
					: discountAmount,
				totals
			),
			name: __( 'Discount', 'woocommerce' ),
		} );
	}

	const feesAmount = parseMinorUnitAmount( totals.total_fees );
	if ( feesAmount ) {
		displayItems.push( {
			amount: transformPrice(
				displayPriceIncludingTax
					? feesAmount + parseMinorUnitAmount( totals.total_fees_tax )
					: feesAmount,
				totals
			),
			name: __( 'Fees', 'woocommerce' ),
		} );
	}

	const taxAmount = parseMinorUnitAmount( totals.total_tax );
	if ( taxAmount && ! displayPriceIncludingTax ) {
		displayItems.push( {
			amount: transformPrice( taxAmount, totals ),
			name: __( 'Tax', 'woocommerce' ),
		} );
	}

	const refundAmount = parseMinorUnitAmount( totals.total_refund );
	if ( refundAmount ) {
		displayItems.push( {
			amount: -transformPrice( refundAmount, totals ),
			name: __( 'Refund', 'woocommerce' ),
		} );
	}

	return displayItems;
};

const getCartShippingRates = ( cart ) => {
	const cartData = normalizeStoreApiCart( cart );
	const baseShippingRates =
		cartData.shipping_rates?.[ 0 ]?.shipping_rates || [];
	const effectiveShippingRates = applyFilters(
		'wcpay.express-checkout.shipping-rates',
		baseShippingRates,
		cartData
	);

	if ( ! Array.isArray( effectiveShippingRates ) ) {
		return [];
	}

	return effectiveShippingRates
		.sort( ( rateA, rateB ) => {
			if ( rateA.selected === rateB.selected ) {
				return 0;
			}

			return rateA.selected ? -1 : 1;
		} )
		.slice( 0, SHIPPING_RATES_UPPER_LIMIT_COUNT )
		.map( ( rate ) => {
			const amount = params?.checkout?.display_prices_with_tax
				? parseMinorUnitAmount( rate.price ) +
				  parseMinorUnitAmount( rate.taxes )
				: parseMinorUnitAmount( rate.price );
			const metaData = Array.isArray( rate.meta_data )
				? rate.meta_data
				: [];

			return {
				id: rate.rate_id,
				displayName: decodeEntities( rate.name || '' ),
				amount: transformPrice( amount, rate ),
				deliveryEstimate: metaData
					.filter( ( item ) =>
						[ 'pickup_address', 'pickup_details' ].includes(
							item.key
						)
					)
					.map( ( item ) => decodeEntities( item.value ) )
					.filter( Boolean )
					.join( ' - ' ),
			};
		} );
};

const getPackageIdForShippingRate = ( cart, rateId ) => {
	const cartData = normalizeStoreApiCart( cart );
	const packages = Array.isArray( cartData.shipping_rates )
		? cartData.shipping_rates
		: [];
	const packageIndex = packages.findIndex( ( shippingPackage ) =>
		( shippingPackage.shipping_rates || [] ).some(
			( rate ) => rate.rate_id === rateId
		)
	);
	let packageId = 0;

	if ( packageIndex >= 0 ) {
		packageId = packages[ packageIndex ].package_id ?? packageIndex;
	}

	return applyFilters(
		'wcpay.express-checkout.shipping-package-id',
		packageId,
		cartData,
		rateId
	);
};

const getElementsUpdateOptionsForCart = ( cart ) => {
	const cartData = normalizeStoreApiCart( cart );
	const options = {
		amount: applyFilters(
			'wcpay.express-checkout.total-amount',
			getCartTotalPrice( cart ),
			cartData
		),
	};

	// setupFutureUsage is a confirmation-token option; Elements created in
	// manual payment-method mode rejects it on update.
	if ( shouldUseConfirmationTokens() ) {
		options.setupFutureUsage = getSetupFutureUsageForCart( cartData );
	}

	return options;
};

const getCartCurrencyMismatch = ( expectedCurrency, cart ) => {
	const updatedCurrency = getCartTotalsCurrency( cart );

	return Boolean(
		expectedCurrency &&
			updatedCurrency &&
			expectedCurrency !== updatedCurrency
	);
};

const getShippingAddressFromEvent = ( event, fallback = {} ) => {
	const nameParts = splitName( event?.name || '' );

	return normalizeAddress(
		{
			...nameParts,
			...( event?.address || {} ),
		},
		fallback
	);
};

const getOrderNotes = () =>
	window.wp?.data?.select?.( 'wc/store/checkout' )?.getOrderNotes?.() || '';

const GENERIC_PAYMENT_ERROR_MESSAGE = __(
	'Unable to process this payment, please try again.',
	'woocommerce'
);

const getCheckoutErrorMessage = ( response ) => {
	const details = response?.payment_result?.payment_details;
	const errorDetail = Array.isArray( details )
		? details.find( ( detail ) => detail.key === 'errorMessage' )
		: null;

	return (
		errorDetail?.value ||
		response?.message ||
		__( 'Unable to process this payment, please try again.', 'woocommerce' )
	);
};

const getPaymentData = ( paymentCredentialId, paymentMethodTypes ) => [
	{
		key: shouldUseConfirmationTokens()
			? 'wcpay-confirmation-token'
			: 'wcpay-payment-method',
		value: paymentCredentialId,
	},
	{
		key: 'wcpay-express-payment-method-types',
		value: JSON.stringify( paymentMethodTypes ),
	},
	{
		key: 'wcpay-express-checkout-context',
		value: params.button_context || 'checkout',
	},
	{
		key: 'wcpay-fraud-prevention-token',
		value: window.wcpayFraudPreventionToken ?? '',
	},
];

const getRedirectUrl = ( response ) =>
	response?.payment_result?.redirect_url ||
	response?.payment_result?.payment_details?.find(
		( detail ) => detail.key === 'redirect'
	)?.value ||
	'';

const requestOrderStatusUpdate = async ( orderId, nonce, intentId ) => {
	const body = new window.URLSearchParams();
	body.append( 'action', 'update_order_status' );
	body.append( 'order_id', orderId );
	body.append( '_ajax_nonce', nonce );
	body.append( 'intent_id', intentId );
	body.append( 'should_save_payment_method', 'false' );
	body.append(
		'is_changing_payment',
		settings?.isChangingPayment ? 'true' : 'false'
	);

	const response = await window.fetch( params.ajax_url, {
		method: 'POST',
		credentials: 'same-origin',
		headers: {
			'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
		},
		body,
	} );

	return response.json();
};

/**
 * Confirm an intent that needs a next action (3DS) and get the order's return URL.
 *
 * Port of the client's WCPayAPI.confirmIntent() (client/checkout/api/index.js): returns `true` when the
 * redirect carries no `#wcpay-confirm-` hash, otherwise a promise of the authenticated return URL.
 *
 * @param {string} redirectUrl Redirect URL or confirmation hash from the checkout response.
 * @return {true|Promise<string>} `true` when there is nothing to confirm.
 */
const confirmIntent = ( redirectUrl ) => {
	const partials = redirectUrl.match(
		/#wcpay-confirm-(pi|si):([^:]+):([^:]+):([^:]+)(?::(.+))?$/
	);

	if ( ! partials ) {
		return true;
	}

	const [ , intentType, orderId, clientSecret, nonce, confirmationToken ] =
		partials;

	const confirmPaymentOrSetup = async () => {
		const stripe = getStripe();

		if ( ! stripe ) {
			throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
		}

		if ( intentType === 'si' && confirmationToken ) {
			return stripe.confirmSetup( {
				clientSecret,
				confirmParams: {
					confirmation_token: confirmationToken,
				},
				redirect: 'if_required',
			} );
		}

		return stripe.handleNextAction( { clientSecret } );
	};

	return confirmPaymentOrSetup().then( async ( result ) => {
		const intent = result?.paymentIntent || result?.setupIntent;
		let paymentError = intent?.last_payment_error?.message || '';

		// A closed wallet iframe resolves without an error, but the intent still requires action.
		if ( result?.paymentIntent?.status === 'requires_action' ) {
			paymentError = 'Payment requires additional action.';
		}

		const intentId =
			intent?.id ||
			result?.error?.payment_intent?.id ||
			result?.error?.setup_intent?.id;

		if ( result?.error ) {
			// The server records the failed confirmation; the shopper sees Stripe's error.
			if ( intentId ) {
				requestOrderStatusUpdate( orderId, nonce, intentId ).catch(
					() => {}
				);
			}
			throw new Error( result.error.message );
		}

		if ( ! intentId ) {
			throw new Error( GENERIC_PAYMENT_ERROR_MESSAGE );
		}

		const response = await requestOrderStatusUpdate(
			orderId,
			nonce,
			intentId
		);

		if ( response?.error ) {
			throw new Error( response.error.message );
		}

		if ( paymentError ) {
			throw new Error( paymentError );
		}

		return response?.return_url;
	} );
};

// One API object for every express method, as the client passes its WCPayAPI instance (client/checkout/blocks/index.js).
const expressCheckoutApi = { confirmIntent };

const redirectToOrder = async ( response, api ) => {
	const redirectUrl = getRedirectUrl( response );

	if ( ! redirectUrl ) {
		return;
	}

	const confirmationRequest = api.confirmIntent( redirectUrl );
	window.location.href =
		confirmationRequest === true ? redirectUrl : await confirmationRequest;
};

const refreshBlocksCartData = () => {
	dispatch( cartStore )?.invalidateResolutionForStore?.();
};

// Same Store API cart update WooPayments sends when a wallet flow is canceled.
const refreshBlocksCartUi = () =>
	extensionCartUpdate( {
		namespace: REFRESH_UI_NAMESPACE,
		data: {},
	} ).catch( refreshBlocksCartData );

const ExpressCheckoutContent = ( {
	method,
	api,
	billing,
	cartData,
	shippingData,
	onClick,
	onClose,
	setExpressPaymentError,
} ) => {
	const containerRef = useRef( null );
	const stripeRef = useRef( null );
	const elementsRef = useRef( null );
	const expressElementRef = useRef( null );
	const cartDataRef = useRef( cartData );
	const billingRef = useRef( billing );
	const shippingDataRef = useRef( shippingData );
	const appliedElementsOptionsRef = useRef( null );
	const latestCartDataRef = useRef( null );
	const walletMutatedCartRef = useRef( false );
	const methodConfig = METHOD_CONFIG[ method ];

	const markWalletCartMutation = useCallback( () => {
		walletMutatedCartRef.current = true;
	}, [] );

	const refreshCartAfterWalletMutation = useCallback(
		( refreshCart = refreshBlocksCartData ) => {
			if ( ! walletMutatedCartRef.current ) {
				return;
			}

			walletMutatedCartRef.current = false;
			latestCartDataRef.current = null;
			refreshCart();
		},
		[]
	);

	// The wallet handlers are registered once; they read the live Blocks data through these refs.
	cartDataRef.current = cartData;
	billingRef.current = billing;
	shippingDataRef.current = shippingData;

	const getCurrentCart = useCallback(
		() =>
			normalizeStoreApiCart(
				latestCartDataRef.current || cartDataRef.current || {},
				billingRef.current,
				shippingDataRef.current
			),
		[]
	);
	const liveElementsOptions = JSON.stringify(
		getLiveElementsOptions( billing, getCurrentCart() )
	);

	useEffect( () => {
		if (
			expressElementRef.current ||
			! containerRef.current ||
			! window.Stripe ||
			! params?.stripe?.publishableKey
		) {
			return;
		}

		initOrderAttribution();

		stripeRef.current = window.Stripe( params.stripe.publishableKey, {
			locale: params.stripe.locale || 'auto',
			...( params.stripe.accountId
				? { stripeAccount: params.stripe.accountId }
				: {} ),
			betas: getStripeBetas(),
		} );
		elementsRef.current = stripeRef.current.elements(
			getStripeElementsOptions( billing, getCurrentCart() )
		);
		appliedElementsOptionsRef.current = JSON.parse( liveElementsOptions );
		expressElementRef.current = elementsRef.current.create(
			'expressCheckout',
			getExpressButtonOptions( method )
		);
		const elementCurrency = getCartCurrency( billing );

		expressElementRef.current.on( 'ready', ( event ) => {
			if (
				event?.availablePaymentMethods?.[ method ] &&
				methodConfig.loadEvent
			) {
				recordWooPaymentsUserEvent(
					getTrackingSettings(),
					methodConfig.loadEvent,
					{ source: params.button_context || 'checkout' }
				);
			}
		} );

		expressElementRef.current.on( 'click', ( event ) => {
			// If login is required for checkout, display the redirect
			// confirmation dialog instead of opening the wallet sheet.
			if ( params?.login_confirmation ) {
				displayLoginConfirmation( event?.expressPaymentType );
				return;
			}

			const currentCart = getCurrentCart();
			const shippingAddressRequired = Boolean(
				shippingDataRef.current?.needsShipping ||
					params?.checkout?.needs_shipping
			);
			let shippingRates;

			if ( shippingAddressRequired ) {
				shippingRates = getCartShippingRates( currentCart );
				if ( ! shippingRates.length ) {
					shippingRates = [
						{
							id: 'pending',
							displayName: __( 'Pending', 'woocommerce' ),
							amount: 0,
						},
					];
				}
			}

			onClick?.();
			if ( methodConfig.clickEvent ) {
				recordWooPaymentsUserEvent(
					getTrackingSettings(),
					methodConfig.clickEvent,
					{ source: params.button_context || 'checkout' }
				);
			}

			event.resolve( {
				business: {
					name: params.store_name || '',
				},
				emailRequired: true,
				phoneNumberRequired: Boolean(
					params?.checkout?.needs_payer_phone
				),
				lineItems: getCartDisplayItems( currentCart ),
				shippingAddressRequired,
				shippingRates,
				allowedShippingCountries:
					params?.checkout?.allowed_shipping_countries || [],
			} );
		} );

		// Client 11.1.0 refreshes on cancel only after an address pick (event-handlers.js:319-325); native also does after a
		// rate pick, which the server cart already holds. Accepted improvement, inbox N-266.
		expressElementRef.current.on( 'cancel', () => {
			refreshCartAfterWalletMutation( refreshBlocksCartUi );
			onClose?.();
		} );

		expressElementRef.current.on(
			'shippingaddresschange',
			async ( event ) => {
				try {
					const updatedCart = await apiFetch( {
						method: 'POST',
						path: getStoreApiPath(
							'/wc/store/v1/cart/update-customer'
						),
						headers: {
							...getStoreApiHeaders(),
							'X-WooPayments-Tokenized-Cart': true,
						},
						data: {
							shipping_address: getShippingAddressFromEvent(
								event,
								shippingDataRef.current?.shippingAddress ||
									billingRef.current?.billingAddress ||
									{}
							),
						},
					} );
					markWalletCartMutation();

					if (
						getCartCurrencyMismatch( elementCurrency, updatedCart )
					) {
						setExpressPaymentError?.(
							__(
								'The cart currency changed for the selected shipping address. Use the regular checkout to continue.',
								'woocommerce'
							)
						);
						event.reject();
						return;
					}

					const shippingRates = getCartShippingRates( updatedCart );
					if ( ! shippingRates.length ) {
						setExpressPaymentError?.(
							__(
								'No shipping options are available for the selected address. Choose a different shipping address, or use the regular checkout.',
								'woocommerce'
							)
						);
						event.reject();
						return;
					}

					await elementsRef.current.update(
						getElementsUpdateOptionsForCart( updatedCart )
					);
					latestCartDataRef.current = updatedCart;
					event.resolve( {
						shippingRates,
						lineItems: getCartDisplayItems( updatedCart ),
					} );
				} catch ( error ) {
					event.reject();
					setExpressPaymentError?.(
						error?.message ||
							__(
								'Unable to update shipping for this payment, please try again.',
								'woocommerce'
							)
					);
				}
			}
		);

		expressElementRef.current.on( 'shippingratechange', async ( event ) => {
			const currentCart = getCurrentCart();
			try {
				const updatedCart = await apiFetch( {
					method: 'POST',
					path: getStoreApiPath(
						'/wc/store/v1/cart/select-shipping-rate'
					),
					headers: getStoreApiHeaders(),
					data: {
						package_id: getPackageIdForShippingRate(
							currentCart,
							event?.shippingRate?.id
						),
						rate_id: event?.shippingRate?.id,
					},
				} );
				markWalletCartMutation();

				if ( getCartCurrencyMismatch( elementCurrency, updatedCart ) ) {
					setExpressPaymentError?.(
						__(
							'The cart currency changed for the selected shipping rate. Use the regular checkout to continue.',
							'woocommerce'
						)
					);
					event.reject();
					return;
				}

				await elementsRef.current.update(
					getElementsUpdateOptionsForCart( updatedCart )
				);
				latestCartDataRef.current = updatedCart;
				event.resolve( {
					lineItems: getCartDisplayItems( updatedCart ),
				} );
			} catch ( error ) {
				event.reject();
				setExpressPaymentError?.(
					error?.message ||
						__(
							'Unable to update shipping for this payment, please try again.',
							'woocommerce'
						)
				);
			}
		} );

		expressElementRef.current.on( 'confirm', async ( event ) => {
			try {
				const submitResult = await elementsRef.current.submit();

				if ( submitResult?.error ) {
					throw new Error( submitResult.error.message );
				}

				const credentialResult = shouldUseConfirmationTokens()
					? await stripeRef.current.createConfirmationToken( {
							elements: elementsRef.current,
					  } )
					: await stripeRef.current.createPaymentMethod( {
							elements: elementsRef.current,
					  } );

				if ( credentialResult?.error ) {
					throw new Error( credentialResult.error.message );
				}

				const paymentCredentialId = shouldUseConfirmationTokens()
					? credentialResult.confirmationToken.id
					: credentialResult.paymentMethod.id;

				const paymentMethodTypes = getPaymentMethodTypes(
					getCurrentCart()
				);
				const orderNotes = getOrderNotes();
				// Stripe provides no separate shipping phone, so the
				// billing one is reused on the shipping address.
				const walletBillingPhone = getWalletBillingPhone( event );
				const eventShippingAddress = event?.shippingAddress
					? {
							...getShippingAddressFromEvent(
								event.shippingAddress,
								shippingDataRef.current?.shippingAddress || {}
							),
							...( walletBillingPhone
								? { phone: walletBillingPhone }
								: {} ),
					  }
					: shippingDataRef.current?.shippingAddress;
				const response = await apiFetch( {
					method: 'POST',
					path: getStoreApiPath( '/wc/store/v1/checkout' ),
					headers: {
						...getStoreApiHeaders(
							params.button_context === 'product'
						),
						'X-WooPayments-Tokenized-Cart': true,
						// Lets the server reject placement when the cart's
						// currency drifted away from the one the Element
						// booted with.
						...( elementCurrency
							? {
									'X-WooPayments-Payment-Currency':
										elementCurrency,
							  }
							: {} ),
					},
					data: {
						payment_method: PAYMENT_METHOD_NAME,
						billing_address: getBillingAddress(
							event,
							billingRef.current
						),
						shipping_address: eventShippingAddress || undefined,
						...( orderNotes ? { customer_note: orderNotes } : {} ),
						payment_data: getPaymentData(
							paymentCredentialId,
							paymentMethodTypes
						),
						extensions: getPlaceOrderExtensions(),
					},
				} );

				if (
					response?.payment_result?.payment_status &&
					response.payment_result.payment_status !== 'success'
				) {
					throw new Error( getCheckoutErrorMessage( response ) );
				}

				await redirectToOrder( response, api );
			} catch ( error ) {
				const message =
					error?.message ||
					__(
						'Unable to process this payment, please try again.',
						'woocommerce'
					);

				event?.paymentFailed?.( {
					reason: 'fail',
					message,
				} );
				refreshCartAfterWalletMutation();
				setExpressPaymentError?.( message );
			}
		} );

		expressElementRef.current.mount( containerRef.current );
	}, [
		api,
		billing,
		getCurrentCart,
		liveElementsOptions,
		method,
		methodConfig.clickEvent,
		methodConfig.loadEvent,
		markWalletCartMutation,
		onClick,
		onClose,
		refreshCartAfterWalletMutation,
		setExpressPaymentError,
	] );

	// Keep the wallet amount in step with the cart, as the client's <Elements options> does on every render.
	useEffect( () => {
		if ( ! elementsRef.current || ! appliedElementsOptionsRef.current ) {
			return;
		}

		const nextOptions = JSON.parse( liveElementsOptions );
		const changedOptions = getChangedElementsOptions(
			nextOptions,
			appliedElementsOptionsRef.current
		);
		appliedElementsOptionsRef.current = nextOptions;

		if ( Object.keys( changedOptions ).length ) {
			elementsRef.current.update( changedOptions );
		}
	}, [ liveElementsOptions ] );

	return (
		<div className="wcpay-core-express-checkout">
			<div
				ref={ containerRef }
				className="wcpay-core-express-checkout__element"
			/>
		</div>
	);
};

const getExpressPaymentMethod = ( method ) => {
	const methodConfig = METHOD_CONFIG[ method ];

	return {
		name: methodConfig.name,
		title: methodConfig.title,
		description: methodConfig.title,
		gatewayId: PAYMENT_METHOD_NAME,
		paymentMethodId: EXPRESS_CHECKOUT_PAYMENT_METHOD_NAME,
		content: (
			<ExpressCheckoutContent
				method={ method }
				api={ expressCheckoutApi }
			/>
		),
		edit: (
			<ExpressCheckoutContent
				method={ method }
				api={ expressCheckoutApi }
			/>
		),
		canMakePayment: ( { cart } ) => {
			if (
				! getEnabledMethodsForCart( cart ).includes(
					methodConfig.enabledMethod
				)
			) {
				return false;
			}

			return checkPaymentMethodIsAvailable( method, cart );
		},
		supports: {
			features: settings?.supports || [ 'products' ],
			style: [ 'height', 'borderRadius' ],
		},
	};
};

const registerWooPaymentsExpressCheckout = () => {
	availabilityCache.clear();
	availabilityStripe = null;

	if (
		Array.isArray( params.enabled_methods ) &&
		params.enabled_methods.includes( 'payment_request' )
	) {
		registerExpressPaymentMethod( getExpressPaymentMethod( 'applePay' ) );
		registerExpressPaymentMethod( getExpressPaymentMethod( 'googlePay' ) );
	}

	if (
		Array.isArray( params.enabled_methods ) &&
		params.enabled_methods.includes( 'amazon_pay' ) &&
		getPaymentMethodTypes().includes( 'amazon_pay' )
	) {
		registerExpressPaymentMethod( getExpressPaymentMethod( 'amazonPay' ) );
	}
};

registerSubscriptionsCompatibility();
registerWooPaymentsExpressCheckout();

export default registerWooPaymentsExpressCheckout;
