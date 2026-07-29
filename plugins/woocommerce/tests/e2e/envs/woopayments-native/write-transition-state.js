#!/usr/bin/env node

const {
	chmodSync,
	closeSync,
	fsyncSync,
	lstatSync,
	openSync,
	readFileSync,
	renameSync,
	unlinkSync,
	writeFileSync,
} = require( 'node:fs' );
const { randomBytes } = require( 'node:crypto' );
const { basename, dirname, join } = require( 'node:path' );

const [ statePath, key, rawValue, type = 'string' ] = process.argv.slice( 2 );

if (
	! statePath ||
	! key ||
	! [ 'string', 'number', 'boolean' ].includes( type )
) {
	throw new Error(
		'Usage: write-transition-state.js <path> <key> <value> [string|number|boolean]'
	);
}
if (
	lstatSync( statePath ).isSymbolicLink() ||
	! lstatSync( statePath ).isFile()
) {
	throw new Error( 'Transition state must be an exact regular file.' );
}

let value = rawValue;
if ( type === 'number' ) {
	value = Number( rawValue );
	if ( ! Number.isSafeInteger( value ) ) {
		throw new Error(
			'Transition numeric state values must be safe integers.'
		);
	}
} else if ( type === 'boolean' ) {
	if ( ! [ 'true', 'false' ].includes( rawValue ) ) {
		throw new Error(
			'Transition boolean state values must be true or false.'
		);
	}
	value = rawValue === 'true';
}

const state = JSON.parse( readFileSync( statePath, 'utf8' ) );
state[ key ] = value;

const stateDirectory = dirname( statePath );
const temporaryPath = join(
	stateDirectory,
	`${ basename( statePath ) }.tmp-${ process.pid }-${ randomBytes(
		12
	).toString( 'hex' ) }`
);
let temporaryDescriptor;
let renamed = false;
let operationError;

try {
	temporaryDescriptor = openSync( temporaryPath, 'wx', 0o600 );
	writeFileSync( temporaryDescriptor, `${ JSON.stringify( state ) }\n` );
	fsyncSync( temporaryDescriptor );
	closeSync( temporaryDescriptor );
	temporaryDescriptor = undefined;

	if (
		process.env.E2E_TRANSITION_STATE_WRITE_FAIL_POINT === 'before-rename'
	) {
		throw new Error(
			'Injected transition state write failure before rename.'
		);
	}

	renameSync( temporaryPath, statePath );
	renamed = true;
	chmodSync( statePath, 0o600 );

	const directoryDescriptor = openSync( stateDirectory, 'r' );
	try {
		fsyncSync( directoryDescriptor );
	} finally {
		closeSync( directoryDescriptor );
	}
} catch ( error ) {
	operationError = error;
}

let cleanupError;
if ( ! renamed ) {
	if ( temporaryDescriptor !== undefined ) {
		try {
			closeSync( temporaryDescriptor );
		} catch ( error ) {
			cleanupError = error;
		}
	}
	try {
		unlinkSync( temporaryPath );
	} catch ( error ) {
		if ( error.code !== 'ENOENT' && ! cleanupError ) {
			cleanupError = error;
		}
	}
}

if ( operationError ) {
	throw operationError;
}
if ( cleanupError ) {
	throw cleanupError;
}
