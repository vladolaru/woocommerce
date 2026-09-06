/**
 * External dependencies
 */
import path from 'path';
import { ESLint } from 'eslint';

const eslintConfig = require( '../../../../.eslintrc.js' );

const lintWooPaymentsFixture = async ( filePath: string, code: string ) => {
	const eslint = new ESLint( {
		cwd: process.cwd(),
		useEslintrc: false,
		overrideConfig: {
			parser: require.resolve( '@typescript-eslint/parser' ),
			parserOptions: {
				ecmaVersion: 2020,
				sourceType: 'module',
			},
			overrides: eslintConfig.overrides,
		},
	} );

	return eslint.lintText( code, {
		filePath: path.join( process.cwd(), filePath ),
	} );
};

describe( 'WooPayments store registration lint guard', () => {
	it( 'forbids direct @wordpress/data store registration imports outside data/register.ts', async () => {
		const [ result ] = await lintWooPaymentsFixture(
			'client/woopayments/settings/data/store.ts',
			[
				"import { register, registerStore, registerGenericStore } from '@wordpress/data';",
				'register( {} );',
				"registerStore( 'test/store', {} );",
				"registerGenericStore( 'test/store', {} );",
			].join( '\n' )
		);

		const restrictedMessages = result.messages
			.filter( ( message ) => message.ruleId === 'no-restricted-syntax' )
			.map( ( message ) => message.message );

		expect( restrictedMessages ).toEqual(
			expect.arrayContaining( [
				expect.stringContaining( 'register' ),
				expect.stringContaining( 'registerStore' ),
				expect.stringContaining( 'registerGenericStore' ),
			] )
		);
	} );

	it( 'allows @wordpress/data register imports in data/register.ts', async () => {
		const [ result ] = await lintWooPaymentsFixture(
			'client/woopayments/settings/data/register.ts',
			[
				"import { register, registerStore, registerGenericStore } from '@wordpress/data';",
				"import * as wpData from '@wordpress/data';",
				'register( {} );',
				"registerStore( 'test/store', {} );",
				"registerGenericStore( 'test/store', {} );",
				'wpData.register( {} );',
			].join( '\n' )
		);

		expect(
			result.messages.some(
				( message ) => message.ruleId === 'no-restricted-syntax'
			)
		).toBe( false );
	} );

	it( 'forbids @wordpress/data namespace imports outside data/register.ts', async () => {
		const [ result ] = await lintWooPaymentsFixture(
			'client/woopayments/settings/data/store.ts',
			[
				"import * as wpData from '@wordpress/data';",
				'wpData.register( {} );',
			].join( '\n' )
		);

		expect(
			result.messages.some(
				( message ) =>
					message.ruleId === 'no-restricted-syntax' &&
					message.message.includes( 'namespace' )
			)
		).toBe( true );
	} );

	it.each( [
		[
			'client/woopayments/settings/settings-page.tsx',
			'../admin/documents/vat-modal',
		],
		[ 'client/woopayments/settings/fraud-protection/index.tsx', './tour' ],
		[
			'client/woopayments/settings/express-checkout/express-checkout-settings.tsx',
			'./woopay-settings',
		],
	] )(
		'preserves the existing lazy-chunk guard for %s importing %s',
		async ( filePath, importPath ) => {
			const [ result ] = await lintWooPaymentsFixture(
				filePath,
				`import '${ importPath }';\n`
			);

			expect(
				result.messages.some(
					( message ) =>
						message.ruleId === 'no-restricted-imports' &&
						message.message.includes( 'lazily' )
				)
			).toBe( true );
		}
	);
} );
