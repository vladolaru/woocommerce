/**
 * External dependencies
 */
import { globalIgnores } from 'eslint/config';
import globals from 'globals';

export default [
	globalIgnores( [
		'**/*.min.js',
		'js/accounting/**',
		'js/flexslider/**',
		'js/jquery-blockui/**',
		'js/jquery-cookie/**',
		'js/jquery-flot/**',
		'js/jquery-payment/**',
		'js/jquery-qrcode/**',
		'js/jquery-serializejson/**',
		'js/jquery-tiptip/**',
		'js/jquery-ui-touch-punch/**',
		'js/js-cookie/**',
		'js/photoswipe/**',
		'js/prettyPhoto/**',
		'js/round/**',
		'js/select2/**',
		'js/selectWoo/**',
		'js/stupidtable/**',
		'js/zoom/**',
	] ),
	{
		files: [ 'js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2018,
			/*
			 * These are classic scripts, not modules. eslintrc defaulted to
			 * `script`; flat config defaults `.js` to `module`, which would
			 * parse them in strict mode.
			 */
			sourceType: 'script',
			globals: {
				...globals.browser,
				...globals.node,
				...globals.es2015,
				wp: 'writable',
				wpApiSettings: 'writable',
				wcSettings: 'writable',
			},
			parserOptions: {
				ecmaFeatures: {
					jsx: true,
				},
			},
		},
		rules: {
			camelcase: 'off',
			indent: 'off',
			'max-len': [ 'error', { code: 140 } ],
			'no-console': 'warn',
			// The Grunt build minifies these scripts with uglify-es, which parses ES2018 except `for await`.
			'no-restricted-syntax': [
				'error',
				{
					selector: 'ForOfStatement[await=true]',
					message:
						'uglify-es, which minifies these scripts, cannot parse `for await`.',
				},
			],
		},
	},
];
