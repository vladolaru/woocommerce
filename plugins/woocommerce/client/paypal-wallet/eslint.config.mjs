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
	},
];
