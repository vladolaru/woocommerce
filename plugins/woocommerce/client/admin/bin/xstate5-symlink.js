/**
 * Patches @xstate5/react to resolve xstate from the xstate5 alias.
 *
 * See https://github.com/woocommerce/woocommerce/pull/45548 for context.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const source = path.join( __dirname, '../node_modules/xstate5' );
const destination = path.join(
	__dirname,
	'../node_modules/@xstate5/react/node_modules/xstate'
);

// With pnpm's global virtual store (enable-global-virtual-store), the
// @xstate5/react directory is shared between checkouts, so another clone's
// install may have left this symlink behind - possibly pointing at a pruned
// store path. fs-extra's ensureSymlinkSync stat()s the destination, treats a
// dangling link as absent, and then crashes with EEXIST trying to recreate
// it. Drop any pre-existing link first so the install stays idempotent.
try {
	if ( fs.lstatSync( destination ).isSymbolicLink() ) {
		fs.unlinkSync( destination );
	}
} catch ( error ) {
	if ( 'ENOENT' !== error.code ) {
		throw error;
	}
}

require( 'fs-extra' ).ensureSymlinkSync( source, destination );
