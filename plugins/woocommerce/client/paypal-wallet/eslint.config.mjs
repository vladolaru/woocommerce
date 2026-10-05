import woocommerce from '@woocommerce/eslint-config';

export default [
	...woocommerce,
	{
		ignores: [ 'node_modules/**', 'tests/js/__mocks__/**' ],
	},
	{
		files: [ 'modules/**/*.{js,jsx}' ],
		languageOptions: {
			globals: {
				wc: 'readonly',
				jQuery: 'readonly',
				paypal: 'readonly',
				ppcpSettings: 'readonly',
				// Browser globals the shared config does not declare.
				alert: 'readonly',
				DOMParser: 'readonly',
				location: 'readonly',
				MutationObserver: 'readonly',
				// Provided by WordPress and WooCommerce.
				lodash: 'readonly',
				React: 'readonly',
				ReactDOM: 'readonly',
				wc_cart_fragments_params: 'readonly',
				// Data and namespaces that PHP and the SDK scripts put on the page.
				FraudNetConfig: 'readonly',
				PayPalCommerceGateway: 'readonly',
				PayPalCommerceGatewaySettings: 'readonly',
				PcpCartPayLaterBlock: 'readonly',
				PcpCheckoutPayLaterBlock: 'readonly',
				PcpPayLaterBlock: 'readonly',
				PcpProductPayLaterBlock: 'readonly',
				PcpProductSmartButtonsBlock: 'readonly',
				PcpVoidButton: 'readonly',
				ppcpBlocksPaypalExpressButtons: 'readonly',
			},
		},
	},
];
