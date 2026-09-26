/**
 * External dependencies
 */
import { globalIgnores } from 'eslint/config';

/**
 * Internal dependencies
 */
import woocommerce from '@woocommerce/eslint-config';
import { coreModules } from '@woocommerce/eslint-config/core-modules.js';

export default [
	// node_modules is ignored by default. `api` has its own config and command.
	globalIgnores( [
		'bin/*',
		'!bin/generate-docs',
		'build',
		'build-module',
		'build-types',
		'coverage',
		'languages',
		'vendor',
		'legacy',
		'tests/e2e',
		'api',
	] ),
	/*
	 * The eslintrc registered the `import` plugin itself. It must not:
	 * eslint-plugin-import has no ESLint v10 support, and the shared config
	 * already provides the fixupPluginRules-wrapped instance WordPress registers.
	 * Registering a second copy is both fatal at rule-run time and a
	 * "Cannot redefine plugin" hazard.
	 */
	...woocommerce,
	{
		settings: {
			'import/core-modules': [
				...coreModules,
				'@wordpress/block-library',
				'dompurify',
				'@react-spring/web',
				'react-router-dom',
				'redux',
				'xstate',
				'xstate5',
			],
			'import/resolver': {
				node: {},
				webpack: {},
				typescript: {
					project: [
						'plugins/woocommerce/client/admin/tsconfig.json',
					],
				},
			},
		},
	},
	{
		files: [ 'client/**/*.js', 'client/**/*.jsx', 'client/**/*.tsx' ],
		rules: {
			'react/react-in-jsx-scope': 'off',
		},
	},
	// Keep optional WooPayments settings surfaces in their own lazy-loaded chunks.
	{
		files: [ 'client/woopayments/settings/settings-page.tsx' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{
					paths: [
						{
							name: '../admin/documents/vat-modal',
							message:
								'Load the VAT details modal lazily via lazy( () => import( ... ) ) so it stays in its own chunk; a static import bundles it into the main settings chunk.',
						},
					],
				},
			],
		},
	},
	{
		files: [ 'client/woopayments/settings/fraud-protection/index.tsx' ],
		rules: {
			'no-restricted-imports': [
				'error',
				{
					paths: [
						{
							name: './tour',
							message:
								'Load the fraud protection tour lazily via lazy( () => import( ... ) ) so it stays in its own chunk; a static import bundles it into the main settings chunk.',
						},
					],
				},
			],
		},
	},
	{
		files: [
			'client/woopayments/settings/express-checkout/express-checkout-settings.tsx',
		],
		rules: {
			'no-restricted-imports': [
				'error',
				{
					paths: [
						{
							name: './woopay-settings',
							message:
								'Load the WooPay settings lazily via lazy( () => import( ... ) ) so it stays in its own chunk; a static import bundles it into the main settings chunk.',
						},
						{
							name: './payment-request-settings',
							message:
								'Load the payment request settings lazily via lazy( () => import( ... ) ) so it stays in its own chunk; a static import bundles it into the main settings chunk.',
						},
						{
							name: './amazon-pay-settings',
							message:
								'Load the Amazon Pay settings lazily via lazy( () => import( ... ) ) so it stays in its own chunk; a static import bundles it into the main settings chunk.',
						},
					],
				},
			],
		},
	},
	{
		files: [ 'client/woopayments/**/*.{js,ts,tsx}' ],
		ignores: [ 'client/woopayments/**/data/register.ts' ],
		rules: {
			'no-restricted-syntax': [
				'error',
				{
					selector:
						"ImportDeclaration[source.value='@wordpress/data'] ImportSpecifier[imported.name='register']",
					message:
						'Import register from @wordpress/data only in data/register.ts so WooPayments store registration stays lazy and coexistence-safe.',
				},
				{
					selector:
						"ImportDeclaration[source.value='@wordpress/data'] ImportSpecifier[imported.name='registerStore']",
					message:
						'Import registerStore from @wordpress/data only in data/register.ts so WooPayments store registration stays lazy and coexistence-safe.',
				},
				{
					selector:
						"ImportDeclaration[source.value='@wordpress/data'] ImportSpecifier[imported.name='registerGenericStore']",
					message:
						'Import registerGenericStore from @wordpress/data only in data/register.ts so WooPayments store registration stays lazy and coexistence-safe.',
				},
				{
					selector:
						"ImportDeclaration[source.value='@wordpress/data'] ImportNamespaceSpecifier",
					message:
						'Do not namespace import @wordpress/data in WooPayments files; named imports keep store registration enforceable from data/register.ts only.',
				},
			],
		},
	},
];
