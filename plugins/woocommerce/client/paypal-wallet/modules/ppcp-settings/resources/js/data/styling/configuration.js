/**
 * Configuration for UI components.
 *
 * @file
 */

import { __ } from '@wordpress/i18n';

export const STYLING_LOCATIONS = {
	cart: {
		value: 'cart',
		label: __( 'Cart', 'woocommerce' ),
		link: 'https://woocommerce.com/document/woocommerce-paypal-payments/#button-on-cart',
		props: { layout: false, tagline: false },
	},
	classicCheckout: {
		value: 'classicCheckout',
		label: __( 'Classic Checkout', 'woocommerce' ),
		link: 'https://woocommerce.com/document/woocommerce-paypal-payments/#button-on-checkout',
		props: { layout: true, tagline: true },
	},
	expressCheckout: {
		value: 'expressCheckout',
		label: __( 'Express Checkout', 'woocommerce' ),
		link: 'https://woocommerce.com/document/woocommerce-paypal-payments/#button-on-block-express-checkout',
		props: { layout: false, tagline: false },
	},
	miniCart: {
		value: 'miniCart',
		label: __( 'Mini Cart', 'woocommerce' ),
		link: 'https://woocommerce.com/document/woocommerce-paypal-payments/#button-on-mini-cart',
		props: { layout: true, tagline: true },
	},
	product: {
		value: 'product',
		label: __( 'Product Page', 'woocommerce' ),
		link: 'https://woocommerce.com/document/woocommerce-paypal-payments/#button-on-single-product',
		props: { layout: true, tagline: true },
	},
};

export const STYLING_LABELS = {
	paypal: {
		value: 'paypal',
		label: __( 'PayPal', 'woocommerce' ),
	},
	checkout: {
		value: 'checkout',
		label: __( 'Checkout', 'woocommerce' ),
	},
	buynow: {
		value: 'buynow',
		label: __( 'PayPal Buy Now', 'woocommerce' ),
	},
	pay: {
		value: 'pay',
		label: __( 'Pay with PayPal', 'woocommerce' ),
	},
};

export const STYLING_COLORS = {
	gold: {
		value: 'gold',
		label: __( 'Gold (Recommended)', 'woocommerce' ),
	},
	blue: {
		value: 'blue',
		label: __( 'Blue', 'woocommerce' ),
	},
	silver: {
		value: 'silver',
		label: __( 'Silver', 'woocommerce' ),
	},
	black: {
		value: 'black',
		label: __( 'Black', 'woocommerce' ),
	},
	white: {
		value: 'white',
		label: __( 'White', 'woocommerce' ),
	},
};

export const STYLING_LAYOUTS = {
	vertical: {
		value: 'vertical',
		label: __( 'Vertical', 'woocommerce' ),
	},
	horizontal: {
		value: 'horizontal',
		label: __( 'Horizontal', 'woocommerce' ),
	},
};

export const STYLING_SHAPES = {
	rect: {
		value: 'rect',
		label: __( 'Rectangle', 'woocommerce' ),
	},
	pill: {
		value: 'pill',
		label: __( 'Pill', 'woocommerce' ),
	},
};

export const STYLING_PAYMENT_METHODS = {
	'ppcp-gateway': {
		value: 'ppcp-gateway',
		label: __( 'PayPal', 'woocommerce' ),
		checked: true,
		disabled: true,
	},
	venmo: {
		value: 'venmo',
		label: __( 'Venmo', 'woocommerce' ),
		isFunding: true,
	},
	'pay-later': {
		value: 'pay-later',
		fundingKey: 'paylater',
		label: __( 'Pay Later', 'woocommerce' ),
		isFunding: true,
	},
};
