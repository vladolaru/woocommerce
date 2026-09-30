/**
 * @jest-environment node
 */

/**
 * External dependencies
 */
import fs from 'fs';
import path from 'path';
import * as sass from 'sass';

test( 'inlines the DataViews stylesheet the lists need', () => {
	const dataViewsPackageDir = path.dirname(
		require.resolve( '@wordpress/dataviews/package.json' )
	);
	const css = sass.compileString(
		fs.readFileSync(
			path.resolve( __dirname, '../dataviews.scss' ),
			'utf8'
		),
		{ loadPaths: [ path.resolve( dataViewsPackageDir, '../..' ) ] }
	).css;

	// A plain CSS `@import` would leave the stylesheet out of the shared bundle.
	expect( css ).not.toMatch( /@import/ );
	expect( css ).toContain( '.dataviews-view-table' );
	expect( css ).toContain( '.dataviews-pagination' );
	expect( css ).toContain( '.dataviews-filters__container' );
} );
