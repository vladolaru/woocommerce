/**
 * External dependencies
 */
import { readdirSync, readFileSync } from 'fs';
import { join } from 'path';

const APP_DIR = join( __dirname, '../app' );
const ENTRY_FILE = join(
	__dirname,
	'../../wp-admin-scripts/paypal-wallet-settings/index.ts'
);

// Bundled into the chunk rather than read from the page.
const BUNDLED_PACKAGES = [ '@wordpress/icons' ];

const sourceFiles = ( dir: string ): string[] =>
	readdirSync( dir, { withFileTypes: true } ).flatMap( ( entry ) => {
		const path = join( dir, entry.name );
		if ( entry.isDirectory() ) {
			return sourceFiles( path );
		}
		return /\.jsx?$/.test( entry.name ) &&
			! /\.test\.jsx?$/.test( entry.name )
			? [ path ]
			: [];
	} );

const pagePackagesImportedBy = ( source: string ): string[] =>
	Array.from(
		source.matchAll( /from\s+'((?:@wordpress\/[^/']+)|react|react-dom)'/g ),
		( match ) => match[ 1 ]
	).filter( ( name ) => ! BUNDLED_PACKAGES.includes( name ) );

describe( 'PayPal wallet settings entry', () => {
	it( 'lists every WordPress script the lazy settings app reads from the page', () => {
		const appPackages = new Set(
			sourceFiles( APP_DIR ).flatMap( ( file ) =>
				pagePackagesImportedBy( readFileSync( file, 'utf8' ) )
			)
		);
		const entry = readFileSync( ENTRY_FILE, 'utf8' );

		expect( appPackages.size ).toBeGreaterThan( 0 );
		appPackages.forEach( ( name ) => {
			expect( entry ).toContain( `import '${ name }';` );
		} );
	} );
} );
