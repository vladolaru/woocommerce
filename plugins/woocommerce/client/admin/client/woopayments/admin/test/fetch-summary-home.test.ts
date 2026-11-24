/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';

const ADMIN_DIR = path.resolve( __dirname, '..' );
const HOME = path.join( ADMIN_DIR, 'money-movement', 'utils.ts' );

const sourceFiles = ( dir: string ): string[] =>
	fs.readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const full = path.join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return entry.name === 'test' ? [] : sourceFiles( full );
		}
		return /\.tsx?$/.test( entry.name ) ? [ full ] : [];
	} );

const resolveImport = ( from: string, specifier: string ) =>
	[ '.ts', '.tsx', '/index.ts' ]
		.map( ( ext ) => path.resolve( path.dirname( from ), specifier ) + ext )
		.find( ( candidate ) => fs.existsSync( candidate ) );

/**
 * The summary helper is too small for webpack to split out on its own, so a module of its own is copied into
 * every route chunk that uses it. It lives in money-movement/utils.ts, which ships once in the chunk the
 * Capital, Overview, Reports and money movement routes already share.
 */
describe( 'fetchSummary home', () => {
	const files = sourceFiles( ADMIN_DIR );

	it( 'is defined only in money-movement/utils.ts', () => {
		const definitions = files.filter( ( file ) =>
			/export\s+(const|async\s+function|function)\s+fetchSummary\b/.test(
				fs.readFileSync( file, 'utf8' )
			)
		);

		expect( definitions ).toEqual( [ HOME ] );
	} );

	it( 'is imported only from money-movement/utils.ts', () => {
		const importers = files.flatMap( ( file ) =>
			[
				...fs
					.readFileSync( file, 'utf8' )
					.matchAll(
						/import\s*{[^}]*\bfetchSummary\b[^}]*}\s*from\s*'([^']+)'/g
					),
			].map( ( match ) => ( {
				file: path.relative( ADMIN_DIR, file ),
				source: resolveImport( file, match[ 1 ] ),
			} ) )
		);

		expect( importers.map( ( { file } ) => file ) ).toEqual(
			expect.arrayContaining( [
				'capital/data.ts',
				'money-movement/data.ts',
				'overview/data.ts',
				'reports/data.ts',
			] )
		);
		importers.forEach( ( { source } ) => expect( source ).toBe( HOME ) );
	} );
} );
