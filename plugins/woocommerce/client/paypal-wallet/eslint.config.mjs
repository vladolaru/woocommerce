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
			// turns into an error is a warning here until plan B lifts each module (see the lint ledger).
			'no-console': 'warn',
			'@wordpress/i18n-text-domain': [
				'warn',
				{ allowedTextDomain: 'woocommerce' },
			],
			'prettier/prettier': 'warn',
			'import/order': 'warn',
			yoda: 'warn',
			'jsdoc/require-param-type': 'warn',
			'jsdoc/require-returns-description': 'warn',
			'jsdoc/check-param-names': 'warn',
			radix: 'warn',
			eqeqeq: 'warn',
			'no-alert': 'warn',
			'no-useless-constructor': 'warn',
			'@wordpress/no-unused-vars-before-return': 'warn',
			'@typescript-eslint/no-this-alias': 'warn',
			'jsdoc/check-alignment': 'warn',
			'jsx-a11y/click-events-have-key-events': 'warn',
			'jsx-a11y/no-static-element-interactions': 'warn',
			'@wordpress/i18n-no-collapsible-whitespace': 'warn',
			'@wordpress/i18n-no-variables': 'warn',
			'no-bitwise': 'warn',
		},
	},
];
