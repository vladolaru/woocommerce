import { createHash } from 'node:crypto';
import {
	mkdir,
	mkdtemp,
	readFile,
	readdir,
	rm,
	stat,
	writeFile,
} from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

import { expect, test } from '@playwright/test';

import * as quarantineModule from './resource-quarantine';
import {
	assertResourcesUsable,
	quarantineResources,
} from './resource-quarantine';

const resourceKey = 'acct_local/account:provider-writes';
const resourceKeyHash = createHash( 'sha256' )
	.update( resourceKey )
	.digest( 'hex' );

async function quarantineDirectory(): Promise< string > {
	return mkdtemp( join( tmpdir(), 'woopayments-quarantine-test-' ) );
}

function useLockDirectory( directory: string ): () => void {
	const previous = process.env.E2E_WOOPAYMENTS_LOCK_DIR;
	process.env.E2E_WOOPAYMENTS_LOCK_DIR = directory;
	return () => {
		if ( previous === undefined ) {
			delete process.env.E2E_WOOPAYMENTS_LOCK_DIR;
		} else {
			process.env.E2E_WOOPAYMENTS_LOCK_DIR = previous;
		}
	};
}

test( 'quarantine writes a versioned 0600 receipt using only the SHA-256 resource key', async () => {
	const directory = await quarantineDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		const [ receipt ] = await quarantineResources(
			[ resourceKey ],
			'cleanup-failed',
			'test-results/run-quarantine'
		);
		const quarantinePath = join( directory, 'quarantine' );
		const receiptPath = join( quarantinePath, `${ resourceKeyHash }.json` );
		const receiptText = await readFile( receiptPath, 'utf8' );

		expect( receipt ).toEqual( {
			version: 1,
			resourceKeyHash,
			runId: 'run-quarantine',
			reasonCode: 'cleanup-failed',
			evidencePath: 'test-results/run-quarantine',
			quarantinedAt: expect.any( Number ),
		} );
		expect( JSON.parse( receiptText ) ).toEqual( receipt );
		expect( ( await stat( receiptPath ) ).mode % 0o1000 ).toBe( 0o600 );
		expect(
			( await readdir( quarantinePath ) ).filter( ( file ) =>
				file.endsWith( '.json' )
			)
		).toEqual( [ `${ resourceKeyHash }.json` ] );
		expect( receiptText ).not.toContain( resourceKey );
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a malformed quarantine receipt fails closed', async () => {
	const directory = await quarantineDirectory();
	const restoreLockDirectory = useLockDirectory( directory );
	const quarantinePath = join( directory, 'quarantine' );

	try {
		await mkdir( quarantinePath );
		await writeFile(
			join( quarantinePath, `${ resourceKeyHash }.json` ),
			'{"version":1'
		);

		await expect(
			assertResourcesUsable( [ resourceKey ] )
		).rejects.toThrow( /quarantine.*invalid|invalid.*quarantine/i );
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'a second quarantine preserves the first receipt and appends an event', async () => {
	const directory = await quarantineDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		const [ first ] = await quarantineResources(
			[ resourceKey ],
			'cleanup-failed',
			'test-results/run-first'
		);
		const [ repeated ] = await quarantineResources(
			[ resourceKey ],
			'teardown-failed',
			'test-results/run-second'
		);
		const quarantinePath = join( directory, 'quarantine' );
		const stored = JSON.parse(
			await readFile(
				join( quarantinePath, `${ resourceKeyHash }.json` ),
				'utf8'
			)
		);
		const events = (
			await readFile( join( quarantinePath, 'events.jsonl' ), 'utf8' )
		 )
			.trim()
			.split( '\n' )
			.map( ( line ) => JSON.parse( line ) );

		expect( repeated ).toEqual( first );
		expect( stored ).toEqual( first );
		expect( events ).toEqual( [
			{
				version: 1,
				resourceKeyHash,
				runId: 'run-first',
				reasonCode: 'cleanup-failed',
				evidencePath: 'test-results/run-first',
				quarantinedAt: expect.any( Number ),
			},
			{
				version: 1,
				resourceKeyHash,
				runId: 'run-second',
				reasonCode: 'teardown-failed',
				evidencePath: 'test-results/run-second',
				quarantinedAt: expect.any( Number ),
			},
		] );
		expect( JSON.stringify( events ) ).not.toContain( resourceKey );
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'concurrent quarantines both resolve with an immutable first receipt and valid events', async () => {
	const directory = await quarantineDirectory();
	const restoreLockDirectory = useLockDirectory( directory );

	try {
		const [ firstResult, secondResult ] = await Promise.all( [
			quarantineResources(
				[ resourceKey ],
				'cleanup-failed',
				'test-results/run-first'
			),
			quarantineResources(
				[ resourceKey ],
				'teardown-failed',
				'test-results/run-second'
			),
		] );
		const firstReceipt = firstResult[ 0 ];
		const quarantinePath = join( directory, 'quarantine' );
		const stored = JSON.parse(
			await readFile(
				join( quarantinePath, `${ resourceKeyHash }.json` ),
				'utf8'
			)
		);
		const events = (
			await readFile( join( quarantinePath, 'events.jsonl' ), 'utf8' )
		 )
			.trim()
			.split( '\n' )
			.map( ( line ) => JSON.parse( line ) );

		expect( firstResult[ 0 ] ).toEqual( stored );
		expect( secondResult[ 0 ] ).toEqual( stored );
		expect( stored ).toEqual( firstReceipt );
		expect( events ).toHaveLength( 2 );
		for ( const event of events ) {
			expect( event ).toMatchObject( {
				version: 1,
				resourceKeyHash,
				runId: expect.any( String ),
				reasonCode: expect.stringMatching(
					/^(cleanup-failed|teardown-failed)$/
				),
				evidencePath: expect.stringMatching(
					/^test-results\/run-(first|second)$/
				),
				quarantinedAt: expect.any( Number ),
			} );
		}
	} finally {
		restoreLockDirectory();
		await rm( directory, { recursive: true, force: true } );
	}
} );

test( 'no public clear/unquarantine method exists', () => {
	expect( Object.keys( quarantineModule ).sort() ).toEqual( [
		'assertResourcesUsable',
		'quarantineResources',
	] );
} );
