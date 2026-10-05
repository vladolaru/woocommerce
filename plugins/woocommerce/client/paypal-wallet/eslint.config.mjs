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
			},
		},
		rules: {
			// Forked from the extension at 0083204e7 with its own lint rules; every rule the WooCommerce config
			// turns into an error is a warning here until the inherited code is cleaned up.
			'@wordpress/i18n-text-domain': [
				'warn',
				{ allowedTextDomain: 'woocommerce' },
			],
			'jsx-a11y/click-events-have-key-events': 'warn',
			'jsx-a11y/no-static-element-interactions': 'warn',
		},
	},
];
