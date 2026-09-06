/** @jest-environment node */

/**
 * External dependencies
 */
import path from 'path';
import { spawnSync } from 'child_process';

const adminDirectory = path.resolve( __dirname, '../../../..' );

const fixtures = [
	{
		id: 'direct-register-imports',
		filePath: 'client/woopayments/settings/data/store.ts',
		code: [
			"import { register, registerStore, registerGenericStore } from '@wordpress/data';",
			'register( {} );',
			"registerStore( 'test/store', {} );",
			"registerGenericStore( 'test/store', {} );",
		].join( '\n' ),
	},
	{
		id: 'allowed-register-imports',
		filePath: 'client/woopayments/settings/data/register.ts',
		code: [
			"import { register, registerStore, registerGenericStore } from '@wordpress/data';",
			"import * as wpData from '@wordpress/data';",
			'register( {} );',
			"registerStore( 'test/store', {} );",
			"registerGenericStore( 'test/store', {} );",
			'wpData.register( {} );',
		].join( '\n' ),
	},
	{
		id: 'namespace-import',
		filePath: 'client/woopayments/settings/data/store.ts',
		code: [
			"import * as wpData from '@wordpress/data';",
			'wpData.register( {} );',
		].join( '\n' ),
	},
	{
		id: 'vat-modal-import',
		filePath: 'client/woopayments/settings/settings-page.tsx',
		code: "import '../admin/documents/vat-modal';\n",
	},
	{
		id: 'fraud-tour-import',
		filePath: 'client/woopayments/settings/fraud-protection/index.tsx',
		code: "import './tour';\n",
	},
	{
		id: 'woopay-settings-import',
		filePath:
			'client/woopayments/settings/express-checkout/express-checkout-settings.tsx',
		code: "import './woopay-settings';\n",
	},
];

const lintResults = new Map();

const lintWooPaymentsFixtures = () => {
	const result = spawnSync(
		process.execPath,
		[
			'--input-type=module',
			'--eval',
			[
				`import { ESLint } from ${ JSON.stringify(
					require.resolve( 'eslint' )
				) };`,
				'const chunks = [];',
				'for await ( const chunk of process.stdin ) { chunks.push( chunk ); }',
				'const fixtures = JSON.parse( Buffer.concat( chunks ).toString() );',
				"const eslint = new ESLint( { cwd: process.cwd(), overrideConfigFile: 'eslint.config.mjs' } );",
				'const results = await Promise.all( fixtures.map( async ( fixture ) => {',
				'\tconst [ result ] = await eslint.lintText( fixture.code, { filePath: fixture.filePath } );',
				'\treturn result;',
				'} ) );',
				'process.stdout.write( JSON.stringify( results ) );',
			].join( '\n' ),
		],
		{
			cwd: adminDirectory,
			encoding: 'utf8',
			input: JSON.stringify( fixtures ),
			timeout: 30000,
		}
	);

	if ( result.status !== 0 && result.status !== 1 ) {
		throw new Error( result.error?.message || result.stderr );
	}

	const results = JSON.parse( result.stdout );
	fixtures.forEach( ( fixture, index ) => {
		lintResults.set( fixture.id, results[ index ] );
	} );
};

const getLintResult = ( id: string ) => {
	const result = lintResults.get( id );

	if ( ! result ) {
		throw new Error( `Missing lint result for ${ id }.` );
	}

	return result;
};

describe( 'WooPayments store registration lint guard', () => {
	beforeAll( () => {
		lintWooPaymentsFixtures();
	} );

	it( 'forbids direct @wordpress/data store registration imports outside data/register.ts', () => {
		const result = getLintResult( 'direct-register-imports' );

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

	it( 'allows @wordpress/data register imports in data/register.ts', () => {
		const result = getLintResult( 'allowed-register-imports' );

		expect(
			result.messages.some(
				( message ) => message.ruleId === 'no-restricted-syntax'
			)
		).toBe( false );
	} );

	it( 'forbids @wordpress/data namespace imports outside data/register.ts', () => {
		const result = getLintResult( 'namespace-import' );

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
			'vat-modal-import',
		],
		[
			'client/woopayments/settings/fraud-protection/index.tsx',
			'./tour',
			'fraud-tour-import',
		],
		[
			'client/woopayments/settings/express-checkout/express-checkout-settings.tsx',
			'./woopay-settings',
			'woopay-settings-import',
		],
	] )(
		'preserves the existing lazy-chunk guard for %s importing %s',
		( filePath, importPath, id ) => {
			const result = getLintResult( id );

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
